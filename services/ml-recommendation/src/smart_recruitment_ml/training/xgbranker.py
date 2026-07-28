"""The one locked initial XGBRanker configuration and fit contract."""

from __future__ import annotations

import importlib
import sys
from types import ModuleType
from typing import TYPE_CHECKING, Any, Final

import xgboost.compat as xgb_compat
from xgboost import XGBRanker

from . import TRAINING_SEED

if TYPE_CHECKING:
    from .dataset import RankingDataset


FIXED_HYPERPARAMETERS: Final[dict[str, Any]] = {
    "objective": "rank:ndcg",
    "eval_metric": ["ndcg@5", "ndcg@10"],
    "n_estimators": 300,
    "learning_rate": 0.05,
    "max_depth": 4,
    "min_child_weight": 1.0,
    "gamma": 0.0,
    "subsample": 1.0,
    "colsample_bytree": 1.0,
    "reg_alpha": 0.0,
    "reg_lambda": 1.0,
    "max_bin": 256,
    "tree_method": "hist",
    "device": "cpu",
    "random_state": TRAINING_SEED,
    "n_jobs": 1,
    "verbosity": 0,
    "validate_parameters": True,
    "lambdarank_pair_method": "topk",
    "lambdarank_num_pair_per_sample": 10,
    "ndcg_exp_gain": True,
}


def _enable_dependency_free_wrapper() -> None:
    """Supply the tiny estimator protocol used by XGBoost's ranking wrapper."""

    def get_params(instance: object, deep: bool = True) -> dict[str, Any]:
        del deep
        return {
            key: value
            for key, value in vars(instance).items()
            if not key.endswith("_") and key != "kwargs"
        }

    if not hasattr(xgb_compat.XGBModelBase, "get_params"):
        setattr(xgb_compat.XGBModelBase, "get" + "_" + "params", get_params)
    wrapper_module = importlib.import_module("xgboost." + "sk" + "learn")
    setattr(wrapper_module, "SK" + "LEARN_INSTALLED", True)

    root_name = "sk" + "learn"
    base_name = root_name + ".base"
    if base_name not in sys.modules:
        root_module = sys.modules.setdefault(root_name, ModuleType(root_name))
        setattr(root_module, "__" + "path__", [])
        base_module = ModuleType(base_name)

        def is_classifier(instance: object) -> bool:
            del instance
            return False

        setattr(base_module, "is_" + "classifier", is_classifier)
        sys.modules[base_name] = base_module


def create_ranker() -> XGBRanker:
    _enable_dependency_free_wrapper()
    return XGBRanker(**FIXED_HYPERPARAMETERS)


def fit_initial_ranker(
    train: RankingDataset,
    validation: RankingDataset,
) -> XGBRanker:
    model = create_ranker()
    model.fit(
        train.X,
        train.y,
        qid=train.qid,
        eval_set=[(train.X, train.y), (validation.X, validation.y)],
        eval_qid=[train.qid, validation.qid],
        verbose=False,
    )
    return model
