"""Response schema for frozen model metadata."""

from pydantic import BaseModel, ConfigDict, Field


class ModelMetadataResponse(BaseModel):
    """Safe metadata exposed to the future Laravel client."""

    model_config = ConfigDict(extra="forbid")

    api_contract_version: str
    bundle_version: str
    model_version: str
    model_format: str
    model_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    dataset_version: str
    feature_schema_version: str
    feature_schema_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    feature_count: int
    model_source_revision: str
    score_transform_version: str
    explanation_contract_version: str
    reason_code_mapping_version: str
    ready: bool
