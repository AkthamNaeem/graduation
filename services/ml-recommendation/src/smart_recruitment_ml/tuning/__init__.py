"""Deterministic bounded XGBRanker tuning for Phase 9."""

from typing import Final

TUNING_RUN_VERSION: Final = "xgbranker-bounded-tuning-v1"
TUNING_PIPELINE_VERSION: Final = "0.1.0"
TUNING_SPACE_VERSION: Final = "xgbranker-bounded-space-v1"
SELECTION_POLICY_VERSION: Final = "validation-ndcg10-v1"
TUNED_MODEL_VERSION: Final = "xgbranker-tuned-v1"
FINAL_TRAINING_CONTRACT: Final = "train-plus-validation-v1"
TUNING_SEED: Final = 20260724
TUNING_RELEASE_DATE: Final = "2026-07-24"
