"""Typed response models for liveness and readiness."""

from typing import Literal

from pydantic import BaseModel, ConfigDict


class StrictHealthModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class LivenessResponse(StrictHealthModel):
    """Response returned when the HTTP process is alive."""

    status: Literal["live"] = "live"
    service: Literal["ml-recommendation"] = "ml-recommendation"
    service_version: str


class ReadyResponse(StrictHealthModel):
    """Response returned after token and Bundle validation succeed."""

    status: Literal["ready"] = "ready"
    service: Literal["ml-recommendation"] = "ml-recommendation"
    service_version: str
    bundle_version: str
    model_version: str
    feature_schema_version: str


class NotReadyResponse(StrictHealthModel):
    """Safe readiness failure without paths or exception details."""

    status: Literal["not_ready"] = "not_ready"
    code: Literal["MODEL_BUNDLE_NOT_READY", "SERVICE_TOKEN_NOT_CONFIGURED"]
