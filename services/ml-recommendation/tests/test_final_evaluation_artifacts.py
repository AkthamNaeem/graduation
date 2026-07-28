"""Published Phase 10 artifact, one-shot, and reproducibility contracts."""

from __future__ import annotations

import json
from collections import defaultdict
from pathlib import Path
from typing import Any

import numpy as np

from smart_recruitment_ml.evaluation.final_evaluator import (
    OUTPUT_COUNTS,
    rebuild_derived_artifacts,
    sha256_file,
)
from smart_recruitment_ml.schemas.final_evaluation import (
    EvaluationReceipt,
    FinalTestPrediction,
)

SERVICE_ROOT = Path(__file__).resolve().parents[1]
OUTPUT_DIR = SERVICE_ROOT / "data/evaluations/final-test/v1"
ARTIFACT_NAMES = {*OUTPUT_COUNTS, "manifest.json"}


def _json(name: str) -> dict[str, Any]:
    return json.loads((OUTPUT_DIR / name).read_text(encoding="utf-8"))


def _records() -> list[FinalTestPrediction]:
    return [
        FinalTestPrediction.model_validate_json(line)
        for line in (OUTPUT_DIR / "test_predictions.jsonl").read_text(encoding="utf-8").splitlines()
    ]


def test_exact_artifacts_receipt_and_manifest_hashes() -> None:
    assert {path.name for path in OUTPUT_DIR.iterdir()} == ARTIFACT_NAMES
    receipt = EvaluationReceipt.model_validate_json(
        (OUTPUT_DIR / "evaluation_receipt.json").read_text(encoding="utf-8")
    )
    assert receipt.phase == 10
    assert receipt.test_records_parsed == 1620
    assert receipt.test_prediction_run_count == 1
    assert receipt.training_executed is False
    assert receipt.tuning_executed is False
    assert receipt.model_modified is False
    assert receipt.recovery_execution is True
    assert receipt.evaluation_attempt_number == 2
    assert receipt.prior_attempt_status == "failed_before_artifact_publication"
    assert receipt.prior_attempt_failure_stage == "system_score_conversion"
    assert receipt.prior_predictions_artifact_published is False
    assert receipt.prior_metrics_published is False
    assert receipt.prior_test_results_observed is False
    assert receipt.recovery_authorized_by_user is True
    assert receipt.model_changed_between_attempts is False
    assert receipt.feature_changed_between_attempts is False
    assert receipt.hyperparameters_changed_between_attempts is False
    assert receipt.selection_changed_between_attempts is False
    assert receipt.metrics_contract_changed_between_attempts is False
    assert receipt.training_run_between_attempts is False
    assert receipt.tuning_run_between_attempts is False
    manifest = _json("manifest.json")
    for field in (
        "recovery_execution",
        "evaluation_attempt_number",
        "prior_attempt_status",
        "prior_attempt_failure_stage",
        "prior_predictions_artifact_published",
        "prior_metrics_published",
        "prior_test_results_observed",
        "recovery_authorized_by_user",
        "model_changed_between_attempts",
        "feature_changed_between_attempts",
        "hyperparameters_changed_between_attempts",
        "selection_changed_between_attempts",
        "metrics_contract_changed_between_attempts",
        "training_run_between_attempts",
        "tuning_run_between_attempts",
    ):
        assert manifest[field] == receipt.model_dump(mode="json")[field]
    assert "manifest.json" not in {value["path"] for value in manifest["output_files"]}
    for output in manifest["output_files"]:
        path = OUTPUT_DIR / output["path"]
        assert output["sha256"] == sha256_file(path)
        assert output["size_bytes"] == path.stat().st_size
        assert output["record_count"] == OUTPUT_COUNTS[output["path"]]


def test_predictions_are_complete_finite_ordered_and_feature_free() -> None:
    records = _records()
    assert len(records) == 1620
    assert len({record.pair_id for record in records}) == 1620
    assert records == sorted(
        records,
        key=lambda value: (value.candidate_id, value.job_id, value.pair_id),
    )
    by_candidate: dict[str, list[FinalTestPrediction]] = defaultdict(list)
    for record in records:
        by_candidate[record.candidate_id].append(record)
        dumped = record.model_dump(mode="json")
        assert "feature_values" not in dumped
        for system in (
            record.skills_only,
            record.laravel_matching_2_0,
            record.python_matching_2_0,
            record.initial_xgbranker,
            record.tuned_xgbranker,
        ):
            assert np.isfinite(system.score)
    assert len(by_candidate) == 27
    for group in by_candidate.values():
        for field in (
            "skills_only",
            "laravel_matching_2_0",
            "python_matching_2_0",
            "initial_xgbranker",
            "tuned_xgbranker",
        ):
            assert {getattr(record, field).rank for record in group} == set(range(1, 61))


def test_metrics_comparison_parity_and_disposition() -> None:
    metrics = _json("metrics.json")
    comparison = _json("comparison.json")
    parity = _json("matching_parity.json")
    assert metrics["test_candidate_count"] == 27
    assert metrics["test_record_count"] == 1620
    assert all(
        summary["group_count"] == 27
        for system in (
            "skills_only",
            "laravel_matching_2_0",
            "python_matching_2_0",
            "initial_xgbranker",
            "tuned_xgbranker",
        )
        for summary in metrics[system].values()
    )
    assert parity["pair_count"] == 1620
    assert parity["missing_count"] == 0
    assert parity["extra_count"] == 0
    assert parity["database_query_count"] == 0
    assert parity["database_write_count"] == 0
    assert parity["parity_passed"] is True
    assert comparison["quality_disposition"] in {
        "PROMOTE_TO_EXPLAINABILITY",
        "HOLD_MODEL_CANDIDATE",
    }
    report = (OUTPUT_DIR / "FINAL_TEST_REPORT.md").read_text(encoding="utf-8")
    assert "## Controlled Recovery Disclosure" in report
    assert "No further Test execution is permitted." in report


def test_derived_artifacts_rebuild_without_predictions(tmp_path: Path) -> None:
    rebuild = tmp_path / "derived"
    rebuild_derived_artifacts(OUTPUT_DIR, rebuild)
    names = {
        "metrics.json",
        "comparison.json",
        "matching_parity.json",
        "evaluation_receipt.json",
        "manifest.json",
        "FINAL_TEST_REPORT.md",
    }
    assert {path.name for path in rebuild.iterdir()} == names
    assert not (rebuild / "test_predictions.jsonl").exists()
    for name in names:
        assert (rebuild / name).read_bytes() == (OUTPUT_DIR / name).read_bytes()
