"""Authenticated frozen-Model metadata endpoint."""

from __future__ import annotations

from typing import Annotated, cast

from fastapi import APIRouter, Depends, Request, status

from smart_recruitment_ml.core.errors import ServiceError
from smart_recruitment_ml.core.inference import API_CONTRACT_VERSION, RuntimeState
from smart_recruitment_ml.core.security import require_service_token
from smart_recruitment_ml.schemas.model import ModelMetadataResponse

router = APIRouter(prefix="/v1/model", tags=["model"])


def get_runtime_state(request: Request) -> RuntimeState:
    """Return startup-owned immutable runtime state."""
    return cast("RuntimeState", request.app.state.runtime_state)


RuntimeDependency = Annotated[RuntimeState, Depends(get_runtime_state)]
AuthenticationDependency = Annotated[None, Depends(require_service_token)]


@router.get(
    "/metadata",
    response_model=ModelMetadataResponse,
    responses={401: {"description": "Authentication failed."}, 503: {"description": "Not ready."}},
)
def model_metadata(
    runtime: RuntimeDependency,
    _authentication: AuthenticationDependency,
) -> ModelMetadataResponse:
    """Return safe identity and contract metadata, never artifact contents."""
    if not runtime.ready or runtime.bundle is None:
        raise ServiceError(
            code="MODEL_METADATA_UNAVAILABLE",
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
        )
    manifest = runtime.bundle.manifest
    return ModelMetadataResponse(
        api_contract_version=API_CONTRACT_VERSION,
        bundle_version=manifest.bundle_version,
        model_version=manifest.model_version,
        model_format=manifest.model_format,
        model_sha256=manifest.model_sha256,
        dataset_version=manifest.dataset_version,
        feature_schema_version=manifest.feature_schema_version,
        feature_schema_sha256=manifest.feature_schema_sha256,
        feature_count=manifest.feature_count,
        model_source_revision=manifest.model_source_revision,
        score_transform_version=manifest.score_transform_version,
        explanation_contract_version=manifest.explanation_contract_version,
        reason_code_mapping_version=manifest.reason_code_mapping_version,
        ready=True,
    )
