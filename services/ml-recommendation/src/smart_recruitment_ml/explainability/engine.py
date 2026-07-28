"""Immutable data/model loading and exact XGBoost contribution computation."""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any, Final

import numpy as np
import xgboost

from smart_recruitment_ml.training.dataset import RankingDataset, load_ranking_dataset, sha256_file

if TYPE_CHECKING:
    from pathlib import Path

    from numpy.typing import NDArray

TRAIN_SHA256: Final = "d87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d"
VALIDATION_SHA256: Final = "a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e"
FEATURE_SCHEMA_SHA256: Final = "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0"
MODEL_SHA256: Final = "3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e"
MODEL_METADATA_SHA256: Final = "5485a2058d22777c3cafe9ea5871ac7534f555bfce6fb8275ddf89526358cb11"
TUNED_MANIFEST_SHA256: Final = "8d71babf225363b0b3d773147c2f95cad4bf910f78695a58dea7d31a6d7a042b"
PREDICTIONS_SHA256: Final = "55e64d1b7ad8098da6d4938b0c01cc18c456566beaa3b58089ce8f84f80cac5c"
COMPARISON_SHA256: Final = "c1dacc745fdfc9ade72f53829e2336a4f0cec427979066d337f5052b30cc0886"
RECEIPT_SHA256: Final = "a9f58db9b33dfb81a8476dae6581e2beb6e26331ad8e3e9f7f47b0816584036c"
FINAL_MANIFEST_SHA256: Final = "0a4051fa80a7f22cdf741679922150e3f4dab5caee21bc8a41933cffb5c13386"


@dataclass(frozen=True)
class CombinedDataset:
    pair_ids: tuple[str, ...]
    candidate_ids: tuple[str, ...]
    job_ids: tuple[str, ...]
    source_splits: tuple[str, ...]
    X: NDArray[np.float32]
    y: NDArray[np.float32]
    train_count: int
    validation_count: int
    candidate_count: int


@dataclass(frozen=True)
class ContributionResult:
    contributions: NDArray[np.float32]
    margins: NDArray[np.float32]
    scores: NDArray[np.float32]
    original_shape: tuple[int, ...]
    errors: NDArray[np.float64]


def read_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"JSON object expected: {path}.")
    return value


def check_hash(path: Path, expected: str, label: str) -> None:
    actual = sha256_file(path)
    if actual != expected:
        raise ValueError(f"{label} SHA-256 mismatch: expected {expected}, got {actual}.")


def load_feature_schema(path: Path) -> tuple[list[str], list[dict[str, Any]], dict[str, Any]]:
    check_hash(path, FEATURE_SCHEMA_SHA256, "Feature schema")
    schema = read_json(path)
    names = schema.get("feature_names")
    definitions = schema.get("feature_definitions")
    if (
        schema.get("feature_schema_version") != "job-rec-features-v1"
        or schema.get("feature_count") != 103
        or not isinstance(names, list)
        or not all(isinstance(name, str) for name in names)
        or not isinstance(definitions, list)
        or not all(isinstance(item, dict) for item in definitions)
    ):
        raise ValueError("Frozen feature schema contract mismatch.")
    return list(names), list(definitions), schema


def load_combined_dataset(train_path: Path, validation_path: Path) -> CombinedDataset:
    train = load_ranking_dataset(
        train_path,
        split="train",
        expected_sha256=TRAIN_SHA256,
        expected_records=7560,
        expected_candidates=126,
    )
    validation = load_ranking_dataset(
        validation_path,
        split="validation",
        expected_sha256=VALIDATION_SHA256,
        expected_records=1620,
        expected_candidates=27,
    )
    _validate_cross_split(train, validation)
    return CombinedDataset(
        pair_ids=train.pair_ids + validation.pair_ids,
        candidate_ids=train.candidate_ids + validation.candidate_ids,
        job_ids=train.job_ids + validation.job_ids,
        source_splits=("train",) * train.record_count + ("validation",) * validation.record_count,
        X=np.concatenate((train.X, validation.X), axis=0),
        y=np.concatenate((train.y, validation.y), axis=0),
        train_count=train.record_count,
        validation_count=validation.record_count,
        candidate_count=train.candidate_count + validation.candidate_count,
    )


def _validate_cross_split(train: RankingDataset, validation: RankingDataset) -> None:
    if set(train.candidate_ids) & set(validation.candidate_ids):
        raise ValueError("Candidate overlap between Train and Validation.")
    all_pairs = train.pair_ids + validation.pair_ids
    if len(set(all_pairs)) != len(all_pairs):
        raise ValueError("Duplicate Pair IDs across Train and Validation.")


def validate_frozen_inputs(
    *,
    tuned_model_dir: Path,
    predictions_path: Path,
    comparison_path: Path,
    receipt_path: Path,
    final_manifest_path: Path,
) -> dict[str, dict[str, Any]]:
    model_path = tuned_model_dir / "model.json"
    metadata_path = tuned_model_dir / "model_metadata.json"
    manifest_path = tuned_model_dir / "manifest.json"
    checks = (
        (model_path, MODEL_SHA256, "Tuned model"),
        (metadata_path, MODEL_METADATA_SHA256, "Tuned metadata"),
        (manifest_path, TUNED_MANIFEST_SHA256, "Tuned manifest"),
        (predictions_path, PREDICTIONS_SHA256, "Final Train+Validation predictions"),
        (comparison_path, COMPARISON_SHA256, "Phase 10 comparison"),
        (receipt_path, RECEIPT_SHA256, "Phase 10 receipt"),
        (final_manifest_path, FINAL_MANIFEST_SHA256, "Phase 10 manifest"),
    )
    for path, expected, label in checks:
        check_hash(path, expected, label)
    metadata = read_json(metadata_path)
    if (
        metadata.get("model_version") != "xgbranker-tuned-v1"
        or metadata.get("feature_count") != 103
        or metadata.get("selected_config_id") != "T06"
        or metadata.get("final_training_contract") != "train-plus-validation-v1"
        or metadata.get("hyperparameters", {}).get("objective") != "rank:ndcg"
    ):
        raise ValueError("Frozen tuned model metadata contract mismatch.")
    comparison = read_json(comparison_path)
    if comparison.get("quality_disposition") != "PROMOTE_TO_EXPLAINABILITY":
        raise ValueError("Phase 10 disposition does not permit explainability.")
    receipt = read_json(receipt_path)
    required_receipt = {
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
    if any(receipt.get(key) != value for key, value in required_receipt.items()):
        raise ValueError("Phase 10 controlled recovery receipt mismatch.")
    return {
        "metadata": metadata,
        "comparison": comparison,
        "receipt": receipt,
        "final_manifest": read_json(final_manifest_path),
    }


def load_booster(model_path: Path, feature_names: list[str]) -> xgboost.Booster:
    check_hash(model_path, MODEL_SHA256, "Tuned model")
    booster = xgboost.Booster()
    booster.load_model(model_path)
    if booster.num_features() != 103:
        raise ValueError("Frozen tuned model feature count mismatch.")
    if booster.feature_names not in (None, feature_names):
        raise ValueError("Frozen tuned model feature names mismatch.")
    config = json.loads(booster.save_config())
    objective = config["learner"]["objective"]["name"]
    if objective != "rank:ndcg":
        raise ValueError("Frozen tuned model objective mismatch.")
    return booster


def compute_exact_contributions(
    booster: xgboost.Booster,
    dataset: CombinedDataset,
    feature_names: list[str],
) -> ContributionResult:
    dmatrix = xgboost.DMatrix(dataset.X, feature_names=feature_names)
    raw = booster.predict(
        dmatrix,
        pred_contribs=True,
        approx_contribs=False,
        strict_shape=True,
    )
    expected_shape = (9180, 1, 104)
    if raw.shape != expected_shape:
        raise ValueError(f"Unexpected exact contribution shape: {raw.shape}.")
    flattened = raw[:, 0, :]
    margins_raw = booster.predict(dmatrix, output_margin=True, strict_shape=True)
    scores_raw = booster.predict(dmatrix, strict_shape=True)
    margins = margins_raw.reshape(-1)
    scores = scores_raw.reshape(-1)
    if (
        flattened.shape != (9180, 104)
        or margins.shape != (9180,)
        or scores.shape != (9180,)
        or not np.isfinite(flattened).all()
        or not np.isfinite(margins).all()
        or not np.isfinite(scores).all()
    ):
        raise ValueError("Non-finite or invalid frozen-model contribution output.")
    reconstructed = flattened[:, :103].sum(axis=1, dtype=np.float64) + flattened[:, 103]
    errors = np.abs(reconstructed - margins.astype(np.float64))
    if not np.isfinite(errors).all() or float(errors.max()) > 1e-5:
        raise ValueError("Exact contribution additivity contract failed.")
    if not all(math.isfinite(float(value)) for value in flattened[:, 103]):
        raise ValueError("Non-finite contribution bias.")
    return ContributionResult(
        contributions=flattened.astype(np.float32, copy=False),
        margins=margins.astype(np.float32, copy=False),
        scores=scores.astype(np.float32, copy=False),
        original_shape=tuple(int(value) for value in raw.shape),
        errors=errors,
    )
