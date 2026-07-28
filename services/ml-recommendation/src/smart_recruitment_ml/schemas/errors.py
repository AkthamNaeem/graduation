"""Stable internal API error envelope."""

from __future__ import annotations

import uuid  # noqa: TC003 - Pydantic resolves this annotation at runtime.
from typing import Any

from pydantic import BaseModel, ConfigDict


class ErrorBody(BaseModel):
    """Safe machine-readable error details."""

    model_config = ConfigDict(extra="forbid")

    code: str
    message: str
    details: dict[str, Any]


class ErrorEnvelope(BaseModel):
    """Error response shared by validation and runtime failures."""

    model_config = ConfigDict(extra="forbid")

    request_id: uuid.UUID | None
    error: ErrorBody
