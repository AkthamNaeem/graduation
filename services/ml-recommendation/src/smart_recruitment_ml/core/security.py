"""Shared-secret authentication for internal ML routes."""

from __future__ import annotations

import hmac
from typing import Annotated, cast

from fastapi import Depends, Header, Request, status

from smart_recruitment_ml.core.config import (
    Settings,  # noqa: TC001 - FastAPI resolves dependency annotations at runtime.
)
from smart_recruitment_ml.core.errors import ServiceError


def get_request_settings(request: Request) -> Settings:
    """Return settings owned by this application instance."""
    return cast("Settings", request.app.state.settings)


def require_service_token(
    settings: Annotated[Settings, Depends(get_request_settings)],
    supplied_token: Annotated[str | None, Header(alias="X-ML-Service-Token")] = None,
) -> None:
    """Require the configured token without distinguishing missing from incorrect."""
    configured = settings.service_token
    supplied = supplied_token or ""
    expected = configured or ""
    if not configured or not hmac.compare_digest(
        supplied.encode("utf-8"),
        expected.encode("utf-8"),
    ):
        raise ServiceError(
            code="SERVICE_AUTHENTICATION_FAILED",
            status_code=status.HTTP_401_UNAUTHORIZED,
        )


ServiceAuthentication = Annotated[None, Depends(require_service_token)]
