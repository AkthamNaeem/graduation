"""Exact native XGBoost contribution contracts for Phase 11."""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pytest
import xgboost

import smart_recruitment_ml.explainability.engine as engine_module
from smart_recruitment_ml.explainability.engine import (
    MODEL_SHA256,
    CombinedDataset,
    ContributionResult,
    check_hash,
    compute_exact_contributions,
    load_booster,
    load_combined_dataset,
    load_feature_schema,
    validate_frozen_inputs,
)
from smart_recruitment_ml.training.dataset import RankingDataset, sha256_file

SERVICE_ROOT = Path(__file__).resolve().parents[1]
SCHEMA = SERVICE_ROOT / "data/features/v1/feature_schema.json"
MODEL = SERVICE_ROOT / "data/models/tuned/v1/model.json"
TRAIN = SERVICE_ROOT / "data/splits/v1/train.jsonl"
VALIDATION = SERVICE_ROOT / "data/splits/v1/validation.jsonl"


@pytest.fixture(scope="module")
def exact_result() -> tuple[CombinedDataset, ContributionResult, list[str]]:
    feature_names, _, _ = load_feature_schema(SCHEMA)
    dataset = load_combined_dataset(TRAIN, VALIDATION)
    booster = load_booster(MODEL, feature_names)
    return dataset, compute_exact_contributions(booster, dataset, feature_names), feature_names


def test_combined_dataset_contract_ids_and_labels_are_outside_x(
    exact_result: tuple[CombinedDataset, ContributionResult, list[str]],
) -> None:
    dataset, _, names = exact_result
    assert dataset.X.shape == (9180, 103)
    assert dataset.y.shape == (9180,)
    assert len(dataset.pair_ids) == len(set(dataset.pair_ids)) == 9180
    assert len(set(dataset.candidate_ids)) == 153
    assert set(dataset.source_splits) == {"train", "validation"}
    assert np.isfinite(dataset.X).all()
    assert len(names) == 103
    assert not {"pair_id", "candidate_id", "job_id", "relevance_label"} & set(names)


def test_exact_contribution_shape_finiteness_and_additivity(
    exact_result: tuple[CombinedDataset, ContributionResult, list[str]],
) -> None:
    _, result, _ = exact_result
    assert result.original_shape == (9180, 1, 104)
    assert result.contributions.shape == (9180, 104)
    assert np.isfinite(result.contributions).all()
    assert np.isfinite(result.margins).all()
    assert np.isfinite(result.scores).all()
    assert result.errors.shape == (9180,)
    assert np.count_nonzero(result.errors > 1e-5) == 0
    assert float(result.errors.max()) <= 1e-5


def test_exact_contributions_are_deterministic(
    exact_result: tuple[CombinedDataset, ContributionResult, list[str]],
) -> None:
    dataset, first, names = exact_result
    booster = load_booster(MODEL, names)
    second = compute_exact_contributions(booster, dataset, names)
    assert np.array_equal(first.contributions, second.contributions)
    assert np.array_equal(first.margins, second.margins)
    assert np.array_equal(first.scores, second.scores)
    assert np.array_equal(first.errors, second.errors)


def test_frozen_model_contract_and_hash_unchanged(
    exact_result: tuple[CombinedDataset, ContributionResult, list[str]],
) -> None:
    _, _, names = exact_result
    before = sha256_file(MODEL)
    booster = load_booster(MODEL, names)
    config = json.loads(booster.save_config())
    assert booster.num_features() == 103
    assert config["learner"]["objective"]["name"] == "rank:ndcg"
    assert before == sha256_file(MODEL) == MODEL_SHA256


def test_hash_mismatch_blocks(tmp_path: Path) -> None:
    changed = tmp_path / "model.json"
    changed.write_text("{}", encoding="utf-8")
    with pytest.raises(ValueError, match="SHA-256 mismatch"):
        check_hash(changed, MODEL_SHA256, "Tuned model")


def test_contribution_shape_failure_blocks(monkeypatch: pytest.MonkeyPatch) -> None:
    class InvalidBooster:
        def predict(self, *_args: object, **kwargs: object) -> np.ndarray:
            if kwargs.get("pred_contribs"):
                return np.zeros((1, 1, 104), dtype=np.float32)
            return np.zeros((1, 1), dtype=np.float32)

    dataset = load_combined_dataset(TRAIN, VALIDATION)
    monkeypatch.setattr(xgboost, "DMatrix", lambda *_args, **_kwargs: object())
    with pytest.raises(ValueError, match="shape"):
        compute_exact_contributions(InvalidBooster(), dataset, ["x"] * 103)  # type: ignore[arg-type]


def _empty_combined_dataset() -> CombinedDataset:
    return CombinedDataset(
        pair_ids=tuple(f"pair_{index}" for index in range(9180)),
        candidate_ids=tuple(f"candidate_{index // 60}" for index in range(9180)),
        job_ids=tuple(f"job_{index % 60}" for index in range(9180)),
        source_splits=("train",) * 7560 + ("validation",) * 1620,
        X=np.zeros((9180, 103), dtype=np.float32),
        y=np.zeros(9180, dtype=np.float32),
        train_count=7560,
        validation_count=1620,
        candidate_count=153,
    )


@pytest.mark.parametrize("failure", ["nonfinite", "additivity"])
def test_contribution_validation_rejects_invalid_provider_output(
    monkeypatch: pytest.MonkeyPatch,
    failure: str,
) -> None:
    raw = np.zeros((9180, 1, 104), dtype=np.float32)
    margins = np.zeros((9180, 1), dtype=np.float32)
    if failure == "nonfinite":
        raw[0, 0, 0] = np.nan
    else:
        margins[:] = 1.0

    class InvalidOutputBooster:
        def predict(self, *_args: object, **kwargs: object) -> np.ndarray:
            if kwargs.get("pred_contribs"):
                assert kwargs == {
                    "pred_contribs": True,
                    "approx_contribs": False,
                    "strict_shape": True,
                }
                return raw
            if kwargs.get("output_margin"):
                return margins
            return margins

    monkeypatch.setattr(xgboost, "DMatrix", lambda *_args, **_kwargs: object())
    message = "Non-finite" if failure == "nonfinite" else "additivity"
    with pytest.raises(ValueError, match=message):
        compute_exact_contributions(
            InvalidOutputBooster(),  # type: ignore[arg-type]
            _empty_combined_dataset(),
            [f"feature_{index}" for index in range(103)],
        )


@pytest.mark.parametrize(
    ("schema", "message"),
    [
        ([], "JSON object expected"),
        ({"feature_schema_version": "wrong"}, "contract mismatch"),
        (
            {
                "feature_schema_version": "job-rec-features-v1",
                "feature_count": 103,
                "feature_names": ["feature"] * 103,
                "feature_definitions": [None] * 103,
            },
            "contract mismatch",
        ),
    ],
)
def test_feature_schema_structure_mismatch_blocks(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    schema: object,
    message: str,
) -> None:
    path = tmp_path / "schema.json"
    path.write_text(json.dumps(schema), encoding="utf-8")
    monkeypatch.setattr(engine_module, "check_hash", lambda *_args: None)
    with pytest.raises(ValueError, match=message):
        load_feature_schema(path)


def _valid_recovery_receipt() -> dict[str, object]:
    return {
        "recovery_execution": True,
        "evaluation_attempt_number": 2,
        "prior_attempt_status": "failed_before_artifact_publication",
        "prior_attempt_failure_stage": "system_score_conversion",
        "prior_predictions_artifact_published": False,
        "prior_metrics_published": False,
        "prior_test_results_observed": False,
        "recovery_authorized_by_user": True,
        "model_changed_between_attempts": False,
        "feature_changed_between_attempts": False,
        "hyperparameters_changed_between_attempts": False,
        "selection_changed_between_attempts": False,
        "metrics_contract_changed_between_attempts": False,
        "training_run_between_attempts": False,
        "tuning_run_between_attempts": False,
    }


@pytest.mark.parametrize("failure", ["disposition", "receipt"])
def test_phase_10_aggregate_contract_mismatch_blocks(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    failure: str,
) -> None:
    model_dir = tmp_path / "model"
    model_dir.mkdir()
    metadata = {
        "model_version": "xgbranker-tuned-v1",
        "feature_count": 103,
        "selected_config_id": "T06",
        "final_training_contract": "train-plus-validation-v1",
        "hyperparameters": {"objective": "rank:ndcg"},
    }
    receipt = _valid_recovery_receipt()
    comparison = {"quality_disposition": "PROMOTE_TO_EXPLAINABILITY"}
    if failure == "disposition":
        comparison["quality_disposition"] = "HOLD_MODEL_CANDIDATE"
    else:
        receipt["prior_test_results_observed"] = True
    files = {
        model_dir / "model.json": {},
        model_dir / "model_metadata.json": metadata,
        model_dir / "manifest.json": {},
        tmp_path / "predictions.jsonl": {},
        tmp_path / "comparison.json": comparison,
        tmp_path / "receipt.json": receipt,
        tmp_path / "final-manifest.json": {},
    }
    for path, value in files.items():
        path.write_text(json.dumps(value), encoding="utf-8")
    monkeypatch.setattr(engine_module, "check_hash", lambda *_args: None)
    message = "disposition" if failure == "disposition" else "recovery receipt"
    with pytest.raises(ValueError, match=message):
        validate_frozen_inputs(
            tuned_model_dir=model_dir,
            predictions_path=tmp_path / "predictions.jsonl",
            comparison_path=tmp_path / "comparison.json",
            receipt_path=tmp_path / "receipt.json",
            final_manifest_path=tmp_path / "final-manifest.json",
        )


def test_cross_split_candidate_and_pair_overlap_are_rejected() -> None:
    def dataset(split: str, candidate: str, pair: str) -> RankingDataset:
        return RankingDataset(
            split=split,  # type: ignore[arg-type]
            pair_ids=(pair,),
            candidate_ids=(candidate,),
            job_ids=("job",),
            X=np.zeros((1, 103), dtype=np.float32),
            y=np.zeros(1, dtype=np.float32),
            qid=np.zeros(1, dtype=np.int32),
            group_sizes=(1,),
        )

    with pytest.raises(ValueError, match="Candidate overlap"):
        engine_module._validate_cross_split(
            dataset("train", "same", "one"),
            dataset("validation", "same", "two"),
        )
    with pytest.raises(ValueError, match="Duplicate Pair"):
        engine_module._validate_cross_split(
            dataset("train", "one", "same"),
            dataset("validation", "two", "same"),
        )
