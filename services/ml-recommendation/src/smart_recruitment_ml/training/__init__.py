"""Deterministic initial XGBRanker training for Phase 8."""

from typing import Final, Literal

MODEL_VERSION: Final[Literal["xgbranker-initial-v1"]] = "xgbranker-initial-v1"
TRAINING_PIPELINE_VERSION: Final[Literal["0.1.0"]] = "0.1.0"
TRAINING_CONFIG_VERSION: Final[Literal["xgbranker-fixed-config-v1"]] = "xgbranker-fixed-config-v1"
MODEL_FORMAT: Final[Literal["xgboost-json-v1"]] = "xgboost-json-v1"
TRAINING_SEED: Final[Literal[20260724]] = 20260724

__all__ = [
    "MODEL_FORMAT",
    "MODEL_VERSION",
    "TRAINING_CONFIG_VERSION",
    "TRAINING_PIPELINE_VERSION",
    "TRAINING_SEED",
]
