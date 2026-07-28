"""Unauthenticated liveness and readiness endpoints."""

from typing import Annotated, Literal, cast

from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse

from smart_recruitment_ml.core.config import Settings
from smart_recruitment_ml.core.inference import RuntimeState
from smart_recruitment_ml.schemas.health import (
    LivenessResponse,
    NotReadyResponse,
    ReadyResponse,
)

router = APIRouter(prefix="/health", tags=["health"])


def get_request_settings(request: Request) -> Settings:
    """Return settings owned by the current application instance."""
    return cast("Settings", request.app.state.settings)


SettingsDependency = Annotated[Settings, Depends(get_request_settings)]


def get_runtime_state(request: Request) -> RuntimeState:
    return cast("RuntimeState", request.app.state.runtime_state)


RuntimeDependency = Annotated[RuntimeState, Depends(get_runtime_state)]


@router.get("/live", response_model=LivenessResponse)
def liveness(settings: SettingsDependency) -> LivenessResponse:
    """Report that the HTTP application is running."""
    return LivenessResponse(service_version=settings.service_version)


@router.get(
    "/ready",
    response_model=ReadyResponse | NotReadyResponse,
)
def readiness(
    settings: SettingsDependency,
    runtime: RuntimeDependency,
) -> ReadyResponse | JSONResponse:
    """Report token and Bundle readiness without reloading either."""
    if not runtime.ready or runtime.bundle is None:
        response = NotReadyResponse(
            code=cast(
                "Literal['MODEL_BUNDLE_NOT_READY', 'SERVICE_TOKEN_NOT_CONFIGURED']",
                runtime.not_ready_code or "MODEL_BUNDLE_NOT_READY",
            ),
        )
        return JSONResponse(status_code=503, content=response.model_dump(mode="json"))
    manifest = runtime.bundle.manifest
    return ReadyResponse(
        service_version=settings.service_version,
        bundle_version=manifest.bundle_version,
        model_version=manifest.model_version,
        feature_schema_version=manifest.feature_schema_version,
    )
