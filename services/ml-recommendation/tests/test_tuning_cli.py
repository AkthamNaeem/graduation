"""Phase 9 CLI surface, lock rejection, and atomic publication."""

from __future__ import annotations

import argparse
import os
import subprocess
from pathlib import Path

import pytest

import smart_recruitment_ml.training.trainer as trainer_module
from smart_recruitment_ml.training.trainer import _publish_directory
from smart_recruitment_ml.tuning.tuner import build_parser, tune

REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
SERVICE_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"
SPLITS = SERVICE_ROOT / "data/splits/v1"
PYTHON = SERVICE_ROOT / ".venv/Scripts/python.exe"
CONSOLE = SERVICE_ROOT / ".venv/Scripts/train-tuned-xgbranker.exe"


def _args(**overrides: str | int) -> argparse.Namespace:
    values: dict[str, str | int] = {
        "train_file": str(SPLITS / "train.jsonl"),
        "validation_file": str(SPLITS / "validation.jsonl"),
        "feature_schema_file": str(SERVICE_ROOT / "data/features/v1/feature_schema.json"),
        "split_manifest": str(SPLITS / "manifest.json"),
        "test_lock_file": str(SPLITS / "test_lock.json"),
        "baseline_metrics_file": str(SERVICE_ROOT / "data/baselines/v1/metrics.json"),
        "baseline_manifest_file": str(SERVICE_ROOT / "data/baselines/v1/manifest.json"),
        "initial_model_dir": str(SERVICE_ROOT / "data/models/initial/v1"),
        "output_dir": str(SERVICE_ROOT / "data/models/tuned/v1"),
        "tuning_run_version": "xgbranker-bounded-tuning-v1",
        "tuned_model_version": "xgbranker-tuned-v1",
        "seed": 20260724,
        "source_revision": "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71",
        "architecture_sha256": ("60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"),
    }
    values.update(overrides)
    return argparse.Namespace(**values)


def test_parser_has_exact_allowed_arguments() -> None:
    destinations = {action.dest for action in build_parser()._actions}
    assert destinations == {
        "help",
        "train_file",
        "validation_file",
        "feature_schema_file",
        "split_manifest",
        "test_lock_file",
        "baseline_metrics_file",
        "baseline_manifest_file",
        "initial_model_dir",
        "output_dir",
        "tuning_run_version",
        "tuned_model_version",
        "seed",
        "source_revision",
        "architecture_sha256",
    }
    assert not {
        "test_file",
        "evaluate_test",
        "trial_count",
        "search_space_file",
        "early_stopping_rounds",
        "cv_folds",
    }.intersection(destinations)


@pytest.mark.parametrize(
    "command",
    [[str(PYTHON), "-m", "smart_recruitment_ml.tuning", "--help"], [str(CONSOLE), "--help"]],
)
def test_module_and_console_help(command: list[str]) -> None:
    result = subprocess.run(
        command, cwd=REPOSITORY_ROOT, capture_output=True, text=True, check=False
    )
    assert result.returncode == 0
    assert "--initial-model-dir" in result.stdout
    assert "--test-file" not in result.stdout


def test_invalid_locked_argument_exits_before_training(tmp_path: Path) -> None:
    with pytest.raises(ValueError, match="Locked Test path"):
        tune(_args(train_file=str(SPLITS / "test.jsonl")))
    locked_copy = tmp_path / "locked-copy.jsonl"
    locked_copy.write_bytes((SPLITS / "test.jsonl").read_bytes())
    with pytest.raises(ValueError, match="Locked Test content hash"):
        tune(_args(validation_file=str(locked_copy)))


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
