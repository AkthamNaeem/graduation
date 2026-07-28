"""Safe service exceptions and stable error metadata."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import TYPE_CHECKING, Any, Final

if TYPE_CHECKING:
    from uuid import UUID

ERROR_MESSAGES: Final = {
    "REQUEST_VALIDATION_FAILED": "Request validation failed.",
    "SENSITIVE_FIELD_NOT_ALLOWED": "Sensitive field is not allowed.",
    "FEATURE_SCHEMA_VERSION_UNSUPPORTED": "Feature Schema version is unsupported.",
    "DUPLICATE_JOB_ID": "Job IDs must be unique.",
    "JOB_LIMIT_EXCEEDED": "Job limit exceeded.",
    "SERVICE_AUTHENTICATION_FAILED": "Service authentication failed.",
    "MODEL_BUNDLE_NOT_READY": "Model bundle is not ready.",
    "MODEL_METADATA_UNAVAILABLE": "Model metadata is unavailable.",
    "FEATURE_PIPELINE_FAILED": "Feature Pipeline failed.",
    "INFERENCE_CONTRACT_FAILED": "Inference contract failed.",
}


@dataclass(slots=True)
class ServiceError(Exception):
    """An expected failure that is safe to serialize."""

    code: str
    status_code: int
    request_id: UUID | None = None
    details: dict[str, Any] = field(default_factory=dict)

    @property
    def message(self) -> str:
        return ERROR_MESSAGES[self.code]
