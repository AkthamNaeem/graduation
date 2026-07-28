"""Strict Phase 8 training and model artifact contracts."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class TrainingPrediction(StrictModel):
    pair_id: str
    candidate_id: str
    job_id: str
    relevance_label: int = Field(ge=0, le=3)
    prediction_score: float
    rank: int = Field(ge=1, le=60)
    model_version: Literal["xgbranker-initial-v1"]
    feature_schema_version: Literal["job-rec-features-v1"]


class PredictionStatistics(StrictModel):
    count: int = Field(ge=0)
    minimum: float
    maximum: float
    mean: float
    standard_deviation: float = Field(ge=0.0)
    finite_count: int = Field(ge=0)
    unique_value_count: int = Field(ge=0)


class RoundTripVerification(StrictModel):
    max_absolute_error: float = Field(ge=0.0, le=1e-12)
    rank_agreement: float = Field(ge=0.0, le=1.0)
    feature_count_agreement: bool


class TrainingHistoryEntry(StrictModel):
    round: int = Field(ge=1, le=300)
    train_ndcg_at_5: float
    train_ndcg_at_10: float
    validation_ndcg_at_5: float
    validation_ndcg_at_10: float


class ModelMetadata(StrictModel):
    model_version: Literal["xgbranker-initial-v1"]
    training_pipeline_version: Literal["0.1.0"]
    training_config_version: Literal["xgbranker-fixed-config-v1"]
    model_format: Literal["xgboost-json-v1"]
    objective: Literal["rank:ndcg"]
    hyperparameters: dict[str, Any]
    training_seed: Literal[20260724]
    deterministic: Literal[True]
    device: Literal["cpu"]
    thread_count: Literal[1]
    xgboost_version: Literal["3.3.0"]
    numpy_version: Literal["2.5.1"]
    scipy_version: Literal["1.18.0"]
    python_version: str
    feature_schema_version: Literal["job-rec-features-v1"]
    feature_schema_sha256: str
    feature_count: Literal[103]
    feature_names: list[str]
    split_version: Literal["candidate-group-split-v1"]
    train_candidate_count: Literal[126]
    train_record_count: Literal[7560]
    validation_candidate_count: Literal[27]
    validation_record_count: Literal[1620]
    source_revision: str
    architecture_sha256: str
    training_release_date: Literal["2026-07-24"]
    model_file: Literal["model.json"]
    model_sha256: str
    model_size_bytes: int = Field(gt=0)
    early_stopping_used: Literal[False]
    hyperparameter_tuning_used: Literal[False]
    test_evaluated: Literal[False]
    test_records_parsed: Literal[False]
    round_trip_max_absolute_error: float = Field(ge=0.0, le=1e-12)
    round_trip_rank_agreement: float = Field(ge=1.0, le=1.0)


class SourceArtifact(StrictModel):
    path: str
    record_count: int = Field(ge=0)
    size_bytes: int = Field(ge=0)
    sha256: str
    usage: str
    records_parsed: bool


class OutputArtifact(StrictModel):
    path: str
    record_count: int = Field(ge=0)
    size_bytes: int = Field(ge=0)
    sha256: str


class TrainingManifest(StrictModel):
    model_version: Literal["xgbranker-initial-v1"]
    training_pipeline_version: Literal["0.1.0"]
    training_config_version: Literal["xgbranker-fixed-config-v1"]
    model_format: Literal["xgboost-json-v1"]
    training_seed: Literal[20260724]
    training_release_date: Literal["2026-07-24"]
    deterministic: Literal[True]
    source_revision: str
    architecture_sha256: str
    source_dataset_version: Literal["synthetic-job-rec-1.0.0"]
    feature_schema_version: Literal["job-rec-features-v1"]
    feature_pipeline_version: Literal["0.1.0"]
    split_version: Literal["candidate-group-split-v1"]
    baseline_evaluation_version: Literal["job-rec-baselines-v1"]
    dependencies: dict[str, str]
    source_files: list[SourceArtifact]
    training_contract: dict[str, Any]
    validation_contract: dict[str, Any]
    test_lock_verification: dict[str, Any]
    hyperparameters: dict[str, Any]
    output_files: list[OutputArtifact]
    intended_use: list[str]
    limitations: list[str]
