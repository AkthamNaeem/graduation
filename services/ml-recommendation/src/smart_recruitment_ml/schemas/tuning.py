"""Strict Phase 9 tuning artifact schemas."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class SelectedValidationPrediction(StrictModel):
    pair_id: str
    candidate_id: str
    job_id: str
    relevance_label: int = Field(ge=0, le=3)
    prediction_score: float
    rank: int = Field(ge=1, le=60)
    config_id: str
    tuning_run_version: str
    feature_schema_version: str


class FinalPrediction(StrictModel):
    pair_id: str
    candidate_id: str
    job_id: str
    relevance_label: int = Field(ge=0, le=3)
    prediction_score: float
    rank: int = Field(ge=1, le=60)
    model_version: str
    feature_schema_version: str
    source_split: Literal["train", "validation"]


class TuningTrial(StrictModel):
    config_id: str
    hyperparameters: dict[str, Any]
    train_metrics: dict[str, Any]
    validation_metrics: dict[str, Any]
    train_prediction_statistics: dict[str, Any]
    validation_prediction_statistics: dict[str, Any]
    selected: bool
    selection_rank: int = Field(ge=1, le=8)
    control_trial: bool


class TunedModelMetadata(StrictModel):
    model_version: str
    model_format: str
    tuning_run_version: str
    tuning_pipeline_version: str
    tuning_space_version: str
    selection_policy_version: str
    final_training_contract: str
    selected_config_id: str
    hyperparameters: dict[str, Any]
    training_seed: int
    deterministic: bool
    device: str
    thread_count: int
    python_version: str
    numpy_version: str
    scipy_version: str
    xgboost_version: str
    feature_schema_version: str
    feature_schema_sha256: str
    feature_count: int
    feature_names: list[str]
    split_version: str
    train_candidate_count: int
    validation_candidate_count: int
    final_candidate_count: int
    train_record_count: int
    validation_record_count: int
    final_record_count: int
    model_file: str
    model_sha256: str
    model_size_bytes: int
    source_revision: str
    architecture_sha256: str
    tuning_release_date: str
    control_reproduction_passed: bool
    round_trip_max_absolute_error: float
    round_trip_rank_agreement: float
    early_stopping_used: bool
    cross_validation_used: bool
    test_evaluated: bool
    test_records_parsed: bool
