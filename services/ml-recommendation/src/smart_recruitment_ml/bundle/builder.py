"""Deterministic, atomic builder for the self-contained inference bundle."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import shutil
import sys
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final

from pydantic import ValidationError

from smart_recruitment_ml.schemas.bundle import (
    BundleArtifact,
    BundleManifest,
    FrozenState,
    ReasonCodeMapping,
    ReasonCodePair,
    ScoreTransform,
    TestNonUsage,
)

if TYPE_CHECKING:
    from collections.abc import Mapping, Sequence

BUNDLE_VERSION: Final = "job-rec-inference-bundle-v1"
BUNDLE_SCHEMA_VERSION: Final = "inference-bundle-schema-v1"
BUNDLE_BUILDER_VERSION: Final = "0.1.0"
BUNDLE_RELEASE_DATE: Final = "2026-07-25"
MODEL_VERSION: Final = "xgbranker-tuned-v1"
MODEL_FORMAT: Final = "xgboost-json-v1"
MODEL_SHA256: Final = "3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e"
MODEL_METADATA_SHA256: Final = "5485a2058d22777c3cafe9ea5871ac7534f555bfce6fb8275ddf89526358cb11"
FEATURE_SCHEMA_VERSION: Final = "job-rec-features-v1"
FEATURE_SCHEMA_SHA256: Final = "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0"
FEATURE_COUNT: Final = 103
EXPLANATION_CONTRACT_VERSION: Final = "recommendation-explanation-contract-v1"
EXPLANATION_CONTRACT_SHA256: Final = (
    "d6d38967faab739c038bdad04df23d3ecd3683f3279c2ca7d39c95b17cd3b8a1"
)
EXPLAINABILITY_MANIFEST_SHA256: Final = (
    "547907387e5c25707f7dad48e3f8ce6e2c2422535a83193a7f552b9608c619a7"
)
REASON_CODE_MAPPING_VERSION: Final = "recommendation-reason-codes-v1"
SCORE_TRANSFORM_VERSION: Final = "validation-minmax-selected-trial-t06-v1"
SCORE_SEMANTICS_VERSION: Final = "relevance-indicator-v1"
DATASET_VERSION: Final = "synthetic-job-rec-1.0.0"
SELECTED_CONFIG_ID: Final = "T06"
SELECTED_VALIDATION_SHA256: Final = (
    "a9bf4d7944cfa532e80219f4b5299ecdc058c6a2cea64508ff1bba0372e09321"
)
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60eb219152ce26b525735ed65564f667d403bf438f29000b4ece90d65950553f"
SOURCE_VALIDATION_ARTIFACT: Final = (
    "services/ml-recommendation/data/models/tuned/v1/selected_validation_predictions.jsonl"
)

REASON_CODES: Final = (
    ("domain_compatibility", "DOMAIN_ALIGNMENT", "DOMAIN_MISMATCH"),
    (
        "nice_transferable_skills",
        "OPTIONAL_SKILLS_ALIGNMENT",
        "OPTIONAL_SKILLS_GAP",
    ),
    (
        "required_skills",
        "REQUIRED_SKILLS_ALIGNMENT",
        "REQUIRED_SKILLS_GAP",
    ),
    (
        "interactions",
        "COMBINED_PROFILE_ALIGNMENT",
        "COMBINED_PROFILE_GAP",
    ),
    ("career_level", "CAREER_LEVEL_ALIGNMENT", "CAREER_LEVEL_GAP"),
    ("experience", "EXPERIENCE_ALIGNMENT", "EXPERIENCE_GAP"),
    ("education", "EDUCATION_ALIGNMENT", "EDUCATION_GAP"),
    (
        "preferences",
        "WORK_PREFERENCE_ALIGNMENT",
        "WORK_PREFERENCE_MISMATCH",
    ),
    ("text_alignment", "TEXT_ALIGNMENT", "TEXT_MISMATCH"),
    (
        "missing_indicators",
        "PROFILE_DATA_COMPLETENESS",
        "PROFILE_DATA_MISSING",
    ),
)


def _sha256(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def _json_bytes(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")


def _load_json(content: bytes, label: str) -> dict[str, Any]:
    try:
        parsed = json.loads(content)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ValueError(f"{label} is not valid JSON.") from error
    if not isinstance(parsed, dict):
        raise ValueError(f"{label} must contain a JSON object.")
    return parsed


def _read_locked(path: Path, expected_hash: str, label: str) -> bytes:
    content = path.read_bytes()
    if _sha256(content) != expected_hash:
        raise ValueError(f"{label} checksum mismatch.")
    return content


def _validate_sources(
    model: bytes,
    metadata: bytes,
    feature_schema: bytes,
    explanation_contract: bytes,
    explainability_manifest: bytes,
    source_revision: str,
    architecture_sha256: str,
) -> None:
    model_metadata = _load_json(metadata, "Model metadata")
    schema = _load_json(feature_schema, "Feature Schema")
    contract = _load_json(explanation_contract, "Explanation contract")
    explainability = _load_json(explainability_manifest, "Explainability manifest")
    expected_metadata = {
        "model_version": MODEL_VERSION,
        "model_format": MODEL_FORMAT,
        "model_sha256": MODEL_SHA256,
        "feature_schema_version": FEATURE_SCHEMA_VERSION,
        "feature_schema_sha256": FEATURE_SCHEMA_SHA256,
        "feature_count": FEATURE_COUNT,
        "selected_config_id": SELECTED_CONFIG_ID,
        "source_revision": source_revision,
        "architecture_sha256": architecture_sha256.upper(),
    }
    for key, expected in expected_metadata.items():
        actual = model_metadata.get(key)
        if isinstance(expected, str) and isinstance(actual, str):
            matches = actual.casefold() == expected.casefold()
        else:
            matches = actual == expected
        if not matches:
            raise ValueError(f"Model metadata {key} mismatch.")
    if _sha256(model) != MODEL_SHA256:
        raise ValueError("Model checksum mismatch.")
    if schema.get("feature_schema_version") != FEATURE_SCHEMA_VERSION:
        raise ValueError("Feature Schema version mismatch.")
    if schema.get("feature_count") != FEATURE_COUNT:
        raise ValueError("Feature count mismatch.")
    names = schema.get("feature_names")
    definitions = schema.get("feature_definitions")
    if not isinstance(names, list) or len(names) != FEATURE_COUNT:
        raise ValueError("Feature order is invalid.")
    if (
        not isinstance(definitions, list)
        or [item.get("name") for item in definitions if isinstance(item, dict)] != names
    ):
        raise ValueError("Feature definitions do not follow Feature order.")
    if contract.get("explanation_contract_version") != EXPLANATION_CONTRACT_VERSION:
        raise ValueError("Explanation contract version mismatch.")
    if contract.get("model_version") != MODEL_VERSION:
        raise ValueError("Explanation contract Model mismatch.")
    if contract.get("feature_schema_version") != FEATURE_SCHEMA_VERSION:
        raise ValueError("Explanation contract Feature Schema mismatch.")
    if explainability.get("explanation_contract_version") != EXPLANATION_CONTRACT_VERSION:
        raise ValueError("Explainability manifest contract mismatch.")
    if str(explainability.get("model_sha256", "")).casefold() != MODEL_SHA256:
        raise ValueError("Explainability manifest Model mismatch.")
    if explainability.get("phase_10_aggregate_disposition") != "PROMOTE_TO_EXPLAINABILITY":
        raise ValueError("Phase 10 disposition mismatch.")
    test_non_usage = explainability.get("test_non_usage")
    if not isinstance(test_non_usage, dict) or any(test_non_usage.values()):
        raise ValueError("Explainability manifest reports locked Test usage.")


def _score_transform(path: Path) -> ScoreTransform:
    content = _read_locked(
        path,
        SELECTED_VALIDATION_SHA256,
        "Selected Validation predictions",
    )
    scores: list[float] = []
    for line_number, line in enumerate(content.splitlines(), start=1):
        try:
            record = json.loads(line)
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise ValueError(
                f"Selected Validation prediction line {line_number} is invalid.",
            ) from error
        if not isinstance(record, dict) or record.get("config_id") != SELECTED_CONFIG_ID:
            raise ValueError("Selected Validation predictions must contain T06 only.")
        score = record.get("prediction_score")
        if isinstance(score, bool) or not isinstance(score, int | float):
            raise ValueError("Selected Validation prediction score is invalid.")
        numeric = float(score)
        if not math.isfinite(numeric):
            raise ValueError("Selected Validation prediction score is non-finite.")
        scores.append(numeric)
    if len(scores) != 1620:
        raise ValueError("Selected Validation prediction count mismatch.")
    minimum = min(scores)
    maximum = max(scores)
    if minimum >= maximum:
        raise ValueError("Selected Validation score range is invalid.")
    return ScoreTransform(
        score_transform_version=SCORE_TRANSFORM_VERSION,
        score_semantics_version=SCORE_SEMANTICS_VERSION,
        source_artifact=SOURCE_VALIDATION_ARTIFACT,
        source_artifact_sha256=SELECTED_VALIDATION_SHA256,
        source_record_count=1620,
        source_config_id=SELECTED_CONFIG_ID,
        minimum_raw_score=minimum,
        maximum_raw_score=maximum,
        formula=(
            "round(100 * clamp((raw_score - minimum_raw_score) / "
            "(maximum_raw_score - minimum_raw_score), 0, 1), 2)"
        ),
        clipping="Values below/above the Validation range are clipped to 0/100.",
        rounding="round_half_even_to_2_decimal_places",
        is_probability=False,
        is_acceptance_prediction=False,
        is_calibrated_probability=False,
        fit_source="selected_validation_predictions_only",
        locked_test_used=False,
        limitations=(
            "Validation-derived normalization is not probability calibration.",
            "Scores outside the selected Validation range are clipped.",
        ),
    )


def _reason_mapping() -> ReasonCodeMapping:
    return ReasonCodeMapping(
        reason_code_mapping_version=REASON_CODE_MAPPING_VERSION,
        explanation_contract_version=EXPLANATION_CONTRACT_VERSION,
        groups=tuple(
            ReasonCodePair(
                feature_group=group,
                positive=positive,
                negative=negative,
            )
            for group, positive, negative in REASON_CODES
        ),
    )


def _bundle_card(score_transform: ScoreTransform) -> bytes:
    return f"""# Inference Bundle Card

## Identity

- Bundle: `{BUNDLE_VERSION}`
- Bundle schema: `{BUNDLE_SCHEMA_VERSION}`
- Builder: `{BUNDLE_BUILDER_VERSION}`
- Model: `{MODEL_VERSION}` (`{MODEL_FORMAT}`)
- Feature Schema: `{FEATURE_SCHEMA_VERSION}` ({FEATURE_COUNT} ordered features)
- Explanation contract: `{EXPLANATION_CONTRACT_VERSION}`
- Reason codes: `{REASON_CODE_MAPPING_VERSION}`
- Release date: `{BUNDLE_RELEASE_DATE}`

## Runtime contract

The bundle is self-contained. Runtime loading reads only these eight files and
does not access training, tuning, evaluation, explainability source directories,
a database, a cache, or the network. The frozen XGBoost Model is loaded once
during FastAPI startup and is never trained, modified, or saved.

## Scores and explanations

`raw_score` is the frozen ranking margin. `display_score` applies
`{SCORE_TRANSFORM_VERSION}` using selected T06 Validation predictions only:
minimum `{score_transform.minimum_raw_score!r}`, maximum
`{score_transform.maximum_raw_score!r}`. Values outside that range are clipped
to 0 or 100. It is not a probability or acceptance prediction.

Explanations are exact Tree SHAP attributions aggregated into ten allowlisted
feature groups. At most three positive and three negative reason codes are
returned. Raw Feature names and values are not returned. Attribution is neither
causality, fairness certification, nor a hiring decision.

## Frozen-state evidence

- Test features read: `false`
- Test predictions read: `false`
- Test inference run: `false`
- Test evaluation rerun: `false`
- Training executed: `false`
- Tuning executed: `false`
- Model modified: `false`
- Feature Pipeline modified: `false`

## Limitations

The Model uses synthetic training data and handcrafted Features. Display
normalization is Validation-derived and clips outside that observed range.
Shared-secret deployment, production traffic, and Laravel integration require
later hardening and validation.
""".replace("\r\n", "\n").encode("utf-8")


def _artifact(name: str, content: bytes) -> BundleArtifact:
    return BundleArtifact(path=name, bytes=len(content), sha256=_sha256(content))


def _publish_atomically(output_dir: Path, artifacts: Mapping[str, bytes]) -> None:
    parent = output_dir.resolve(strict=False).parent
    parent.mkdir(parents=True, exist_ok=True)
    temporary = parent / f".{output_dir.name}.phase12-tmp"
    backup = parent / f".{output_dir.name}.phase12-backup"
    if temporary.exists() or backup.exists():
        raise ValueError("Atomic publish workspace is not clean.")
    temporary.mkdir()
    try:
        for name, content in sorted(artifacts.items()):
            (temporary / name).write_bytes(content)
        moved_existing = False
        if output_dir.exists():
            os.replace(output_dir, backup)
            moved_existing = True
        try:
            os.replace(temporary, output_dir)
        except OSError:
            if moved_existing:
                os.replace(backup, output_dir)
            raise
        if backup.exists():
            shutil.rmtree(backup)
    except Exception:
        if temporary.exists():
            shutil.rmtree(temporary)
        raise


def build_bundle(
    *,
    tuned_model_dir: Path,
    feature_schema_file: Path,
    explanation_contract_file: Path,
    explainability_manifest_file: Path,
    selected_validation_predictions_file: Path,
    output_dir: Path,
    bundle_version: str,
    source_revision: str,
    architecture_sha256: str,
) -> BundleManifest:
    """Validate frozen inputs and atomically publish all eight bundle files."""
    if bundle_version != BUNDLE_VERSION:
        raise ValueError("Bundle version mismatch.")
    if source_revision.casefold() != SOURCE_REVISION:
        raise ValueError("Source revision mismatch.")
    if architecture_sha256.casefold() != ARCHITECTURE_SHA256:
        raise ValueError("Architecture checksum mismatch.")
    model = _read_locked(tuned_model_dir / "model.json", MODEL_SHA256, "Model")
    metadata = _read_locked(
        tuned_model_dir / "model_metadata.json",
        MODEL_METADATA_SHA256,
        "Model metadata",
    )
    feature_schema = _read_locked(
        feature_schema_file,
        FEATURE_SCHEMA_SHA256,
        "Feature Schema",
    )
    explanation_contract = _read_locked(
        explanation_contract_file,
        EXPLANATION_CONTRACT_SHA256,
        "Explanation contract",
    )
    explainability_manifest = _read_locked(
        explainability_manifest_file,
        EXPLAINABILITY_MANIFEST_SHA256,
        "Explainability manifest",
    )
    _validate_sources(
        model,
        metadata,
        feature_schema,
        explanation_contract,
        explainability_manifest,
        source_revision,
        architecture_sha256,
    )
    score_transform = _score_transform(selected_validation_predictions_file)
    reason_mapping = _reason_mapping()
    score_bytes = _json_bytes(score_transform.model_dump(mode="json"))
    reason_bytes = _json_bytes(reason_mapping.model_dump(mode="json"))
    card_bytes = _bundle_card(score_transform)
    dependent = {
        "BUNDLE_CARD.md": card_bytes,
        "explanation_contract.json": explanation_contract,
        "feature_schema.json": feature_schema,
        "model.json": model,
        "model_metadata.json": metadata,
        "reason_code_mapping.json": reason_bytes,
        "score_transform.json": score_bytes,
    }
    manifest = BundleManifest(
        bundle_schema_version=BUNDLE_SCHEMA_VERSION,
        bundle_builder_version=BUNDLE_BUILDER_VERSION,
        bundle_version=BUNDLE_VERSION,
        bundle_release_date=BUNDLE_RELEASE_DATE,
        deterministic=True,
        model_version=MODEL_VERSION,
        model_format=MODEL_FORMAT,
        model_sha256=MODEL_SHA256,
        model_source_revision=SOURCE_REVISION,
        selected_config_id=SELECTED_CONFIG_ID,
        dataset_version=DATASET_VERSION,
        feature_schema_version=FEATURE_SCHEMA_VERSION,
        feature_schema_sha256=FEATURE_SCHEMA_SHA256,
        feature_count=FEATURE_COUNT,
        explanation_contract_version=EXPLANATION_CONTRACT_VERSION,
        reason_code_mapping_version=REASON_CODE_MAPPING_VERSION,
        score_transform_version=SCORE_TRANSFORM_VERSION,
        architecture_sha256=ARCHITECTURE_SHA256,
        artifacts=tuple(_artifact(name, content) for name, content in sorted(dependent.items())),
        test_non_usage=TestNonUsage(),
        frozen_state=FrozenState(),
    )
    artifacts = dict(dependent)
    artifacts["bundle_manifest.json"] = _json_bytes(manifest.model_dump(mode="json"))
    _publish_atomically(output_dir, artifacts)
    return manifest


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Build the deterministic self-contained inference Bundle.",
    )
    parser.add_argument("--tuned-model-dir", type=Path, required=True)
    parser.add_argument("--feature-schema-file", type=Path, required=True)
    parser.add_argument("--explanation-contract-file", type=Path, required=True)
    parser.add_argument("--explainability-manifest-file", type=Path, required=True)
    parser.add_argument("--selected-validation-predictions-file", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--bundle-version", required=True)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    """CLI entry point returning a stable nonzero status on contract failure."""
    args = _parser().parse_args(argv)
    try:
        manifest = build_bundle(
            tuned_model_dir=args.tuned_model_dir,
            feature_schema_file=args.feature_schema_file,
            explanation_contract_file=args.explanation_contract_file,
            explainability_manifest_file=args.explainability_manifest_file,
            selected_validation_predictions_file=args.selected_validation_predictions_file,
            output_dir=args.output_dir,
            bundle_version=args.bundle_version,
            source_revision=args.source_revision,
            architecture_sha256=args.architecture_sha256,
        )
    except (OSError, ValidationError, ValueError) as error:
        print(f"Inference Bundle build failed: {error}", file=sys.stderr)
        return 2
    print(
        f"Built {manifest.bundle_version} with {manifest.feature_count} features "
        f"at {args.output_dir}",
    )
    return 0
