"""The fixed, ordered, eight-configuration Phase 9 search space."""

from __future__ import annotations

from typing import Any, Final

from smart_recruitment_ml.training.xgbranker import FIXED_HYPERPARAMETERS

COMMON_HYPERPARAMETERS: Final[dict[str, Any]] = {
    "objective": "rank:ndcg",
    "eval_metric": ["ndcg@5", "ndcg@10"],
    "max_bin": 256,
    "tree_method": "hist",
    "device": "cpu",
    "random_state": 20260724,
    "n_jobs": 1,
    "verbosity": 0,
    "validate_parameters": True,
    "lambdarank_pair_method": "topk",
    "lambdarank_num_pair_per_sample": 10,
    "ndcg_exp_gain": True,
    "reg_alpha": 0.0,
}

_VARIABLE_CONFIGS: Final[tuple[tuple[str, str, dict[str, Any]], ...]] = (
    (
        "T00",
        "Initial Control",
        {
            "n_estimators": 300,
            "learning_rate": 0.05,
            "max_depth": 4,
            "min_child_weight": 1.0,
            "gamma": 0.0,
            "subsample": 1.0,
            "colsample_bytree": 1.0,
            "reg_lambda": 1.0,
        },
    ),
    (
        "T01",
        "Shallow Regularized",
        {
            "n_estimators": 400,
            "learning_rate": 0.04,
            "max_depth": 3,
            "min_child_weight": 2.0,
            "gamma": 0.0,
            "subsample": 1.0,
            "colsample_bytree": 1.0,
            "reg_lambda": 3.0,
        },
    ),
    (
        "T02",
        "Shallow Subsampled",
        {
            "n_estimators": 350,
            "learning_rate": 0.05,
            "max_depth": 3,
            "min_child_weight": 1.0,
            "gamma": 0.0,
            "subsample": 0.9,
            "colsample_bytree": 0.9,
            "reg_lambda": 1.0,
        },
    ),
    (
        "T03",
        "Medium Regularized",
        {
            "n_estimators": 400,
            "learning_rate": 0.04,
            "max_depth": 4,
            "min_child_weight": 3.0,
            "gamma": 0.05,
            "subsample": 1.0,
            "colsample_bytree": 1.0,
            "reg_lambda": 3.0,
        },
    ),
    (
        "T04",
        "Medium Faster",
        {
            "n_estimators": 220,
            "learning_rate": 0.08,
            "max_depth": 4,
            "min_child_weight": 1.0,
            "gamma": 0.0,
            "subsample": 1.0,
            "colsample_bytree": 1.0,
            "reg_lambda": 1.0,
        },
    ),
    (
        "T05",
        "Deep Conservative",
        {
            "n_estimators": 450,
            "learning_rate": 0.03,
            "max_depth": 5,
            "min_child_weight": 3.0,
            "gamma": 0.10,
            "subsample": 0.9,
            "colsample_bytree": 0.9,
            "reg_lambda": 5.0,
        },
    ),
    (
        "T06",
        "Deep Standard",
        {
            "n_estimators": 300,
            "learning_rate": 0.05,
            "max_depth": 5,
            "min_child_weight": 1.0,
            "gamma": 0.0,
            "subsample": 1.0,
            "colsample_bytree": 1.0,
            "reg_lambda": 1.0,
        },
    ),
    (
        "T07",
        "Strong Regularization",
        {
            "n_estimators": 500,
            "learning_rate": 0.03,
            "max_depth": 4,
            "min_child_weight": 5.0,
            "gamma": 0.15,
            "subsample": 0.9,
            "colsample_bytree": 0.9,
            "reg_lambda": 8.0,
        },
    ),
)


def search_space() -> tuple[dict[str, Any], ...]:
    """Return fresh dictionaries so callers cannot mutate the locked constants."""
    return tuple(
        {
            "config_id": config_id,
            "name": name,
            "hyperparameters": {**COMMON_HYPERPARAMETERS, **variables},
        }
        for config_id, name, variables in _VARIABLE_CONFIGS
    )


def validate_search_space() -> None:
    """Fail closed if the source-level locked search contract ever drifts."""
    configs = search_space()
    ids = [str(config["config_id"]) for config in configs]
    if len(configs) != 8 or ids != [f"T{index:02d}" for index in range(8)]:
        raise ValueError("The bounded search space must contain ordered T00..T07 only.")
    if len(set(ids)) != 8:
        raise ValueError("Tuning configuration IDs must be unique.")
    if configs[0]["hyperparameters"] != FIXED_HYPERPARAMETERS:
        raise ValueError("T00 must exactly reproduce the Phase 8 fixed configuration.")
    for config in configs:
        parameters = config["hyperparameters"]
        if parameters["device"] != "cpu" or parameters["n_jobs"] != 1:
            raise ValueError("Every tuning configuration must use one CPU thread.")
        if ("early_" + "stopping_rounds") in parameters:
            raise ValueError("Early stopping is prohibited.")
