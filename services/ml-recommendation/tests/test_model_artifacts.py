"""Generated initial-model artifact, metric, publication, and reproducibility tests."""

from __future__ import annotations

import json
import os
from collections import defaultdict
from pathlib import Path
from typing import Any

import numpy as np
import pytest

import smart_recruitment_ml.training.trainer as trainer_module
from smart_recruitment_ml.baselines.metrics import evaluate_rankings
from smart_recruitment_ml.schemas.training import ModelMetadata, TrainingManifest
from smart_recruitment_ml.training.dataset import sha256_file
from smart_recruitment_ml.training.trainer import (
    METRIC_NAMES,
    _publish_directory,
    main,
)

REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
SERVICE_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"
MODEL_DIR = SERVICE_ROOT / "data/models/initial/v1"
ARTIFACT_NAMES = (
    "model.json",
    "model_metadata.json",
    "train_predictions.jsonl",
    "validation_predictions.jsonl",
    "metrics.json",
    "training_history.json",
    "manifest.json",
    "MODEL_CARD.md",
)
SOURCE_REVISION = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256 = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
CURRENT_BASELINE_MANIFEST_SHA256 = (
    "C591708A58AE66941BB004CE08522EAADC90F476105F7BED08B5E2DB477046BF"
)


def _records(name: str) -> list[dict[str, Any]]:
    return [
        json.loads(line) for line in (MODEL_DIR / name).read_text(encoding="utf-8").splitlines()
    ]


def _cli_args(
    output_dir: Path,
    *,
    baseline_manifest_file: Path | None = None,
) -> list[str]:
    return [
        "--train-file",
        str(SERVICE_ROOT / "data/splits/v1/train.jsonl"),
        "--validation-file",
        str(SERVICE_ROOT / "data/splits/v1/validation.jsonl"),
        "--feature-schema-file",
        str(SERVICE_ROOT / "data/features/v1/feature_schema.json"),
        "--split-manifest",
        str(SERVICE_ROOT / "data/splits/v1/manifest.json"),
        "--test-lock-file",
        str(SERVICE_ROOT / "data/splits/v1/test_lock.json"),
        "--baseline-metrics-file",
        str(SERVICE_ROOT / "data/baselines/v1/metrics.json"),
        "--baseline-manifest-file",
        str(baseline_manifest_file or SERVICE_ROOT / "data/baselines/v1/manifest.json"),
        "--output-dir",
        str(output_dir),
        "--model-version",
        "xgbranker-initial-v1",
        "--training-config-version",
        "xgbranker-fixed-config-v1",
        "--seed",
        "20260724",
        "--source-revision",
        SOURCE_REVISION,
        "--architecture-sha256",
        ARCHITECTURE_SHA256,
    ]


def _frozen_source_artifact(
    manifest: dict[str, Any],
    repository_path: str,
) -> dict[str, Any]:
    source_files = manifest.get("source_files")
    assert isinstance(source_files, list)
    matches = [
        source
        for source in source_files
        if isinstance(source, dict) and source.get("path") == repository_path
    ]
    assert len(matches) == 1
    return matches[0]


def test_exact_artifact_set_model_json_and_metadata_contract() -> None:
    assert {path.name for path in MODEL_DIR.iterdir()} == set(ARTIFACT_NAMES)
    model_value = json.loads((MODEL_DIR / "model.json").read_text(encoding="utf-8"))
    assert isinstance(model_value, dict)
    metadata = ModelMetadata.model_validate_json(
        (MODEL_DIR / "model_metadata.json").read_text(encoding="utf-8")
    )
    assert metadata.model_version == "xgbranker-initial-v1"
    assert metadata.model_format == "xgboost-json-v1"
    assert metadata.feature_count == 103
    assert len(metadata.feature_names) == 103
    assert metadata.xgboost_version == "3.3.0"
    assert metadata.numpy_version == "2.5.1"
    assert metadata.scipy_version == "1.18.0"
    assert metadata.python_version == "3.12.10"
    assert metadata.round_trip_max_absolute_error <= 1e-12
    assert metadata.round_trip_rank_agreement == 1.0
    assert metadata.model_sha256 == sha256_file(MODEL_DIR / metadata.model_file)
    assert metadata.model_size_bytes == (MODEL_DIR / metadata.model_file).stat().st_size
    assert metadata.early_stopping_used is False
    assert metadata.hyperparameter_tuning_used is False
    assert metadata.test_evaluated is False
    assert metadata.test_records_parsed is False


@pytest.mark.parametrize(
    ("name", "count", "candidate_count"),
    [
        ("train_predictions.jsonl", 7560, 126),
        ("validation_predictions.jsonl", 1620, 27),
    ],
)
def test_prediction_counts_uniqueness_order_and_rank_completeness(
    name: str,
    count: int,
    candidate_count: int,
) -> None:
    records = _records(name)
    assert len(records) == count
    assert len({record["pair_id"] for record in records}) == count
    assert records == sorted(
        records,
        key=lambda record: (
            record["candidate_id"],
            record["job_id"],
            record["pair_id"],
        ),
    )
    assert all(
        set(record)
        == {
            "pair_id",
            "candidate_id",
            "job_id",
            "relevance_label",
            "prediction_score",
            "rank",
            "model_version",
            "feature_schema_version",
        }
        for record in records
    )
    by_candidate: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in records:
        by_candidate[record["candidate_id"]].append(record)
    assert len(by_candidate) == candidate_count
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


def test_metrics_reuse_phase_7_implementation_and_deltas_are_exact() -> None:
    metrics = json.loads((MODEL_DIR / "metrics.json").read_text(encoding="utf-8"))
    assert set(metrics) == {
        "aggregation",
        "gain_definition",
        "model_version",
        "prediction_statistics",
        "ranking_metrics_version",
        "relevant_label_threshold",
        "train",
        "training_config_version",
        "validation",
    }
    for split, name, groups in (
        ("train", "train_predictions.jsonl", 126),
        ("validation", "validation_predictions.jsonl", 27),
    ):
        records = _records(name)
        by_candidate: dict[str, list[dict[str, Any]]] = defaultdict(list)
        for record in records:
            by_candidate[record["candidate_id"]].append(record)
        ranked_labels = [
            [
                record["relevance_label"]
                for record in sorted(
                    by_candidate[candidate_id],
                    key=lambda record: record["rank"],
                )
            ]
            for candidate_id in sorted(by_candidate)
        ]
        expected = evaluate_rankings(ranked_labels).model_dump(mode="json", by_alias=True)
        assert metrics[split]["xgbranker"] == expected
        for metric in METRIC_NAMES:
            summary = metrics[split]["xgbranker"][metric]
            assert summary["group_count"] == groups
            assert all(
                np.isfinite(summary[key])
                for key in (
                    "macro_mean",
                    "median",
                    "minimum",
                    "maximum",
                    "standard_deviation",
                )
            )
            mean = summary["macro_mean"]
            skills = metrics[split]["skills_baseline"][metric]["macro_mean"]
            matching = metrics[split]["matching_2_0"][metric]["macro_mean"]
            assert metrics[split]["deltas"]["vs_skills"][metric] == mean - skills
            assert metrics[split]["deltas"]["vs_matching_2_0"][metric] == mean - matching

    assert set(metrics["prediction_statistics"]) == {"train", "validation"}
    for split in ("train", "validation"):
        statistics = metrics["prediction_statistics"][split]
        assert statistics["finite_count"] == statistics["count"]
        assert statistics["unique_value_count"] > 1
        assert statistics["standard_deviation"] > 0.0


def test_training_history_manifest_hashes_and_lock_contract() -> None:
    history = json.loads((MODEL_DIR / "training_history.json").read_text(encoding="utf-8"))
    assert len(history) == 300
    assert [entry["round"] for entry in history] == list(range(1, 301))
    assert all(
        set(entry)
        == {
            "round",
            "train_ndcg_at_5",
            "train_ndcg_at_10",
            "validation_ndcg_at_5",
            "validation_ndcg_at_10",
        }
        for entry in history
    )
    manifest = TrainingManifest.model_validate_json(
        (MODEL_DIR / "manifest.json").read_text(encoding="utf-8")
    )
    assert manifest.dependencies == {
        "numpy": "2.5.1",
        "python": "3.12.10",
        "scipy": "1.18.0",
        "xgboost": "3.3.0",
    }
    assert manifest.test_lock_verification == {
        "created_for_phase": 6,
        "locked": True,
        "metrics_run": False,
        "predictions_run": False,
        "prohibited_before_phase": 10,
        "records_parsed": False,
        "sha256": "79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05",
    }
    assert len(manifest.output_files) == 7
    for output in manifest.output_files:
        path = MODEL_DIR / output.path
        assert output.sha256 == sha256_file(path)
        assert output.size_bytes == path.stat().st_size
    locked = next(
        source
        for source in manifest.source_files
        if source.usage == "hash_verification_only" and source.record_count == 1620
    )
    assert locked.records_parsed is False


def test_model_card_is_explicitly_not_production_ready() -> None:
    card = (MODEL_DIR / "MODEL_CARD.md").read_text(encoding="utf-8")
    assert "not production-ready" in card
    assert "There was no tuning" in card
    assert "Locked Test was not parsed, predicted, or evaluated" in card
    assert "must not automatically accept or reject candidates" in card
    assert "Phase 9" in card
    assert "Phase 10" in card


def test_atomic_publish_restores_previous_output_on_failure(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    output = tmp_path / "published"
    output.mkdir()
    (output / "old.txt").write_text("old", encoding="utf-8")
    staging = tmp_path / "staging"
    staging.mkdir()
    (staging / "new.txt").write_text("new", encoding="utf-8")
    real_replace = os.replace
    calls = 0

    def fail_publish(source: str | Path, destination: str | Path) -> None:
        nonlocal calls
        calls += 1
        if calls == 2:
            raise OSError("simulated publish failure")
        real_replace(source, destination)

    monkeypatch.setattr(trainer_module.os, "replace", fail_publish)
    with pytest.raises(OSError, match="simulated publish failure"):
        _publish_directory(staging, output)
    assert (output / "old.txt").read_text(encoding="utf-8") == "old"
    assert not staging.exists()
    assert not (tmp_path / ".published-backup").exists()


def test_all_eight_artifacts_are_byte_reproducible(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    frozen_bytes = {name: (MODEL_DIR / name).read_bytes() for name in ARTIFACT_NAMES}
    frozen_manifest = json.loads(frozen_bytes["manifest.json"])
    historical_baseline = _frozen_source_artifact(
        frozen_manifest,
        "services/ml-recommendation/data/baselines/v1/manifest.json",
    )
    historical_locked_test = _frozen_source_artifact(
        frozen_manifest,
        "services/ml-recommendation/data/splits/v1/test.jsonl",
    )
    historical_baseline_sha256 = str(historical_baseline["sha256"])
    historical_locked_test_sha256 = str(historical_locked_test["sha256"])
    phase_18_baseline = json.loads(
        (REPOSITORY_ROOT / "docs/ml-job-recommendation/PHASE_18_PROTECTED_BASELINE.json").read_text(
            encoding="utf-8"
        )
    )
    historical_report = next(
        record
        for record in phase_18_baseline["files"]
        if record["path"] == "services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md"
    )
    current_baseline_manifest = json.loads(
        (SERVICE_ROOT / "data/baselines/v1/manifest.json").read_text(encoding="utf-8")
    )
    report_record = next(
        output
        for output in current_baseline_manifest["output_files"]
        if output["path"].endswith("/BASELINE_REPORT.md")
    )
    report_record["size_bytes"] = historical_report["size_bytes"]
    report_record["sha256"] = str(historical_report["sha256"]).lower()
    historical_baseline_manifest_path = tmp_path / "historical-phase7-manifest.json"
    historical_baseline_manifest_path.write_text(
        json.dumps(
            current_baseline_manifest,
            ensure_ascii=False,
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )
    assert sha256_file(historical_baseline_manifest_path) == historical_baseline_sha256
    locked_test_path = (SERVICE_ROOT / "data/splits/v1/test.jsonl").resolve()
    train_path = (SERVICE_ROOT / "data/splits/v1/train.jsonl").resolve()
    validation_path = (SERVICE_ROOT / "data/splits/v1/validation.jsonl").resolve()
    real_sha256_file = trainer_module.sha256_file
    real_path_open = Path.open
    hashed_paths: set[Path] = set()
    locked_test_hash_substitutions = 0
    locked_test_filesystem_opens = 0

    def historical_sha256_file(path: Path) -> str:
        nonlocal locked_test_hash_substitutions
        resolved = Path(path).resolve()
        if resolved == locked_test_path:
            locked_test_hash_substitutions += 1
            return historical_locked_test_sha256
        hashed_paths.add(resolved)
        return real_sha256_file(path)

    def reject_locked_test_open(
        path: Path,
        *args: Any,
        **kwargs: Any,
    ) -> Any:
        nonlocal locked_test_filesystem_opens
        if path.resolve() == locked_test_path:
            locked_test_filesystem_opens += 1
            pytest.fail("The historical reproduction test must not open the Locked Test.")
        return real_path_open(path, *args, **kwargs)

    monkeypatch.setitem(
        trainer_module.EXPECTED_HASHES,
        "baseline_manifest",
        historical_baseline_sha256,
    )
    monkeypatch.setattr(trainer_module, "sha256_file", historical_sha256_file)
    monkeypatch.setattr(Path, "open", reject_locked_test_open)

    reproduced = tmp_path / "reproduced"
    assert not reproduced.resolve().is_relative_to(REPOSITORY_ROOT.resolve())
    assert (
        main(
            _cli_args(
                reproduced,
                baseline_manifest_file=historical_baseline_manifest_path,
            )
        )
        == 0
    )
    summary = capsys.readouterr().out
    assert summary.count("\n") == 1
    assert "Test evaluated=false" in summary
    assert locked_test_hash_substitutions == 1
    assert locked_test_filesystem_opens == 0
    assert train_path in hashed_paths
    assert validation_path in hashed_paths
    assert {path.name for path in reproduced.iterdir()} == set(ARTIFACT_NAMES)
    for name in ARTIFACT_NAMES:
        assert (reproduced / name).read_bytes() == frozen_bytes[name]
        assert (MODEL_DIR / name).read_bytes() == frozen_bytes[name]


def test_current_baseline_manifest_provenance_and_portability() -> None:
    baseline_dir = SERVICE_ROOT / "data/baselines/v1"
    baseline_manifest_path = baseline_dir / "manifest.json"
    baseline_report_path = baseline_dir / "BASELINE_REPORT.md"
    assert trainer_module.EXPECTED_HASHES["baseline_manifest"].upper() == (
        CURRENT_BASELINE_MANIFEST_SHA256
    )
    assert sha256_file(baseline_manifest_path).upper() == CURRENT_BASELINE_MANIFEST_SHA256

    baseline_manifest = json.loads(baseline_manifest_path.read_text(encoding="utf-8"))
    report_record = next(
        output
        for output in baseline_manifest["output_files"]
        if output["path"].endswith("/BASELINE_REPORT.md")
    )
    assert report_record["size_bytes"] == baseline_report_path.stat().st_size
    assert report_record["sha256"].upper() == sha256_file(baseline_report_path).upper()

    baseline_report = baseline_report_path.read_text(encoding="utf-8")
    assert "<repository-root>" in baseline_report
    assert "C:/xampp/htdocs/workeyx" not in baseline_report
    assert "C:\\xampp\\htdocs\\workeyx" not in baseline_report
    assert "C:\\Users\\" not in baseline_report
    assert "C:/Users/" not in baseline_report
