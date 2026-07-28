"""Authenticated internal ranking endpoint."""

from __future__ import annotations

from typing import Annotated, cast

from fastapi import APIRouter, Depends, Request, status

from smart_recruitment_ml.core.config import Settings
from smart_recruitment_ml.core.errors import ServiceError
from smart_recruitment_ml.core.inference import RuntimeState, rank_jobs
from smart_recruitment_ml.core.security import require_service_token
from smart_recruitment_ml.schemas.inference import RankRequest, RankResponse

router = APIRouter(prefix="/v1/recommendations", tags=["recommendations"])


def get_runtime_state(request: Request) -> RuntimeState:
    return cast("RuntimeState", request.app.state.runtime_state)


def get_settings(request: Request) -> Settings:
    return cast("Settings", request.app.state.settings)


RuntimeDependency = Annotated[RuntimeState, Depends(get_runtime_state)]
SettingsDependency = Annotated[Settings, Depends(get_settings)]
AuthenticationDependency = Annotated[None, Depends(require_service_token)]


@router.post(
    "/rank",
    response_model=RankResponse,
    responses={
        401: {"description": "Authentication failed."},
        422: {"description": "Request validation failed."},
        500: {"description": "Inference contract failed."},
        503: {"description": "Model Bundle is not ready."},
    },
)
def rank_recommendations(
    payload: RankRequest,
    runtime: RuntimeDependency,
    settings: SettingsDependency,
    _authentication: AuthenticationDependency,
) -> RankResponse:
    """Rank all supplied Jobs; requested limit is intentionally not truncating."""
    if len(payload.jobs) > settings.max_jobs_per_request:
        raise ServiceError(
            code="JOB_LIMIT_EXCEEDED",
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            request_id=payload.request_id,
        )
    if payload.limit > settings.max_results:
        raise ServiceError(
            code="REQUEST_VALIDATION_FAILED",
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            request_id=payload.request_id,
        )
    return rank_jobs(payload, runtime)
