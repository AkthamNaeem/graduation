"""Tests for typed service configuration."""

from pathlib import Path

import pytest
from pydantic import ValidationError

from smart_recruitment_ml.core.config import Settings, clear_settings_cache, get_settings


def test_default_settings() -> None:
    settings = Settings()

    assert settings.service_name == "ml-recommendation"
    assert settings.service_version == "0.2.0"
    assert settings.environment == "local"
    assert settings.host == "0.0.0.0"
    assert settings.port == 8001
    assert settings.log_level == "INFO"
    assert settings.docs_enabled is True
    assert settings.bundle_dir.is_absolute()
    assert settings.service_token is None
    assert settings.max_jobs_per_request == 500
    assert settings.max_results == 100


def test_ml_environment_prefix_overrides_settings(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("ML_SERVICE_NAME", "Configured ML Service")
    monkeypatch.setenv("ML_ENVIRONMENT", "staging")
    monkeypatch.setenv("ML_PORT", "9001")
    monkeypatch.setenv("ML_DOCS_ENABLED", "false")

    settings = Settings()

    assert settings.service_name == "Configured ML Service"
    assert settings.environment == "staging"
    assert settings.port == 9001
    assert settings.docs_enabled is False


@pytest.mark.parametrize("port", [0, 65536])
def test_port_outside_valid_range_is_rejected(port: int) -> None:
    with pytest.raises(ValidationError):
        Settings(port=port)


def test_unsupported_environment_is_rejected() -> None:
    with pytest.raises(ValidationError):
        Settings(environment="preview")  # type: ignore[arg-type]


def test_unsupported_log_level_is_rejected() -> None:
    with pytest.raises(ValidationError):
        Settings(log_level="TRACE")  # type: ignore[arg-type]


def test_settings_cache_can_be_cleared(monkeypatch: pytest.MonkeyPatch) -> None:
    initial = get_settings()
    monkeypatch.setenv("ML_SERVICE_NAME", "Changed after cache")

    assert get_settings() is initial
    assert get_settings().service_name == "ml-recommendation"

    clear_settings_cache()

    refreshed = get_settings()
    assert refreshed is not initial
    assert refreshed.service_name == "Changed after cache"


def test_bundle_path_is_resolved() -> None:
    settings = Settings(bundle_dir=Path("relative-bundle"))

    assert settings.bundle_dir.is_absolute()
    assert settings.bundle_dir.name == "relative-bundle"


def test_short_configured_token_is_rejected() -> None:
    with pytest.raises(ValidationError, match="at least 32"):
        Settings(service_token="short")


@pytest.mark.parametrize(
    ("field", "value"),
    [
        ("max_jobs_per_request", 0),
        ("max_jobs_per_request", 1001),
        ("max_results", 0),
        ("max_results", 501),
    ],
)
def test_invalid_inference_limits_are_rejected(field: str, value: int) -> None:
    with pytest.raises(ValidationError):
        Settings.model_validate({field: value})


def test_result_limit_cannot_exceed_job_limit() -> None:
    with pytest.raises(ValidationError, match="cannot exceed"):
        Settings(max_jobs_per_request=10, max_results=11)
