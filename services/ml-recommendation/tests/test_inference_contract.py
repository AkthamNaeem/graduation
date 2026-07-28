"""Generated OpenAPI, examples, manifest, and reproducibility tests."""

import hashlib
import json
from pathlib import Path
from typing import Any

import pytest

from smart_recruitment_ml.api.contract_export import (
    API_CONTRACT_VERSION,
    ARCHITECTURE_SHA256,
    SOURCE_REVISION,
    export_contract,
)
from smart_recruitment_ml.schemas.errors import ErrorEnvelope
from smart_recruitment_ml.schemas.inference import RankRequest, RankResponse

SERVICE_ROOT = Path(__file__).resolve().parents[1]
CONTRACT_DIR = SERVICE_ROOT / "data" / "contracts" / "inference" / "v1"
BUNDLE_DIR = SERVICE_ROOT / "data" / "bundles" / "recommendation" / "v1"
CANDIDATES = SERVICE_ROOT / "data" / "synthetic" / "v1" / "candidates.jsonl"
JOBS = SERVICE_ROOT / "data" / "synthetic" / "v1" / "jobs.jsonl"
TOKEN = "phase12-local-test-token-20260725-0001"


def _json(name: str) -> dict[str, Any]:
    return json.loads((CONTRACT_DIR / name).read_bytes())


def _hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def test_contract_directory_contains_exactly_five_files() -> None:
    assert {path.name for path in CONTRACT_DIR.iterdir()} == {
        "INFERENCE_CONTRACT.md",
        "contract_manifest.json",
        "openapi.json",
        "request.example.json",
        "response.example.json",
    }


def test_openapi_has_exact_route_contract() -> None:
    openapi = _json("openapi.json")

    assert set(openapi["paths"]) == {
        "/health/live",
        "/health/ready",
        "/v1/model/metadata",
        "/v1/recommendations/rank",
    }
    assert set(openapi["paths"]["/health/live"]) == {"get"}
    assert set(openapi["paths"]["/health/ready"]) == {"get"}
    assert set(openapi["paths"]["/v1/model/metadata"]) == {"get"}
    assert set(openapi["paths"]["/v1/recommendations/rank"]) == {"post"}


def test_request_example_validates_and_contains_no_labels_or_vectors() -> None:
    request = _json("request.example.json")
    validated = RankRequest.model_validate(request)
    serialized = json.dumps(request).casefold()

    assert len(validated.jobs) == 3
    assert "relevance_label" not in serialized
    assert "feature_values" not in serialized
    assert "candidate_id" not in serialized
    assert "pair_id" not in serialized


def test_response_example_validates_and_is_deterministic() -> None:
    response = _json("response.example.json")
    validated = RankResponse.model_validate(response)

    assert validated.latency_ms == 0.0
    assert validated.prediction_count == len(validated.predictions) == 3
    assert [item.rank for item in validated.predictions] == [1, 2, 3]
    assert [item.job_id for item in validated.predictions] == [123, 122, 121]


def test_error_example_validates() -> None:
    validated = ErrorEnvelope.model_validate(
        {
            "request_id": None,
            "error": {
                "code": "REQUEST_VALIDATION_FAILED",
                "message": "Request validation failed.",
                "details": {},
            },
        },
    )

    assert validated.error.code == "REQUEST_VALIDATION_FAILED"


def test_contract_manifest_captures_auth_limits_and_non_usage() -> None:
    manifest = _json("contract_manifest.json")

    assert manifest["api_contract_version"] == API_CONTRACT_VERSION
    assert manifest["service_version"] == "0.2.0"
    assert manifest["bundle_version"] == "job-rec-inference-bundle-v1"
    assert manifest["request_schema"] == "RankRequest"
    assert manifest["response_schema"] == "RankResponse"
    assert manifest["error_schema"] == "ErrorEnvelope"
    assert manifest["authentication"]["header"] == "X-ML-Service-Token"
    assert manifest["limits"]["maximum_jobs"] == 500
    assert manifest["limits"]["maximum_limit"] == 100
    assert not any(manifest["test_non_usage"].values())


def test_contract_manifest_output_integrity_matches_files() -> None:
    manifest = _json("contract_manifest.json")

    for artifact in manifest["output_files"]:
        path = CONTRACT_DIR / artifact["path"]
        assert artifact["records"] == 1
        assert artifact["bytes"] == path.stat().st_size
        assert artifact["sha256"] == _hash(path)


def test_contract_reexport_is_byte_for_byte_deterministic(tmp_path: Path) -> None:
    output = tmp_path / "contract"
    export_contract(
        output_dir=output,
        candidates_file=CANDIDATES,
        jobs_file=JOBS,
        bundle_dir=BUNDLE_DIR,
        service_token=TOKEN,
        source_revision=SOURCE_REVISION,
        architecture_sha256=ARCHITECTURE_SHA256,
    )

    assert {path.name: _hash(path) for path in sorted(CONTRACT_DIR.iterdir())} == {
        path.name: _hash(path) for path in sorted(output.iterdir())
    }


def test_invalid_contract_source_revision_fails(tmp_path: Path) -> None:
    with pytest.raises(ValueError, match="Source revision"):
        export_contract(
            output_dir=tmp_path / "contract",
            candidates_file=CANDIDATES,
            jobs_file=JOBS,
            bundle_dir=BUNDLE_DIR,
            service_token=TOKEN,
            source_revision="0" * 40,
            architecture_sha256=ARCHITECTURE_SHA256,
        )
