"""Bundle Builder integrity, provenance, atomicity, and reproducibility tests."""

import hashlib
import json
from pathlib import Path

import pytest

from smart_recruitment_ml.bundle import builder

SERVICE_ROOT = Path(__file__).resolve().parents[1]
MODEL_DIR = SERVICE_ROOT / "data" / "models" / "tuned" / "v1"
FEATURE_SCHEMA = SERVICE_ROOT / "data" / "features" / "v1" / "feature_schema.json"
EXPLAINABILITY_DIR = SERVICE_ROOT / "data" / "explainability" / "tuned" / "v1"
VALIDATION_PREDICTIONS = MODEL_DIR / "selected_validation_predictions.jsonl"


def _build(output_dir: Path) -> None:
    builder.build_bundle(
        tuned_model_dir=MODEL_DIR,
        feature_schema_file=FEATURE_SCHEMA,
        explanation_contract_file=EXPLAINABILITY_DIR / "explanation_contract.json",
        explainability_manifest_file=EXPLAINABILITY_DIR / "manifest.json",
        selected_validation_predictions_file=VALIDATION_PREDICTIONS,
        output_dir=output_dir,
        bundle_version=builder.BUNDLE_VERSION,
        source_revision=builder.SOURCE_REVISION,
        architecture_sha256=builder.ARCHITECTURE_SHA256,
    )


def _hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def test_builder_publishes_exactly_eight_files(tmp_path: Path) -> None:
    output = tmp_path / "bundle"
    _build(output)

    assert {path.name for path in output.iterdir()} == {
        "BUNDLE_CARD.md",
        "bundle_manifest.json",
        "explanation_contract.json",
        "feature_schema.json",
        "model.json",
        "model_metadata.json",
        "reason_code_mapping.json",
        "score_transform.json",
    }


@pytest.mark.parametrize(
    ("source", "name"),
    [
        (MODEL_DIR / "model.json", "model.json"),
        (MODEL_DIR / "model_metadata.json", "model_metadata.json"),
        (FEATURE_SCHEMA, "feature_schema.json"),
        (EXPLAINABILITY_DIR / "explanation_contract.json", "explanation_contract.json"),
    ],
)
def test_frozen_copies_are_byte_identical(
    tmp_path: Path,
    source: Path,
    name: str,
) -> None:
    output = tmp_path / "bundle"
    _build(output)

    assert (output / name).read_bytes() == source.read_bytes()


def test_manifest_locks_model_and_feature_integrity(tmp_path: Path) -> None:
    output = tmp_path / "bundle"
    _build(output)
    manifest = json.loads((output / "bundle_manifest.json").read_bytes())

    assert manifest["model_sha256"] == builder.MODEL_SHA256
    assert manifest["feature_schema_sha256"] == builder.FEATURE_SCHEMA_SHA256
    assert manifest["feature_count"] == 103
    assert manifest["selected_config_id"] == "T06"
    assert manifest["test_non_usage"] == {
        "test_evaluation_rerun": False,
        "test_features_read": False,
        "test_inference_run": False,
        "test_predictions_read": False,
    }
    assert not any(manifest["frozen_state"].values())


def test_score_transform_uses_finite_t06_validation_range(tmp_path: Path) -> None:
    output = tmp_path / "bundle"
    _build(output)
    transform = json.loads((output / "score_transform.json").read_bytes())

    assert transform["source_record_count"] == 1620
    assert transform["source_config_id"] == "T06"
    assert transform["minimum_raw_score"] == pytest.approx(-4.985489368438721)
    assert transform["maximum_raw_score"] == pytest.approx(4.705573558807373)
    assert transform["minimum_raw_score"] < transform["maximum_raw_score"]
    assert transform["fit_source"] == "selected_validation_predictions_only"
    assert transform["locked_test_used"] is False
    assert transform["is_probability"] is False


def test_reason_mapping_covers_ten_groups_with_unique_codes(tmp_path: Path) -> None:
    output = tmp_path / "bundle"
    _build(output)
    mapping = json.loads((output / "reason_code_mapping.json").read_bytes())
    groups = mapping["groups"]
    codes = [code for item in groups for code in (item["positive"], item["negative"])]

    assert len(groups) == 10
    assert {item["feature_group"] for item in groups} == {item[0] for item in builder.REASON_CODES}
    assert len(codes) == len(set(codes)) == 20


def test_rebuild_is_byte_for_byte_deterministic(tmp_path: Path) -> None:
    first = tmp_path / "first"
    second = tmp_path / "second"
    _build(first)
    _build(second)

    assert {path.name: _hash(path) for path in sorted(first.iterdir())} == {
        path.name: _hash(path) for path in sorted(second.iterdir())
    }


def test_atomic_publish_cleans_temporary_directory_on_failure(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    output = tmp_path / "bundle"

    def fail_replace(_source: Path, _destination: Path) -> None:
        raise OSError("simulated publish failure")

    monkeypatch.setattr(builder.os, "replace", fail_replace)
    with pytest.raises(OSError, match="simulated"):
        _build(output)

    assert not output.exists()
    assert not (tmp_path / ".bundle.phase12-tmp").exists()
    assert not (tmp_path / ".bundle.phase12-backup").exists()


def test_invalid_locked_builder_argument_fails(tmp_path: Path) -> None:
    with pytest.raises(ValueError, match="Source revision"):
        builder.build_bundle(
            tuned_model_dir=MODEL_DIR,
            feature_schema_file=FEATURE_SCHEMA,
            explanation_contract_file=EXPLAINABILITY_DIR / "explanation_contract.json",
            explainability_manifest_file=EXPLAINABILITY_DIR / "manifest.json",
            selected_validation_predictions_file=VALIDATION_PREDICTIONS,
            output_dir=tmp_path / "bundle",
            bundle_version=builder.BUNDLE_VERSION,
            source_revision="0" * 40,
            architecture_sha256=builder.ARCHITECTURE_SHA256,
        )
