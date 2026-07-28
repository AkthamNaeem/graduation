"""Deterministic global, group, stability, and local explanation aggregation."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
import pytest

from smart_recruitment_ml.explainability.aggregation import (
    aggregate_global_importance,
    aggregate_group_importance,
    stability_checks,
)
from smart_recruitment_ml.explainability.engine import (
    CombinedDataset,
    ContributionResult,
    load_feature_schema,
)
from smart_recruitment_ml.explainability.feature_groups import (
    EXPECTED_GROUP_COUNTS,
    build_feature_group_mapping,
)
from smart_recruitment_ml.explainability.selector import (
    SELECTED_RANKS,
    FrozenPrediction,
    build_local_explanations,
    select_frozen_predictions,
)
from smart_recruitment_ml.training.dataset import RankingDataset
from smart_recruitment_ml.tuning import tuner as tuner_module

SERVICE_ROOT = Path(__file__).resolve().parents[1]
SCHEMA = SERVICE_ROOT / "data/features/v1/feature_schema.json"
PREDICTIONS = SERVICE_ROOT / "data/models/tuned/v1/final_train_validation_predictions.jsonl"


@pytest.fixture(scope="module")
def schema_contract() -> tuple[list[str], dict[str, str]]:
    names, definitions, _ = load_feature_schema(SCHEMA)
    return names, build_feature_group_mapping(names, definitions)


def _contributions() -> np.ndarray:
    values = np.zeros((8, 104), dtype=np.float32)
    for row in range(8):
        for feature in range(103):
            values[row, feature] = ((feature % 7) - 3) * (row + 1) / 100
    values[:, 103] = 0.25
    return values


def test_feature_mapping_is_complete_unique_and_schema_driven(
    schema_contract: tuple[list[str], dict[str, str]],
) -> None:
    names, mapping = schema_contract
    assert list(mapping) == names
    assert len(mapping) == len(set(mapping)) == 103
    assert {
        group: list(mapping.values()).count(group) for group in sorted(set(mapping.values()))
    } == EXPECTED_GROUP_COUNTS


def test_feature_mapping_rejects_missing_and_duplicate_contracts() -> None:
    with pytest.raises(ValueError, match="exactly 103"):
        build_feature_group_mapping(["one"], [{"name": "one", "family": "x"}])


@pytest.mark.parametrize(
    ("names", "definitions", "message"),
    [
        (
            [f"feature_{index}" for index in range(103)],
            [{"name": f"wrong_{index}", "family": "required_skills"} for index in range(103)],
            "schema order",
        ),
        (
            [f"feature_{index}" for index in range(103)],
            [{"name": f"feature_{index}", "family": ""} for index in range(103)],
            "Missing frozen feature family",
        ),
        (
            ["duplicate"] * 103,
            [{"name": "duplicate", "family": "required_skills"} for _ in range(103)],
            "Duplicate feature mapping",
        ),
        (
            [f"feature_{index}" for index in range(103)],
            [{"name": f"feature_{index}", "family": "required_skills"} for index in range(103)],
            "Unexpected frozen feature groups",
        ),
    ],
)
def test_feature_mapping_rejects_malformed_schema_metadata(
    names: list[str],
    definitions: list[dict[str, str]],
    message: str,
) -> None:
    with pytest.raises(ValueError, match=message):
        build_feature_group_mapping(names, definitions)


def test_global_importance_has_103_features_and_normalized_splits(
    schema_contract: tuple[list[str], dict[str, str]],
) -> None:
    names, mapping = schema_contract
    features = aggregate_global_importance(
        _contributions(),
        train_count=5,
        feature_names=names,
        feature_groups=mapping,
    )
    assert len(features) == 103
    assert {item.feature_index for item in features} == set(range(103))
    assert [item.rank for item in features] == list(range(1, 104))
    for split in ("combined", "train", "validation"):
        assert sum(getattr(item, split).normalized_importance_share for item in features) == (
            pytest.approx(1.0)
        )
        for item in features:
            stats = getattr(item, split)
            assert stats.positive_fraction + stats.negative_fraction + stats.zero_fraction == (
                pytest.approx(1.0)
            )


def test_global_rank_order_and_schema_index_tie_break(
    schema_contract: tuple[list[str], dict[str, str]],
) -> None:
    names, mapping = schema_contract
    features = aggregate_global_importance(
        _contributions(),
        train_count=5,
        feature_names=names,
        feature_groups=mapping,
    )
    keys = [(-item.combined.mean_absolute_contribution, item.feature_index) for item in features]
    assert keys == sorted(keys)


def test_group_importance_covers_every_feature_once(
    schema_contract: tuple[list[str], dict[str, str]],
) -> None:
    names, mapping = schema_contract
    features = aggregate_global_importance(
        _contributions(),
        train_count=5,
        feature_names=names,
        feature_groups=mapping,
    )
    groups = aggregate_group_importance(features)
    assert len(groups) == 10
    flattened = [name for group in groups for name in group.feature_names]
    assert len(flattened) == len(set(flattened)) == 103
    assert set(flattened) == set(names)
    assert sum(group.combined.normalized_importance_share for group in groups) == (
        pytest.approx(1.0)
    )
    assert all(len(group.combined.top_features) <= 5 for group in groups)
    assert [
        (-group.combined.normalized_importance_share, group.feature_group) for group in groups
    ] == sorted(
        (-group.combined.normalized_importance_share, group.feature_group) for group in groups
    )


def test_stability_is_finite_descriptive_and_deterministic(
    schema_contract: tuple[list[str], dict[str, str]],
) -> None:
    names, mapping = schema_contract
    features = aggregate_global_importance(
        _contributions(),
        train_count=5,
        feature_names=names,
        feature_groups=mapping,
    )
    checks = stability_checks(features)
    assert np.isfinite(checks["spearman"])
    assert 0 <= checks["top_10_overlap"] <= 10
    assert 0 <= checks["top_20_overlap"] <= 20
    assert 0 <= checks["top_10_jaccard"] <= 1
    assert 0 <= checks["top_20_jaccard"] <= 1
    assert checks["descriptive_only"] is True
    assert checks["model_changed_based_on_stability"] is False


def test_local_selection_is_validation_origin_and_fixed_rank_only() -> None:
    pair_ids = {
        json.loads(line)["pair_id"] for line in PREDICTIONS.read_text(encoding="utf-8").splitlines()
    }
    selected = select_frozen_predictions(PREDICTIONS, expected_pair_ids=pair_ids)
    assert len(selected) == 108
    assert len({item.candidate_id for item in selected}) == 27
    assert {item.model_rank for item in selected} == set(SELECTED_RANKS)
    assert {item.source_split for item in selected} == {"validation"}
    for candidate_id in {item.candidate_id for item in selected}:
        assert sorted(
            item.model_rank for item in selected if item.candidate_id == candidate_id
        ) == list(SELECTED_RANKS)


def test_local_selection_rejects_missing_frozen_rank(tmp_path: Path) -> None:
    path = tmp_path / "predictions.jsonl"
    records: list[dict[str, object]] = []
    for candidate in range(153):
        for job in range(60):
            rank = job + 1
            if candidate == 126 and job == 0:
                rank = 2
            records.append(
                {
                    "pair_id": f"pair_{candidate:03d}_{job:02d}",
                    "candidate_id": f"candidate_{candidate:03d}",
                    "job_id": f"job_{job:02d}",
                    "source_split": "train" if candidate < 126 else "validation",
                    "model_version": "xgbranker-tuned-v1",
                    "rank": rank,
                    "prediction_score": float(60 - job),
                }
            )
    path.write_text(
        "".join(json.dumps(record) + "\n" for record in records),
        encoding="utf-8",
    )
    with pytest.raises(ValueError, match="Incomplete frozen ranks"):
        select_frozen_predictions(
            path,
            expected_pair_ids={str(record["pair_id"]) for record in records},
        )


def test_local_explanation_rejects_frozen_score_mismatch() -> None:
    pair_ids = tuple(f"pair_{index:03d}" for index in range(108))
    candidate_ids = tuple(f"candidate_{index // 4:02d}" for index in range(108))
    job_ids = tuple(f"job_{index % 4:02d}" for index in range(108))
    dataset = CombinedDataset(
        pair_ids=pair_ids,
        candidate_ids=candidate_ids,
        job_ids=job_ids,
        source_splits=("validation",) * 108,
        X=np.zeros((108, 103), dtype=np.float32),
        y=np.zeros(108, dtype=np.float32),
        train_count=0,
        validation_count=108,
        candidate_count=27,
    )
    contributions = np.zeros((108, 104), dtype=np.float32)
    result = ContributionResult(
        contributions=contributions,
        margins=np.zeros(108, dtype=np.float32),
        scores=np.zeros(108, dtype=np.float32),
        original_shape=(108, 1, 104),
        errors=np.zeros(108, dtype=np.float64),
    )
    ranks = (1, 5, 10, 60)
    selections = [
        FrozenPrediction(
            pair_id=pair_ids[index],
            candidate_id=candidate_ids[index],
            job_id=job_ids[index],
            source_split="validation",
            model_rank=ranks[index % 4],
            model_score=1.0 if index == 0 else 0.0,
        )
        for index in range(108)
    ]
    names = [f"feature_{index}" for index in range(103)]
    with pytest.raises(ValueError, match="score_mismatches=1"):
        build_local_explanations(
            selections=selections,
            dataset=dataset,
            result=result,
            feature_names=names,
            feature_groups={name: "synthetic" for name in names},
        )


def _ranking_fixture(
    *,
    split: str,
    first_candidate: int,
    candidate_count: int,
) -> RankingDataset:
    candidate_ids = tuple(
        f"candidate_{candidate:03d}"
        for candidate in range(first_candidate, first_candidate + candidate_count)
        for _ in range(60)
    )
    job_ids = tuple(f"job_{job:02d}" for _ in range(candidate_count) for job in range(60))
    pair_ids = tuple(
        f"pair_{candidate:03d}_{job:02d}"
        for candidate in range(first_candidate, first_candidate + candidate_count)
        for job in range(60)
    )
    return RankingDataset(
        split=split,  # type: ignore[arg-type]
        pair_ids=pair_ids,
        candidate_ids=candidate_ids,
        job_ids=job_ids,
        X=np.zeros((candidate_count * 60, 103), dtype=np.float32),
        y=np.asarray(
            [job % 4 for _ in range(candidate_count) for job in range(60)],
            dtype=np.float32,
        ),
        qid=np.repeat(np.arange(candidate_count, dtype=np.int32), 60),
        group_sizes=(60,) * candidate_count,
    )


def test_tuning_rank_ties_metrics_and_prediction_contracts() -> None:
    dataset = _ranking_fixture(split="validation", first_candidate=126, candidate_count=1)
    scores = np.asarray([100.0, 100.0, *range(98, 40, -1)], dtype=np.float32)
    ranks = tuner_module._rank_values(
        dataset.candidate_ids,
        dataset.job_ids,
        dataset.pair_ids,
        scores,
    )
    assert ranks[0] == 1
    assert ranks[1] == 2
    assert ranks[2] == 3
    assert sorted(ranks) == list(range(1, 61))

    metrics = tuner_module._metrics(dataset, scores)
    assert set(metrics) == set(tuner_module.METRIC_NAMES)
    predictions = tuner_module._selected_predictions(dataset, scores, "T06")
    assert len(predictions) == 60
    assert {record.rank for record in predictions} == set(range(1, 61))
    assert all(record.config_id == "T06" for record in predictions)


@pytest.mark.parametrize(
    ("scores", "message"),
    [
        (np.zeros(59, dtype=np.float32), "incomplete"),
        (np.full(60, np.nan, dtype=np.float32), "non-finite"),
        (np.ones(60, dtype=np.float32), "non-zero variance"),
    ],
)
def test_tuning_rank_validation_rejects_invalid_scores(
    scores: np.ndarray,
    message: str,
) -> None:
    dataset = _ranking_fixture(split="validation", first_candidate=126, candidate_count=1)
    with pytest.raises(ValueError, match=message):
        tuner_module._rank_values(
            dataset.candidate_ids,
            dataset.job_ids,
            dataset.pair_ids,
            scores,
        )


def test_tuning_combination_and_final_predictions_are_complete() -> None:
    train = _ranking_fixture(split="train", first_candidate=0, candidate_count=126)
    validation = _ranking_fixture(
        split="validation",
        first_candidate=126,
        candidate_count=27,
    )
    combined = tuner_module.combine_datasets(train, validation)
    assert combined.record_count == 9180
    assert combined.candidate_count == 153
    assert set(combined.group_sizes) == {60}
    assert set(combined.source_splits) == {"train", "validation"}

    scores = np.tile(np.arange(60, dtype=np.float32), 153)
    records = tuner_module._final_predictions(combined, scores)
    assert len(records) == 9180
    assert len({record.pair_id for record in records}) == 9180
    assert {record.rank for record in records} == set(range(1, 61))
    assert {record.source_split for record in records} == {"train", "validation"}

    overlapping = _ranking_fixture(split="validation", first_candidate=125, candidate_count=27)
    with pytest.raises(ValueError, match="Candidate overlap"):
        tuner_module.combine_datasets(train, overlapping)


def test_tuning_metric_helpers_and_nested_contract_validation() -> None:
    left = {name: {"macro_mean": 0.75} for name in tuner_module.METRIC_NAMES}
    right = {name: {"macro_mean": 0.25} for name in tuner_module.METRIC_NAMES}
    assert tuner_module._metric_means(left) == {name: 0.75 for name in tuner_module.METRIC_NAMES}
    assert tuner_module._metric_deltas(left, right) == {
        name: 0.5 for name in tuner_module.METRIC_NAMES
    }
    assert (
        tuner_module._maximum_numeric_error(
            {"a": {"b": 1.0}, "label": "same"},
            {"a": {"b": 1.25}, "label": "same"},
        )
        == 0.25
    )
    with pytest.raises(ValueError, match="key mismatch"):
        tuner_module._maximum_numeric_error({"a": 1}, {"b": 1})
    with pytest.raises(ValueError, match="value mismatch"):
        tuner_module._maximum_numeric_error("one", "two")


def test_tuning_serialization_model_card_and_version_gate(tmp_path: Path) -> None:
    payload = {"z": 2, "a": 1}
    path = tmp_path / "payload.json"
    tuner_module._write_text(path, tuner_module._json_content(payload))
    assert tuner_module._read_json(path) == payload
    assert tuner_module._jsonl_content([payload]) == '{"a":1,"z":2}\n'
    path.write_text("[]", encoding="utf-8")
    with pytest.raises(ValueError, match="JSON object expected"):
        tuner_module._read_json(path)

    def metrics(value: float) -> dict[str, dict[str, float]]:
        return {name: {"macro_mean": value} for name in tuner_module.METRIC_NAMES}

    report = tuner_module._model_card(
        selected={
            "config_id": "T06",
            "hyperparameters": {"max_depth": 5},
            "validation_metrics": metrics(0.75),
        },
        baseline_metrics={
            "splits": {
                "validation": {
                    "skills_weighted_v1": metrics(0.25),
                    "laravel_matching_2.0": metrics(0.5),
                }
            }
        },
        initial_metrics=metrics(0.6),
    )
    assert "Selected Trial (T06)" in report
    assert "`max_depth`: `5`" in report
    assert "Locked Test was not parsed" in report

    valid = argparse.Namespace(
        tuning_run_version=tuner_module.TUNING_RUN_VERSION,
        tuned_model_version=tuner_module.TUNED_MODEL_VERSION,
        seed=tuner_module.TUNING_SEED,
        source_revision=tuner_module.SOURCE_REVISION,
        architecture_sha256=tuner_module.ARCHITECTURE_SHA256,
    )
    tuner_module._validate_versions_and_args(valid)
    valid.seed = -1
    with pytest.raises(ValueError, match="version, seed, or provenance"):
        tuner_module._validate_versions_and_args(valid)
