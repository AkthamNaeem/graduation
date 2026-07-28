"""Shared fixtures that isolate environment-backed settings."""

import json
from collections.abc import Iterator
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

from smart_recruitment_ml.core.config import Settings, clear_settings_cache
from smart_recruitment_ml.main import create_app

ML_ENVIRONMENT_VARIABLES = (
    "ML_SERVICE_NAME",
    "ML_SERVICE_VERSION",
    "ML_ENVIRONMENT",
    "ML_HOST",
    "ML_PORT",
    "ML_LOG_LEVEL",
    "ML_DOCS_ENABLED",
    "ML_BUNDLE_DIR",
    "ML_SERVICE_TOKEN",
    "ML_MAX_JOBS_PER_REQUEST",
    "ML_MAX_RESULTS",
)
SERVICE_ROOT = Path(__file__).resolve().parents[1]
BUNDLE_DIR = SERVICE_ROOT / "data" / "bundles" / "recommendation" / "v1"
CONTRACT_DIR = SERVICE_ROOT / "data" / "contracts" / "inference" / "v1"
SERVICE_TOKEN = "phase12-local-test-token-20260725-0001"


@pytest.fixture(autouse=True)
def isolate_settings(monkeypatch: pytest.MonkeyPatch) -> Iterator[None]:
    """Remove ML settings and clear the settings cache around every test."""
    for variable in ML_ENVIRONMENT_VARIABLES:
        monkeypatch.delenv(variable, raising=False)
    clear_settings_cache()
    yield
    clear_settings_cache()


@pytest.fixture
def inference_settings() -> Settings:
    """Return valid local settings backed by the generated frozen Bundle."""
    return Settings(bundle_dir=BUNDLE_DIR, service_token=SERVICE_TOKEN)


@pytest.fixture
def rank_payload() -> dict[str, Any]:
    """Return the deterministic three-Job Contract request."""
    return json.loads((CONTRACT_DIR / "request.example.json").read_bytes())


@pytest.fixture
def inference_client(inference_settings: Settings) -> Iterator[TestClient]:
    """Run the application lifespan for a ready in-process client."""
    with TestClient(create_app(inference_settings)) as client:
        yield client
