"""FastAPI application factory and one-time inference Bundle lifecycle."""

from __future__ import annotations

from contextlib import asynccontextmanager
from typing import TYPE_CHECKING
from uuid import UUID

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from smart_recruitment_ml.api.health import router as health_router
from smart_recruitment_ml.api.model import router as model_router
from smart_recruitment_ml.api.recommendations import router as recommendations_router
from smart_recruitment_ml.bundle.loader import load_bundle
from smart_recruitment_ml.core.config import Settings, get_settings
from smart_recruitment_ml.core.errors import ERROR_MESSAGES, ServiceError
from smart_recruitment_ml.core.inference import RuntimeState, unavailable_state
from smart_recruitment_ml.schemas.errors import ErrorBody, ErrorEnvelope

if TYPE_CHECKING:
    from collections.abc import AsyncIterator


def _error_response(
    *,
    code: str,
    status_code: int,
    request_id: UUID | None = None,
    details: dict[str, object] | None = None,
) -> JSONResponse:
    envelope = ErrorEnvelope(
        request_id=request_id,
        error=ErrorBody(
            code=code,
            message=ERROR_MESSAGES[code],
            details=details or {},
        ),
    )
    return JSONResponse(
        status_code=status_code,
        content=envelope.model_dump(mode="json"),
    )


def _safe_request_id(body: object) -> UUID | None:
    if not isinstance(body, dict):
        return None
    value = body.get("request_id")
    if not isinstance(value, str):
        return None
    try:
        return UUID(value)
    except ValueError:
        return None


async def _service_error_handler(_request: Request, error: ServiceError) -> JSONResponse:
    return _error_response(
        code=error.code,
        status_code=error.status_code,
        request_id=error.request_id,
        details=error.details,
    )


async def _validation_error_handler(
    _request: Request,
    error: RequestValidationError,
) -> JSONResponse:
    errors = error.errors()
    error_types = {str(item.get("type")) for item in errors}
    if "sensitive_field_not_allowed" in error_types:
        code = "SENSITIVE_FIELD_NOT_ALLOWED"
    elif "feature_schema_version_unsupported" in error_types:
        code = "FEATURE_SCHEMA_VERSION_UNSUPPORTED"
    elif "duplicate_job_id" in error_types:
        code = "DUPLICATE_JOB_ID"
    elif any(
        item.get("type") == "too_long" and tuple(item.get("loc", ())) == ("body", "jobs")
        for item in errors
    ):
        code = "JOB_LIMIT_EXCEEDED"
    else:
        code = "REQUEST_VALIDATION_FAILED"
    return _error_response(
        code=code,
        status_code=422,
        request_id=_safe_request_id(error.body),
        details={"error_count": len(errors)},
    )


async def _unhandled_error_handler(_request: Request, _error: Exception) -> JSONResponse:
    return _error_response(code="INFERENCE_CONTRACT_FAILED", status_code=500)


def create_app(settings: Settings | None = None) -> FastAPI:
    """Create the service and load its immutable Bundle once in lifespan startup."""
    resolved_settings = settings or get_settings()
    docs_enabled = resolved_settings.docs_enabled

    @asynccontextmanager
    async def lifespan(application: FastAPI) -> AsyncIterator[None]:
        bundle = None
        try:
            bundle = load_bundle(resolved_settings.bundle_dir)
        except (OSError, ValueError):
            runtime = unavailable_state("MODEL_BUNDLE_NOT_READY")
        else:
            runtime = RuntimeState(
                bundle=bundle,
                ready=resolved_settings.service_token is not None,
                not_ready_code=(
                    None
                    if resolved_settings.service_token is not None
                    else "SERVICE_TOKEN_NOT_CONFIGURED"
                ),
                load_count=1,
            )
        if resolved_settings.service_token is None:
            runtime = RuntimeState(
                bundle=bundle,
                ready=False,
                not_ready_code="SERVICE_TOKEN_NOT_CONFIGURED",
                load_count=int(bundle is not None),
            )
        application.state.runtime_state = runtime
        try:
            yield
        finally:
            bundle = None
            application.state.runtime_state = unavailable_state("MODEL_BUNDLE_NOT_READY")

    application = FastAPI(
        title=resolved_settings.service_name,
        version=resolved_settings.service_version,
        docs_url="/docs" if docs_enabled else None,
        redoc_url="/redoc" if docs_enabled else None,
        openapi_url="/openapi.json" if docs_enabled else None,
        lifespan=lifespan,
    )
    application.state.settings = resolved_settings
    application.state.runtime_state = unavailable_state("MODEL_BUNDLE_NOT_READY")
    application.add_exception_handler(
        ServiceError,
        _service_error_handler,  # type: ignore[arg-type]
    )
    application.add_exception_handler(
        RequestValidationError,
        _validation_error_handler,  # type: ignore[arg-type]
    )
    application.add_exception_handler(Exception, _unhandled_error_handler)
    application.include_router(health_router)
    application.include_router(model_router)
    application.include_router(recommendations_router)
    return application


app = create_app()
