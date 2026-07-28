"""Published Phase 11 artifact, manifest, and consumer-contract verification."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path
from typing import Any

import pytest

from smart_recruitment_ml.schemas.explainability import (
    ExplainabilityChecks,
    FeatureGroupRecord,
    GlobalFeatureRecord,
    LocalExplanation,
)
from smart_recruitment_ml.training.dataset import sha256_file

SERVICE_ROOT = Path(__file__).resolve().parents[1]
OUTPUT = SERVICE_ROOT / "data/explainability/tuned/v1"
NAMES = {
    "global_feature_importance.json",
    "feature_group_importance.json",
    "local_explanations.jsonl",
    "explainability_checks.json",
    "explanation_contract.json",
    "manifest.json",
    "MODEL_EXPLAINABILITY_REPORT.md",
}


def _json(name: str) -> dict[str, Any]:
    return json.loads((OUTPUT / name).read_text(encoding="utf-8"))


def test_exact_artifact_set_and_manifest_hashes() -> None:
    assert {path.name for path in OUTPUT.iterdir()} == NAMES
    manifest = _json("manifest.json")
    assert "manifest.json" not in {item["path"] for item in manifest["output_files"]}
    assert len(manifest["output_files"]) == 6
    for item in manifest["output_files"]:
        path = OUTPUT / item["path"]
        assert path.stat().st_size == item["size_bytes"]
        assert sha256_file(path) == item["sha256"]
    assert all(
        "test.jsonl" not in item["path"] and "test_predictions.jsonl" not in item["path"]
        for item in manifest["source_files"]
    )


def test_global_and_group_artifact_contracts() -> None:
    features = [
        GlobalFeatureRecord.model_validate(item)
        for item in _json("global_feature_importance.json")["features"]
    ]
    groups = [
        FeatureGroupRecord.model_validate(item)
        for item in _json("feature_group_importance.json")["feature_groups"]
    ]
    assert len(features) == 103
    assert [item.rank for item in features] == list(range(1, 104))
    assert sum(item.combined.normalized_importance_share for item in features) == (
        pytest.approx(1.0, abs=1e-10)
    )
    assert len(groups) == 10
    assert sum(item.combined.feature_count for item in groups) == 103
    assert sum(item.combined.normalized_importance_share for item in groups) == (
        pytest.approx(1.0, abs=1e-10)
    )


def test_local_artifact_contract_without_full_vector() -> None:
    values = [
        json.loads(line)
        for line in (OUTPUT / "local_explanations.jsonl").read_text("utf-8").splitlines()
    ]
    records = [LocalExplanation.model_validate(item) for item in values]
    assert len(records) == 108
    assert len({item.candidate_id for item in records}) == 27
    assert Counter(item.model_rank for item in records) == {1: 27, 5: 27, 10: 27, 60: 27}
    assert all(len(item.top_positive_factors) <= 5 for item in records)
    assert all(len(item.top_negative_factors) <= 5 for item in records)
    assert all(item.source_split == "validation" for item in records)
    assert all(item.additivity_error <= 1e-5 for item in records)
    assert all("contributions" not in value and "feature_values" not in value for value in values)


def test_checks_frozen_state_and_non_usage_flags() -> None:
    checks = ExplainabilityChecks.model_validate(_json("explainability_checks.json"))
    assert checks.input_contract == {
        "candidate_groups": 153,
        "combined_records": 9180,
        "feature_count": 103,
        "train_records": 7560,
        "validation_records": 1620,
    }
    assert checks.contribution_contract["actual_shape"] == [9180, 1, 104]
    assert checks.contribution_contract["nonfinite_count"] == 0
    assert checks.additivity["passed"] is True
    assert checks.additivity["failed_rows"] == 0
    assert checks.importance_normalization["passed"] is True
    assert not any(checks.frozen_state.values())
    assert not any(checks.test_non_usage.values())


def test_explanation_contract_semantics_and_prohibitions() -> None:
    contract = _json("explanation_contract.json")
    assert contract["local_factor_limit"] == 5
    assert contract["supported_source_splits"] == ["validation"]
    assert contract["prohibited_interpretations"] == [
        "not a probability",
        "not an acceptance prediction",
        "not a causal explanation",
        "not a fairness certification",
        "not an automatic hiring decision",
    ]
    assert set(contract["consumer_contract"]) == {
        "top_positive_factors",
        "top_negative_factors",
        "model_score",
        "model_rank",
        "explanation_note",
    }


def test_report_contains_required_limitations_and_gate() -> None:
    report = (OUTPUT / "MODEL_EXPLAINABILITY_REPORT.md").read_text(encoding="utf-8")
    for phrase in (
        "Exact native XGBoost Tree SHAP",
        "PROMOTE_TO_EXPLAINABILITY",
        "Test features and saved Test predictions were not read",
        "do not establish causality",
        "do not certify fairness",
        "automatic hiring decisions",
        "READY FOR PHASE 12",
    ):
        assert phrase in report
