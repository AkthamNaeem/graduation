"""Strict contracts for Phase 7 baseline evaluation artifacts."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class RankedScore(StrictModel):
    score: float = Field(ge=0.0, le=100.0)
    rank: int = Field(ge=1)


class MatchingV2Components(StrictModel):
    required_skills: float = Field(ge=0.0, le=45.0)
    nice_to_have_skills: float = Field(ge=0.0, le=10.0)
    experience: float = Field(ge=0.0, le=20.0)
    education: float = Field(ge=0.0, le=10.0)
    text_similarity: float = Field(ge=0.0, le=15.0)
    cosine_similarity: float = Field(ge=0.0, le=1.0)


class MatchingV2Prediction(StrictModel):
    score: float = Field(ge=0.0, le=100.0)
    rank: int = Field(ge=1)
    matching_score_version: Literal["2.0"] = "2.0"
    components: MatchingV2Components


class MatchingV2Parity(StrictModel):
    absolute_score_error: float = Field(ge=0.0)
    rank_match: bool


class BaselinePredictionRecord(StrictModel):
    pair_id: str
    candidate_id: str
    job_id: str
    relevance_label: int = Field(ge=0, le=3)
    skills_baseline: RankedScore
    laravel_matching_v2: MatchingV2Prediction
    python_matching_v2_parity: MatchingV2Prediction
    parity: MatchingV2Parity


class MetricSummary(StrictModel):
    macro_mean: float
    median: float
    minimum: float
    maximum: float
    standard_deviation: float = Field(ge=0.0)
    group_count: int = Field(ge=0)


class BaselineMetrics(StrictModel):
    ndcg_at_5: MetricSummary = Field(serialization_alias="NDCG@5")
    ndcg_at_10: MetricSummary = Field(serialization_alias="NDCG@10")
    precision_at_5: MetricSummary = Field(serialization_alias="Precision@5")
    recall_at_5: MetricSummary = Field(serialization_alias="Recall@5")
    mrr: MetricSummary = Field(serialization_alias="MRR")
    hit_rate_at_5: MetricSummary = Field(serialization_alias="HitRate@5")


class SplitMetrics(StrictModel):
    candidate_count: int = Field(ge=0)
    record_count: int = Field(ge=0)
    skills_weighted_v1: BaselineMetrics
    laravel_matching_2_0: BaselineMetrics = Field(serialization_alias="laravel_matching_2.0")
    python_matching_v2_parity: BaselineMetrics


class MetricsArtifact(StrictModel):
    baseline_evaluation_version: Literal["job-rec-baselines-v1"]
    ranking_metrics_version: Literal["ranking-metrics-v1"]
    relevant_label_threshold: Literal[2]
    gain_definition: Literal["2^relevance_label - 1"]
    aggregation: Literal["candidate_macro"]
    splits: dict[Literal["train", "validation"], SplitMetrics]


class ParitySplitSummary(StrictModel):
    pair_count: int = Field(ge=0)
    missing_count: int = Field(ge=0)
    extra_count: int = Field(ge=0)
    score_max_absolute_error: float = Field(ge=0.0)
    score_mean_absolute_error: float = Field(ge=0.0)
    score_exact_match_count: int = Field(ge=0)
    score_tolerance_match_count: int = Field(ge=0)
    component_mismatch_counts: dict[str, int]
    rank_match_count: int = Field(ge=0)
    rank_match_rate: float = Field(ge=0.0, le=1.0)


class ParityArtifact(StrictModel):
    matching_adapter_version: Literal["synthetic-to-laravel-matching-v1"]
    laravel_matching_version: Literal["2.0"]
    python_parity_version: Literal["matching-v2-parity-v1"]
    train: ParitySplitSummary
    validation: ParitySplitSummary
    tolerance: float = Field(gt=0.0)
    parity_passed: bool
    database_query_count: int = Field(ge=0)
    database_write_count: int = Field(ge=0)


class SourceFileMetadata(StrictModel):
    path: str
    record_count: int = Field(ge=0)
    size_bytes: int = Field(ge=0)
    sha256: str
    usage: str
    records_parsed: bool


class ProductionSourceMetadata(StrictModel):
    path: str
    size_bytes: int = Field(ge=0)
    sha256: str
    git_blob: str


class OutputFileMetadata(StrictModel):
    path: str
    record_count: int = Field(ge=0)
    sha256: str
    size_bytes: int = Field(ge=0)


class BaselineManifest(StrictModel):
    baseline_evaluation_version: Literal["job-rec-baselines-v1"]
    baseline_evaluator_version: Literal["0.1.0"]
    skills_baseline_version: Literal["skills-weighted-v1"]
    laravel_matching_version: Literal["2.0"]
    python_parity_version: Literal["matching-v2-parity-v1"]
    matching_adapter_version: Literal["synthetic-to-laravel-matching-v1"]
    ranking_metrics_version: Literal["ranking-metrics-v1"]
    source_dataset_version: Literal["synthetic-job-rec-1.0.0"]
    source_dataset_schema_version: Literal["synthetic-job-rec-schema-v1"]
    feature_schema_version: Literal["job-rec-features-v1"]
    feature_pipeline_version: Literal["0.1.0"]
    split_version: Literal["candidate-group-split-v1"]
    source_revision: str
    architecture_sha256: str
    evaluation_release_date: Literal["2026-07-24"]
    deterministic: Literal[True]
    test_evaluated: Literal[False]
    source_files: list[SourceFileMetadata]
    production_matching_sources: list[ProductionSourceMetadata]
    evaluation_splits: dict[str, dict[str, int]]
    metric_definitions: dict[str, str]
    relevance_threshold: Literal[2]
    adapter_policy: list[str]
    parity_policy: dict[str, float | str | bool]
    output_files: list[OutputFileMetadata]
    intended_use: list[str]
    limitations: list[str]
    reproducibility_command: str


class AdaptedSkillRequirement(StrictModel):
    skill_id: int = Field(ge=1)
    requirement_type: Literal["required", "nice_to_have"]
    weight: int = Field(ge=1, le=5)


class AdaptedCandidate(StrictModel):
    source_id: str
    headline: str
    summary: Literal[""] = ""
    skill_ids: list[int]
    experience_title: str
    experience_start: Literal["2000-01-01"] = "2000-01-01"
    experience_end: str
    experience_duration_days: int = Field(ge=0)
    education_degree: str
    education_field: str


class AdaptedJob(StrictModel):
    source_id: str
    title: str
    department: str
    description: str
    responsibilities: str
    requirements: str
    skills: list[AdaptedSkillRequirement]
    experience_level: str
    education_level: str
    published_at: Literal["2026-01-01T00:00:00Z"] = "2026-01-01T00:00:00Z"


class AdaptedDataset(StrictModel):
    adapter_version: Literal["synthetic-to-laravel-matching-v1"]
    skill_registry: dict[str, int]
    candidates: dict[str, AdaptedCandidate]
    jobs: dict[str, AdaptedJob]
