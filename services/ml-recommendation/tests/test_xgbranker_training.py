"""Fixed configuration, fitting, determinism, and round-trip contracts."""

from __future__ import annotations

import inspect
from pathlib import Path
from typing import TYPE_CHECKING

import numpy as np
import pytest

from smart_recruitment_ml.training.dataset import RankingDataset, load_ranking_dataset
from smart_recruitment_ml.training.trainer import EXPECTED_HASHES, _prediction_records
from smart_recruitment_ml.training.xgbranker import (
    FIXED_HYPERPARAMETERS,
    create_ranker,
    fit_initial_ranker,
)

if TYPE_CHECKING:
    from xgboost import XGBRanker

SERVICE_ROOT = Path(__file__).resolve().parents[1]
SPLITS = SERVICE_ROOT / "data/splits/v1"
EXPECTED_CONFIG = {
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
    "random_state": 20260724,
    "n_jobs": 1,
    "verbosity": 0,
    "validate_parameters": True,
    "lambdarank_pair_method": "topk",
    "lambdarank_num_pair_per_sample": 10,
    "ndcg_exp_gain": True,
}


@pytest.fixture(scope="module")
def datasets() -> tuple[RankingDataset, RankingDataset]:
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


@pytest.fixture(scope="module")
def trained(
    datasets: tuple[RankingDataset, RankingDataset],
) -> XGBRanker:
    return fit_initial_ranker(*datasets)


def test_configuration_is_exact_fixed_cpu_single_thread() -> None:
    assert FIXED_HYPERPARAMETERS == EXPECTED_CONFIG
    assert FIXED_HYPERPARAMETERS["device"] == "cpu"
    assert FIXED_HYPERPARAMETERS["n_jobs"] == 1
    assert FIXED_HYPERPARAMETERS["random_state"] == 20260724
    assert "early_stopping_rounds" not in FIXED_HYPERPARAMETERS
    signature = inspect.signature(fit_initial_ranker)
    assert set(signature.parameters) == {"train", "validation"}


def test_model_fits_full_history_and_preserves_features(trained: XGBRanker) -> None:
    assert trained.get_booster().num_boosted_rounds() == 300
    assert trained.get_booster().num_features() == 103
    history = trained.evals_result()
    assert set(history) == {"validation_0", "validation_1"}
    assert set(history["validation_0"]) == {"ndcg@5", "ndcg@10"}
    assert all(len(values) == 300 for split in history.values() for values in split.values())
    with pytest.raises(AttributeError):
        _ = trained.best_iteration


def test_predictions_are_finite_variable_complete_and_repeatable(
    datasets: tuple[RankingDataset, RankingDataset],
    trained: XGBRanker,
) -> None:
    for dataset in datasets:
        first = np.asarray(trained.predict(dataset.X), dtype=np.float32)
        second = np.asarray(trained.predict(dataset.X), dtype=np.float32)
        assert np.array_equal(first, second)
        assert np.all(np.isfinite(first))
        assert np.var(first, dtype=np.float64) > 0.0
        assert np.unique(first).size > 1
        records = _prediction_records(dataset, first)
        assert len(records) == dataset.record_count
        by_candidate: dict[str, set[int]] = {}
        for record in records:
            by_candidate.setdefault(record.candidate_id, set()).add(record.rank)
        assert all(ranks == set(range(1, 61)) for ranks in by_candidate.values())


def test_same_seed_produces_identical_model_and_predictions(
    datasets: tuple[RankingDataset, RankingDataset],
    trained: XGBRanker,
    tmp_path: Path,
) -> None:
    repeated = fit_initial_ranker(*datasets)
    first_path = tmp_path / "first.json"
    second_path = tmp_path / "second.json"
    trained.save_model(first_path)
    repeated.save_model(second_path)
    assert first_path.read_bytes() == second_path.read_bytes()
    for dataset in datasets:
        assert np.array_equal(trained.predict(dataset.X), repeated.predict(dataset.X))


def test_model_save_load_round_trip_is_exact(
    datasets: tuple[RankingDataset, RankingDataset],
    trained: XGBRanker,
    tmp_path: Path,
) -> None:
    model_path = tmp_path / "model.json"
    trained.save_model(model_path)
    assert model_path.stat().st_size > 0
    loaded = create_ranker()
    loaded.load_model(model_path)
    expected_ranks: dict[str, int] = {}
    actual_ranks: dict[str, int] = {}
    maximum_error = 0.0
    for dataset in datasets:
        expected = np.asarray(trained.predict(dataset.X), dtype=np.float32)
        actual = np.asarray(loaded.predict(dataset.X), dtype=np.float32)
        maximum_error = max(
            maximum_error,
            float(np.max(np.abs(expected.astype(np.float64) - actual.astype(np.float64)))),
        )
        expected_ranks.update(
            {record.pair_id: record.rank for record in _prediction_records(dataset, expected)}
        )
        actual_ranks.update(
            {record.pair_id: record.rank for record in _prediction_records(dataset, actual)}
        )
    assert maximum_error <= 1e-12
    assert expected_ranks == actual_ranks
    assert loaded.get_booster().num_features() == 103


@pytest.mark.parametrize(
    "scores",
    [
        np.zeros(60, dtype=np.float32),
        np.full(60, np.nan, dtype=np.float32),
    ],
)
def test_prediction_sanity_gate_rejects_invalid_scores(
    scores: np.ndarray,
    datasets: tuple[RankingDataset, RankingDataset],
) -> None:
    train, _validation = datasets
    one_group = RankingDataset(
        split="train",
        pair_ids=train.pair_ids[:60],
        candidate_ids=train.candidate_ids[:60],
        job_ids=train.job_ids[:60],
        X=train.X[:60],
        y=train.y[:60],
        qid=train.qid[:60],
        group_sizes=(60,),
    )
    with pytest.raises(ValueError, match=r"non-finite|non-zero variance"):
        _prediction_records(one_group, scores)
