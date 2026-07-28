"""CLI tests for deterministic feature Dataset generation."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

import pytest

from smart_recruitment_ml.features.pipeline import main

SERVICE_DIR = Path(__file__).resolve().parents[1]
SOURCE_DIR = SERVICE_DIR / "data" / "synthetic" / "v1"


def test_module_and_console_entry_point_help() -> None:
    module_result = subprocess.run(
        [sys.executable, "-m", "smart_recruitment_ml.features", "--help"],
        check=False,
        capture_output=True,
        text=True,
    )
    assert module_result.returncode == 0
    assert "--feature-schema-version" in module_result.stdout
    assert "--architecture-sha256" in module_result.stdout

    executable = Path(sys.executable).with_name("build-feature-dataset.exe")
    result = subprocess.run(
        [str(executable), "--help"],
        check=False,
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0
    assert "Build deterministic Shared Feature Dataset v1" in result.stdout


def test_cli_success_writes_only_four_artifacts_and_short_summary(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "features"
    result = main(
        [
            "--input-dir",
            str(SOURCE_DIR),
            "--output-dir",
            str(output_dir),
            "--feature-schema-version",
            "job-rec-features-v1",
            "--pipeline-version",
            "0.1.0",
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
    assert "Generated 10800 feature records with 103 features" in captured.out
    assert {path.name for path in output_dir.iterdir()} == {
        "feature_schema.json",
        "features.jsonl",
        "manifest.json",
        "FEATURE_SCHEMA_CARD.md",
    }


@pytest.mark.parametrize(
    ("argument", "value", "message"),
    [
        ("--feature-schema-version", "v2", "feature schema version"),
        ("--pipeline-version", "9.0.0", "pipeline version"),
        ("--source-revision", "bad", "source revision"),
        ("--architecture-sha256", "bad", "Architecture hash"),
    ],
)
def test_cli_rejects_unlocked_versions(
    argument: str,
    value: str,
    message: str,
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    result = main(
        [
            "--input-dir",
            str(SOURCE_DIR),
            "--output-dir",
            str(tmp_path / "output"),
            argument,
            value,
        ],
    )
    captured = capsys.readouterr()
    assert result == 2
    assert captured.out == ""
    assert f"Locked {message} mismatch" in captured.err
    assert not (tmp_path / "output").exists()


def test_cli_invalid_input_returns_nonzero_and_leaves_no_output(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    output_dir = tmp_path / "output"
    result = main(
        [
            "--input-dir",
            str(tmp_path / "missing"),
            "--output-dir",
            str(output_dir),
        ],
    )
    captured = capsys.readouterr()
    assert result == 2
    assert "Missing source files" in captured.err
    assert not output_dir.exists()
