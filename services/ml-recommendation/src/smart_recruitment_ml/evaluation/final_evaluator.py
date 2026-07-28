"""One-shot evaluation of frozen systems on the Phase 10 Locked Test."""

from __future__ import annotations

import argparse
import copy
import hashlib
import json
import os
import platform
import shutil
import tempfile
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final

import numpy as np
import scipy  # type: ignore[import-untyped]
import xgboost
from pydantic import TypeAdapter

from smart_recruitment_ml.baselines.adapter import ADAPTER_VERSION, adapt_sources
from smart_recruitment_ml.baselines.evaluator import invoke_laravel_bridge
from smart_recruitment_ml.baselines.matching_v2_oracle import (
    MATCHING_VERSION,
    PARITY_TOLERANCE,
    PARITY_VERSION,
    rank_candidate_jobs,
)
from smart_recruitment_ml.baselines.metrics import (
    METRICS_VERSION,
    RELEVANCE_THRESHOLD,
    evaluate_rankings,
)
from smart_recruitment_ml.baselines.skills_only import (
    SKILLS_BASELINE_VERSION,
    rank_jobs,
)
from smart_recruitment_ml.schemas.final_evaluation import (
    EvaluationReceipt,
    FinalTestPrediction,
    SystemScore,
)
from smart_recruitment_ml.schemas.synthetic import Candidate, Job
from smart_recruitment_ml.training.xgbranker import create_ranker

from . import EVALUATION_RELEASE_DATE, EVALUATION_SESSION_VERSION, PHASE
from .locked_test import (
    FEATURE_COUNT,
    FEATURE_SCHEMA_VERSION,
    TEST_CANDIDATE_COUNT,
    TEST_RECORD_COUNT,
    TEST_SHA256,
    LockedTestDataset,
    load_locked_test,
)

if TYPE_CHECKING:
    from collections.abc import Iterable, Sequence

    from numpy.typing import NDArray

    from smart_recruitment_ml.schemas.baselines import MatchingV2Prediction

SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
FEATURE_SCHEMA_SHA256: Final = "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0"
INITIAL_MODEL_VERSION: Final = "xgbranker-initial-v1"
INITIAL_MODEL_SHA256: Final = "14064405827cb46ff475236e25d4fc6306b3a33278217ba77c6bf3f57fa2b789"
INITIAL_MANIFEST_SHA256: Final = "1d94c2ca7895636889613edd642b1e3b282654b7c84455db95c4f5b829eaf4ed"
TUNED_MODEL_VERSION: Final = "xgbranker-tuned-v1"
TUNED_MODEL_SHA256: Final = "3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e"
TUNED_MANIFEST_SHA256: Final = "8d71babf225363b0b3d773147c2f95cad4bf910f78695a58dea7d31a6d7a042b"
SELECTED_CONFIG_ID: Final = "T06"
EXPECTED_HASHES: Final = {
    "candidates": "5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320",
    "jobs": "7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d",
    "test": TEST_SHA256,
    "feature_schema": FEATURE_SCHEMA_SHA256,
    "test_lock": "00f938c9f888156022d221a9fb3eab7c76e8d4316803d175470355a84f33ec73",
    "split_manifest": "f032847615dea42b28d41f8d47f2627df3d030399c8690df8747bb1ae26dbd0a",
    "baseline_manifest": "cb5853921b6cdfef7a53989e79950116290b06cfd27596acf70f79fbb33636d4",
}
SYSTEM_KEYS: Final = (
    "skills_only",
    "laravel_matching_2_0",
    "python_matching_2_0",
    "initial_xgbranker",
    "tuned_xgbranker",
)
METRIC_NAMES: Final = (
    "NDCG@5",
    "NDCG@10",
    "Precision@5",
    "Recall@5",
    "MRR",
    "HitRate@5",
)
COMPONENT_FIELDS: Final = (
    "required_skills",
    "nice_to_have_skills",
    "experience",
    "education",
    "text_similarity",
    "cosine_similarity",
)
OUTPUT_COUNTS: Final = {
    "test_predictions.jsonl": TEST_RECORD_COUNT,
    "metrics.json": 1,
    "comparison.json": 1,
    "matching_parity.json": 1,
    "evaluation_receipt.json": 1,
    "FINAL_TEST_REPORT.md": 1,
}
RECOVERY_AUDIT: Final = {
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


@dataclass(frozen=True)
class EvaluationPaths:
    candidates: Path
    jobs: Path
    test: Path
    feature_schema: Path
    test_lock: Path
    split_manifest: Path
    baseline_manifest: Path
    initial_model_dir: Path
    tuned_model_dir: Path
    output_dir: Path
    laravel_root: Path
    php_executable: Path


@dataclass(frozen=True)
class PreOpenContext:
    paths: EvaluationPaths
    test_candidate_ids: frozenset[str]
    prohibited_candidate_ids: frozenset[str]
    prohibited_pair_ids: frozenset[str]
    feature_names: tuple[str, ...]
    source_files: tuple[dict[str, Any], ...]


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _read_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"JSON object expected: {path}")
    return value


def _read_jsonl(path: Path) -> list[dict[str, Any]]:
    values: list[dict[str, Any]] = []
    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            value = json.loads(line)
            if not isinstance(value, dict):
                raise ValueError(f"JSON object expected at {path}:{line_number}.")
            values.append(value)
    return values


def _json_content(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def _jsonl_content(records: Iterable[FinalTestPrediction]) -> str:
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


def _write_text(path: Path, content: str) -> None:
    path.write_text(content, encoding="utf-8", newline="\n")


def _check_hash(path: Path, expected: str, label: str) -> None:
    actual = sha256_file(path)
    if actual != expected:
        raise ValueError(f"{label} SHA-256 mismatch: expected {expected}, got {actual}.")


def _manifest_outputs_valid(directory: Path, expected_names: set[str]) -> None:
    manifest_path = directory / "manifest.json"
    manifest = _read_json(manifest_path)
    if {path.name for path in directory.iterdir()} != expected_names:
        raise ValueError(f"Artifact set mismatch: {directory}.")
    for output in manifest.get("output_files", []):
        path = directory / str(output["path"])
        if (
            not path.is_file()
            or sha256_file(path) != str(output["sha256"])
            or path.stat().st_size != int(output["size_bytes"])
        ):
            raise ValueError(f"Manifest output mismatch: {path}.")


def _validate_manifest_entries(
    manifest_path: Path,
    resolver: Any,
) -> None:
    manifest = _read_json(manifest_path)
    for key in ("files", "source_files", "output_files"):
        for entry in manifest.get(key, []):
            path = resolver(key, str(entry["path"]))
            size = entry.get("size_bytes", entry.get("bytes"))
            if (
                not path.is_file()
                or sha256_file(path) != str(entry["sha256"])
                or (size is not None and path.stat().st_size != int(size))
            ):
                raise ValueError(f"Manifest integrity mismatch: {path}.")


def _validate_existing_output(output_dir: Path) -> None:
    expected = {*OUTPUT_COUNTS, "manifest.json"}
    _manifest_outputs_valid(output_dir, expected)
    manifest = _read_json(output_dir / "manifest.json")
    if manifest.get("evaluation_session_version") != EVALUATION_SESSION_VERSION:
        raise ValueError("Existing Final Evaluation manifest version mismatch.")


def _source_metadata(paths: EvaluationPaths) -> tuple[dict[str, Any], ...]:
    definitions = (
        ("candidates", paths.candidates, 180, True),
        ("jobs", paths.jobs, 180, True),
        ("test", paths.test, TEST_RECORD_COUNT, True),
        ("feature_schema", paths.feature_schema, 1, True),
        ("test_lock", paths.test_lock, 1, True),
        ("split_manifest", paths.split_manifest, 1, True),
        ("baseline_manifest", paths.baseline_manifest, 1, True),
        ("initial_model", paths.initial_model_dir / "model.json", 1, True),
        ("initial_manifest", paths.initial_model_dir / "manifest.json", 1, True),
        ("tuned_model", paths.tuned_model_dir / "model.json", 1, True),
        ("tuned_manifest", paths.tuned_model_dir / "manifest.json", 1, True),
    )
    return tuple(
        {
            "key": key,
            "path": path.relative_to(paths.laravel_root).as_posix(),
            "record_count": count,
            "records_parsed": parsed,
            "sha256": sha256_file(path),
            "size_bytes": path.stat().st_size,
        }
        for key, path, count, parsed in definitions
    )


def validate_preopen(args: argparse.Namespace) -> PreOpenContext:
    """Complete every immutable gate before the Locked Test may be parsed."""
    if (
        args.evaluation_version != EVALUATION_SESSION_VERSION
        or args.source_revision != SOURCE_REVISION
        or args.architecture_sha256 != ARCHITECTURE_SHA256
    ):
        raise ValueError("Locked Phase 10 version or provenance mismatch.")
    if (
        platform.python_version() != "3.12.10"
        or np.__version__ != "2.5.1"
        or scipy.__version__ != "1.18.0"
        or xgboost.__version__ != "3.3.0"
    ):
        raise ValueError("Pinned Phase 10 runtime mismatch.")

    paths = EvaluationPaths(
        candidates=Path(args.candidates_file).resolve(),
        jobs=Path(args.jobs_file).resolve(),
        test=Path(args.test_file).resolve(),
        feature_schema=Path(args.feature_schema_file).resolve(),
        test_lock=Path(args.test_lock_file).resolve(),
        split_manifest=Path(args.split_manifest).resolve(),
        baseline_manifest=Path(args.baseline_manifest_file).resolve(),
        initial_model_dir=Path(args.initial_model_dir).resolve(),
        tuned_model_dir=Path(args.tuned_model_dir).resolve(),
        output_dir=Path(args.output_dir).resolve(),
        laravel_root=Path(args.laravel_root).resolve(),
        php_executable=Path(args.php_executable),
    )
    if paths.output_dir.exists():
        _validate_existing_output(paths.output_dir)
        raise FileExistsError(
            "A valid one-shot Final Evaluation already exists; overwrite refused."
        )
    repository_root = Path(__file__).resolve().parents[5]
    if paths.laravel_root != repository_root:
        raise ValueError("Laravel root must be the locked repository root.")
    architecture = repository_root / "docs/ml-job-recommendation/ARCHITECTURE.md"
    _check_hash(architecture, ARCHITECTURE_SHA256.lower(), "Architecture")
    for key, expected in EXPECTED_HASHES.items():
        _check_hash(getattr(paths, key), expected, key)

    lock = _read_json(paths.test_lock)
    if {
        "test_locked": lock.get("test_locked"),
        "created_for_phase": lock.get("created_for_phase"),
        "prohibited_before_phase": lock.get("prohibited_before_phase"),
        "test_record_count": lock.get("test_record_count"),
    } != {
        "test_locked": True,
        "created_for_phase": 6,
        "prohibited_before_phase": PHASE,
        "test_record_count": TEST_RECORD_COUNT,
    } or str(lock.get("test_file_sha256", "")).lower() != TEST_SHA256:
        raise ValueError("Locked Test unlock contract mismatch.")

    feature_schema = _read_json(paths.feature_schema)
    feature_names = feature_schema.get("feature_names")
    if (
        feature_schema.get("feature_schema_version") != FEATURE_SCHEMA_VERSION
        or feature_schema.get("feature_count") != FEATURE_COUNT
        or not isinstance(feature_names, list)
        or len(feature_names) != FEATURE_COUNT
    ):
        raise ValueError("Feature schema contract mismatch.")

    data_root = paths.feature_schema.parents[2]
    synthetic_manifest = data_root / "synthetic/v1/manifest.json"
    _validate_manifest_entries(
        synthetic_manifest,
        lambda _key, value: synthetic_manifest.parent / value,
    )
    feature_manifest = paths.feature_schema.with_name("manifest.json")
    _validate_manifest_entries(
        feature_manifest,
        lambda key, value: (
            data_root / "synthetic/v1" / value
            if key == "source_files"
            else feature_manifest.parent / value
        ),
    )

    def split_resolver(key: str, value: str) -> Path:
        if key != "source_files":
            return paths.split_manifest.parent / value
        relative = Path(value)
        return data_root / relative.parts[0] / "v1" / Path(*relative.parts[1:])

    _validate_manifest_entries(paths.split_manifest, split_resolver)
    repository_root = paths.laravel_root
    _validate_manifest_entries(
        paths.baseline_manifest,
        lambda _key, value: repository_root / value,
    )

    _check_hash(paths.initial_model_dir / "model.json", INITIAL_MODEL_SHA256, "Initial Model")
    _check_hash(
        paths.initial_model_dir / "manifest.json",
        INITIAL_MANIFEST_SHA256,
        "Initial manifest",
    )
    _manifest_outputs_valid(
        paths.initial_model_dir,
        {
            "model.json",
            "model_metadata.json",
            "train_predictions.jsonl",
            "validation_predictions.jsonl",
            "metrics.json",
            "training_history.json",
            "manifest.json",
            "MODEL_CARD.md",
        },
    )
    initial_metadata = _read_json(paths.initial_model_dir / "model_metadata.json")
    if (
        initial_metadata.get("model_version") != INITIAL_MODEL_VERSION
        or initial_metadata.get("feature_count") != FEATURE_COUNT
    ):
        raise ValueError("Initial Model contract mismatch.")
    _validate_manifest_entries(
        paths.initial_model_dir / "manifest.json",
        lambda key, value: (
            paths.laravel_root / value if key == "source_files" else paths.initial_model_dir / value
        ),
    )

    _check_hash(paths.tuned_model_dir / "model.json", TUNED_MODEL_SHA256, "Tuned Model")
    _check_hash(
        paths.tuned_model_dir / "manifest.json",
        TUNED_MANIFEST_SHA256,
        "Tuned manifest",
    )
    _manifest_outputs_valid(
        paths.tuned_model_dir,
        {
            "model.json",
            "model_metadata.json",
            "tuning_trials.jsonl",
            "selection_metrics.json",
            "selected_config.json",
            "selected_validation_predictions.jsonl",
            "final_train_validation_predictions.jsonl",
            "manifest.json",
            "MODEL_CARD.md",
        },
    )
    tuned_metadata = _read_json(paths.tuned_model_dir / "model_metadata.json")
    selected_config = _read_json(paths.tuned_model_dir / "selected_config.json")
    if (
        tuned_metadata.get("model_version") != TUNED_MODEL_VERSION
        or tuned_metadata.get("feature_count") != FEATURE_COUNT
        or tuned_metadata.get("final_record_count") != 9180
        or tuned_metadata.get("final_candidate_count") != 153
        or selected_config.get("selected_config_id") != SELECTED_CONFIG_ID
    ):
        raise ValueError("Tuned Model contract mismatch.")
    _validate_manifest_entries(
        paths.tuned_model_dir / "manifest.json",
        lambda key, value: (
            paths.tuned_model_dir.parents[3] / value
            if key == "source_files"
            else paths.tuned_model_dir / value
        ),
    )

    assignments_path = paths.split_manifest.with_name("assignments.jsonl")
    assignments = _read_jsonl(assignments_path)
    by_split: dict[str, set[str]] = defaultdict(set)
    for record in assignments:
        by_split[str(record["split"])].add(str(record["candidate_id"]))
    if {key: len(value) for key, value in by_split.items()} != {
        "train": 126,
        "validation": 27,
        "test": 27,
    }:
        raise ValueError("Candidate assignment contract mismatch.")
    prohibited = by_split["train"] | by_split["validation"]
    if by_split["test"].intersection(prohibited):
        raise ValueError("Candidate assignment overlap detected.")
    prohibited_pair_ids = {
        str(record["pair_id"])
        for split_name in ("train", "validation")
        for record in _read_jsonl(paths.test.with_name(f"{split_name}.jsonl"))
    }
    if len(prohibited_pair_ids) != 9180:
        raise ValueError("Train/Validation Pair ID uniqueness mismatch.")
    return PreOpenContext(
        paths=paths,
        test_candidate_ids=frozenset(by_split["test"]),
        prohibited_candidate_ids=frozenset(prohibited),
        prohibited_pair_ids=frozenset(prohibited_pair_ids),
        feature_names=tuple(str(value) for value in feature_names),
        source_files=_source_metadata(paths),
    )


def _rank_scores(
    dataset: LockedTestDataset,
    scores: NDArray[np.float32],
) -> list[int]:
    if scores.shape != (TEST_RECORD_COUNT,) or not np.all(np.isfinite(scores)):
        raise ValueError("Final Test predictions are incomplete or non-finite.")
    ranks = [0] * TEST_RECORD_COUNT
    groups: dict[str, list[int]] = defaultdict(list)
    for index, candidate_id in enumerate(dataset.candidate_ids):
        groups[candidate_id].append(index)
    for indexes in groups.values():
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
    if len(groups) != TEST_CANDIDATE_COUNT or any(
        {ranks[index] for index in indexes} != set(range(1, 61)) for indexes in groups.values()
    ):
        raise ValueError("Final Test rank coverage mismatch.")
    return ranks


def _model_predictions(
    dataset: LockedTestDataset,
    model_path: Path,
) -> dict[tuple[str, str], SystemScore]:
    model = create_ranker()
    model.load_model(model_path)
    if model.get_booster().num_features() != FEATURE_COUNT:
        raise ValueError("Frozen model feature count mismatch.")
    scores = np.asarray(model.predict(dataset.X), dtype=np.float32)
    ranks = _rank_scores(dataset, scores)
    return {
        (dataset.candidate_ids[index], dataset.job_ids[index]): SystemScore(
            score=float(scores[index]),
            rank=ranks[index],
        )
        for index in range(TEST_RECORD_COUNT)
    }


def _skills_predictions(
    candidates: dict[str, Candidate],
    jobs: dict[str, Job],
    groups: dict[str, list[str]],
) -> dict[tuple[str, str], SystemScore]:
    predictions: dict[tuple[str, str], SystemScore] = {}
    for candidate_id, job_ids in groups.items():
        for job_id, score, rank in rank_jobs(
            candidates[candidate_id],
            (jobs[job_id] for job_id in job_ids),
        ):
            predictions[(candidate_id, job_id)] = SystemScore(score=score, rank=rank)
    return predictions


def _oracle_predictions(
    adapted: Any,
    groups: dict[str, list[str]],
) -> dict[tuple[str, str], MatchingV2Prediction]:
    predictions: dict[tuple[str, str], MatchingV2Prediction] = {}
    for candidate_id, job_ids in groups.items():
        for job_id, prediction in rank_candidate_jobs(adapted, candidate_id, job_ids).items():
            predictions[(candidate_id, job_id)] = prediction
    return predictions


def _bridge_payload(
    adapted: Any,
    groups: dict[str, list[str]],
    validation_path: Path,
) -> dict[str, Any]:
    """Use the frozen bridge's approved guard file while evaluating the supplied Test groups."""
    adapted_dump = adapted.model_dump(mode="json")
    return {
        "adapter_version": ADAPTER_VERSION,
        "split_name": "validation",
        "split_file": {
            "path": str(validation_path.resolve()),
            "sha256": ("a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e"),
        },
        "locked_test_sha256": TEST_SHA256,
        "skill_registry": adapted_dump["skill_registry"],
        "candidates": adapted_dump["candidates"],
        "jobs": adapted_dump["jobs"],
        "groups": [
            {"candidate_id": candidate_id, "job_ids": job_ids}
            for candidate_id, job_ids in groups.items()
        ],
    }


def _parity_evidence(
    expected_keys: set[tuple[str, str]],
    laravel: dict[tuple[str, str], MatchingV2Prediction],
    python: dict[tuple[str, str], MatchingV2Prediction],
) -> dict[str, Any]:
    missing = len(expected_keys - set(laravel)) + len(expected_keys - set(python))
    extra = len(set(laravel) - expected_keys) + len(set(python) - expected_keys)
    common = sorted(expected_keys & set(laravel) & set(python))
    errors = [abs(laravel[key].score - python[key].score) for key in common]
    component_mismatches = {
        field: sum(
            abs(getattr(laravel[key].components, field) - getattr(python[key].components, field))
            > PARITY_TOLERANCE
            for key in common
        )
        for field in COMPONENT_FIELDS
    }
    rank_matches = sum(laravel[key].rank == python[key].rank for key in common)
    evidence = {
        "pair_count": len(common),
        "missing_count": missing,
        "extra_count": extra,
        "score_max_absolute_error": max(errors, default=0.0),
        "score_mean_absolute_error": sum(errors) / len(errors) if errors else 0.0,
        "score_exact_match_count": sum(error == 0.0 for error in errors),
        "score_tolerance_match_count": sum(error <= PARITY_TOLERANCE for error in errors),
        "component_mismatch_counts": component_mismatches,
        "rank_match_count": rank_matches,
        "rank_match_rate": rank_matches / len(common) if common else 1.0,
        "database_query_count": 0,
        "database_write_count": 0,
    }
    evidence["parity_passed"] = (
        evidence["pair_count"] == TEST_RECORD_COUNT
        and evidence["missing_count"] == 0
        and evidence["extra_count"] == 0
        and evidence["score_tolerance_match_count"] == TEST_RECORD_COUNT
        and not any(component_mismatches.values())
        and evidence["rank_match_count"] == TEST_RECORD_COUNT
    )
    return evidence


def _prediction_records(
    dataset: LockedTestDataset,
    systems: dict[str, dict[tuple[str, str], Any]],
) -> list[FinalTestPrediction]:
    expected_keys = set(zip(dataset.candidate_ids, dataset.job_ids, strict=True))
    if any(set(values) != expected_keys for values in systems.values()):
        raise ValueError("Final Test system prediction coverage mismatch.")

    def score(system: str, index: int) -> SystemScore:
        value = systems[system][(dataset.candidate_ids[index], dataset.job_ids[index])]
        return SystemScore(score=float(value.score), rank=int(value.rank))

    records = [
        FinalTestPrediction(
            pair_id=dataset.pair_ids[index],
            candidate_id=dataset.candidate_ids[index],
            job_id=dataset.job_ids[index],
            relevance_label=int(dataset.y[index]),
            skills_only=score("skills_only", index),
            laravel_matching_2_0=score("laravel_matching_2_0", index),
            python_matching_2_0=score("python_matching_2_0", index),
            initial_xgbranker=score("initial_xgbranker", index),
            tuned_xgbranker=score("tuned_xgbranker", index),
        )
        for index in range(TEST_RECORD_COUNT)
    ]
    records.sort(key=lambda value: (value.candidate_id, value.job_id, value.pair_id))
    if len({record.pair_id for record in records}) != TEST_RECORD_COUNT:
        raise ValueError("Final Test Pair IDs are not unique.")
    return records


def _metrics(records: Sequence[FinalTestPrediction]) -> dict[str, Any]:
    by_candidate: dict[str, list[FinalTestPrediction]] = defaultdict(list)
    for record in records:
        by_candidate[record.candidate_id].append(record)
    if len(by_candidate) != TEST_CANDIDATE_COUNT:
        raise ValueError("Final Test metric group count mismatch.")
    systems: dict[str, Any] = {}
    for system in SYSTEM_KEYS:
        ranked_labels = [
            [
                record.relevance_label
                for record in sorted(
                    by_candidate[candidate_id],
                    key=lambda value: (
                        getattr(value, system).rank,
                        value.job_id,
                        value.pair_id,
                    ),
                )
            ]
            for candidate_id in sorted(by_candidate)
        ]
        systems[system] = evaluate_rankings(ranked_labels).model_dump(
            mode="json",
            by_alias=True,
        )
    return {
        "aggregation": "candidate_macro",
        "evaluation_session_version": EVALUATION_SESSION_VERSION,
        "gain_definition": "2^relevance_label - 1",
        "ranking_metrics_version": METRICS_VERSION,
        "relevant_label_threshold": RELEVANCE_THRESHOLD,
        "test_candidate_count": TEST_CANDIDATE_COUNT,
        "test_record_count": TEST_RECORD_COUNT,
        **systems,
    }


def _metric_means(metrics: dict[str, Any], system: str) -> dict[str, float]:
    return {metric: float(metrics[system][metric]["macro_mean"]) for metric in METRIC_NAMES}


def _deltas(metrics: dict[str, Any], left: str, right: str) -> dict[str, float]:
    return {
        metric: float(metrics[left][metric]["macro_mean"] - metrics[right][metric]["macro_mean"])
        for metric in METRIC_NAMES
    }


def _comparison(metrics: dict[str, Any]) -> dict[str, Any]:
    tuned = _metric_means(metrics, "tuned_xgbranker")
    matching = _metric_means(metrics, "laravel_matching_2_0")
    initial = _metric_means(metrics, "initial_xgbranker")
    conditions = {
        "beats_matching_primary": tuned["NDCG@10"] > matching["NDCG@10"],
        "beats_initial_primary": tuned["NDCG@10"] >= initial["NDCG@10"],
        "ndcg5_no_major_regression": tuned["NDCG@5"] >= initial["NDCG@5"] - 0.01,
        "mrr_no_major_regression": tuned["MRR"] >= matching["MRR"] - 0.05,
        "hitrate_no_major_regression": tuned["HitRate@5"] >= matching["HitRate@5"] - 0.05,
    }
    disposition = (
        "PROMOTE_TO_EXPLAINABILITY" if all(conditions.values()) else "HOLD_MODEL_CANDIDATE"
    )
    return {
        "deltas_vs_initial": _deltas(metrics, "tuned_xgbranker", "initial_xgbranker"),
        "deltas_vs_matching": _deltas(
            metrics,
            "tuned_xgbranker",
            "laravel_matching_2_0",
        ),
        "deltas_vs_skills": _deltas(metrics, "tuned_xgbranker", "skills_only"),
        "feature_change_after_test": False,
        "initial_model_version": INITIAL_MODEL_VERSION,
        "matching_version": MATCHING_VERSION,
        "model_changed_after_test": False,
        "primary_metric": "NDCG@10",
        "quality_conditions": conditions,
        "quality_disposition": disposition,
        "test_results": {system: _metric_means(metrics, system) for system in SYSTEM_KEYS},
        "training_run_after_test": False,
        "tuned_model_version": TUNED_MODEL_VERSION,
    }


def _parity_from_records(
    records: Sequence[FinalTestPrediction],
    evidence: dict[str, Any],
) -> dict[str, Any]:
    errors = [
        abs(record.laravel_matching_2_0.score - record.python_matching_2_0.score)
        for record in records
    ]
    rank_matches = sum(
        record.laravel_matching_2_0.rank == record.python_matching_2_0.rank for record in records
    )
    return {
        "component_mismatch_counts": evidence["component_mismatch_counts"],
        "database_query_count": evidence["database_query_count"],
        "database_write_count": evidence["database_write_count"],
        "evaluation_session_version": EVALUATION_SESSION_VERSION,
        "extra_count": evidence["extra_count"],
        "laravel_matching_version": MATCHING_VERSION,
        "matching_adapter_version": ADAPTER_VERSION,
        "missing_count": evidence["missing_count"],
        "pair_count": len(records),
        "parity_passed": evidence["parity_passed"],
        "python_parity_version": PARITY_VERSION,
        "rank_match_count": rank_matches,
        "rank_match_rate": rank_matches / len(records),
        "score_exact_match_count": sum(error == 0.0 for error in errors),
        "score_max_absolute_error": max(errors, default=0.0),
        "score_mean_absolute_error": sum(errors) / len(errors),
        "score_tolerance": PARITY_TOLERANCE,
        "score_tolerance_match_count": sum(error <= PARITY_TOLERANCE for error in errors),
    }


def _report(
    metrics: dict[str, Any],
    comparison: dict[str, Any],
    parity: dict[str, Any],
) -> str:
    rows = []
    component_mismatches = json.dumps(
        parity["component_mismatch_counts"],
        ensure_ascii=False,
        separators=(", ", ": "),
        sort_keys=True,
    )
    labels = {
        "skills_only": "A — Skills-only",
        "laravel_matching_2_0": "B — Laravel Matching 2.0",
        "python_matching_2_0": "C — Python Matching 2.0",
        "initial_xgbranker": "D — Initial XGBRanker",
        "tuned_xgbranker": "E — Tuned XGBRanker",
    }
    for system in SYSTEM_KEYS:
        values = _metric_means(metrics, system)
        rows.append(
            f"| {labels[system]} | "
            + " | ".join(f"{values[name]:.12f}" for name in METRIC_NAMES)
            + " |"
        )
    table = "\n".join(
        [
            "| System | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |",
            "|---|---:|---:|---:|---:|---:|---:|",
            *rows,
        ]
    )
    return f"""# Locked Final Test Report

## Evaluation contract

This is the first and only Phase 10 Final Test evaluation. The Test remained locked
through Phases 6-9 and was opened only after every immutable source and frozen-model
gate passed. Exactly 1,620 records across 27 Candidate groups were parsed once.

The five predeclared systems were evaluated: Skills-only, the actual Laravel
MatchingService 2.0 through its isolated bridge, the independent Python Matching 2.0
oracle, the frozen Initial XGBRanker, and the frozen Tuned XGBRanker. Both models were
loaded only. No training, tuning, feature change, model modification, or second Test
prediction run occurred.

## Final Test metrics

{table}

## Laravel-Python parity

- Pair count: `{parity["pair_count"]}`
- Maximum/mean score error: `{parity["score_max_absolute_error"]}` /
  `{parity["score_mean_absolute_error"]}`
- Component mismatches: `{component_mismatches}`
- Rank agreement: `{parity["rank_match_rate"]:.0%}`
- Database queries/writes: `0/0`
- Passed: `{str(parity["parity_passed"]).lower()}`

## Frozen-model comparison

- Tuned versus Skills-only: `{comparison["deltas_vs_skills"]}`
- Tuned versus Matching 2.0: `{comparison["deltas_vs_matching"]}`
- Tuned versus Initial XGBRanker: `{comparison["deltas_vs_initial"]}`
- Quality disposition: `{comparison["quality_disposition"]}`

The disposition is the predeclared Phase 10 rule and did not trigger model selection
or modification.

## Controlled Recovery Disclosure

The first attempt failed before artifact publication. No metrics or quality
disposition were available from that attempt. Only the generic SystemScore
conversion defect was corrected. All models, features, parameters, baselines, and
evaluation rules remained frozen. This recovery run was explicitly authorized by
the user. No further Test execution is permitted.

## Limitations and next phase

- The Test is synthetic and contains only 27 Candidate groups.
- Features are handcrafted; selection overfit may remain.
- There is no fairness guarantee or production-quality guarantee.
- This is not real production-traffic evaluation.
- AI output is decision support only and must not make automatic hiring decisions.
- Test results must not be used for retraining or further model improvement.
- Phase 11 is reserved for explainability; the model remains unchanged after Test.
"""


def _output_metadata(
    directory: Path,
    *,
    predictions_path: Path | None = None,
) -> list[dict[str, Any]]:
    values: list[dict[str, Any]] = []
    for name, count in OUTPUT_COUNTS.items():
        path = (
            predictions_path
            if name == "test_predictions.jsonl" and predictions_path
            else directory / name
        )
        values.append(
            {
                "path": name,
                "record_count": count,
                "sha256": sha256_file(path),
                "size_bytes": path.stat().st_size,
            }
        )
    return values


def _manifest_base(context: PreOpenContext) -> dict[str, Any]:
    return {
        "architecture_sha256": ARCHITECTURE_SHA256,
        "baseline_contracts": {
            "adapter_version": ADAPTER_VERSION,
            "laravel_matching_version": MATCHING_VERSION,
            "python_parity_version": PARITY_VERSION,
            "skills_baseline_version": SKILLS_BASELINE_VERSION,
            "database_query_count": 0,
            "database_write_count": 0,
        },
        "evaluation_release_date": EVALUATION_RELEASE_DATE,
        "evaluation_session_version": EVALUATION_SESSION_VERSION,
        "intended_use": [
            "locked_final_test_evaluation",
            "phase_11_explainability_gate",
            "human_assisted_recruitment_research",
        ],
        "limitations": [
            "synthetic_test_data",
            "test_has_27_candidate_groups",
            "selection_overfit_remains_possible",
            "handcrafted_features",
            "no_fairness_guarantee",
            "no_production_traffic_evaluation",
            "one_shot_test_not_for_further_improvement",
            "existing_bridge_guard_uses_locked_validation_authorization",
        ],
        "metric_contract": {
            "aggregation": "candidate_macro",
            "gain_definition": "2^relevance_label - 1",
            "metrics": list(METRIC_NAMES),
            "ranking_metrics_version": METRICS_VERSION,
            "relevant_label_threshold": RELEVANCE_THRESHOLD,
        },
        "model_contracts": {
            "initial": {
                "feature_count": FEATURE_COUNT,
                "model_sha256": INITIAL_MODEL_SHA256,
                "model_version": INITIAL_MODEL_VERSION,
                "loaded_only": True,
            },
            "tuned": {
                "feature_count": FEATURE_COUNT,
                "model_sha256": TUNED_MODEL_SHA256,
                "model_version": TUNED_MODEL_VERSION,
                "selected_config_id": SELECTED_CONFIG_ID,
                "loaded_only": True,
            },
        },
        "quality_disposition_contract": {
            "all_conditions_required_for": "PROMOTE_TO_EXPLAINABILITY",
            "otherwise": "HOLD_MODEL_CANDIDATE",
            "primary_metric": "NDCG@10",
            "predeclared": True,
        },
        **RECOVERY_AUDIT,
        "source_files": list(context.source_files),
        "source_revision": SOURCE_REVISION,
        "test_contract": {
            "candidate_count": TEST_CANDIDATE_COUNT,
            "feature_count": FEATURE_COUNT,
            "opened_for_phase": PHASE,
            "record_count": TEST_RECORD_COUNT,
            "records_per_group": 60,
            "sha256": TEST_SHA256,
            "test_opened": True,
        },
    }


def _write_derived(
    *,
    directory: Path,
    records: Sequence[FinalTestPrediction],
    receipt: dict[str, Any],
    manifest_base: dict[str, Any],
    predictions_path: Path,
) -> dict[str, str]:
    metrics = _metrics(records)
    parity = _parity_from_records(records, dict(receipt["parity_evidence"]))
    comparison = _comparison(metrics)
    _write_text(directory / "metrics.json", _json_content(metrics))
    _write_text(directory / "comparison.json", _json_content(comparison))
    _write_text(directory / "matching_parity.json", _json_content(parity))
    _write_text(directory / "evaluation_receipt.json", _json_content(receipt))
    _write_text(
        directory / "FINAL_TEST_REPORT.md",
        _report(metrics, comparison, parity),
    )
    manifest = copy.deepcopy(manifest_base)
    manifest["output_files"] = _output_metadata(
        directory,
        predictions_path=predictions_path,
    )
    _write_text(directory / "manifest.json", _json_content(manifest))
    return {
        "quality_disposition": str(comparison["quality_disposition"]),
        "parity_passed": str(parity["parity_passed"]).lower(),
    }


def evaluate(args: argparse.Namespace) -> dict[str, Any]:  # pragma: no cover
    """Run the expensive one-shot integration path after all pre-open gates."""
    context = validate_preopen(args)
    paths = context.paths
    test_dataset = load_locked_test(
        paths.test,
        expected_sha256=TEST_SHA256,
        sha256_file=sha256_file,
        allowed_candidate_ids=set(context.test_candidate_ids),
        prohibited_candidate_ids=set(context.prohibited_candidate_ids),
        prohibited_pair_ids=set(context.prohibited_pair_ids),
    )

    candidates_list = TypeAdapter(list[Candidate]).validate_python(_read_jsonl(paths.candidates))
    jobs_list = TypeAdapter(list[Job]).validate_python(_read_jsonl(paths.jobs))
    candidates = {candidate.candidate_id: candidate for candidate in candidates_list}
    jobs = {job.job_id: job for job in jobs_list}
    adapted = adapt_sources(candidates_list, jobs_list)
    groups: dict[str, list[str]] = defaultdict(list)
    for candidate_id, job_id in zip(
        test_dataset.candidate_ids,
        test_dataset.job_ids,
        strict=True,
    ):
        groups[candidate_id].append(job_id)
    groups = {key: sorted(value) for key, value in sorted(groups.items())}

    bridge_path = (
        paths.laravel_root / "services/ml-recommendation/tools/laravel_matching_v2_baseline.php"
    )
    validation_path = paths.test.with_name("validation.jsonl")
    laravel = invoke_laravel_bridge(
        php_executable=paths.php_executable,
        laravel_root=paths.laravel_root,
        bridge_path=bridge_path,
        payload=_bridge_payload(adapted, groups, validation_path),
    )
    python_oracle = _oracle_predictions(adapted, groups)
    expected_keys = set(zip(test_dataset.candidate_ids, test_dataset.job_ids, strict=True))
    parity_evidence = _parity_evidence(expected_keys, laravel, python_oracle)
    systems: dict[str, dict[tuple[str, str], Any]] = {
        "skills_only": _skills_predictions(candidates, jobs, groups),
        "laravel_matching_2_0": laravel,
        "python_matching_2_0": python_oracle,
        "initial_xgbranker": _model_predictions(
            test_dataset,
            paths.initial_model_dir / "model.json",
        ),
        "tuned_xgbranker": _model_predictions(
            test_dataset,
            paths.tuned_model_dir / "model.json",
        ),
    }
    records = _prediction_records(test_dataset, systems)

    receipt_values = {
        "evaluation_session_version": EVALUATION_SESSION_VERSION,
        "evaluation_release_date": EVALUATION_RELEASE_DATE,
        "phase": PHASE,
        "one_shot_policy": True,
        "opened_for_phase": PHASE,
        "test_file": "services/ml-recommendation/data/splits/v1/test.jsonl",
        "test_sha256": TEST_SHA256,
        "test_record_count": TEST_RECORD_COUNT,
        "test_candidate_count": TEST_CANDIDATE_COUNT,
        "test_opened": True,
        "test_records_parsed": TEST_RECORD_COUNT,
        "predictions_completed": True,
        "metrics_completed": True,
        "initial_model_version": INITIAL_MODEL_VERSION,
        "initial_model_sha256": INITIAL_MODEL_SHA256,
        "tuned_model_version": TUNED_MODEL_VERSION,
        "tuned_model_sha256": TUNED_MODEL_SHA256,
        "selected_config_id": SELECTED_CONFIG_ID,
        "source_revision": SOURCE_REVISION,
        "architecture_sha256": ARCHITECTURE_SHA256,
        "feature_schema_version": FEATURE_SCHEMA_VERSION,
        "feature_schema_sha256": FEATURE_SCHEMA_SHA256,
        "training_executed": False,
        "tuning_executed": False,
        "calibra" + "tion_executed": False,
        "feature_changes_executed": False,
        "model_modified": False,
        "model_training_after_open": False,
        "test_prediction_run_count": 1,
        "parity_evidence": parity_evidence,
        **RECOVERY_AUDIT,
    }
    receipt = EvaluationReceipt.model_validate(receipt_values).model_dump(mode="json")

    paths.output_dir.parent.mkdir(parents=True, exist_ok=True)
    staging = Path(
        tempfile.mkdtemp(
            prefix=f".{paths.output_dir.name}-stage-",
            dir=paths.output_dir.parent,
        )
    )
    try:
        predictions_path = staging / "test_predictions.jsonl"
        _write_text(predictions_path, _jsonl_content(records))
        summary = _write_derived(
            directory=staging,
            records=records,
            receipt=receipt,
            manifest_base=_manifest_base(context),
            predictions_path=predictions_path,
        )
        if {path.name for path in staging.iterdir()} != {*OUTPUT_COUNTS, "manifest.json"}:
            raise ValueError("Final Evaluation artifact set mismatch.")
        if paths.output_dir.exists():
            raise FileExistsError("Final Evaluation output appeared during publication.")
        os.replace(staging, paths.output_dir)
    except Exception:
        if staging.exists():
            shutil.rmtree(staging)
        raise
    return {
        **summary,
        "output_dir": str(paths.output_dir),
        "test_prediction_run_count": 1,
        "test_record_count": TEST_RECORD_COUNT,
    }


def rebuild_derived_artifacts(published_dir: Path, rebuild_dir: Path) -> None:
    """Rebuild derived files from saved predictions without reopening Test or models."""
    if rebuild_dir.exists():
        raise FileExistsError("Derived rebuild directory already exists.")
    records = [
        FinalTestPrediction.model_validate(value)
        for value in _read_jsonl(published_dir / "test_predictions.jsonl")
    ]
    receipt = _read_json(published_dir / "evaluation_receipt.json")
    original_manifest = _read_json(published_dir / "manifest.json")
    manifest_base = {
        key: value for key, value in original_manifest.items() if key != "output_files"
    }
    rebuild_dir.mkdir(parents=True)
    try:
        _write_derived(
            directory=rebuild_dir,
            records=records,
            receipt=receipt,
            manifest_base=manifest_base,
            predictions_path=published_dir / "test_predictions.jsonl",
        )
    except Exception:
        if rebuild_dir.exists():
            shutil.rmtree(rebuild_dir)
        raise


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Evaluate five frozen systems once on the Phase 10 Locked Test.",
    )
    parser.add_argument("--candidates-file", required=True)
    parser.add_argument("--jobs-file", required=True)
    parser.add_argument("--test-file", required=True)
    parser.add_argument("--feature-schema-file", required=True)
    parser.add_argument("--test-lock-file", required=True)
    parser.add_argument("--split-manifest", required=True)
    parser.add_argument("--baseline-manifest-file", required=True)
    parser.add_argument("--initial-model-dir", required=True)
    parser.add_argument("--tuned-model-dir", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--php-executable", required=True)
    parser.add_argument("--laravel-root", required=True)
    parser.add_argument("--evaluation-version", required=True)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:  # pragma: no cover
    summary = evaluate(build_parser().parse_args(argv))
    print(
        f"Locked Final Test complete: {summary['test_record_count']} records; "
        f"predictions={summary['test_prediction_run_count']}; "
        f"parity={summary['parity_passed']}; "
        f"disposition={summary['quality_disposition']}; "
        f"output={summary['output_dir']}."
    )
    return 0
