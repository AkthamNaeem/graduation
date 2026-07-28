"""Typed contracts for the versioned candidate-group split artifacts."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

SplitName = Literal["train", "validation", "test"]


class _StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class CandidateSplitAssignment(_StrictModel):
    """One target-independent Candidate group assignment."""

    candidate_id: str
    primary_domain: str
    split: SplitName


class SplitFileMetadata(_StrictModel):
    """Integrity metadata for a generated split artifact."""

    path: str
    record_count: int = Field(ge=0)
    size_bytes: int = Field(ge=0)
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class SourceFileMetadata(_StrictModel):
    """Integrity metadata for one locked source file."""

    path: str
    record_count: int = Field(ge=0)
    size_bytes: int = Field(ge=0)
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class SplitStatistics(_StrictModel):
    """Structural counts for one split."""

    candidate_count: int = Field(ge=0)
    record_count: int = Field(ge=0)
    unique_job_count: int = Field(ge=0)


class LockedTestGuard(_StrictModel):
    """Cryptographic and policy guard for the untouched locked Test split."""

    split_version: str
    split_seed: int
    test_locked: Literal[True]
    group_key: Literal["candidate_id"]
    test_candidate_count: int
    test_record_count: int
    test_candidate_ids_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    test_file_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    source_features_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    feature_schema_sha256: str = Field(pattern=r"^[a-f0-9]{64}$")
    source_revision: str
    created_for_phase: Literal[6]
    allowed_future_use: list[str]
    prohibited_before_phase: Literal[10]


class SplitManifest(_StrictModel):
    """Complete manifest for a deterministic candidate-group split."""

    split_version: str
    split_generator_version: str
    split_seed: int
    split_release_date: str
    deterministic: Literal[True]
    group_key: Literal["candidate_id"]
    train_ratio: float
    validation_ratio: float
    test_ratio: float
    source_dataset_version: str
    source_dataset_schema_version: str
    feature_schema_version: str
    feature_pipeline_version: str
    source_revision: str
    architecture_sha256: str
    source_files: list[SourceFileMetadata]
    candidate_count: int
    record_count: int
    feature_count: int
    split_counts: dict[SplitName, SplitStatistics]
    candidate_domain_distribution: dict[SplitName, dict[str, int]]
    label_distribution: dict[SplitName, dict[str, int]]
    job_coverage: dict[SplitName, int]
    candidate_overlap_counts: dict[str, int]
    pair_overlap_counts: dict[str, int]
    test_locked: Literal[True]
    test_lock_file: SplitFileMetadata
    generation_config: dict[str, str | int | float]
    intended_use: list[str]
    limitations: list[str]
    output_files: list[SplitFileMetadata]
