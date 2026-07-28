"""Generated Tuned Model artifact and Locked Test safety contracts."""

from __future__ import annotations

import json
from collections import defaultdict
from pathlib import Path
from typing import Any

import numpy as np

from smart_recruitment_ml.schemas.tuning import TunedModelMetadata, TuningTrial
from smart_recruitment_ml.training.dataset import LOCKED_TEST_SHA256, sha256_file
from smart_recruitment_ml.training.xgbranker import create_ranker

SERVICE_ROOT = Path(__file__).resolve().parents[1]
MODEL_DIR = SERVICE_ROOT / "data/models/tuned/v1"
ARTIFACT_NAMES = {
    "model.json",
    "model_metadata.json",
    "tuning_trials.jsonl",
    "selection_metrics.json",
    "selected_config.json",
    "selected_validation_predictions.jsonl",
    "final_train_validation_predictions.jsonl",
    "manifest.json",
    "MODEL_CARD.md",
}


def _json(name: str) -> dict[str, Any]:
    return json.loads((MODEL_DIR / name).read_text(encoding="utf-8"))


def _records(name: str) -> list[dict[str, Any]]:
    return [
        json.loads(line) for line in (MODEL_DIR / name).read_text(encoding="utf-8").splitlines()
    ]


def test_exact_artifact_set_metadata_model_and_control() -> None:
    assert {path.name for path in MODEL_DIR.iterdir()} == ARTIFACT_NAMES
    metadata = TunedModelMetadata.model_validate_json(
        (MODEL_DIR / "model_metadata.json").read_text(encoding="utf-8")
    )
    assert metadata.model_version == "xgbranker-tuned-v1"
    assert metadata.final_candidate_count == 153
    assert metadata.final_record_count == 9180
    assert metadata.feature_count == 103
    assert metadata.model_sha256 == sha256_file(MODEL_DIR / metadata.model_file)
    assert metadata.model_size_bytes == (MODEL_DIR / metadata.model_file).stat().st_size
    assert metadata.control_reproduction_passed is True
    assert metadata.round_trip_max_absolute_error <= 1e-12
    assert metadata.round_trip_rank_agreement == 1.0
    assert metadata.early_stopping_used is False
    assert metadata.cross_validation_used is False
    assert metadata.test_evaluated is False
    assert metadata.test_records_parsed is False
    model = create_ranker()
    model.load_model(MODEL_DIR / "model.json")
    assert model.get_booster().num_features() == 103

    control = _json("selection_metrics.json")["control_reproduction"]
    assert control["model_sha256_identical"] is True
    assert control["validation_prediction_max_absolute_error"] <= 1e-12
    assert control["validation_rank_agreement"] == 1.0
    assert control["metric_max_absolute_error"] <= 1e-12
    assert control["passed"] is True


def test_trials_selection_and_validation_only_policy() -> None:
    trials = [TuningTrial.model_validate(record) for record in _records("tuning_trials.jsonl")]
    assert len(trials) == 8
    assert [trial.config_id for trial in trials] == [f"T{index:02d}" for index in range(8)]
    assert len({trial.selection_rank for trial in trials}) == 8
    assert sum(trial.selected for trial in trials) == 1
    assert trials[0].control_trial is True
    selected = _json("selected_config.json")
    assert selected["trial_count"] == 8
    assert selected["test_evaluated"] is False
    assert (
        next(trial.config_id for trial in trials if trial.selected)
        == selected["selected_config_id"]
    )
    selection_metrics = _json("selection_metrics.json")
    assert "test" not in selection_metrics
    assert selection_metrics["selected_trial"]["selection_rank"] == 1


def test_prediction_counts_order_finiteness_variance_uniqueness_and_ranks() -> None:
    for name, expected_count, expected_groups in (
        ("selected_validation_predictions.jsonl", 1620, 27),
        ("final_train_validation_predictions.jsonl", 9180, 153),
    ):
        records = _records(name)
        assert len(records) == expected_count
        assert len({record["pair_id"] for record in records}) == expected_count
        assert records == sorted(
            records,
            key=lambda record: (
                record["candidate_id"],
                record["job_id"],
                record["pair_id"],
            ),
        )
        scores = np.asarray([record["prediction_score"] for record in records])
        assert np.all(np.isfinite(scores))
        assert np.var(scores) > 0.0
        by_candidate: dict[str, list[dict[str, Any]]] = defaultdict(list)
        for record in records:
            by_candidate[record["candidate_id"]].append(record)
        assert len(by_candidate) == expected_groups
        for group in by_candidate.values():
            assert len(group) == 60
            assert {record["rank"] for record in group} == set(range(1, 61))
            ranked = sorted(
                group,
                key=lambda record: (
                    -record["prediction_score"],
                    record["job_id"],
                    record["pair_id"],
                ),
            )
            assert [record["rank"] for record in ranked] == list(range(1, 61))


def test_manifest_hashes_lock_flags_and_model_card() -> None:
    manifest = _json("manifest.json")
    assert manifest["test_lock_verification"] == {
        "created_for_phase": 6,
        "locked": True,
        "metrics_run": False,
        "predictions_run": False,
        "prohibited_before_phase": 10,
        "records_parsed": False,
        "sha256": LOCKED_TEST_SHA256,
        "usage": "hash_verification_only",
    }
    assert len(manifest["search_space"]) == 8
    assert "manifest.json" not in {output["path"] for output in manifest["output_files"]}
    for output in manifest["output_files"]:
        path = MODEL_DIR / output["path"]
        assert output["sha256"] == sha256_file(path)
        assert output["size_bytes"] == path.stat().st_size
    locked_source = next(
        source for source in manifest["source_files"] if source["path"].endswith("test.jsonl")
    )
    assert locked_source["records_parsed"] is False
    assert locked_source["usage"] == "hash_verification_only"
    card = (MODEL_DIR / "MODEL_CARD.md").read_text(encoding="utf-8")
    for phrase in (
        "exactly eight",
        "T00",
        "Train + Validation",
        "no fairness guarantee",
        "not production-ready",
        "Phase 10",
        "Locked Test was not parsed, predicted, or evaluated",
    ):
        assert phrase in card
