"""Strict schemas for Phase 10 Final Test artifacts."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class SystemScore(StrictModel):
    score: float
    rank: int = Field(ge=1, le=60)


class FinalTestPrediction(StrictModel):
    pair_id: str
    candidate_id: str
    job_id: str
    relevance_label: int = Field(ge=0, le=3)
    skills_only: SystemScore
    laravel_matching_2_0: SystemScore
    python_matching_2_0: SystemScore
    initial_xgbranker: SystemScore
    tuned_xgbranker: SystemScore


class EvaluationReceipt(StrictModel):
    evaluation_session_version: Literal["locked-final-test-v1"]
    evaluation_release_date: Literal["2026-07-24"]
    phase: Literal[10]
    one_shot_policy: Literal[True]
    opened_for_phase: Literal[10]
    test_file: str
    test_sha256: str
    test_record_count: Literal[1620]
    test_candidate_count: Literal[27]
    test_opened: Literal[True]
    test_records_parsed: Literal[1620]
    predictions_completed: Literal[True]
    metrics_completed: Literal[True]
    initial_model_version: Literal["xgbranker-initial-v1"]
    initial_model_sha256: str
    tuned_model_version: Literal["xgbranker-tuned-v1"]
    tuned_model_sha256: str
    selected_config_id: Literal["T06"]
    source_revision: str
    architecture_sha256: str
    feature_schema_version: Literal["job-rec-features-v1"]
    feature_schema_sha256: str
    training_executed: Literal[False]
    tuning_executed: Literal[False]
    calibration_executed: Literal[False]
    feature_changes_executed: Literal[False]
    model_modified: Literal[False]
    model_training_after_open: Literal[False]
    test_prediction_run_count: Literal[1]
    parity_evidence: dict[str, Any]
    recovery_execution: Literal[True]
    evaluation_attempt_number: Literal[2]
    prior_attempt_status: Literal["failed_before_artifact_publication"]
    prior_attempt_failure_stage: Literal["system_score_conversion"]
    prior_predictions_artifact_published: Literal[False]
    prior_metrics_published: Literal[False]
    prior_test_results_observed: Literal[False]
    recovery_authorized_by_user: Literal[True]
    model_changed_between_attempts: Literal[False]
    feature_changed_between_attempts: Literal[False]
    hyperparameters_changed_between_attempts: Literal[False]
    selection_changed_between_attempts: Literal[False]
    metrics_contract_changed_between_attempts: Literal[False]
    training_run_between_attempts: Literal[False]
    tuning_run_between_attempts: Literal[False]
