"""CLI, pre-gate, aggregate receipt, and frozen-path contracts."""

from __future__ import annotations

import argparse
import os
import subprocess
from pathlib import Path

import numpy as np
import pytest

import smart_recruitment_ml.explainability.explainer as explainer_module
from smart_recruitment_ml.explainability.engine import (
    CombinedDataset,
    ContributionResult,
    validate_frozen_inputs,
)
from smart_recruitment_ml.explainability.explainer import (
    ARCHITECTURE_SHA256,
    SOURCE_REVISION,
    build_parser,
    explain,
)
from smart_recruitment_ml.explainability.feature_groups import EXPECTED_GROUP_COUNTS
from smart_recruitment_ml.explainability.selector import FrozenPrediction

REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
SERVICE_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"
PYTHON = SERVICE_ROOT / ".venv/Scripts/python.exe"
CONSOLE = SERVICE_ROOT / ".venv/Scripts/explain-tuned-xgbranker.exe"


def _args(tmp_path: Path, **overrides: str) -> argparse.Namespace:
    values = {
        "train_file": str(SERVICE_ROOT / "data/splits/v1/train.jsonl"),
        "validation_file": str(SERVICE_ROOT / "data/splits/v1/validation.jsonl"),
        "feature_schema_file": str(SERVICE_ROOT / "data/features/v1/feature_schema.json"),
        "tuned_model_dir": str(SERVICE_ROOT / "data/models/tuned/v1"),
        "final_train_validation_predictions_file": str(
            SERVICE_ROOT / "data/models/tuned/v1/final_train_validation_predictions.jsonl"
        ),
        "final_test_comparison_file": str(
            SERVICE_ROOT / "data/evaluations/final-test/v1/comparison.json"
        ),
        "final_test_receipt_file": str(
            SERVICE_ROOT / "data/evaluations/final-test/v1/evaluation_receipt.json"
        ),
        "final_test_manifest_file": str(
            SERVICE_ROOT / "data/evaluations/final-test/v1/manifest.json"
        ),
        "output_dir": str(tmp_path / "explainability"),
        "explanation_version": "xgbranker-tuned-explainability-v1",
        "source_revision": SOURCE_REVISION,
        "architecture_sha256": ARCHITECTURE_SHA256,
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
        "tuned_model_dir",
        "final_train_validation_predictions_file",
        "final_test_comparison_file",
        "final_test_receipt_file",
        "final_test_manifest_file",
        "output_dir",
        "explanation_version",
        "source_revision",
        "architecture_sha256",
    }
    for prohibited in (
        "test_file",
        "test_predictions_file",
        "train",
        "fit",
        "tune",
        "modify_model",
        "feature_selection",
        "approx_contribs",
        "interactions",
    ):
        assert prohibited not in destinations


@pytest.mark.parametrize(
    "command",
    [
        [str(PYTHON), "-m", "smart_recruitment_ml.explainability", "--help"],
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
    assert "--train-file" in result.stdout
    assert "--final-test-comparison-file" in result.stdout
    assert "--interactions" not in result.stdout


@pytest.mark.parametrize(
    ("field", "value", "message"),
    [
        ("architecture_sha256", "bad", "provenance"),
        ("source_revision", "bad", "provenance"),
        ("explanation_version", "bad", "provenance"),
    ],
)
def test_locked_version_mismatch_blocks(
    tmp_path: Path,
    field: str,
    value: str,
    message: str,
) -> None:
    with pytest.raises(ValueError, match=message):
        explain(_args(tmp_path, **{field: value}))
    assert not (tmp_path / "explainability").exists()


def test_frozen_aggregate_and_recovery_contract_passes() -> None:
    values = validate_frozen_inputs(
        tuned_model_dir=SERVICE_ROOT / "data/models/tuned/v1",
        predictions_path=(
            SERVICE_ROOT / "data/models/tuned/v1/final_train_validation_predictions.jsonl"
        ),
        comparison_path=SERVICE_ROOT / "data/evaluations/final-test/v1/comparison.json",
        receipt_path=SERVICE_ROOT / "data/evaluations/final-test/v1/evaluation_receipt.json",
        final_manifest_path=SERVICE_ROOT / "data/evaluations/final-test/v1/manifest.json",
    )
    assert values["comparison"]["quality_disposition"] == "PROMOTE_TO_EXPLAINABILITY"
    assert values["receipt"]["recovery_execution"] is True
    assert values["receipt"]["prior_test_results_observed"] is False


def _synthetic_explainability(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> argparse.Namespace:
    source_dir = tmp_path / "sources"
    model_dir = source_dir / "model"
    model_dir.mkdir(parents=True)
    paths = {
        "train_file": source_dir / "train-fixture.jsonl",
        "validation_file": source_dir / "validation-fixture.jsonl",
        "feature_schema_file": source_dir / "schema-fixture.json",
        "final_train_validation_predictions_file": source_dir / "predictions-fixture.jsonl",
        "final_test_comparison_file": source_dir / "comparison-fixture.json",
        "final_test_receipt_file": source_dir / "receipt-fixture.json",
        "final_test_manifest_file": source_dir / "manifest-fixture.json",
    }
    for path in (*paths.values(), model_dir / "model.json", model_dir / "model_metadata.json"):
        path.write_text("{}\n", encoding="utf-8")
    (model_dir / "manifest.json").write_text("{}\n", encoding="utf-8")

    feature_names = [f"feature_{index:03d}" for index in range(103)]
    families = [family for family, count in EXPECTED_GROUP_COUNTS.items() for _ in range(count)]
    definitions = [
        {"name": name, "family": families[index]} for index, name in enumerate(feature_names)
    ]
    candidate_ids = tuple(
        f"candidate_{candidate:03d}" for candidate in range(153) for _ in range(60)
    )
    job_ids = tuple(f"job_{job:02d}" for _ in range(153) for job in range(60))
    pair_ids = tuple(
        f"pair_{candidate:03d}_{job:02d}" for candidate in range(153) for job in range(60)
    )
    source_splits = ("train",) * 7560 + ("validation",) * 1620
    X = np.zeros((9180, 103), dtype=np.float32)
    y = np.zeros(9180, dtype=np.float32)
    dataset = CombinedDataset(
        pair_ids=pair_ids,
        candidate_ids=candidate_ids,
        job_ids=job_ids,
        source_splits=source_splits,
        X=X,
        y=y,
        train_count=7560,
        validation_count=1620,
        candidate_count=153,
    )
    contribution_row = np.linspace(-0.051, 0.051, 103, dtype=np.float32)
    contributions = np.empty((9180, 104), dtype=np.float32)
    contributions[:, :103] = contribution_row
    contributions[:, 103] = 0.25
    margins = np.full(
        9180,
        contribution_row.sum(dtype=np.float64) + 0.25,
        dtype=np.float32,
    )
    result = ContributionResult(
        contributions=contributions,
        margins=margins,
        scores=margins.copy(),
        original_shape=(9180, 1, 104),
        errors=np.zeros(9180, dtype=np.float64),
    )
    selections = [
        FrozenPrediction(
            pair_id=pair_ids[candidate * 60 + rank - 1],
            candidate_id=f"candidate_{candidate:03d}",
            job_id=f"job_{rank - 1:02d}",
            source_split="validation",
            model_rank=rank,
            model_score=float(margins[candidate * 60 + rank - 1]),
        )
        for candidate in range(126, 153)
        for rank in (1, 5, 10, 60)
    ]
    monkeypatch.setattr(
        explainer_module,
        "load_feature_schema",
        lambda _path: (
            feature_names,
            definitions,
            {"feature_schema_version": "job-rec-features-v1"},
        ),
    )
    monkeypatch.setattr(
        explainer_module,
        "validate_frozen_inputs",
        lambda **_kwargs: {
            "comparison": {"quality_disposition": "PROMOTE_TO_EXPLAINABILITY"},
            "receipt": {},
            "metadata": {},
            "final_manifest": {},
        },
    )
    monkeypatch.setattr(
        explainer_module,
        "load_combined_dataset",
        lambda _train, _validation: dataset,
    )
    monkeypatch.setattr(explainer_module, "load_booster", lambda _path, _names: object())
    monkeypatch.setattr(
        explainer_module,
        "compute_exact_contributions",
        lambda _booster, _dataset, _names: result,
    )
    monkeypatch.setattr(
        explainer_module,
        "select_frozen_predictions",
        lambda _path, *, expected_pair_ids: (
            selections if expected_pair_ids == set(pair_ids) else []
        ),
    )
    return _args(
        tmp_path,
        tuned_model_dir=str(model_dir),
        **{key: str(value) for key, value in paths.items()},
    )


def test_synthetic_atomic_build_and_summary(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    summary = explain(_synthetic_explainability(tmp_path, monkeypatch))
    output = tmp_path / "explainability"
    assert summary == {
        "output_dir": str(output.resolve()),
        "records": 9180,
        "features": 103,
        "groups": 10,
        "local_explanations": 108,
        "maximum_additivity_error": 0.0,
        "test_features_read": False,
        "test_predictions_read": False,
    }
    assert {path.name for path in output.iterdir()} == {
        "global_feature_importance.json",
        "feature_group_importance.json",
        "local_explanations.jsonl",
        "explainability_checks.json",
        "explanation_contract.json",
        "manifest.json",
        "MODEL_EXPLAINABILITY_REPORT.md",
    }
    assert not list(tmp_path.glob(".explainability-*"))


def test_atomic_publish_restores_previous_output_and_cleans_staging(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    args = _synthetic_explainability(tmp_path, monkeypatch)
    explain(args)
    output = tmp_path / "explainability"
    original_manifest = (output / "manifest.json").read_bytes()
    real_replace = os.replace
    calls = 0

    def fail_second_replace(source: str | Path, destination: str | Path) -> None:
        nonlocal calls
        calls += 1
        if calls == 2:
            raise OSError("synthetic publication failure")
        real_replace(source, destination)

    monkeypatch.setattr(explainer_module.os, "replace", fail_second_replace)
    with pytest.raises(OSError, match="synthetic publication failure"):
        explain(args)
    assert (output / "manifest.json").read_bytes() == original_manifest
    assert not (tmp_path / ".explainability-backup").exists()
    assert not list(tmp_path.glob(".explainability-stage-*"))


def test_invalid_cli_input_exits_nonzero(tmp_path: Path) -> None:
    command = [
        str(PYTHON),
        "-m",
        "smart_recruitment_ml.explainability",
        "--train-file",
        str(tmp_path / "missing.jsonl"),
    ]
    result = subprocess.run(
        command,
        cwd=REPOSITORY_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode != 0
