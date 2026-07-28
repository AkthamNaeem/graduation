"""Recursive privacy, safe-failure, and forbidden-capability tests."""

from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

from smart_recruitment_ml.features.pipeline import FeaturePipelineV1

SERVICE_ROOT = Path(__file__).resolve().parents[1]
HEADERS = {"X-ML-Service-Token": "phase12-local-test-token-20260725-0001"}
SENSITIVE_KEYS = (
    "name",
    "full_name",
    "email",
    "phone",
    "birth_date",
    "date_of_birth",
    "age",
    "gender",
    "sex",
    "nationality",
    "marital_status",
    "personal_address",
    "address",
    "cv_file",
    "cv_path",
    "raw_cv",
    "raw_cv_text",
    "parsed_cv_json",
    "cover_letter",
    "screening_answers",
    "application_status",
    "application_history",
    "test_results",
    "interview_results",
    "internal_notes",
    "auth_token",
    "sanctum_token",
    "cookie",
    "cookies",
    "session",
    "password",
    "secret",
    "db_password",
    "database_url",
)


@pytest.mark.parametrize("sensitive_key", SENSITIVE_KEYS)
def test_recursive_sensitive_denylist_rejects_every_key(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
    sensitive_key: str,
) -> None:
    secret_value = "never-reflect-this-value"
    rank_payload["candidate"]["professional_facts"]["nested"] = {
        "deeper": {sensitive_key.upper().replace("_", "-"): secret_value},
    }

    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "SENSITIVE_FIELD_NOT_ALLOWED"
    assert secret_value not in response.text
    assert response.json()["error"]["details"] == {"error_count": 1}


@pytest.mark.parametrize(
    "profile_ref",
    ["candidate@example.test", "+963 999 111 222"],
)
def test_profile_reference_rejects_contact_data(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
    profile_ref: str,
) -> None:
    rank_payload["candidate"]["profile_ref"] = profile_ref

    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "SENSITIVE_FIELD_NOT_ALLOWED"
    assert profile_ref not in response.text


def test_legitimate_skill_name_is_not_blocked(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 200


def test_api_cannot_accept_feature_vectors(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
) -> None:
    rank_payload["candidate"]["feature_values"] = [0.0] * 103

    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "REQUEST_VALIDATION_FAILED"


def test_feature_pipeline_failure_is_safe(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def fail_transform(
        _self: FeaturePipelineV1,
        _candidate: object,
        _job: object,
    ) -> object:
        raise ValueError("private pipeline details")

    monkeypatch.setattr(FeaturePipelineV1, "transform", fail_transform)
    response = inference_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 500
    assert response.json()["error"]["code"] == "FEATURE_PIPELINE_FAILED"
    assert "private pipeline details" not in response.text
    assert "traceback" not in response.text.casefold()


def test_unexpected_inference_failure_is_safe(
    inference_client: TestClient,
    rank_payload: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    import smart_recruitment_ml.api.recommendations as recommendations

    def fail_inference(_payload: object, _runtime: object) -> object:
        raise RuntimeError("private inference details")

    monkeypatch.setattr(recommendations, "rank_jobs", fail_inference)
    safe_client = TestClient(inference_client.app, raise_server_exceptions=False)
    response = safe_client.post(
        "/v1/recommendations/rank",
        json=rank_payload,
        headers=HEADERS,
    )

    assert response.status_code == 500
    assert response.json()["error"]["code"] == "INFERENCE_CONTRACT_FAILED"
    assert "private inference details" not in response.text
    assert "traceback" not in response.text.casefold()


def test_phase12_runtime_sources_have_no_forbidden_capabilities() -> None:
    package = SERVICE_ROOT / "src" / "smart_recruitment_ml"
    paths = [
        *sorted((package / "api").glob("*.py")),
        *sorted((package / "bundle").glob("*.py")),
        *sorted((package / "core").glob("*.py")),
    ]
    source = "\n".join(path.read_text(encoding="utf-8").casefold() for path in paths)
    forbidden = (
        "xgboost.train",
        "xgb.train",
        "import shap",
        "from shap",
        "sqlalchemy",
        "pymysql",
        "mysqlclient",
        "redis",
        "requests.",
        "httpx.",
    )

    assert not any(value in source for value in forbidden)


def test_generated_artifacts_do_not_contain_the_service_token() -> None:
    token = HEADERS["X-ML-Service-Token"].encode()
    directories = [
        SERVICE_ROOT / "data" / "bundles" / "recommendation" / "v1",
        SERVICE_ROOT / "data" / "contracts" / "inference" / "v1",
    ]

    assert not any(
        token in path.read_bytes()
        for directory in directories
        for path in directory.iterdir()
        if path.is_file()
    )
