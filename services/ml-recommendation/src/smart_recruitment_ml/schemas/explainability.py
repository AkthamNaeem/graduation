"""Strict schemas for Phase 11 explainability artifacts."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class ContributionStatistics(StrictModel):
    mean_absolute_contribution: float = Field(ge=0.0)
    mean_signed_contribution: float
    standard_deviation: float = Field(ge=0.0)
    positive_fraction: float = Field(ge=0.0, le=1.0)
    negative_fraction: float = Field(ge=0.0, le=1.0)
    zero_fraction: float = Field(ge=0.0, le=1.0)
    normalized_importance_share: float = Field(ge=0.0, le=1.0)


class GlobalFeatureRecord(StrictModel):
    rank: int = Field(ge=1, le=103)
    feature_index: int = Field(ge=0, le=102)
    feature_name: str
    feature_group: str
    combined: ContributionStatistics
    train: ContributionStatistics
    validation: ContributionStatistics


class TopFeature(StrictModel):
    feature_index: int = Field(ge=0, le=102)
    feature_name: str
    mean_absolute_contribution: float = Field(ge=0.0)


class GroupStatistics(StrictModel):
    feature_count: int = Field(gt=0)
    sum_mean_absolute_contribution: float = Field(ge=0.0)
    mean_feature_absolute_contribution: float = Field(ge=0.0)
    normalized_importance_share: float = Field(ge=0.0, le=1.0)
    mean_signed_contribution: float
    top_features: list[TopFeature] = Field(max_length=5)


class FeatureGroupRecord(StrictModel):
    rank: int = Field(ge=1)
    feature_group: str
    feature_names: list[str]
    combined: GroupStatistics
    train: GroupStatistics
    validation: GroupStatistics


class LocalFactor(StrictModel):
    feature_index: int = Field(ge=0, le=102)
    feature_name: str
    feature_group: str
    feature_value: float
    contribution: float
    direction: Literal["increases_model_score", "decreases_model_score"]


class LocalExplanation(StrictModel):
    pair_id: str
    candidate_id: str
    job_id: str
    source_split: Literal["validation"]
    model_version: Literal["xgbranker-tuned-v1"]
    model_rank: Literal[1, 5, 10, 60]
    model_score: float
    relevance_label: int = Field(ge=0, le=3)
    bias: float
    raw_margin: float
    reconstructed_margin: float
    additivity_error: float = Field(ge=0.0, le=1e-5)
    top_positive_factors: list[LocalFactor] = Field(max_length=5)
    top_negative_factors: list[LocalFactor] = Field(max_length=5)
    positive_contribution_sum: float = Field(ge=0.0)
    negative_contribution_sum: float = Field(le=0.0)
    zero_contribution_count: int = Field(ge=0, le=103)
    explanation_contract_version: Literal["recommendation-explanation-contract-v1"]
    attribution_method_version: Literal["xgboost-exact-tree-shap-v1"]


class ExplainabilityChecks(StrictModel):
    explanation_version: str
    model_version: str
    attribution_method_version: str
    input_contract: dict[str, Any]
    contribution_contract: dict[str, Any]
    additivity: dict[str, Any]
    bias: dict[str, Any]
    importance_normalization: dict[str, Any]
    stability: dict[str, Any]
    local_explanations: dict[str, Any]
    frozen_state: dict[str, bool]
    test_non_usage: dict[str, bool]
