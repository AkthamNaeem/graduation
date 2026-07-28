"""Deterministic Phase 9 bounded tuning, verification, and publication."""

from __future__ import annotations

import argparse
import json
import platform
import shutil
import tempfile
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final, Literal

import numpy as np
import scipy  # type: ignore[import-untyped]
import xgboost

from smart_recruitment_ml.baselines.metrics import RELEVANCE_THRESHOLD, evaluate_rankings
from smart_recruitment_ml.schemas.tuning import (
    FinalPrediction,
    SelectedValidationPrediction,
    TunedModelMetadata,
    TuningTrial,
)
from smart_recruitment_ml.training.dataset import (
    FEATURE_COUNT,
    FEATURE_SCHEMA_VERSION,
    LOCKED_TEST_SHA256,
    RankingDataset,
    load_ranking_dataset,
    reject_locked_test,
    sha256_file,
)
from smart_recruitment_ml.training.trainer import (
    ARCHITECTURE_SHA256,
    EXPECTED_HASHES,
    METRIC_NAMES,
    NUMPY_VERSION,
    SCIPY_VERSION,
    SOURCE_REVISION,
    XGBOOST_VERSION,
    _baseline_split,
    _prediction_statistics,
    _publish_directory,
    _validate_datasets,
    _validate_inputs,
)
from smart_recruitment_ml.training.xgbranker import create_ranker

from . import (
    FINAL_TRAINING_CONTRACT,
    SELECTION_POLICY_VERSION,
    TUNED_MODEL_VERSION,
    TUNING_PIPELINE_VERSION,
    TUNING_RELEASE_DATE,
    TUNING_RUN_VERSION,
    TUNING_SEED,
    TUNING_SPACE_VERSION,
)
from .search_space import search_space, validate_search_space
from .selection import TIE_TOLERANCE, rank_trials, select_trial

if TYPE_CHECKING:
    from collections.abc import Iterable, Sequence

    from numpy.typing import NDArray
    from xgboost import XGBRanker

MODEL_FORMAT: Final = "xgboost-json-v1"
INITIAL_MODEL_VERSION: Final = "xgbranker-initial-v1"
SOURCE_DATASET_VERSION: Final = "synthetic-job-rec-1.0.0"
FEATURE_PIPELINE_VERSION: Final = "0.1.0"
SPLIT_VERSION: Final = "candidate-group-split-v1"
BASELINE_EVALUATION_VERSION: Final = "job-rec-baselines-v1"
INITIAL_HASHES: Final = {
    "model.json": "14064405827cb46ff475236e25d4fc6306b3a33278217ba77c6bf3f57fa2b789",
    "model_metadata.json": "8f02244ef31ff6b74a22d7451069bf2aefc7e7d30b1fe5d87d258e7679715b71",
    "train_predictions.jsonl": "a2de6137a9b89d44d8997e00749f3e72eb13692b07b620104b4cde3fa1225897",
    "validation_predictions.jsonl": (
        "9ff94d6a74d29a9dce4bb6521c352f296ccb5f72751b3b95635725028dff948b"
    ),
    "metrics.json": "c487fa5446cec543df19a7542165a70b760bb02bf5f938e9c2e859089deed1fe",
    "training_history.json": ("34be7f56dc8493375b8e291aed216b9ef91ff9830723d27ae0782e2e27f52e72"),
    "manifest.json": "1d94c2ca7895636889613edd642b1e3b282654b7c84455db95c4f5b829eaf4ed",
    "MODEL_CARD.md": "628de4d0b5d470a9196fa292ee72a9e95bfbe09f04e4e98d6e5166cd4dd331f0",
}
OUTPUT_COUNTS: Final = {
    "model.json": 1,
    "model_metadata.json": 1,
    "tuning_trials.jsonl": 8,
    "selection_metrics.json": 1,
    "selected_config.json": 1,
    "selected_validation_predictions.jsonl": 1620,
    "final_train_validation_predictions.jsonl": 9180,
    "MODEL_CARD.md": 1,
}


@dataclass(frozen=True)
class CombinedDataset:
    pair_ids: tuple[str, ...]
    candidate_ids: tuple[str, ...]
    job_ids: tuple[str, ...]
    source_splits: tuple[Literal["train", "validation"], ...]
    X: NDArray[np.float32]
    y: NDArray[np.float32]
    qid: NDArray[np.int32]
    group_sizes: tuple[int, ...]

    @property
    def record_count(self) -> int:
        return len(self.pair_ids)

    @property
    def candidate_count(self) -> int:
        return len(self.group_sizes)


def _read_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"JSON object expected: {path}")
    return value


def _json_content(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def _jsonl_content(records: Iterable[Any]) -> str:
    return "".join(
        json.dumps(
            record.model_dump(mode="json") if hasattr(record, "model_dump") else record,
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        )
        + "\n"
        for record in records
    )


def _write_text(path: Path, value: str) -> None:
    path.write_text(value, encoding="utf-8", newline="\n")


def _fit_ranker(
    hyperparameters: dict[str, Any],
    X: NDArray[np.float32],
    y: NDArray[np.float32],
    qid: NDArray[np.int32],
) -> XGBRanker:
    model = create_ranker()
    model.set_params(**hyperparameters)
    model.fit(X, y, qid=qid, verbose=False)
    return model


def _rank_values(
    candidate_ids: Sequence[str],
    job_ids: Sequence[str],
    pair_ids: Sequence[str],
    scores: NDArray[np.float32],
) -> list[int]:
    if scores.shape != (len(pair_ids),) or not np.all(np.isfinite(scores)):
        raise ValueError("Predictions are incomplete or non-finite.")
    if float(np.var(scores, dtype=np.float64)) == 0.0 or np.unique(scores).size == 1:
        raise ValueError("Predictions must have non-zero variance.")
    by_candidate: dict[str, list[int]] = defaultdict(list)
    for index, candidate_id in enumerate(candidate_ids):
        by_candidate[candidate_id].append(index)
    ranks = [0] * len(pair_ids)
    for candidate_id in sorted(by_candidate):
        indexes = by_candidate[candidate_id]
        if len(indexes) != 60:
            raise ValueError("Every Candidate group must contain 60 records.")
        ordered = sorted(
            indexes,
            key=lambda index: (-float(scores[index]), job_ids[index], pair_ids[index]),
        )
        for rank, index in enumerate(ordered, start=1):
            ranks[index] = rank
    if any(rank == 0 for rank in ranks):
        raise ValueError("Prediction rank coverage is incomplete.")
    return ranks


def _metrics(
    dataset: RankingDataset,
    scores: NDArray[np.float32],
) -> dict[str, Any]:
    ranks = _rank_values(
        dataset.candidate_ids,
        dataset.job_ids,
        dataset.pair_ids,
        scores,
    )
    by_candidate: dict[str, list[int]] = defaultdict(list)
    for index, candidate_id in enumerate(dataset.candidate_ids):
        by_candidate[candidate_id].append(index)
    ranked_labels = [
        [
            int(dataset.y[index])
            for index in sorted(
                by_candidate[candidate_id],
                key=lambda index: (
                    ranks[index],
                    dataset.job_ids[index],
                    dataset.pair_ids[index],
                ),
            )
        ]
        for candidate_id in sorted(by_candidate)
    ]
    return evaluate_rankings(ranked_labels).model_dump(mode="json", by_alias=True)


def _selected_predictions(
    dataset: RankingDataset,
    scores: NDArray[np.float32],
    config_id: str,
) -> list[SelectedValidationPrediction]:
    ranks = _rank_values(
        dataset.candidate_ids,
        dataset.job_ids,
        dataset.pair_ids,
        scores,
    )
    records = [
        SelectedValidationPrediction(
            pair_id=dataset.pair_ids[index],
            candidate_id=dataset.candidate_ids[index],
            job_id=dataset.job_ids[index],
            relevance_label=int(dataset.y[index]),
            prediction_score=float(scores[index]),
            rank=ranks[index],
            config_id=config_id,
            tuning_run_version=TUNING_RUN_VERSION,
            feature_schema_version=FEATURE_SCHEMA_VERSION,
        )
        for index in range(dataset.record_count)
    ]
    records.sort(key=lambda record: (record.candidate_id, record.job_id, record.pair_id))
    if len({record.pair_id for record in records}) != dataset.record_count:
        raise ValueError("Selected Validation prediction Pair IDs must be unique.")
    return records


def combine_datasets(train: RankingDataset, validation: RankingDataset) -> CombinedDataset:
    if set(train.candidate_ids).intersection(validation.candidate_ids):
        raise ValueError("Train/Validation Candidate overlap detected.")
    rows = [
        (
            train.candidate_ids[index],
            train.job_ids[index],
            train.pair_ids[index],
            "train",
            train.X[index],
            train.y[index],
        )
        for index in range(train.record_count)
    ]
    rows.extend(
        (
            validation.candidate_ids[index],
            validation.job_ids[index],
            validation.pair_ids[index],
            "validation",
            validation.X[index],
            validation.y[index],
        )
        for index in range(validation.record_count)
    )
    rows.sort(key=lambda row: (row[0], row[1], row[2]))
    pair_ids = tuple(str(row[2]) for row in rows)
    candidate_ids = tuple(str(row[0]) for row in rows)
    job_ids = tuple(str(row[1]) for row in rows)
    source_splits = tuple(row[3] for row in rows)
    if len(set(pair_ids)) != 9180:
        raise ValueError("Combined Pair IDs must be unique.")
    candidate_order = sorted(set(candidate_ids))
    candidate_to_qid = {candidate_id: index for index, candidate_id in enumerate(candidate_order)}
    qid = np.asarray([candidate_to_qid[value] for value in candidate_ids], dtype=np.int32)
    group_sizes = tuple(
        int(np.count_nonzero(qid == group_id)) for group_id in range(len(candidate_order))
    )
    X = np.asarray([row[4] for row in rows], dtype=np.float32)
    y = np.asarray([row[5] for row in rows], dtype=np.float32)
    if (
        X.shape != (9180, FEATURE_COUNT)
        or y.shape != (9180,)
        or len(candidate_order) != 153
        or set(group_sizes) != {60}
        or tuple(np.unique(qid).tolist()) != tuple(range(153))
        or np.any(np.diff(qid) < 0)
    ):
        raise ValueError("Combined Train+Validation contract mismatch.")
    return CombinedDataset(
        pair_ids=pair_ids,
        candidate_ids=candidate_ids,
        job_ids=job_ids,
        source_splits=source_splits,  # type: ignore[arg-type]
        X=X,
        y=y,
        qid=qid,
        group_sizes=group_sizes,
    )


def _final_predictions(
    dataset: CombinedDataset,
    scores: NDArray[np.float32],
) -> list[FinalPrediction]:
    ranks = _rank_values(
        dataset.candidate_ids,
        dataset.job_ids,
        dataset.pair_ids,
        scores,
    )
    records = [
        FinalPrediction(
            pair_id=dataset.pair_ids[index],
            candidate_id=dataset.candidate_ids[index],
            job_id=dataset.job_ids[index],
            relevance_label=int(dataset.y[index]),
            prediction_score=float(scores[index]),
            rank=ranks[index],
            model_version=TUNED_MODEL_VERSION,
            feature_schema_version=FEATURE_SCHEMA_VERSION,
            source_split=dataset.source_splits[index],
        )
        for index in range(dataset.record_count)
    ]
    if len({record.pair_id for record in records}) != 9180:
        raise ValueError("Final prediction Pair IDs must be unique.")
    return records


def _metric_means(metrics: dict[str, Any]) -> dict[str, float]:
    return {metric: float(metrics[metric]["macro_mean"]) for metric in METRIC_NAMES}


def _metric_deltas(left: dict[str, Any], right: dict[str, Any]) -> dict[str, float]:
    return {
        metric: float(left[metric]["macro_mean"] - right[metric]["macro_mean"])
        for metric in METRIC_NAMES
    }


def _maximum_numeric_error(left: Any, right: Any) -> float:
    if isinstance(left, dict) and isinstance(right, dict):
        if set(left) != set(right):
            raise ValueError("Control metric key mismatch.")
        return max((_maximum_numeric_error(left[key], right[key]) for key in left), default=0.0)
    if isinstance(left, (int, float)) and isinstance(right, (int, float)):
        return abs(float(left) - float(right))
    if left != right:
        raise ValueError("Control metric value mismatch.")
    return 0.0


def _validate_initial_model(initial_dir: Path) -> None:
    if not initial_dir.is_dir() or {path.name for path in initial_dir.iterdir()} != set(
        INITIAL_HASHES
    ):
        raise ValueError("Initial Model artifact set mismatch.")
    for name, expected in INITIAL_HASHES.items():
        actual = sha256_file(initial_dir / name)
        if actual != expected:
            raise ValueError(f"Initial Model {name} SHA-256 mismatch.")


def _control_reproduction(
    *,
    model_path: Path,
    initial_dir: Path,
    validation_records: Sequence[SelectedValidationPrediction],
    validation_metrics: dict[str, Any],
) -> dict[str, Any]:
    model_hash_match = sha256_file(model_path) == INITIAL_HASHES["model.json"]
    initial_records = [
        json.loads(line)
        for line in (initial_dir / "validation_predictions.jsonl")
        .read_text(encoding="utf-8")
        .splitlines()
    ]
    if len(initial_records) != len(validation_records):
        raise ValueError("Initial Validation prediction count mismatch.")
    initial_by_pair = {str(record["pair_id"]): record for record in initial_records}
    prediction_error = 0.0
    rank_matches = 0
    for record in validation_records:
        initial = initial_by_pair.get(record.pair_id)
        if initial is None:
            raise ValueError("Initial Validation prediction Pair ID mismatch.")
        prediction_error = max(
            prediction_error,
            abs(record.prediction_score - float(initial["prediction_score"])),
        )
        rank_matches += record.rank == int(initial["rank"])
    rank_agreement = rank_matches / len(validation_records)
    initial_metrics = _read_json(initial_dir / "metrics.json")["validation"]["xgbranker"]
    metric_error = _maximum_numeric_error(validation_metrics, initial_metrics)
    passed = (
        model_hash_match
        and prediction_error <= 1e-12
        and rank_agreement == 1.0
        and metric_error <= 1e-12
    )
    result = {
        "metric_max_absolute_error": metric_error,
        "model_sha256_identical": model_hash_match,
        "passed": passed,
        "validation_prediction_max_absolute_error": prediction_error,
        "validation_rank_agreement": rank_agreement,
    }
    if not passed:
        raise ValueError("T00 control reproduction failed.")
    return result


def _round_trip(
    model_path: Path,
    dataset: CombinedDataset,
    expected_scores: NDArray[np.float32],
    expected_records: Sequence[FinalPrediction],
) -> tuple[float, float]:
    loaded = create_ranker()
    loaded.load_model(model_path)
    actual_scores = np.asarray(loaded.predict(dataset.X), dtype=np.float32)
    maximum_error = float(
        np.max(np.abs(expected_scores.astype(np.float64) - actual_scores.astype(np.float64)))
    )
    actual_records = _final_predictions(dataset, actual_scores)
    actual_ranks = {record.pair_id: record.rank for record in actual_records}
    rank_agreement = sum(
        actual_ranks[record.pair_id] == record.rank for record in expected_records
    ) / len(expected_records)
    if (
        maximum_error > 1e-12
        or rank_agreement != 1.0
        or loaded.get_booster().num_features() != FEATURE_COUNT
    ):
        raise ValueError("Final model round-trip verification failed.")
    return maximum_error, rank_agreement


def _source_files(paths: dict[str, Path], initial_dir: Path) -> list[dict[str, Any]]:
    definitions = (
        ("feature_schema", "data/features/v1/feature_schema.json", 1, True),
        ("train", "data/splits/v1/train.jsonl", 7560, True),
        ("validation", "data/splits/v1/validation.jsonl", 1620, True),
        ("assignments", "data/splits/v1/assignments.jsonl", 180, False),
        ("split_manifest", "data/splits/v1/manifest.json", 1, True),
        ("test_lock", "data/splits/v1/test_lock.json", 1, True),
        ("baseline_metrics", "data/baselines/v1/metrics.json", 1, True),
        ("baseline_manifest", "data/baselines/v1/manifest.json", 1, True),
    )
    values = [
        {
            "path": path,
            "record_count": count,
            "records_parsed": parsed,
            "sha256": EXPECTED_HASHES[key],
            "size_bytes": paths[key].stat().st_size,
            "usage": "parsed_source" if parsed else "hash_verification_only",
        }
        for key, path, count, parsed in definitions
    ]
    test_path = paths["test_lock"].with_name("test.jsonl")
    values.append(
        {
            "path": "data/splits/v1/test.jsonl",
            "record_count": 1620,
            "records_parsed": False,
            "sha256": LOCKED_TEST_SHA256,
            "size_bytes": test_path.stat().st_size,
            "usage": "hash_verification_only",
        }
    )
    values.extend(
        {
            "path": f"data/models/initial/v1/{name}",
            "record_count": 1,
            "records_parsed": name in {"metrics.json", "validation_predictions.jsonl"},
            "sha256": digest,
            "size_bytes": (initial_dir / name).stat().st_size,
            "usage": "control_reproduction",
        }
        for name, digest in sorted(INITIAL_HASHES.items())
    )
    return values


def _output_files(staging_dir: Path) -> list[dict[str, Any]]:
    return [
        {
            "path": name,
            "record_count": count,
            "sha256": sha256_file(staging_dir / name),
            "size_bytes": (staging_dir / name).stat().st_size,
        }
        for name, count in OUTPUT_COUNTS.items()
    ]


def _model_card(
    *,
    selected: dict[str, Any],
    baseline_metrics: dict[str, Any],
    initial_metrics: dict[str, Any],
) -> str:
    selected_metrics = selected["validation_metrics"]
    skills, matching = _baseline_split(baseline_metrics, "validation")

    def row(label: str, metrics: dict[str, Any]) -> str:
        values = " | ".join(f"{metrics[name]['macro_mean']:.12f}" for name in METRIC_NAMES)
        return f"| {label} | {values} |"

    table = "\n".join(
        (
            "| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |",
            "|---|---:|---:|---:|---:|---:|---:|",
            row("Skills-only", skills),
            row("Matching 2.0", matching),
            row("Initial XGBRanker", initial_metrics),
            row(f"Selected Trial ({selected['config_id']})", selected_metrics),
        )
    )
    parameters = "\n".join(
        f"- `{key}`: `{json.dumps(value, separators=(',', ':'))}`"
        for key, value in selected["hyperparameters"].items()
    )
    return f"""# Tuned XGBRanker Model Card

## Model

- Name/version: `{TUNED_MODEL_VERSION}`
- Format: `{MODEL_FORMAT}`
- Status: tuned candidate model only; not production-ready
- Bounded tuning run: `{TUNING_RUN_VERSION}`
- Selected configuration: `{selected["config_id"]}`

Phase 9 evaluated exactly eight fixed configurations, T00 through T07. T00 exactly
reproduced the Phase 8 Initial Model. Selection used Validation NDCG@10, then ties
within `1e-12` used Validation NDCG@5, Validation MRR, fewer estimators, lower depth,
and lexicographic configuration ID.

## Selected hyperparameters

{parameters}

## Validation comparison

{table}

The selected deltas versus Initial XGBRanker are
`{_metric_deltas(selected_metrics, initial_metrics)}` and versus Matching 2.0 are
`{_metric_deltas(selected_metrics, matching)}`.

## Final retraining

The final candidate model was fitted once on Train + Validation: 153 Candidate groups,
9,180 records, 60 records per group, and 103 handcrafted features. There was no eval
set, early stopping, cross-validation, SHAP, calibration, threshold selection, or Test
use in final retraining.

## Intended use and limitations

- Offline research and Phase 10 locked final evaluation only.
- AI is decision support and is not the decision-maker.
- Synthetic data and handcrafted features may not represent production behavior.
- Validation contains only 27 Candidate groups and selection may overfit it.
- The fixed bounded search does not establish global optimality.
- Reproducibility is bounded by the pinned dependency versions and platform.
- There is no fairness guarantee and no production-quality guarantee.
- It must not automatically accept or reject Candidates.
- The Locked Test was not parsed, predicted, or evaluated; Phase 10 alone owns it.
- No configuration may be changed after observing the Phase 10 Test result.
"""


def _validate_versions_and_args(args: argparse.Namespace) -> None:
    if (
        args.tuning_run_version != TUNING_RUN_VERSION
        or args.tuned_model_version != TUNED_MODEL_VERSION
        or args.seed != TUNING_SEED
        or args.source_revision != SOURCE_REVISION
        or args.architecture_sha256 != ARCHITECTURE_SHA256
    ):
        raise ValueError("Locked Phase 9 version, seed, or provenance argument mismatch.")
    if (
        np.__version__ != NUMPY_VERSION
        or scipy.__version__ != SCIPY_VERSION
        or xgboost.__version__ != XGBOOST_VERSION
        or platform.python_version() != "3.12.10"
    ):
        raise ValueError("Pinned Phase 9 runtime version mismatch.")


def tune(args: argparse.Namespace) -> dict[str, Any]:  # pragma: no cover
    """Run the expensive end-to-end pipeline, verified by two real CLI executions."""
    _validate_versions_and_args(args)
    validate_search_space()
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
    reject_locked_test(paths["train"])
    reject_locked_test(paths["validation"])
    feature_schema, _split_manifest, test_lock, baseline_metrics = _validate_inputs(
        paths=paths,
        source_revision=args.source_revision,
        architecture_sha256=args.architecture_sha256,
    )
    repository_root = Path(__file__).resolve().parents[5]
    architecture_path = repository_root / "docs/ml-job-recommendation/ARCHITECTURE.md"
    if sha256_file(architecture_path).upper() != ARCHITECTURE_SHA256:
        raise ValueError("Architecture source SHA-256 mismatch.")
    initial_dir = Path(args.initial_model_dir).resolve()
    _validate_initial_model(initial_dir)
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
    combined = combine_datasets(train_dataset, validation_dataset)

    output_dir = Path(args.output_dir).resolve()
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    staging_dir = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}-stage-", dir=output_dir.parent))
    try:
        trials: list[dict[str, Any]] = []
        validation_scores_by_id: dict[str, NDArray[np.float32]] = {}
        control: dict[str, Any] | None = None
        for config in search_space():
            config_id = str(config["config_id"])
            hyperparameters = dict(config["hyperparameters"])
            model = _fit_ranker(
                hyperparameters,
                train_dataset.X,
                train_dataset.y,
                train_dataset.qid,
            )
            train_scores = np.asarray(model.predict(train_dataset.X), dtype=np.float32)
            validation_scores = np.asarray(
                model.predict(validation_dataset.X),
                dtype=np.float32,
            )
            train_metrics = _metrics(train_dataset, train_scores)
            validation_metrics = _metrics(validation_dataset, validation_scores)
            validation_scores_by_id[config_id] = validation_scores
            trial = {
                "config_id": config_id,
                "control_trial": config_id == "T00",
                "hyperparameters": hyperparameters,
                "selected": False,
                "selection_rank": 0,
                "train_metrics": train_metrics,
                "train_prediction_statistics": _prediction_statistics(train_scores),
                "validation_metrics": validation_metrics,
                "validation_prediction_statistics": _prediction_statistics(validation_scores),
            }
            trials.append(trial)
            if config_id == "T00":
                control_path = staging_dir / ".T00-control.json"
                model.save_model(control_path)
                control_records = _selected_predictions(
                    validation_dataset,
                    validation_scores,
                    config_id,
                )
                control = _control_reproduction(
                    model_path=control_path,
                    initial_dir=initial_dir,
                    validation_records=control_records,
                    validation_metrics=validation_metrics,
                )
                control_path.unlink()
        if control is None or control["passed"] is not True:
            raise ValueError("T00 control reproduction did not run.")

        selected, trace = select_trial(trials)
        ranked = rank_trials(trials)
        rank_by_id = {str(trial["config_id"]): index for index, trial in enumerate(ranked, start=1)}
        for trial in trials:
            trial["selection_rank"] = rank_by_id[str(trial["config_id"])]
            trial["selected"] = trial is selected
        selected_id = str(selected["config_id"])
        selected_validation_records = _selected_predictions(
            validation_dataset,
            validation_scores_by_id[selected_id],
            selected_id,
        )

        final_model = _fit_ranker(
            dict(selected["hyperparameters"]),
            combined.X,
            combined.y,
            combined.qid,
        )
        model_path = staging_dir / "model.json"
        final_model.save_model(model_path)
        final_scores = np.asarray(final_model.predict(combined.X), dtype=np.float32)
        final_records = _final_predictions(combined, final_scores)
        round_trip_error, rank_agreement = _round_trip(
            model_path,
            combined,
            final_scores,
            final_records,
        )

        initial_metrics_artifact = _read_json(initial_dir / "metrics.json")
        initial_validation_metrics = initial_metrics_artifact["validation"]["xgbranker"]
        skills_metrics, matching_metrics = _baseline_split(baseline_metrics, "validation")
        deltas_vs_initial = _metric_deltas(
            selected["validation_metrics"],
            initial_validation_metrics,
        )
        deltas_vs_matching = _metric_deltas(
            selected["validation_metrics"],
            matching_metrics,
        )
        trials_for_output = [
            TuningTrial.model_validate(trial).model_dump(mode="json")
            for trial in sorted(trials, key=lambda value: str(value["config_id"]))
        ]
        _write_text(staging_dir / "tuning_trials.jsonl", _jsonl_content(trials_for_output))
        _write_text(
            staging_dir / "selected_validation_predictions.jsonl",
            _jsonl_content(selected_validation_records),
        )
        _write_text(
            staging_dir / "final_train_validation_predictions.jsonl",
            _jsonl_content(final_records),
        )
        selected_config = {
            "delta_vs_initial": deltas_vs_initial,
            "delta_vs_matching_2_0": deltas_vs_matching,
            "initial_control_metrics": _metric_means(initial_validation_metrics),
            "selected_config_id": selected_id,
            "selected_hyperparameters": selected["hyperparameters"],
            "selection_metrics": {
                "validation_mrr": selected["validation_metrics"]["MRR"]["macro_mean"],
                "validation_ndcg_at_10": selected["validation_metrics"]["NDCG@10"]["macro_mean"],
                "validation_ndcg_at_5": selected["validation_metrics"]["NDCG@5"]["macro_mean"],
            },
            "selection_policy_version": SELECTION_POLICY_VERSION,
            "selection_tie_break_trace": trace,
            "test_evaluated": False,
            "trial_count": 8,
            "tuning_run_version": TUNING_RUN_VERSION,
            "tuning_space_version": TUNING_SPACE_VERSION,
        }
        _write_text(staging_dir / "selected_config.json", _json_content(selected_config))
        selection_metrics = {
            "aggregation": "candidate_macro",
            "control_reproduction": control,
            "deltas": {
                "vs_initial": deltas_vs_initial,
                "vs_matching_2_0": deltas_vs_matching,
            },
            "gain_definition": "2^relevance_label - 1",
            "initial_model_validation_metrics": initial_validation_metrics,
            "matching_2_0_validation_metrics": matching_metrics,
            "relevant_label_threshold": RELEVANCE_THRESHOLD,
            "selected_trial": {
                "config_id": selected_id,
                "selection_rank": 1,
            },
            "selected_trial_train_metrics": selected["train_metrics"],
            "selected_trial_validation_metrics": selected["validation_metrics"],
            "selection_policy_version": SELECTION_POLICY_VERSION,
            "skills_validation_metrics": skills_metrics,
            "trials_summary": [
                {
                    "config_id": trial["config_id"],
                    "selected": trial["selected"],
                    "selection_rank": trial["selection_rank"],
                    "validation_metrics": _metric_means(trial["validation_metrics"]),
                }
                for trial in trials
            ],
            "tuning_run_version": TUNING_RUN_VERSION,
        }
        _write_text(staging_dir / "selection_metrics.json", _json_content(selection_metrics))
        metadata_values = {
            "model_version": TUNED_MODEL_VERSION,
            "model_format": MODEL_FORMAT,
            "tuning_run_version": TUNING_RUN_VERSION,
            "tuning_pipeline_version": TUNING_PIPELINE_VERSION,
            "tuning_space_version": TUNING_SPACE_VERSION,
            "selection_policy_version": SELECTION_POLICY_VERSION,
            "final_training_contract": FINAL_TRAINING_CONTRACT,
            "selected_config_id": selected_id,
            "hyperparameters": selected["hyperparameters"],
            "training_seed": TUNING_SEED,
            "deterministic": True,
            "device": "cpu",
            "thread_count": 1,
            "python_version": platform.python_version(),
            "numpy_version": np.__version__,
            "scipy_version": scipy.__version__,
            "xgboost_version": xgboost.__version__,
            "feature_schema_version": FEATURE_SCHEMA_VERSION,
            "feature_schema_sha256": EXPECTED_HASHES["feature_schema"],
            "feature_count": FEATURE_COUNT,
            "feature_names": list(feature_schema["feature_names"]),
            "split_version": SPLIT_VERSION,
            "train_candidate_count": 126,
            "validation_candidate_count": 27,
            "final_candidate_count": 153,
            "train_record_count": 7560,
            "validation_record_count": 1620,
            "final_record_count": 9180,
            "model_file": "model.json",
            "model_sha256": sha256_file(model_path),
            "model_size_bytes": model_path.stat().st_size,
            "source_revision": args.source_revision,
            "architecture_sha256": args.architecture_sha256,
            "tuning_release_date": TUNING_RELEASE_DATE,
            "control_reproduction_passed": True,
            "round_trip_max_absolute_error": round_trip_error,
            "round_trip_rank_agreement": rank_agreement,
            "early_stopping_used": False,
            "cross_" + "validation_used": False,
            "test_evaluated": False,
            "test_records_parsed": False,
        }
        metadata = TunedModelMetadata.model_validate(metadata_values)
        _write_text(
            staging_dir / "model_metadata.json",
            _json_content(metadata.model_dump(mode="json")),
        )
        _write_text(
            staging_dir / "MODEL_CARD.md",
            _model_card(
                selected=selected,
                baseline_metrics=baseline_metrics,
                initial_metrics=initial_validation_metrics,
            ),
        )
        manifest = {
            "architecture_sha256": args.architecture_sha256,
            "baseline_evaluation_version": BASELINE_EVALUATION_VERSION,
            "dependencies": {
                "numpy": np.__version__,
                "python": platform.python_version(),
                "scipy": scipy.__version__,
                "xgboost": xgboost.__version__,
            },
            "deterministic": True,
            "feature_pipeline_version": FEATURE_PIPELINE_VERSION,
            "feature_schema_version": FEATURE_SCHEMA_VERSION,
            "final_training_contract": {
                "candidate_count": 153,
                "contract_version": FINAL_TRAINING_CONTRACT,
                "early_stopping_used": False,
                "eval_set_used": False,
                "feature_count": FEATURE_COUNT,
                "fit_source": "train_plus_validation",
                "record_count": 9180,
            },
            "initial_model_version": INITIAL_MODEL_VERSION,
            "intended_use": [
                "phase_10_locked_final_evaluation_candidate",
                "offline_human_assisted_recruitment_research",
            ],
            "limitations": [
                "synthetic_training_data",
                "handcrafted_features",
                "validation_has_27_candidate_groups",
                "fixed_bounded_search",
                "validation_selection_overfit_risk",
                "dependency_and_platform_reproducibility_boundary",
                "locked_test_not_evaluated",
                "no_fairness_guarantee",
                "no_production_quality_guarantee",
            ],
            "model_format": MODEL_FORMAT,
            "search_space": list(search_space()),
            "selection_policy": {
                "ordered_criteria": [
                    "validation_ndcg_at_10_desc",
                    "validation_ndcg_at_5_desc",
                    "validation_mrr_desc",
                    "n_estimators_asc",
                    "max_depth_asc",
                    "config_id_lexicographic_asc",
                ],
                "selected_config_id": selected_id,
                "tie_tolerance": TIE_TOLERANCE,
            },
            "selection_policy_version": SELECTION_POLICY_VERSION,
            "source_dataset_version": SOURCE_DATASET_VERSION,
            "source_files": _source_files(paths, initial_dir),
            "source_revision": args.source_revision,
            "split_version": SPLIT_VERSION,
            "test_lock_verification": {
                "created_for_phase": test_lock["created_for_phase"],
                "locked": True,
                "metrics_run": False,
                "predictions_run": False,
                "prohibited_before_phase": test_lock["prohibited_before_phase"],
                "records_parsed": False,
                "sha256": LOCKED_TEST_SHA256,
                "usage": "hash_verification_only",
            },
            "tuned_model_version": TUNED_MODEL_VERSION,
            "tuning_pipeline_version": TUNING_PIPELINE_VERSION,
            "tuning_release_date": TUNING_RELEASE_DATE,
            "tuning_run_version": TUNING_RUN_VERSION,
            "tuning_seed": TUNING_SEED,
            "tuning_space_version": TUNING_SPACE_VERSION,
            "control_reproduction": control,
            "output_files": _output_files(staging_dir),
        }
        _write_text(staging_dir / "manifest.json", _json_content(manifest))
        expected_names = {*OUTPUT_COUNTS, "manifest.json"}
        if {path.name for path in staging_dir.iterdir()} != expected_names:
            raise ValueError("Unexpected or missing Tuned Model artifact.")
        _publish_directory(staging_dir, output_dir)
    except Exception:
        if staging_dir.exists():
            shutil.rmtree(staging_dir)
        raise

    return {
        "delta_vs_initial": deltas_vs_initial["NDCG@10"],
        "delta_vs_matching": deltas_vs_matching["NDCG@10"],
        "final_record_count": 9180,
        "model_path": str(output_dir / "model.json"),
        "selected_config_id": selected_id,
        "test_evaluated": False,
        "trial_count": 8,
        "validation_ndcg_at_10": selected["validation_metrics"]["NDCG@10"]["macro_mean"],
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Run the fixed eight-trial Phase 9 XGBRanker tuning contract.",
    )
    parser.add_argument("--train-file", required=True)
    parser.add_argument("--validation-file", required=True)
    parser.add_argument("--feature-schema-file", required=True)
    parser.add_argument("--split-manifest", required=True)
    parser.add_argument("--test-lock-file", required=True)
    parser.add_argument("--baseline-metrics-file", required=True)
    parser.add_argument("--baseline-manifest-file", required=True)
    parser.add_argument("--initial-model-dir", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--tuning-run-version", required=True)
    parser.add_argument("--tuned-model-version", required=True)
    parser.add_argument("--seed", required=True, type=int)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:  # pragma: no cover
    summary = tune(build_parser().parse_args(argv))
    print(
        f"Trials={summary['trial_count']}; Selected={summary['selected_config_id']}; "
        f"Validation NDCG@10={summary['validation_ndcg_at_10']:.6f}; "
        f"Delta vs Initial={summary['delta_vs_initial']:+.6f}; "
        f"Delta vs Matching 2.0={summary['delta_vs_matching']:+.6f}; "
        f"model={summary['model_path']}; Final training records={summary['final_record_count']}; "
        "Test evaluated=false."
    )
    return 0
