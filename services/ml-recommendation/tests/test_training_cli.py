"""Phase 8 module and console CLI contracts."""

from __future__ import annotations

import subprocess
from pathlib import Path
from typing import Any

import pytest

import smart_recruitment_ml.training.trainer as trainer_module
from smart_recruitment_ml.training.trainer import build_parser, main

SERVICE_ROOT = Path(__file__).resolve().parents[1]


def test_parser_has_exact_allowed_arguments() -> None:
    parser = build_parser()
    options = {
        option
        for action in parser._actions
        for option in action.option_strings
        if option not in {"-h", "--help"}
    }
    assert options == {
        "--train-file",
        "--validation-file",
        "--feature-schema-file",
        "--split-manifest",
        "--test-lock-file",
        "--baseline-metrics-file",
        "--baseline-manifest-file",
        "--output-dir",
        "--model-version",
        "--training-config-version",
        "--seed",
        "--source-revision",
        "--architecture-sha256",
    }


def test_module_help(capsys: pytest.CaptureFixture[str]) -> None:
    with pytest.raises(SystemExit) as exception:
        main(["--help"])
    assert exception.value.code == 0
    output = capsys.readouterr().out
    assert "fixed initial XGBRanker" in output
    assert "--train-file" in output
    assert "--validation-file" in output


def test_console_entry_point_help() -> None:
    executable = SERVICE_ROOT / ".venv/Scripts/train-initial-xgbranker.exe"
    process = subprocess.run(
        [str(executable), "--help"],
        capture_output=True,
        text=True,
        encoding="utf-8",
        check=False,
    )
    assert process.returncode == 0
    assert "--model-version" in process.stdout


def test_invalid_input_exits_nonzero() -> None:
    process = subprocess.run(
        [
            str(SERVICE_ROOT / ".venv/Scripts/python.exe"),
            "-m",
            "smart_recruitment_ml.training",
            "--train-file",
            "missing.jsonl",
        ],
        capture_output=True,
        text=True,
        encoding="utf-8",
        check=False,
    )
    assert process.returncode != 0
    assert "required" in process.stderr


def test_summary_is_short_and_contains_required_values(
    monkeypatch: pytest.MonkeyPatch,
    capsys: pytest.CaptureFixture[str],
) -> None:
    summary: dict[str, Any] = {
        "model_version": "xgbranker-initial-v1",
        "train_record_count": 7560,
        "validation_record_count": 1620,
        "validation_ndcg_at_5": 0.8,
        "validation_ndcg_at_10": 0.7,
        "delta_ndcg_at_5_vs_matching": 0.1,
        "delta_ndcg_at_10_vs_matching": 0.2,
        "model_path": "model.json",
        "test_evaluated": False,
    }
    monkeypatch.setattr(trainer_module, "train", lambda _args: summary)
    arguments = [
        "--train-file",
        "train",
        "--validation-file",
        "validation",
        "--feature-schema-file",
        "schema",
        "--split-manifest",
        "split",
        "--test-lock-file",
        "lock",
        "--baseline-metrics-file",
        "metrics",
        "--baseline-manifest-file",
        "manifest",
        "--output-dir",
        "output",
        "--model-version",
        "xgbranker-initial-v1",
        "--training-config-version",
        "xgbranker-fixed-config-v1",
        "--seed",
        "20260724",
        "--source-revision",
        "revision",
        "--architecture-sha256",
        "digest",
    ]
    assert main(arguments) == 0
    output = capsys.readouterr().out
    assert output.count("\n") == 1
    assert "Train 7560 / Validation 1620" in output
    assert "NDCG@5=0.800000" in output
    assert "Test evaluated=false" in output
