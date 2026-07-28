"""End-to-end authentication, validation, Feature parity, and inference tests."""

import copy
import math
from pathlib import Path
from typing import Any

import numpy as np
import pytest
from fastapi.testclient import TestClient
from httpx import Response

from smart_recruitment_ml.bundle.loader import load_bundle
from smart_recruitment_ml.core.config import Settings
from smart_recruitment_ml.core.inference import (
    _predict,
    build_feature_matrix,
    display_score,
    rank_jobs,
    ready_state,
)
from smart_recruitment_ml.features.pipeline import FEATURE_NAMES, FeaturePipelineV1
from smart_recruitment_ml.main import create_app
from smart_recruitment_ml.schemas.features import CandidateFeatureInput, JobFeatureInput
from smart_recruitment_ml.schemas.inference import RankRequest

SERVICE_ROOT = Path(__file__).resolve().parents[1]
BUNDLE_DIR = SERVICE_ROOT / "data" / "bundles" / "recommendation" / "v1"
TOKEN = "phase12-local-test-token-20260725-0001"
HEADERS = {"X-ML-Service-Token": TOKEN}


def _error_code(response: Response) -> str:
    return str(response.json()["error"]["code"])


def test_missing_and_wrong_tokens_return_the_same_safe_error(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    missing = inference_client.post("/v1/recommendations/rank", json=rank_payload)
    wrong = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers={"X-ML-Service-Token": "w" * 32},
    )

    assert missing.status_code == wrong.status_code == 401
    assert missing.json() == wrong.json()
    assert _error_code(missing) == "SERVICE_AUTHENTICATION_FAILED"
    assert TOKEN not in missing.text


def test_correct_token_accepts_rank_request(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 200
    body = response.json()
    assert body["prediction_count"] == len(rank_payload["jobs"]) == 3
    assert body["requested_limit"] == rank_payload["limit"]
    assert [item["rank"] for item in body["predictions"]] == [1, 2, 3]
    assert [item["job_id"] for item in body["predictions"]] == [123, 122, 121]
    assert all(math.isfinite(item["raw_score"]) for item in body["predictions"])
    assert all(0 <= item["display_score"] <= 100 for item in body["predictions"])


def test_request_limit_does_not_truncate_predictions(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    rank_payload["limit"] = 1
    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 200
    assert response.json()["requested_limit"] == 1
    assert response.json()["prediction_count"] == 3
    assert len(response.json()["predictions"]) == 3


def test_ranking_and_explanations_are_deterministic(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    first = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    ).json()
    second = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    ).json()
    first.pop("latency_ms")
    second.pop("latency_ms")

    assert first == second


def test_metadata_endpoint_is_authenticated_and_safe(
    inference_client: TestClient,
) -> None:
    assert inference_client.get("/v1/model/metadata").status_code == 401
    response = inference_client.get("/v1/model/metadata", headers=HEADERS)

    assert response.status_code == 200
    body = response.json()
    assert body["api_contract_version"] == "recommendation-ranking-api-v1"
    assert body["model_sha256"] == (
        "3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e"
    )
    assert body["feature_count"] == 103
    assert body["ready"] is True
    assert "path" not in response.text.casefold()
    assert TOKEN not in response.text
    assert "hyperparameters" not in response.text


@pytest.mark.parametrize(
    ("mutation", "expected_code"),
    [
        (lambda value: value.update({"unknown": True}), "REQUEST_VALIDATION_FAILED"),
        (
            lambda value: value["candidate"].update({"unknown": True}),
            "REQUEST_VALIDATION_FAILED",
        ),
        (
            lambda value: value["jobs"][0]["professional_facts"].update(
                {"unknown": True},
            ),
            "REQUEST_VALIDATION_FAILED",
        ),
        (
            lambda value: value.update({"request_id": "not-a-uuid"}),
            "REQUEST_VALIDATION_FAILED",
        ),
        (
            lambda value: value.update({"feature_schema_version": "unsupported"}),
            "FEATURE_SCHEMA_VERSION_UNSUPPORTED",
        ),
        (
            lambda value: value["jobs"][1].update(
                {"job_id": value["jobs"][0]["job_id"]},
            ),
            "DUPLICATE_JOB_ID",
        ),
        (lambda value: value.update({"jobs": []}), "REQUEST_VALIDATION_FAILED"),
        (lambda value: value.update({"limit": 0}), "REQUEST_VALIDATION_FAILED"),
        (
            lambda value: value.update({"limit": len(value["jobs"]) + 1}),
            "REQUEST_VALIDATION_FAILED",
        ),
        (
            lambda value: value["candidate"]["professional_facts"].update(
                {"total_experience_years": "NaN"},
            ),
            "REQUEST_VALIDATION_FAILED",
        ),
    ],
)
def test_invalid_requests_use_stable_errors(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
    mutation: object,
    expected_code: str,
) -> None:
    mutation(rank_payload)  # type: ignore[operator]
    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 422
    assert _error_code(response) == expected_code
    assert "traceback" not in response.text.casefold()


def test_configured_job_limit_is_enforced(
    inference_settings: Settings,
    rank_payload: dict[str, Any],
) -> None:
    settings = inference_settings.model_copy(
        update={"max_jobs_per_request": 2, "max_results": 2},
    )
    rank_payload["limit"] = 2
    with TestClient(create_app(settings)) as client:
        response = client.post(
            "/v1/recommendations/rank",
            json=rank_payload,
            headers=HEADERS,
        )

    assert response.status_code == 422
    assert _error_code(response) == "JOB_LIMIT_EXCEEDED"


def test_duplicate_normalized_skills_follow_pipeline_merge_policy(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    skill = copy.deepcopy(rank_payload["candidate"]["professional_facts"]["skills"][0])
    rank_payload["candidate"]["professional_facts"]["skills"].append(skill)

    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 200
    assert response.json()["prediction_count"] == 3


def test_inference_feature_matrix_equals_offline_pipeline_exactly(
    rank_payload: dict[str, Any],
) -> None:
    request = RankRequest.model_validate(rank_payload)
    bundle = load_bundle(BUNDLE_DIR)
    actual = build_feature_matrix(request, bundle)
    candidate = CandidateFeatureInput.model_validate(
        request.candidate.professional_facts.model_dump(),
    )
    expected = np.asarray(
        [
            FeaturePipelineV1()
            .transform(
                candidate,
                JobFeatureInput.model_validate(job.professional_facts.model_dump()),
            )
            .feature_values
            for job in request.jobs
        ],
        dtype=np.float32,
    )

    assert actual.shape == (3, 103)
    assert np.isfinite(actual).all()
    assert np.array_equal(actual, expected)
    assert float(np.max(np.abs(actual - expected))) == 0.0
    assert tuple(bundle.feature_schema["feature_names"]) == FEATURE_NAMES


def test_exact_contributions_reconstruct_raw_scores(
    rank_payload: dict[str, Any],
) -> None:
    request = RankRequest.model_validate(rank_payload)
    bundle = load_bundle(BUNDLE_DIR)
    matrix = build_feature_matrix(request, bundle)
    scores, contributions = _predict(bundle, matrix)

    assert contributions.shape == (3, 104)
    assert np.isfinite(contributions).all()
    assert float(np.max(np.abs(contributions.sum(axis=1) - scores))) <= 1e-5


def test_safe_explanation_contract_contains_only_allowlisted_groups_and_codes(
    rank_payload: dict[str, Any],
) -> None:
    request = RankRequest.model_validate(rank_payload)
    bundle = load_bundle(BUNDLE_DIR)
    response = rank_jobs(request, ready_state(bundle))
    allowed_groups = {item.feature_group for item in bundle.reason_code_mapping.groups}
    allowed_codes = {
        code
        for item in bundle.reason_code_mapping.groups
        for code in (item.positive, item.negative)
    }

    for prediction in response.predictions:
        assert len(prediction.top_positive_factors) <= 3
        assert len(prediction.top_negative_factors) <= 3
        for factor in [
            *prediction.top_positive_factors,
            *prediction.top_negative_factors,
        ]:
            assert factor.feature_group in allowed_groups
            assert factor.code in allowed_codes
            assert 0 <= factor.strength <= 1
            assert factor.contribution != 0
            assert factor.feature_group not in FEATURE_NAMES


def test_display_transform_is_monotonic_and_clips() -> None:
    minimum = -2.0
    maximum = 2.0

    assert display_score(-3.0, minimum, maximum) == 0.0
    assert display_score(-2.0, minimum, maximum) == 0.0
    assert display_score(0.0, minimum, maximum) == 50.0
    assert display_score(2.0, minimum, maximum) == 100.0
    assert display_score(3.0, minimum, maximum) == 100.0


def test_rank_returns_503_when_bundle_is_unavailable(
    tmp_path: Path,
    rank_payload: dict[str, Any],
) -> None:
    settings = Settings(bundle_dir=tmp_path / "missing", service_token=TOKEN)
    with TestClient(create_app(settings)) as client:
        response = client.post(
            "/v1/recommendations/rank",
            json=rank_payload,
            headers=HEADERS,
        )

    assert response.status_code == 503
    assert _error_code(response) == "MODEL_BUNDLE_NOT_READY"
