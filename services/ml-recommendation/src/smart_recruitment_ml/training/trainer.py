"""Deterministic Phase 8 training, verification, and atomic publication."""

from __future__ import annotations

import argparse
import json
import os
import platform
import shutil
import tempfile
from collections import defaultdict
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final, Literal

import numpy as np
import scipy  # type: ignore[import-untyped]
import xgboost

from smart_recruitment_ml.baselines.metrics import (
    METRICS_VERSION,
    RELEVANCE_THRESHOLD,
    evaluate_rankings,
)
from smart_recruitment_ml.schemas.training import (
    ModelMetadata,
    OutputArtifact,
    PredictionStatistics,
    SourceArtifact,
    TrainingHistoryEntry,
    TrainingManifest,
    TrainingPrediction,
)

from . import (
    MODEL_FORMAT,
    MODEL_VERSION,
    TRAINING_CONFIG_VERSION,
    TRAINING_PIPELINE_VERSION,
    TRAINING_SEED,
)
from .dataset import (
    FEATURE_COUNT,
    FEATURE_SCHEMA_VERSION,
    LOCKED_TEST_SHA256,
    RankingDataset,
    load_ranking_dataset,
    sha256_file,
)
from .xgbranker import FIXED_HYPERPARAMETERS, create_ranker, fit_initial_ranker

if TYPE_CHECKING:
    from collections.abc import Iterable, Sequence

    from numpy.typing import NDArray
    from xgboost import XGBRanker

TRAINING_RELEASE_DATE: Final = "2026-07-24"
NUMPY_VERSION: Final[Literal["2.5.1"]] = "2.5.1"
SCIPY_VERSION: Final[Literal["1.18.0"]] = "1.18.0"
XGBOOST_VERSION: Final[Literal["3.3.0"]] = "3.3.0"
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
EXPECTED_HASHES: Final = {
    "feature_schema": "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0",
    "train": "d87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d",
    "validation": "a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e",
    "assignments": "ba5c075f244c8d65200316e44a4b0bb68f579aa6e2b0546e3527e17db98bc502",
    "split_manifest": "f032847615dea42b28d41f8d47f2627df3d030399c8690df8747bb1ae26dbd0a",
    "test_lock": "00f938c9f888156022d221a9fb3eab7c76e8d4316803d175470355a84f33ec73",
    "baseline_metrics": "4bffef2b5e2c2ba16ccf92686465e45533dfc075b8ab56bc2544af1fccd75778",
    "baseline_manifest": "C591708A58AE66941BB004CE08522EAADC90F476105F7BED08B5E2DB477046BF".lower(),
}
OUTPUT_RECORD_COUNTS: Final = {
    "model.json": 1,
    "model_metadata.json": 1,
    "train_predictions.jsonl": 7560,
    "validation_predictions.jsonl": 1620,
    "metrics.json": 1,
    "training_history.json": 300,
    "MODEL_CARD.md": 1,
}
METRIC_NAMES: Final = (
    "NDCG@5",
    "NDCG@10",
    "Precision@5",
    "Recall@5",
    "MRR",
    "HitRate@5",
)


def _read_json(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"JSON object expected: {path}")
    return value


def _json_content(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def _write_text(path: Path, content: str) -> None:
    path.write_text(content, encoding="utf-8", newline="\n")


def _check_hash(path: Path, expected: str, label: str) -> None:
    actual = sha256_file(path)
    if actual != expected:
        raise ValueError(f"{label} SHA-256 mismatch: expected {expected}, got {actual}")


def _validate_inputs(
    *,
    paths: dict[str, Path],
    source_revision: str,
    architecture_sha256: str,
) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any]]:
    for label, expected in EXPECTED_HASHES.items():
        _check_hash(paths[label], expected, label)

    if source_revision != SOURCE_REVISION:
        raise ValueError("Source revision is not the locked Phase 8 revision.")
    if architecture_sha256 != ARCHITECTURE_SHA256:
        raise ValueError("Architecture SHA-256 is not the locked Phase 8 digest.")

    feature_schema = _read_json(paths["feature_schema"])
    split_manifest = _read_json(paths["split_manifest"])
    test_lock = _read_json(paths["test_lock"])
    baseline_metrics = _read_json(paths["baseline_metrics"])
    baseline_manifest = _read_json(paths["baseline_manifest"])
    feature_names = feature_schema.get("feature_names")
    if (
        feature_schema.get("feature_schema_version") != FEATURE_SCHEMA_VERSION
        or feature_schema.get("feature_pipeline_version") != "0.1.0"
        or not isinstance(feature_names, list)
        or len(feature_names) != FEATURE_COUNT
        or len(set(feature_names)) != FEATURE_COUNT
    ):
        raise ValueError("Feature schema contract mismatch.")
    if (
        feature_schema.get("source_revision") != source_revision
        or feature_schema.get("architecture_sha256") != architecture_sha256
    ):
        raise ValueError("Feature schema provenance mismatch.")
    if (
        split_manifest.get("split_version") != "candidate-group-split-v1"
        or split_manifest.get("feature_count") != FEATURE_COUNT
        or split_manifest.get("source_revision") != source_revision
        or split_manifest.get("architecture_sha256") != architecture_sha256
        or split_manifest.get("candidate_overlap_counts", {}).get("candidate_train_validation") != 0
    ):
        raise ValueError("Split manifest contract mismatch.")
    if (
        test_lock.get("test_locked") is not True
        or test_lock.get("created_for_phase") != 6
        or test_lock.get("prohibited_before_phase") != 10
        or test_lock.get("test_record_count") != 1620
        or str(test_lock.get("test_file_sha256", "")).lower() != LOCKED_TEST_SHA256
    ):
        raise ValueError("Locked Test policy contract mismatch.")

    locked_path = paths["test_lock"].with_name("test.jsonl")
    if not locked_path.is_file() or sha256_file(locked_path) != LOCKED_TEST_SHA256:
        raise ValueError("Locked Test hash-only verification failed.")
    if baseline_metrics.get("ranking_metrics_version") != METRICS_VERSION:
        raise ValueError("Baseline ranking metrics version mismatch.")
    if baseline_manifest.get("baseline_evaluation_version") != "job-rec-baselines-v1":
        raise ValueError("Baseline manifest version mismatch.")
    return feature_schema, split_manifest, test_lock, baseline_metrics


def _validate_datasets(train: RankingDataset, validation: RankingDataset) -> None:
    if set(train.candidate_ids).intersection(validation.candidate_ids):
        raise ValueError("Train/Validation Candidate overlap detected.")
    if train.X.shape != (7560, FEATURE_COUNT) or validation.X.shape != (
        1620,
        FEATURE_COUNT,
    ):
        raise ValueError("Feature matrix shape contract mismatch.")
    if train.y.shape != (7560,) or validation.y.shape != (1620,):
        raise ValueError("Label vector shape contract mismatch.")


def _prediction_records(
    dataset: RankingDataset,
    scores: NDArray[np.float32],
) -> list[TrainingPrediction]:
    if scores.shape != (dataset.record_count,) or not np.all(np.isfinite(scores)):
        raise ValueError(f"{dataset.split} predictions are incomplete or non-finite.")
    if float(np.var(scores, dtype=np.float64)) == 0.0 or np.unique(scores).size == 1:
        raise ValueError(f"{dataset.split} predictions must have non-zero variance.")

    ranks: list[int] = [0] * dataset.record_count
    by_candidate: dict[str, list[int]] = defaultdict(list)
    for index, candidate_id in enumerate(dataset.candidate_ids):
        by_candidate[candidate_id].append(index)
    if len(by_candidate) != dataset.candidate_count:
        raise ValueError(f"{dataset.split} Candidate group is missing.")
    for candidate_id in sorted(by_candidate):
        indexes = by_candidate[candidate_id]
        if len(indexes) != 60:
            raise ValueError(f"{dataset.split} Candidate group must contain 60 records.")
        ordered = sorted(
            indexes,
            key=lambda index: (
                -float(scores[index]),
                dataset.job_ids[index],
                dataset.pair_ids[index],
            ),
        )
        for rank, index in enumerate(ordered, start=1):
            ranks[index] = rank
    if any(rank == 0 for rank in ranks):
        raise ValueError(f"{dataset.split} rank coverage is incomplete.")

    records = [
        TrainingPrediction(
            pair_id=dataset.pair_ids[index],
            candidate_id=dataset.candidate_ids[index],
            job_id=dataset.job_ids[index],
            relevance_label=int(dataset.y[index]),
            prediction_score=float(scores[index]),
            rank=ranks[index],
            model_version=MODEL_VERSION,
            feature_schema_version=FEATURE_SCHEMA_VERSION,
        )
        for index in range(dataset.record_count)
    ]
    records.sort(key=lambda record: (record.candidate_id, record.job_id, record.pair_id))
    if len({record.pair_id for record in records}) != dataset.record_count:
        raise ValueError(f"{dataset.split} prediction Pair IDs are not unique.")
    return records


def _prediction_content(records: Iterable[TrainingPrediction]) -> str:
    return "".join(
        json.dumps(
            record.model_dump(mode="json"),
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        )
        + "\n"
        for record in records
    )


def _ranked_labels(records: Sequence[TrainingPrediction]) -> list[list[int]]:
    by_candidate: dict[str, list[TrainingPrediction]] = defaultdict(list)
    for record in records:
        by_candidate[record.candidate_id].append(record)
    groups: list[list[int]] = []
    for candidate_id in sorted(by_candidate):
        group = sorted(
            by_candidate[candidate_id],
            key=lambda record: (
                record.rank,
                record.job_id,
                record.pair_id,
            ),
        )
        if [record.rank for record in group] != list(range(1, 61)):
            raise ValueError(f"Incomplete rank set for Candidate {candidate_id}.")
        groups.append([record.relevance_label for record in group])
    return groups


def _prediction_statistics(scores: NDArray[np.float32]) -> dict[str, Any]:
    statistics = PredictionStatistics(
        count=int(scores.size),
        minimum=float(np.min(scores)),
        maximum=float(np.max(scores)),
        mean=float(np.mean(scores, dtype=np.float64)),
        standard_deviation=float(np.std(scores, dtype=np.float64)),
        finite_count=int(np.count_nonzero(np.isfinite(scores))),
        unique_value_count=int(np.unique(scores).size),
    )
    return statistics.model_dump(mode="json")


def _baseline_split(
    baseline_metrics: dict[str, Any],
    split: str,
) -> tuple[dict[str, Any], dict[str, Any]]:
    split_value = baseline_metrics["splits"][split]
    skills = split_value["skills_weighted_v1"]
    matching = split_value["laravel_matching_2.0"]
    if not isinstance(skills, dict) or not isinstance(matching, dict):
        raise ValueError("Baseline metric structure mismatch.")
    return skills, matching


def _deltas(
    xgbranker_metrics: dict[str, Any],
    skills_metrics: dict[str, Any],
    matching_metrics: dict[str, Any],
) -> dict[str, dict[str, float]]:
    return {
        "vs_skills": {
            metric: float(
                xgbranker_metrics[metric]["macro_mean"] - skills_metrics[metric]["macro_mean"]
            )
            for metric in METRIC_NAMES
        },
        "vs_matching_2_0": {
            metric: float(
                xgbranker_metrics[metric]["macro_mean"] - matching_metrics[metric]["macro_mean"]
            )
            for metric in METRIC_NAMES
        },
    }


def _metrics_artifact(
    *,
    train_records: Sequence[TrainingPrediction],
    validation_records: Sequence[TrainingPrediction],
    train_scores: NDArray[np.float32],
    validation_scores: NDArray[np.float32],
    baseline_metrics: dict[str, Any],
) -> dict[str, Any]:
    train_metrics = evaluate_rankings(_ranked_labels(train_records)).model_dump(
        mode="json",
        by_alias=True,
    )
    validation_metrics = evaluate_rankings(_ranked_labels(validation_records)).model_dump(
        mode="json", by_alias=True
    )
    train_skills, train_matching = _baseline_split(baseline_metrics, "train")
    validation_skills, validation_matching = _baseline_split(
        baseline_metrics,
        "validation",
    )
    artifact = {
        "aggregation": "candidate_macro",
        "gain_definition": "2^relevance_label - 1",
        "model_version": MODEL_VERSION,
        "ranking_metrics_version": METRICS_VERSION,
        "relevant_label_threshold": RELEVANCE_THRESHOLD,
        "train": {
            "candidate_count": 126,
            "record_count": 7560,
            "xgbranker": train_metrics,
            "skills_baseline": train_skills,
            "matching_2_0": train_matching,
            "deltas": _deltas(train_metrics, train_skills, train_matching),
        },
        "training_config_version": TRAINING_CONFIG_VERSION,
        "validation": {
            "candidate_count": 27,
            "record_count": 1620,
            "xgbranker": validation_metrics,
            "skills_baseline": validation_skills,
            "matching_2_0": validation_matching,
            "deltas": _deltas(
                validation_metrics,
                validation_skills,
                validation_matching,
            ),
        },
        "prediction_statistics": {
            "train": _prediction_statistics(train_scores),
            "validation": _prediction_statistics(validation_scores),
        },
    }
    if "test" in artifact:
        raise ValueError("Locked Test metrics are prohibited.")
    return artifact


def _training_history(model: XGBRanker) -> list[dict[str, Any]]:
    result = model.evals_result()
    train_values = result["validation_0"]
    validation_values = result["validation_1"]
    history = [
        TrainingHistoryEntry(
            round=index + 1,
            train_ndcg_at_5=float(train_values["ndcg@5"][index]),
            train_ndcg_at_10=float(train_values["ndcg@10"][index]),
            validation_ndcg_at_5=float(validation_values["ndcg@5"][index]),
            validation_ndcg_at_10=float(validation_values["ndcg@10"][index]),
        ).model_dump(mode="json")
        for index in range(300)
    ]
    if any(len(values) != 300 for values in (*train_values.values(), *validation_values.values())):
        raise ValueError("Training history must contain exactly 300 rounds.")
    return history


def _ranks(records: Sequence[TrainingPrediction]) -> dict[str, int]:
    return {record.pair_id: record.rank for record in records}


def _round_trip(
    *,
    model_path: Path,
    train: RankingDataset,
    validation: RankingDataset,
    train_scores: NDArray[np.float32],
    validation_scores: NDArray[np.float32],
    train_records: Sequence[TrainingPrediction],
    validation_records: Sequence[TrainingPrediction],
) -> tuple[float, float]:
    loaded = create_ranker()
    loaded.load_model(model_path)
    loaded_train = np.asarray(loaded.predict(train.X), dtype=np.float32)
    loaded_validation = np.asarray(loaded.predict(validation.X), dtype=np.float32)
    maximum_error = float(
        max(
            np.max(np.abs(train_scores.astype(np.float64) - loaded_train)),
            np.max(
                np.abs(validation_scores.astype(np.float64) - loaded_validation.astype(np.float64))
            ),
        )
    )
    loaded_train_records = _prediction_records(train, loaded_train)
    loaded_validation_records = _prediction_records(validation, loaded_validation)
    expected_ranks = {
        **_ranks(train_records),
        **_ranks(validation_records),
    }
    actual_ranks = {
        **_ranks(loaded_train_records),
        **_ranks(loaded_validation_records),
    }
    rank_agreement = sum(
        expected_ranks[pair_id] == actual_ranks[pair_id] for pair_id in expected_ranks
    ) / len(expected_ranks)
    if maximum_error > 1e-12 or rank_agreement != 1.0:
        raise ValueError("Model round-trip prediction or rank verification failed.")
    if loaded.get_booster().num_features() != FEATURE_COUNT:
        raise ValueError("Loaded model feature count mismatch.")
    return maximum_error, rank_agreement


def _source_artifacts(paths: dict[str, Path]) -> list[SourceArtifact]:
    definitions = (
        (
            "feature_schema",
            "services/ml-recommendation/data/features/v1/feature_schema.json",
            1,
            "parsed_schema_verification",
            True,
        ),
        (
            "train",
            "services/ml-recommendation/data/splits/v1/train.jsonl",
            7560,
            "parsed_training_source",
            True,
        ),
        (
            "validation",
            "services/ml-recommendation/data/splits/v1/validation.jsonl",
            1620,
            "parsed_validation_evaluation",
            True,
        ),
        (
            "assignments",
            "services/ml-recommendation/data/splits/v1/assignments.jsonl",
            180,
            "hash_verification_only",
            False,
        ),
        (
            "split_manifest",
            "services/ml-recommendation/data/splits/v1/manifest.json",
            1,
            "parsed_split_verification",
            True,
        ),
        (
            "test_lock",
            "services/ml-recommendation/data/splits/v1/test_lock.json",
            1,
            "parsed_lock_verification",
            True,
        ),
        (
            "baseline_metrics",
            "services/ml-recommendation/data/baselines/v1/metrics.json",
            1,
            "parsed_baseline_comparison",
            True,
        ),
        (
            "baseline_manifest",
            "services/ml-recommendation/data/baselines/v1/manifest.json",
            1,
            "parsed_baseline_verification",
            True,
        ),
    )
    artifacts = [
        SourceArtifact(
            path=artifact_path,
            record_count=count,
            size_bytes=paths[key].stat().st_size,
            sha256=EXPECTED_HASHES[key],
            usage=usage,
            records_parsed=parsed,
        )
        for key, artifact_path, count, usage, parsed in definitions
    ]
    locked_path = paths["test_lock"].with_name("test.jsonl")
    artifacts.append(
        SourceArtifact(
            path="services/ml-recommendation/data/splits/v1/test.jsonl",
            record_count=1620,
            size_bytes=locked_path.stat().st_size,
            sha256=LOCKED_TEST_SHA256,
            usage="hash_verification_only",
            records_parsed=False,
        )
    )
    return artifacts


def _output_artifacts(staging_dir: Path) -> list[OutputArtifact]:
    return [
        OutputArtifact(
            path=name,
            record_count=OUTPUT_RECORD_COUNTS[name],
            size_bytes=(staging_dir / name).stat().st_size,
            sha256=sha256_file(staging_dir / name),
        )
        for name in OUTPUT_RECORD_COUNTS
    ]


def _model_card(metrics: dict[str, Any], metadata: ModelMetadata) -> str:
    def row(split: str, label: str, key: str) -> str:
        values = metrics[split][key]
        cells = [f"{values[metric]['macro_mean']:.12f}" for metric in METRIC_NAMES]
        return f"| {label} | " + " | ".join(cells) + " |"

    train_table = "\n".join(
        [
            "| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |",
            "|---|---:|---:|---:|---:|---:|---:|",
            row("train", "Skills-only", "skills_baseline"),
            row("train", "Matching 2.0", "matching_2_0"),
            row("train", "Initial XGBRanker", "xgbranker"),
        ]
    )
    validation_table = "\n".join(
        [
            "| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |",
            "|---|---:|---:|---:|---:|---:|---:|",
            row("validation", "Skills-only", "skills_baseline"),
            row("validation", "Matching 2.0", "matching_2_0"),
            row("validation", "Initial XGBRanker", "xgbranker"),
        ]
    )
    parameters = "\n".join(
        f"- `{key}`: `{json.dumps(value, separators=(',', ':'))}`"
        for key, value in FIXED_HYPERPARAMETERS.items()
    )
    return f"""# Initial XGBRanker Model Card

## Model

- Name/version: `{MODEL_VERSION}`
- Format: `{MODEL_FORMAT}`
- Status: initial experimental ranker; not production-ready
- Objective: `rank:ndcg`
- Inputs: exactly 103 handcrafted features using `{FEATURE_SCHEMA_VERSION}`
- Training groups: 126 Candidates / 7,560 records
- Validation groups: 27 Candidates / 1,620 records

## Fixed hyperparameters

{parameters}

Exactly one fixed configuration was trained on Train. Validation was used only for
evaluation history. There was no tuning, cross-validation, early stopping, calibration,
threshold selection, or best-round selection.

## Metric definitions

Metrics reuse Phase 7 candidate-macro implementations: NDCG uses graded gain
`2^relevance_label - 1`; Precision, Recall, MRR, and HitRate use binary relevance
`relevance_label >= 2`.

## Train results

{train_table}

## Validation results

{validation_table}

Baseline differences are descriptive only and did not alter the configuration.

## Determinism and verification

CPU execution, one thread, seed `{TRAINING_SEED}`, fixed data ordering, stable JSON
serialization, and pinned NumPy `{np.__version__}`, SciPy `{scipy.__version__}`, and
XGBoost `{xgboost.__version__}` control reproducibility. The XGBoost estimator wrapper
uses its bundled minimal compatibility protocol; no optional estimator framework was
installed. The saved model reloaded successfully with maximum absolute prediction error
`{metadata.round_trip_max_absolute_error}` and rank agreement
`{metadata.round_trip_rank_agreement:.0%}` across Train and Validation.

## Intended use

This initial model is a Phase 8 research artifact for offline job-ranking experiments and
bounded Phase 9 tuning. AI output is decision support only and requires human oversight.

## Limitations and non-intended uses

- Synthetic training data and handcrafted features may not represent production behavior.
- Validation has only 27 Candidate groups.
- No fairness guarantee or production-quality guarantee is established.
- It must not automatically accept or reject candidates.
- It is not a production model and has not been promoted.
- There is no inference endpoint or Laravel integration.
- The Locked Test was not parsed, predicted, or evaluated; Phase 10 owns that evaluation.
- Phase 9 is reserved for bounded hyperparameter tuning.
"""


def _publish_directory(staging_dir: Path, output_dir: Path) -> None:
    backup_dir = output_dir.parent / f".{output_dir.name}-backup"
    if backup_dir.exists():
        shutil.rmtree(backup_dir)
    moved_existing = False
    try:
        if output_dir.exists():
            os.replace(output_dir, backup_dir)
            moved_existing = True
        os.replace(staging_dir, output_dir)
        if moved_existing:
            shutil.rmtree(backup_dir)
    except Exception:
        if output_dir.exists() and moved_existing:
            shutil.rmtree(output_dir)
        if backup_dir.exists():
            os.replace(backup_dir, output_dir)
        raise
    finally:
        if staging_dir.exists():
            shutil.rmtree(staging_dir)
        if backup_dir.exists():
            shutil.rmtree(backup_dir)


def train(args: argparse.Namespace) -> dict[str, Any]:
    if args.model_version != MODEL_VERSION:
        raise ValueError("Model version must be the locked Phase 8 version.")
    if args.training_config_version != TRAINING_CONFIG_VERSION:
        raise ValueError("Training configuration version mismatch.")
    if args.seed != TRAINING_SEED:
        raise ValueError("Training seed must be the locked Phase 8 seed.")
    if (
        np.__version__ != NUMPY_VERSION
        or scipy.__version__ != SCIPY_VERSION
        or xgboost.__version__ != XGBOOST_VERSION
    ):
        raise ValueError("Pinned ML dependency version mismatch.")

    paths = {
        "train": Path(args.train_file).resolve(),
        "validation": Path(args.validation_file).resolve(),
        "feature_schema": Path(args.feature_schema_file).resolve(),
        "split_manifest": Path(args.split_manifest).resolve(),
        "test_lock": Path(args.test_lock_file).resolve(),
        "baseline_metrics": Path(args.baseline_metrics_file).resolve(),
        "baseline_manifest": Path(args.baseline_manifest_file).resolve(),
    }
    paths["assignments"] = paths["split_manifest"].with_name("assignments.jsonl")
    feature_schema, _split_manifest, test_lock, baseline_metrics = _validate_inputs(
        paths=paths,
        source_revision=args.source_revision,
        architecture_sha256=args.architecture_sha256,
    )
    train_dataset = load_ranking_dataset(
        paths["train"],
        split="train",
        expected_sha256=EXPECTED_HASHES["train"],
        expected_records=7560,
        expected_candidates=126,
    )
    validation_dataset = load_ranking_dataset(
        paths["validation"],
        split="validation",
        expected_sha256=EXPECTED_HASHES["validation"],
        expected_records=1620,
        expected_candidates=27,
    )
    _validate_datasets(train_dataset, validation_dataset)

    model = fit_initial_ranker(train_dataset, validation_dataset)
    if model.get_booster().num_boosted_rounds() != 300:
        raise ValueError("Initial model must contain exactly 300 boosted rounds.")
    train_scores = np.asarray(model.predict(train_dataset.X), dtype=np.float32)
    validation_scores = np.asarray(model.predict(validation_dataset.X), dtype=np.float32)
    train_predictions = _prediction_records(train_dataset, train_scores)
    validation_predictions = _prediction_records(
        validation_dataset,
        validation_scores,
    )
    metrics = _metrics_artifact(
        train_records=train_predictions,
        validation_records=validation_predictions,
        train_scores=train_scores,
        validation_scores=validation_scores,
        baseline_metrics=baseline_metrics,
    )
    history = _training_history(model)

    output_dir = Path(args.output_dir).resolve()
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    staging_dir = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}-stage-", dir=output_dir.parent))
    try:
        model_path = staging_dir / "model.json"
        model.save_model(model_path)
        if model_path.stat().st_size == 0:
            raise ValueError("Serialized model is empty.")
        _read_json(model_path)
        maximum_error, rank_agreement = _round_trip(
            model_path=model_path,
            train=train_dataset,
            validation=validation_dataset,
            train_scores=train_scores,
            validation_scores=validation_scores,
            train_records=train_predictions,
            validation_records=validation_predictions,
        )
        metadata = ModelMetadata(
            model_version=MODEL_VERSION,
            training_pipeline_version=TRAINING_PIPELINE_VERSION,
            training_config_version=TRAINING_CONFIG_VERSION,
            model_format=MODEL_FORMAT,
            objective="rank:ndcg",
            hyperparameters=FIXED_HYPERPARAMETERS,
            training_seed=TRAINING_SEED,
            deterministic=True,
            device="cpu",
            thread_count=1,
            xgboost_version=XGBOOST_VERSION,
            numpy_version=NUMPY_VERSION,
            scipy_version=SCIPY_VERSION,
            python_version=platform.python_version(),
            feature_schema_version=FEATURE_SCHEMA_VERSION,
            feature_schema_sha256=EXPECTED_HASHES["feature_schema"],
            feature_count=FEATURE_COUNT,
            feature_names=list(feature_schema["feature_names"]),
            split_version="candidate-group-split-v1",
            train_candidate_count=126,
            train_record_count=7560,
            validation_candidate_count=27,
            validation_record_count=1620,
            source_revision=args.source_revision,
            architecture_sha256=args.architecture_sha256,
            training_release_date=TRAINING_RELEASE_DATE,
            model_file="model.json",
            model_sha256=sha256_file(model_path),
            model_size_bytes=model_path.stat().st_size,
            early_stopping_used=False,
            hyperparameter_tuning_used=False,
            test_evaluated=False,
            test_records_parsed=False,
            round_trip_max_absolute_error=maximum_error,
            round_trip_rank_agreement=rank_agreement,
        )
        _write_text(
            staging_dir / "model_metadata.json",
            _json_content(metadata.model_dump(mode="json")),
        )
        _write_text(
            staging_dir / "train_predictions.jsonl",
            _prediction_content(train_predictions),
        )
        _write_text(
            staging_dir / "validation_predictions.jsonl",
            _prediction_content(validation_predictions),
        )
        _write_text(staging_dir / "metrics.json", _json_content(metrics))
        _write_text(staging_dir / "training_history.json", _json_content(history))
        _write_text(staging_dir / "MODEL_CARD.md", _model_card(metrics, metadata))

        manifest = TrainingManifest(
            model_version=MODEL_VERSION,
            training_pipeline_version=TRAINING_PIPELINE_VERSION,
            training_config_version=TRAINING_CONFIG_VERSION,
            model_format=MODEL_FORMAT,
            training_seed=TRAINING_SEED,
            training_release_date=TRAINING_RELEASE_DATE,
            deterministic=True,
            source_revision=args.source_revision,
            architecture_sha256=args.architecture_sha256,
            source_dataset_version="synthetic-job-rec-1.0.0",
            feature_schema_version=FEATURE_SCHEMA_VERSION,
            feature_pipeline_version="0.1.0",
            split_version="candidate-group-split-v1",
            baseline_evaluation_version="job-rec-baselines-v1",
            dependencies={
                "numpy": np.__version__,
                "python": platform.python_version(),
                "scipy": scipy.__version__,
                "xgboost": xgboost.__version__,
            },
            source_files=_source_artifacts(paths),
            training_contract={
                "candidate_count": 126,
                "record_count": 7560,
                "feature_count": FEATURE_COUNT,
                "records_per_group": 60,
                "fit_source": "train_only",
                "qid_policy": "candidate_id_ascending_zero_based_contiguous",
            },
            validation_contract={
                "candidate_count": 27,
                "record_count": 1620,
                "feature_count": FEATURE_COUNT,
                "records_per_group": 60,
                "usage": "evaluation_history_only",
            },
            test_lock_verification={
                "created_for_phase": test_lock["created_for_phase"],
                "locked": True,
                "metrics_run": False,
                "predictions_run": False,
                "prohibited_before_phase": test_lock["prohibited_before_phase"],
                "records_parsed": False,
                "sha256": LOCKED_TEST_SHA256,
            },
            hyperparameters=FIXED_HYPERPARAMETERS,
            output_files=_output_artifacts(staging_dir),
            intended_use=[
                "offline_initial_ranking_model",
                "phase_9_bounded_tuning_reference",
                "human_assisted_recruitment_research",
            ],
            limitations=[
                "synthetic_training_data",
                "handcrafted_features",
                "initial_fixed_configuration",
                "validation_has_27_candidate_groups",
                "no_hyperparameter_tuning",
                "locked_test_not_evaluated",
                "no_fairness_guarantee",
                "no_production_quality_guarantee",
                "dependency_and_platform_reproducibility_boundary",
            ],
        )
        _write_text(
            staging_dir / "manifest.json",
            _json_content(manifest.model_dump(mode="json")),
        )
        if set(path.name for path in staging_dir.iterdir()) != {
            *OUTPUT_RECORD_COUNTS,
            "manifest.json",
        }:
            raise ValueError("Unexpected or missing model artifact.")
        _publish_directory(staging_dir, output_dir)
    except Exception:
        if staging_dir.exists():
            shutil.rmtree(staging_dir)
        raise

    validation_metrics = metrics["validation"]["xgbranker"]
    matching_deltas = metrics["validation"]["deltas"]["vs_matching_2_0"]
    return {
        "model_version": MODEL_VERSION,
        "train_record_count": train_dataset.record_count,
        "validation_record_count": validation_dataset.record_count,
        "validation_ndcg_at_5": validation_metrics["NDCG@5"]["macro_mean"],
        "validation_ndcg_at_10": validation_metrics["NDCG@10"]["macro_mean"],
        "delta_ndcg_at_5_vs_matching": matching_deltas["NDCG@5"],
        "delta_ndcg_at_10_vs_matching": matching_deltas["NDCG@10"],
        "model_path": str(output_dir / "model.json"),
        "test_evaluated": False,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Train the one fixed initial XGBRanker on Train and Validation only.",
    )
    parser.add_argument("--train-file", required=True)
    parser.add_argument("--validation-file", required=True)
    parser.add_argument("--feature-schema-file", required=True)
    parser.add_argument("--split-manifest", required=True)
    parser.add_argument("--test-lock-file", required=True)
    parser.add_argument("--baseline-metrics-file", required=True)
    parser.add_argument("--baseline-manifest-file", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--model-version", required=True)
    parser.add_argument("--training-config-version", required=True)
    parser.add_argument("--seed", required=True, type=int)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    summary = train(build_parser().parse_args(argv))
    print(
        f"Model {summary['model_version']}; "
        f"Train {summary['train_record_count']} / "
        f"Validation {summary['validation_record_count']}; "
        f"Validation NDCG@5={summary['validation_ndcg_at_5']:.6f}, "
        f"NDCG@10={summary['validation_ndcg_at_10']:.6f}; "
        f"deltas vs Matching 2.0={summary['delta_ndcg_at_5_vs_matching']:+.6f}/"
        f"{summary['delta_ndcg_at_10_vs_matching']:+.6f}; "
        f"model={summary['model_path']}; Test evaluated=false."
    )
    return 0
