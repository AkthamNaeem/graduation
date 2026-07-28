"""End-to-end contracts for Phase 7 baseline artifacts and CLI."""

import json
import subprocess
from pathlib import Path

import pytest

from smart_recruitment_ml.baselines.evaluator import main, sha256_file

REPOSITORY_ROOT = Path(__file__).parents[3]
ML_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"
BASELINES = ML_ROOT / "data/baselines/v1"
SOURCE_REVISION = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256 = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
ARTIFACT_NAMES = (
    "train_predictions.jsonl",
    "validation_predictions.jsonl",
    "metrics.json",
    "parity.json",
    "manifest.json",
    "BASELINE_REPORT.md",
)


def cli_args(output_dir: Path) -> list[str]:
    return [
        "--candidates-file",
        str(ML_ROOT / "data/synthetic/v1/candidates.jsonl"),
        "--jobs-file",
        str(ML_ROOT / "data/synthetic/v1/jobs.jsonl"),
        "--train-file",
        str(ML_ROOT / "data/splits/v1/train.jsonl"),
        "--validation-file",
        str(ML_ROOT / "data/splits/v1/validation.jsonl"),
        "--assignments-file",
        str(ML_ROOT / "data/splits/v1/assignments.jsonl"),
        "--feature-schema-file",
        str(ML_ROOT / "data/features/v1/feature_schema.json"),
        "--split-manifest",
        str(ML_ROOT / "data/splits/v1/manifest.json"),
        "--test-lock-file",
        str(ML_ROOT / "data/splits/v1/test_lock.json"),
        "--output-dir",
        str(output_dir),
        "--php-executable",
        "php",
        "--laravel-root",
        str(REPOSITORY_ROOT),
        "--source-revision",
        SOURCE_REVISION,
        "--architecture-sha256",
        ARCHITECTURE_SHA256,
    ]


def jsonl_records(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


def test_generated_artifact_counts_groups_and_unique_pairs() -> None:
    train = jsonl_records(BASELINES / "train_predictions.jsonl")
    validation = jsonl_records(BASELINES / "validation_predictions.jsonl")
    assert len(train) == 7560
    assert len(validation) == 1620
    assert len({record["candidate_id"] for record in train}) == 126
    assert len({record["candidate_id"] for record in validation}) == 27
    assert len({record["pair_id"] for record in [*train, *validation]}) == 9180


def test_every_candidate_group_has_sixty_predictions() -> None:
    for name in ("train_predictions.jsonl", "validation_predictions.jsonl"):
        counts: dict[str, int] = {}
        for record in jsonl_records(BASELINES / name):
            candidate_id = record["candidate_id"]
            counts[candidate_id] = counts.get(candidate_id, 0) + 1
        assert set(counts.values()) == {60}


def test_prediction_contract_excludes_vectors_and_raw_facts() -> None:
    record = jsonl_records(BASELINES / "train_predictions.jsonl")[0]
    assert set(record) == {
        "pair_id",
        "candidate_id",
        "job_id",
        "relevance_label",
        "skills_baseline",
        "laravel_matching_v2",
        "python_matching_v2_parity",
        "parity",
    }
    serialized = json.dumps(record)
    assert "feature_values" not in serialized
    assert "scenario" not in serialized
    assert "rationale" not in serialized


def test_no_locked_test_metrics_or_predictions() -> None:
    metrics = json.loads((BASELINES / "metrics.json").read_text(encoding="utf-8"))
    manifest = json.loads((BASELINES / "manifest.json").read_text(encoding="utf-8"))
    assert set(metrics["splits"]) == {"train", "validation"}
    assert manifest["test_evaluated"] is False
    locked = next(
        item for item in manifest["source_files"] if item["usage"] == "hash_verification_only"
    )
    assert locked["records_parsed"] is False


def test_manifest_output_and_production_hashes_are_current() -> None:
    manifest = json.loads((BASELINES / "manifest.json").read_text(encoding="utf-8"))
    for output in manifest["output_files"]:
        path = REPOSITORY_ROOT / output["path"]
        assert sha256_file(path) == output["sha256"]
        assert path.stat().st_size == output["size_bytes"]
    for source in manifest["production_matching_sources"]:
        path = REPOSITORY_ROOT / source["path"]
        assert sha256_file(path) == source["sha256"]


def test_parity_and_metric_equality_gates() -> None:
    parity = json.loads((BASELINES / "parity.json").read_text(encoding="utf-8"))
    metrics = json.loads((BASELINES / "metrics.json").read_text(encoding="utf-8"))
    assert parity["parity_passed"] is True
    assert parity["database_query_count"] == parity["database_write_count"] == 0
    for split in ("train", "validation"):
        assert parity[split]["missing_count"] == parity[split]["extra_count"] == 0
        assert parity[split]["rank_match_rate"] == 1.0
        assert (
            metrics["splits"][split]["laravel_matching_2.0"]
            == (metrics["splits"][split]["python_matching_v2_parity"])
        )


def test_report_has_exact_twenty_headings() -> None:
    report = (BASELINES / "BASELINE_REPORT.md").read_text(encoding="utf-8")
    headings = [line for line in report.splitlines() if line.startswith("## ")]
    assert len(headings) == 20
    assert headings[0] == "## 1. Baseline"
    assert headings[-1] == "## 20. Exact Repository State"
    assert "Phase 8 — Initial XGBRanker Training" in report
    assert "READY FOR PHASE 8" in report


def test_cli_module_help_has_no_test_argument(capsys: pytest.CaptureFixture[str]) -> None:
    with pytest.raises(SystemExit) as exception:
        main(["--help"])
    assert exception.value.code == 0
    help_text = capsys.readouterr().out
    assert "--train-file" in help_text
    assert "--validation-file" in help_text
    assert "--test-file" not in help_text


def test_console_entry_point_help() -> None:
    executable = ML_ROOT / ".venv/Scripts/evaluate-existing-baselines.exe"
    process = subprocess.run(
        [str(executable), "--help"],
        capture_output=True,
        text=True,
        encoding="utf-8",
        check=False,
    )
    assert process.returncode == 0
    assert "--test-file" not in process.stdout


def test_invalid_input_returns_nonzero_without_partial_output(tmp_path: Path) -> None:
    output = tmp_path / "invalid"
    args = cli_args(output)
    index = args.index("--train-file") + 1
    args[index] = str(ML_ROOT / "data/splits/v1/test.jsonl")
    with pytest.raises(ValueError, match="SHA-256 mismatch"):
        main(args)
    assert not output.exists()


def test_clean_regeneration_is_byte_for_byte_deterministic(
    tmp_path: Path,
    capsys: pytest.CaptureFixture[str],
) -> None:
    regenerated = tmp_path / "regenerated"
    assert main(cli_args(regenerated)) == 0
    summary = capsys.readouterr().out
    assert "Train 7,560 / Validation 1,620" in summary
    assert "Locked Test not evaluated" in summary
    for name in ARTIFACT_NAMES:
        assert (regenerated / name).read_bytes() == (BASELINES / name).read_bytes()
