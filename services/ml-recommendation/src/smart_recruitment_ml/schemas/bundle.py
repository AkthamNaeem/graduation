"""Strict schemas for the self-contained inference bundle."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class StrictFrozenModel(BaseModel):
    """Immutable base for loaded artifact contracts."""

    model_config = ConfigDict(extra="forbid", frozen=True)


class BundleArtifact(StrictFrozenModel):
    """Integrity metadata for one bundle-owned file."""

    path: str
    bytes: int = Field(ge=0)
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class TestNonUsage(StrictFrozenModel):
    """Evidence that Phase 12 did not consume the locked test."""

    test_features_read: Literal[False] = False
    test_predictions_read: Literal[False] = False
    test_inference_run: Literal[False] = False
    test_evaluation_rerun: Literal[False] = False


class FrozenState(StrictFrozenModel):
    """Evidence that inference construction did not mutate learned state."""

    training_executed: Literal[False] = False
    tuning_executed: Literal[False] = False
    model_modified: Literal[False] = False
    feature_pipeline_modified: Literal[False] = False


class ScoreTransform(StrictFrozenModel):
    """Validation-derived display-score transform."""

    score_transform_version: Literal["validation-minmax-selected-trial-t06-v1"]
    score_semantics_version: Literal["relevance-indicator-v1"]
    source_artifact: str
    source_artifact_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    source_record_count: Literal[1620]
    source_config_id: Literal["T06"]
    minimum_raw_score: float
    maximum_raw_score: float
    formula: str
    clipping: str
    rounding: str
    is_probability: Literal[False] = False
    is_acceptance_prediction: Literal[False] = False
    is_calibrated_probability: Literal[False] = False
    fit_source: Literal["selected_validation_predictions_only"]
    locked_test_used: Literal[False] = False
    limitations: tuple[str, ...]


class ReasonCodePair(StrictFrozenModel):
    """Positive and negative allowlisted codes for one feature group."""

    feature_group: str
    positive: str
    negative: str


class ReasonCodeMapping(StrictFrozenModel):
    """Complete group-to-safe-code mapping."""

    reason_code_mapping_version: Literal["recommendation-reason-codes-v1"]
    explanation_contract_version: Literal["recommendation-explanation-contract-v1"]
    groups: tuple[ReasonCodePair, ...]


class BundleManifest(StrictFrozenModel):
    """Root integrity and provenance contract for the inference bundle."""

    bundle_schema_version: Literal["inference-bundle-schema-v1"]
    bundle_builder_version: Literal["0.1.0"]
    bundle_version: Literal["job-rec-inference-bundle-v1"]
    bundle_release_date: Literal["2026-07-25"]
    deterministic: Literal[True]
    model_version: Literal["xgbranker-tuned-v1"]
    model_format: Literal["xgboost-json-v1"]
    model_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    model_source_revision: str = Field(pattern=r"^[a-f0-9]{40}$")
    selected_config_id: Literal["T06"]
    dataset_version: Literal["synthetic-job-rec-1.0.0"]
    feature_schema_version: Literal["job-rec-features-v1"]
    feature_schema_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    feature_count: Literal[103]
    explanation_contract_version: Literal["recommendation-explanation-contract-v1"]
    reason_code_mapping_version: Literal["recommendation-reason-codes-v1"]
    score_transform_version: Literal["validation-minmax-selected-trial-t06-v1"]
    architecture_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    artifacts: tuple[BundleArtifact, ...]
    test_non_usage: TestNonUsage
    frozen_state: FrozenState
