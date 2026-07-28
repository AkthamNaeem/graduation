"""Typed professional-fact and artifact contracts for Feature Pipeline v1."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class _StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class CandidateSkillInput(_StrictModel):
    """Candidate skill fact accepted by the shared transformer."""

    name: str | None = None
    proficiency: float | None = Field(default=None, ge=0, le=5)
    years_experience: float | None = Field(default=None, ge=0)


class RequiredSkillInput(_StrictModel):
    """Weighted Job skill requirement accepted by the shared transformer."""

    name: str | None = None
    weight: float | None = Field(default=None, ge=0, le=5)


class CandidateFeatureInput(_StrictModel):
    """Professional Candidate facts; identity and audit facts are forbidden."""

    primary_domain: str | None = None
    adjacent_domains: list[str] = Field(default_factory=list)
    headline: str | None = None
    career_level: str | None = None
    total_experience_years: float | None = Field(default=None, ge=0)
    education_level: str | None = None
    skills: list[CandidateSkillInput] = Field(default_factory=list)
    preferred_work_modes: list[str] = Field(default_factory=list)
    preferred_employment_types: list[str] = Field(default_factory=list)


class JobFeatureInput(_StrictModel):
    """Professional Job facts; identity, company, and outcome facts are forbidden."""

    domain: str | None = None
    title: str | None = None
    department: str | None = None
    description: str | None = None
    responsibilities: list[str] = Field(default_factory=list)
    required_skills: list[RequiredSkillInput] = Field(default_factory=list)
    nice_to_have_skills: list[str] = Field(default_factory=list)
    minimum_experience_years: float | None = Field(default=None, ge=0)
    education_level: str | None = None
    career_level: str | None = None
    work_mode: str | None = None
    employment_type: str | None = None


class FeatureVector(_StrictModel):
    """One immutable, ordered, finite feature vector."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    feature_schema_version: str
    feature_names: tuple[str, ...]
    feature_values: tuple[float, ...]


class FeatureDatasetRecord(_StrictModel):
    """Exported training target plus features, with label kept outside the vector."""

    pair_id: str
    candidate_id: str
    job_id: str
    relevance_label: int = Field(ge=0, le=3)
    feature_schema_version: str
    feature_values: list[float]


class FeatureDefinition(_StrictModel):
    """Auditable semantics for a single ordered feature."""

    name: str
    family: str
    description: str
    type: Literal["float", "indicator"]
    bounds: tuple[float, float]
    missing_semantics: str


class ArtifactFile(_StrictModel):
    """Integrity metadata for an input or output artifact."""

    path: str
    record_count: int = Field(ge=0)
    bytes: int = Field(ge=0)
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class FeatureSchemaArtifact(_StrictModel):
    """Versioned feature schema shared by offline and online transformation."""

    feature_schema_version: str
    feature_pipeline_version: str
    source_dataset_version: str
    source_dataset_schema_version: str
    source_revision: str
    architecture_sha256: str
    feature_release_date: str
    deterministic: Literal[True]
    feature_count: int
    feature_names: list[str]
    feature_definitions: list[FeatureDefinition]
    feature_bounds: dict[str, tuple[float, float]]
    vocabularies: dict[str, list[str]]
    normalization_policy: dict[str, str]
    missing_value_policy: dict[str, str]
    unknown_category_policy: dict[str, str]
    skill_merge_policy: dict[str, str]
    text_token_limits: dict[str, int]
    critical_skill_weight_threshold: float
    experience_cap_years: float
    excluded_input_fields: list[str]
    label_separation_policy: str


class FeatureDatasetManifest(_StrictModel):
    """Versioned manifest for a complete transformed feature Dataset."""

    feature_schema_version: str
    feature_pipeline_version: str
    source_dataset_version: str
    source_dataset_schema_version: str
    source_revision: str
    architecture_sha256: str
    feature_release_date: str
    deterministic: Literal[True]
    candidate_count: int
    job_count: int
    record_count: int
    feature_count: int
    label_distribution: dict[str, int]
    source_files: list[ArtifactFile]
    output_files: list[ArtifactFile]
    feature_schema_sha256: str
    generation_config: dict[str, str]
    excluded_fields: list[str]
    intended_use: list[str]
    limitations: list[str]
