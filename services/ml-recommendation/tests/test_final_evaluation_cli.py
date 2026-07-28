"""Phase 10 CLI, one-shot refusal, and no-training surface."""

from __future__ import annotations

import argparse
import inspect
import json
import subprocess
from pathlib import Path

import pytest

from smart_recruitment_ml.evaluation.final_evaluator import (
    OUTPUT_COUNTS,
    _validate_existing_output,
    build_parser,
    evaluate,
    sha256_file,
    validate_preopen,
)

REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
SERVICE_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"
PYTHON = SERVICE_ROOT / ".venv/Scripts/python.exe"
CONSOLE = SERVICE_ROOT / ".venv/Scripts/evaluate-locked-final-test.exe"


def _args(**overrides: str) -> argparse.Namespace:
    values = {
        "candidates_file": "missing-candidates",
        "jobs_file": "missing-jobs",
        "test_file": "missing-test",
        "feature_schema_file": "missing-schema",
        "test_lock_file": "missing-lock",
        "split_manifest": "missing-split-manifest",
        "baseline_manifest_file": "missing-baseline-manifest",
        "initial_model_dir": "missing-initial",
        "tuned_model_dir": "missing-tuned",
        "output_dir": "missing-output",
        "php_executable": "php",
        "laravel_root": str(REPOSITORY_ROOT),
        "evaluation_version": "locked-final-test-v1",
        "source_revision": "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71",
        "architecture_sha256": ("60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"),
    }
    values.update(overrides)
    return argparse.Namespace(**values)


def _real_args(**overrides: str) -> argparse.Namespace:
    values = {
        "candidates_file": str(SERVICE_ROOT / "data/synthetic/v1/candidates.jsonl"),
        "jobs_file": str(SERVICE_ROOT / "data/synthetic/v1/jobs.jsonl"),
        "test_file": str(SERVICE_ROOT / "data/splits/v1/test.jsonl"),
        "feature_schema_file": str(SERVICE_ROOT / "data/features/v1/feature_schema.json"),
        "test_lock_file": str(SERVICE_ROOT / "data/splits/v1/test_lock.json"),
        "split_manifest": str(SERVICE_ROOT / "data/splits/v1/manifest.json"),
        "baseline_manifest_file": str(SERVICE_ROOT / "data/baselines/v1/manifest.json"),
        "initial_model_dir": str(SERVICE_ROOT / "data/models/initial/v1"),
        "tuned_model_dir": str(SERVICE_ROOT / "data/models/tuned/v1"),
        "output_dir": str(SERVICE_ROOT / "data/evaluations/final-test/v1"),
        "php_executable": "php",
        "laravel_root": str(REPOSITORY_ROOT),
        "evaluation_version": "locked-final-test-v1",
        "source_revision": "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71",
        "architecture_sha256": ("60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"),
    }
    values.update(overrides)
    return argparse.Namespace(**values)


def test_parser_has_exact_locked_arguments() -> None:
    destinations = {action.dest for action in build_parser()._actions}
    assert destinations == {
        "help",
        "candidates_file",
        "jobs_file",
        "test_file",
        "feature_schema_file",
        "test_lock_file",
        "split_manifest",
        "baseline_manifest_file",
        "initial_model_dir",
        "tuned_model_dir",
        "output_dir",
        "php_executable",
        "laravel_root",
        "evaluation_version",
        "source_revision",
        "architecture_sha256",
    }
    assert not {
        "train_file",
        "validation_file",
        "fit",
        "train",
        "tune",
        "override_existing",
    }.intersection(destinations)


@pytest.mark.parametrize(
    "command",
    [
        [str(PYTHON), "-m", "smart_recruitment_ml.evaluation", "--help"],
        [str(CONSOLE), "--help"],
    ],
)
def test_module_and_console_help(command: list[str]) -> None:
    result = subprocess.run(
        command,
        cwd=REPOSITORY_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0
    assert "--test-file" in result.stdout
    assert "--override-existing" not in result.stdout


def test_invalid_version_fails_before_any_source_open() -> None:
    with pytest.raises(ValueError, match="version or provenance"):
        evaluate(_args(evaluation_version="phase-9-is-prohibited"))


def test_real_preopen_gates_pass_without_parsing_locked_test(tmp_path: Path) -> None:
    context = validate_preopen(_real_args(output_dir=str(tmp_path / "not-published")))
    assert len(context.source_files) == 11
    assert len(context.test_candidate_ids) == 27
    assert len(context.prohibited_candidate_ids) == 153
    assert len(context.prohibited_pair_ids) == 9180


def test_existing_valid_output_is_recognized_and_never_overwritten(tmp_path: Path) -> None:
    output = tmp_path / "v1"
    output.mkdir()
    for name in OUTPUT_COUNTS:
        (output / name).write_text("{}\n", encoding="utf-8")
    output_files = [
        {
            "path": name,
            "record_count": count,
            "sha256": sha256_file(output / name),
            "size_bytes": (output / name).stat().st_size,
        }
        for name, count in OUTPUT_COUNTS.items()
    ]
    manifest = {
        "evaluation_session_version": "locked-final-test-v1",
        "output_files": output_files,
    }
    (output / "manifest.json").write_text(
        json.dumps(manifest),
        encoding="utf-8",
    )
    _validate_existing_output(output)
    original = (output / "metrics.json").read_bytes()
    with pytest.raises(FileExistsError, match="overwrite refused"):
        evaluate(_args(output_dir=str(output)))
    assert (output / "metrics.json").read_bytes() == original


def test_evaluation_source_has_no_training_or_model_serialization() -> None:
    source = inspect.getsource(
        __import__(
            "smart_recruitment_ml.evaluation.final_evaluator",
            fromlist=["dummy"],
        )
    )
    assert ".fit(" not in source
    assert "save_model" not in source
    assert "set_params" not in source
    assert "partial_fit" not in source
