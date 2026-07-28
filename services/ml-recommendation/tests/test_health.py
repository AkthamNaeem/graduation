"""Liveness and startup-owned readiness contract tests."""

from pathlib import Path
from typing import TYPE_CHECKING, cast

from fastapi.testclient import TestClient

from smart_recruitment_ml.core.config import Settings
from smart_recruitment_ml.main import create_app

if TYPE_CHECKING:
    from fastapi import FastAPI


def test_liveness_contract_with_missing_bundle(tmp_path: Path) -> None:
    settings = Settings(
        bundle_dir=tmp_path / "missing",
        service_token="x" * 32,
    )
    with TestClient(create_app(settings)) as client:
        response = client.get("/health/live")

    assert response.status_code == 200
    assert response.json() == {
        "status": "live",
        "service": "ml-recommendation",
        "service_version": "0.2.0",
    }


def test_readiness_is_unavailable_without_bundle(tmp_path: Path) -> None:
    settings = Settings(
        bundle_dir=tmp_path / "missing",
        service_token="x" * 32,
    )
    with TestClient(create_app(settings)) as client:
        response = client.get("/health/ready")

    assert response.status_code == 503
    assert response.json() == {
        "status": "not_ready",
        "code": "MODEL_BUNDLE_NOT_READY",
    }


def test_readiness_requires_a_configured_token(inference_settings: Settings) -> None:
    settings = inference_settings.model_copy(update={"service_token": None})
    with TestClient(create_app(settings)) as client:
        response = client.get("/health/ready")
        assert client.get("/health/live").status_code == 200

    assert response.status_code == 503
    assert response.json() == {
        "status": "not_ready",
        "code": "SERVICE_TOKEN_NOT_CONFIGURED",
    }


def test_ready_contract(inference_settings: Settings) -> None:
    with TestClient(create_app(inference_settings)) as client:
        response = client.get("/health/ready")
        runtime = cast("FastAPI", client.app).state.runtime_state

    assert response.status_code == 200
    assert response.json() == {
        "status": "ready",
        "service": "ml-recommendation",
        "service_version": "0.2.0",
        "bundle_version": "job-rec-inference-bundle-v1",
        "model_version": "xgbranker-tuned-v1",
        "feature_schema_version": "job-rec-features-v1",
    }
    assert runtime.load_count == 1


def test_health_routes_are_unauthenticated(inference_client: TestClient) -> None:
    assert inference_client.get("/health/live").status_code == 200
    assert inference_client.get("/health/ready").status_code == 200


def test_openapi_contains_the_four_internal_paths(inference_client: TestClient) -> None:
    response = inference_client.get("/openapi.json")

    assert response.status_code == 200
    assert set(response.json()["paths"]) == {
        "/health/live",
        "/health/ready",
        "/v1/model/metadata",
        "/v1/recommendations/rank",
    }


def test_disabling_docs_keeps_health_available(inference_settings: Settings) -> None:
    settings = inference_settings.model_copy(update={"docs_enabled": False})
    with TestClient(create_app(settings)) as client:
        assert client.get("/docs").status_code == 404
        assert client.get("/redoc").status_code == 404
        assert client.get("/openapi.json").status_code == 404
        assert client.get("/health/live").status_code == 200
