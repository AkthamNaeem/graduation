"""Locked Phase 9 search-space and combined-data contracts."""

from __future__ import annotations

import inspect
from pathlib import Path

import numpy as np
import pytest

from smart_recruitment_ml.training.dataset import load_ranking_dataset
from smart_recruitment_ml.training.trainer import EXPECTED_HASHES
from smart_recruitment_ml.training.xgbranker import FIXED_HYPERPARAMETERS
from smart_recruitment_ml.tuning.search_space import (
    COMMON_HYPERPARAMETERS,
    search_space,
    validate_search_space,
)
from smart_recruitment_ml.tuning.tuner import _fit_ranker, combine_datasets

SERVICE_ROOT = Path(__file__).resolve().parents[1]
SPLITS = SERVICE_ROOT / "data/splits/v1"


@pytest.fixture(scope="module")
def datasets():
    return (
        load_ranking_dataset(
            SPLITS / "train.jsonl",
            split="train",
            expected_sha256=EXPECTED_HASHES["train"],
            expected_records=7560,
            expected_candidates=126,
        ),
        load_ranking_dataset(
            SPLITS / "validation.jsonl",
            split="validation",
            expected_sha256=EXPECTED_HASHES["validation"],
            expected_records=1620,
            expected_candidates=27,
        ),
    )


def test_exact_ordered_eight_configuration_space() -> None:
    validate_search_space()
    configs = search_space()
    assert len(configs) == 8
    assert [config["config_id"] for config in configs] == [f"T{index:02d}" for index in range(8)]
    assert len({config["config_id"] for config in configs}) == 8
    assert configs[0]["hyperparameters"] == FIXED_HYPERPARAMETERS
    assert all(
        set(config["hyperparameters"])
        == set(COMMON_HYPERPARAMETERS)
        | {
            "n_estimators",
            "learning_rate",
            "max_depth",
            "min_child_weight",
            "gamma",
            "subsample",
            "colsample_bytree",
            "reg_lambda",
        }
        for config in configs
    )
    assert all(
        config["hyperparameters"]["device"] == "cpu"
        and config["hyperparameters"]["n_jobs"] == 1
        and config["hyperparameters"]["random_state"] == 20260724
        and "early_stopping_rounds" not in config["hyperparameters"]
        for config in configs
    )


def test_search_space_returns_defensive_copies() -> None:
    first = search_space()
    first[0]["hyperparameters"]["max_depth"] = 99
    assert search_space()[0]["hyperparameters"]["max_depth"] == 4


def test_trial_fit_contract_has_no_validation_or_eval_set() -> None:
    assert set(inspect.signature(_fit_ranker).parameters) == {"hyperparameters", "X", "y", "qid"}
    source = inspect.getsource(_fit_ranker)
    assert "eval_set" not in source
    assert "early_stopping" not in source
    assert "callbacks" not in source


def test_combined_dataset_shape_groups_qid_and_identity(datasets) -> None:
    combined = combine_datasets(*datasets)
    assert combined.X.shape == (9180, 103)
    assert combined.y.shape == (9180,)
    assert combined.candidate_count == 153
    assert combined.record_count == 9180
    assert set(combined.group_sizes) == {60}
    assert np.array_equal(np.unique(combined.qid), np.arange(153))
    assert np.all(np.diff(combined.qid) >= 0)
    assert len(set(combined.pair_ids)) == 9180
    assert set(combined.source_splits) == {"train", "validation"}
    assert tuple(
        zip(combined.candidate_ids, combined.job_ids, combined.pair_ids, strict=True)
    ) == tuple(
        sorted(zip(combined.candidate_ids, combined.job_ids, combined.pair_ids, strict=True))
    )
