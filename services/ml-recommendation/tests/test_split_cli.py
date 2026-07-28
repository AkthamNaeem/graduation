"""CLI tests for Phase 6 candidate-group split generation."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import pytest

from smart_recruitment_ml.splits.splitter import main

SERVICE_DIR = Path(__file__).resolve().parents[1]
FEATURES_DIR = SERVICE_DIR / "data" / "features" / "v1"
CANDIDATES_FILE = SERVICE_DIR / "data" / "synthetic" / "v1" / "candidates.jsonl"


def test_split_module_and_console_help() -> None:
    module_result = subprocess.run(
        [sys.executable, "-m", "smart_recruitment_ml.splits", "--help"],
        check=False,
        capture_output=True,
        text=True,
    )
    assert module_result.returncode == 0
    assert "--features-dir" in module_result.stdout
    assert "--test-ratio" in module_result.stdout

    executable = Path(sys.executable).with_name("build-candidate-group-split.exe")
    result = subprocess.run(
        [str(executable), "--help"],
        check=False,
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0
    assert "Candidate-grouped Train/Validation/Test" in result.stdout


def test_split_cli_success_has_seven_files_and_short_summary(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "split"
    result = main(
        [
            "--features-dir",
            str(FEATURES_DIR),
            "--candidates-file",
            str(CANDIDATES_FILE),
            "--output-dir",
            str(output_dir),
            "--split-version",
            "candidate-group-split-v1",
            "--generator-version",
            "0.1.0",
            "--seed",
            "20260724",
            "--train-ratio",
            "0.70",
            "--validation-ratio",
            "0.15",
            "--test-ratio",
            "0.15",
            "--source-revision",
            "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71",
            "--architecture-sha256",
            "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F",
        ],
    )
    captured = capsys.readouterr()
    assert result == 0
    assert captured.err == ""
    assert captured.out.count("\n") == 1
    assert "train=126/7560, validation=27/1620, test=27/1620" in captured.out
    assert {path.name for path in output_dir.iterdir()} == {
        "train.jsonl",
        "validation.jsonl",
        "test.jsonl",
        "assignments.jsonl",
        "test_lock.json",
        "manifest.json",
        "SPLIT_CARD.md",
    }


@pytest.mark.parametrize(
    ("argument", "value", "message"),
    [
        ("--split-version", "v2", "split version"),
        ("--generator-version", "9.0.0", "generator version"),
        ("--source-revision", "bad", "source revision"),
        ("--architecture-sha256", "bad", "Architecture hash"),
    ],
)
def test_split_cli_rejects_unlocked_metadata(
    argument: str,
    value: str,
    message: str,
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "output"
    result = main(
        [
            "--features-dir",
            str(FEATURES_DIR),
            "--candidates-file",
            str(CANDIDATES_FILE),
            "--output-dir",
            str(output_dir),
            argument,
            value,
        ],
    )
    captured = capsys.readouterr()
    assert result == 2
    assert f"Locked {message} mismatch" in captured.err
    assert not output_dir.exists()


def test_split_cli_invalid_input_and_ratios_return_nonzero(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "output"
    ratio_result = main(
        [
            "--features-dir",
            str(FEATURES_DIR),
            "--candidates-file",
            str(CANDIDATES_FILE),
            "--output-dir",
            str(output_dir),
            "--train-ratio",
            "0.8",
        ],
    )
    ratio_capture = capsys.readouterr()
    assert ratio_result == 2
    assert "sum to 1.0" in ratio_capture.err
    assert not output_dir.exists()

    missing_result = main(
        [
            "--features-dir",
            str(tmp_path / "missing"),
            "--candidates-file",
            str(tmp_path / "missing.jsonl"),
            "--output-dir",
            str(output_dir),
        ],
    )
    missing_capture = capsys.readouterr()
    assert missing_result == 2
    assert "Missing source files" in missing_capture.err
    assert not output_dir.exists()
