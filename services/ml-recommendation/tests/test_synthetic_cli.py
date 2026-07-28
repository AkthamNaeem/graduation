"""CLI tests for deterministic synthetic Dataset generation."""

import runpy
import subprocess
import sys
from pathlib import Path

import pytest

from smart_recruitment_ml.data.generator import main


def test_module_help_works() -> None:
    result = subprocess.run(
        [sys.executable, "-m", "smart_recruitment_ml.data", "--help"],
        check=False,
        capture_output=True,
        text=True,
    )

    assert result.returncode == 0
    assert "--pairs-per-candidate" in result.stdout


def test_module_main_guard_dispatches_cli(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(sys, "argv", ["smart_recruitment_ml.data", "--help"])

    with pytest.raises(SystemExit) as exit_info:
        runpy.run_module("smart_recruitment_ml.data.__main__", run_name="__main__")

    assert exit_info.value.code == 0


def test_cli_generation_writes_five_files_and_short_summary(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "generated"

    result = main(
        [
            "--output-dir",
            str(output_dir),
            "--seed",
            "404",
            "--candidate-count",
            "24",
            "--job-count",
            "24",
            "--pairs-per-candidate",
            "20",
        ],
    )
    captured = capsys.readouterr()

    assert result == 0
    assert {path.name for path in output_dir.iterdir()} == {
        "candidates.jsonl",
        "jobs.jsonl",
        "pairs.jsonl",
        "manifest.json",
        "DATASET_CARD.md",
    }
    assert "Generated 24 Candidates, 24 Jobs, and 480 pairs" in captured.out
    assert "pair_cand_" not in captured.out


def test_invalid_cli_configuration_returns_nonzero_without_partial_output(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "invalid"

    result = main(
        [
            "--output-dir",
            str(output_dir),
            "--candidate-count",
            "12",
            "--job-count",
            "12",
            "--pairs-per-candidate",
            "5",
        ],
    )
    captured = capsys.readouterr()

    assert result == 2
    assert "Dataset generation failed" in captured.err
    assert not output_dir.exists()


def test_console_entry_point_is_installed() -> None:
    executable = Path(sys.executable).parent / "generate-synthetic-dataset.exe"

    assert executable.is_file()
    result = subprocess.run(
        [executable, "--help"],
        check=False,
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0
    assert "deterministic synthetic" in result.stdout
