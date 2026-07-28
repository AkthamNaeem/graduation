"""Typed, environment-backed settings for the inference service."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

Environment = Literal["local", "testing", "development", "staging", "production"]
LogLevel = Literal["DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"]


class Settings(BaseSettings):
    """Configuration loaded from ML_-prefixed environment variables."""

    model_config = SettingsConfigDict(
        env_prefix="ML_",
        case_sensitive=False,
        extra="ignore",
    )

    service_name: str = "ml-recommendation"
    service_version: str = "0.2.0"
    environment: Environment = "local"
    host: str = "0.0.0.0"
    port: int = Field(default=8001, ge=1, le=65535)
    log_level: LogLevel = "INFO"
    docs_enabled: bool = True
    bundle_dir: Path = Path("services/ml-recommendation/data/bundles/recommendation/v1")
    service_token: str | None = Field(default=None, repr=False)
    max_jobs_per_request: int = Field(default=500, ge=1, le=1000)
    max_results: int = Field(default=100, ge=1, le=500)

    @field_validator("bundle_dir")
    @classmethod
    def resolve_bundle_dir(cls, value: Path) -> Path:
        """Remove relative-path ambiguity without requiring the path to exist."""
        return value.expanduser().resolve(strict=False)

    @field_validator("service_token")
    @classmethod
    def validate_service_token(cls, value: str | None) -> str | None:
        """Permit an absent token for liveness, but reject weak configured tokens."""
        if value is None or value == "":
            return None
        if len(value) < 32:
            raise ValueError("ML_SERVICE_TOKEN must contain at least 32 characters.")
        return value

    @model_validator(mode="after")
    def validate_limits(self) -> Settings:
        """Keep the returned-result ceiling within the accepted Job ceiling."""
        if self.max_results > self.max_jobs_per_request:
            raise ValueError("ML_MAX_RESULTS cannot exceed ML_MAX_JOBS_PER_REQUEST.")
        return self


@lru_cache
def get_settings() -> Settings:
    """Return the process settings using a cache safe to clear in tests."""
    return Settings()


def clear_settings_cache() -> None:
    """Clear cached settings so tests can isolate environment overrides."""
    get_settings.cache_clear()
