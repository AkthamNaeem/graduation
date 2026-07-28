"""Strict runtime loader for the self-contained inference bundle."""

from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any, Final

import xgboost as xgb
from pydantic import ValidationError

from smart_recruitment_ml.explainability.feature_groups import (
    build_feature_group_mapping,
)
from smart_recruitment_ml.features.pipeline import FEATURE_NAMES
from smart_recruitment_ml.schemas.bundle import (
    BundleManifest,
    ReasonCodeMapping,
    ScoreTransform,
)

if TYPE_CHECKING:
    from collections.abc import Mapping
    from pathlib import Path

EXPECTED_FILES: Final = frozenset(
    {
        "BUNDLE_CARD.md",
        "bundle_manifest.json",
        "explanation_contract.json",
        "feature_schema.json",
        "model.json",
        "model_metadata.json",
        "reason_code_mapping.json",
        "score_transform.json",
    },
)
DEPENDENT_FILES: Final = EXPECTED_FILES - {"bundle_manifest.json"}
MODEL_SHA256: Final = "3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e"
FEATURE_SCHEMA_SHA256: Final = "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0"
EXPLANATION_CONTRACT_SHA256: Final = (
    "d6d38967faab739c038bdad04df23d3ecd3683f3279c2ca7d39c95b17cd3b8a1"
)
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
EXPECTED_GROUPS: Final = frozenset(
    {
        "domain_compatibility",
        "nice_transferable_skills",
        "required_skills",
        "interactions",
        "career_level",
        "experience",
        "education",
        "preferences",
        "text_alignment",
        "missing_indicators",
    },
)


@dataclass(frozen=True, slots=True)
class LoadedBundle:
    """Validated runtime state loaded once during application startup."""

    directory: Path
    manifest: BundleManifest
    model_metadata: Mapping[str, Any]
    feature_schema: Mapping[str, Any]
    explanation_contract: Mapping[str, Any]
    score_transform: ScoreTransform
    reason_code_mapping: ReasonCodeMapping
    feature_group_by_name: Mapping[str, str]
    booster: xgb.Booster


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _json_object(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_bytes())
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ValueError(f"Invalid Bundle JSON: {path.name}.") from error
    if not isinstance(value, dict):
        raise ValueError(f"Bundle JSON must be an object: {path.name}.")
    return value


def _validate_artifacts(directory: Path, manifest: BundleManifest) -> None:
    actual_files = {path.name for path in directory.iterdir() if path.is_file()}
    if actual_files != EXPECTED_FILES:
        raise ValueError("Bundle file set mismatch.")
    by_name = {artifact.path: artifact for artifact in manifest.artifacts}
    if set(by_name) != DEPENDENT_FILES or len(by_name) != len(manifest.artifacts):
        raise ValueError("Bundle manifest artifact set mismatch.")
    for name in sorted(DEPENDENT_FILES):
        path = directory / name
        artifact = by_name[name]
        if path.stat().st_size != artifact.bytes:
            raise ValueError(f"Bundle artifact size mismatch: {name}.")
        if _sha256(path) != artifact.sha256:
            raise ValueError(f"Bundle artifact checksum mismatch: {name}.")


def _validate_metadata(
    manifest: BundleManifest,
    metadata: Mapping[str, Any],
    schema: Mapping[str, Any],
    explanation: Mapping[str, Any],
) -> tuple[list[str], list[dict[str, Any]]]:
    required_metadata = {
        "model_version": manifest.model_version,
        "model_format": manifest.model_format,
        "model_sha256": manifest.model_sha256,
        "feature_schema_version": manifest.feature_schema_version,
        "feature_schema_sha256": manifest.feature_schema_sha256,
        "feature_count": manifest.feature_count,
        "selected_config_id": manifest.selected_config_id,
        "source_revision": manifest.model_source_revision,
    }
    for key, expected in required_metadata.items():
        actual = metadata.get(key)
        if isinstance(expected, str) and isinstance(actual, str):
            matches = actual.casefold() == expected.casefold()
        else:
            matches = actual == expected
        if not matches:
            raise ValueError(f"Model metadata mismatch: {key}.")
    if metadata.get("hyperparameters", {}).get("objective") != "rank:ndcg":
        raise ValueError("Model objective mismatch.")
    if schema.get("feature_schema_version") != manifest.feature_schema_version:
        raise ValueError("Feature Schema version mismatch.")
    if schema.get("feature_count") != manifest.feature_count:
        raise ValueError("Feature count mismatch.")
    names = schema.get("feature_names")
    definitions = schema.get("feature_definitions")
    if not isinstance(names, list) or not all(isinstance(name, str) for name in names):
        raise ValueError("Feature names are invalid.")
    if names != list(FEATURE_NAMES):
        raise ValueError("Feature Schema order mismatch.")
    if not isinstance(definitions, list) or not all(isinstance(item, dict) for item in definitions):
        raise ValueError("Feature definitions are invalid.")
    typed_definitions = [dict(item) for item in definitions]
    if [item.get("name") for item in typed_definitions] != names:
        raise ValueError("Feature definition order mismatch.")
    if explanation.get("explanation_contract_version") != (manifest.explanation_contract_version):
        raise ValueError("Explanation contract version mismatch.")
    if explanation.get("model_version") != manifest.model_version:
        raise ValueError("Explanation contract Model mismatch.")
    if explanation.get("feature_schema_version") != manifest.feature_schema_version:
        raise ValueError("Explanation contract Feature Schema mismatch.")
    supported_splits = explanation.get("supported_source_splits")
    if supported_splits != ["validation"]:
        raise ValueError("Explanation contract source split mismatch.")
    return names, typed_definitions


def _validate_transform(transform: ScoreTransform) -> None:
    minimum = transform.minimum_raw_score
    maximum = transform.maximum_raw_score
    if not math.isfinite(minimum) or not math.isfinite(maximum) or minimum >= maximum:
        raise ValueError("Score transform range is invalid.")


def _validate_reason_mapping(
    mapping: ReasonCodeMapping,
    mapped_groups: set[str],
) -> None:
    groups = [item.feature_group for item in mapping.groups]
    if len(groups) != len(set(groups)) or set(groups) != EXPECTED_GROUPS:
        raise ValueError("Reason-code feature groups are incomplete.")
    if set(groups) != mapped_groups:
        raise ValueError("Reason-code mapping does not match Feature groups.")
    codes = [code for item in mapping.groups for code in (item.positive, item.negative)]
    if len(codes) != len(set(codes)):
        raise ValueError("Reason codes must be unique.")


def _load_booster(path: Path, feature_count: int) -> xgb.Booster:
    booster = xgb.Booster()
    booster.load_model(path)
    if booster.num_features() != feature_count:
        raise ValueError("Loaded Model feature count mismatch.")
    try:
        configuration = json.loads(booster.save_config())
        objective = configuration["learner"]["objective"]["name"]
    except (KeyError, TypeError, json.JSONDecodeError) as error:
        raise ValueError("Loaded Model configuration is invalid.") from error
    if objective != "rank:ndcg":
        raise ValueError("Loaded Model objective mismatch.")
    return booster


def load_bundle(bundle_dir: Path) -> LoadedBundle:
    """Load and validate the frozen Bundle without reading any external artifact."""
    directory = bundle_dir.resolve(strict=True)
    if not directory.is_dir():
        raise ValueError("Bundle path is not a directory.")
    try:
        manifest = BundleManifest.model_validate(
            _json_object(directory / "bundle_manifest.json"),
        )
    except ValidationError as error:
        raise ValueError("Bundle manifest contract mismatch.") from error
    if manifest.model_sha256 != MODEL_SHA256:
        raise ValueError("Bundle Model hash mismatch.")
    if manifest.feature_schema_sha256 != FEATURE_SCHEMA_SHA256:
        raise ValueError("Bundle Feature Schema hash mismatch.")
    if manifest.model_source_revision != SOURCE_REVISION:
        raise ValueError("Bundle source revision mismatch.")
    if manifest.dataset_version != "synthetic-job-rec-1.0.0":
        raise ValueError("Bundle Dataset version mismatch.")
    _validate_artifacts(directory, manifest)
    if _sha256(directory / "model.json") != MODEL_SHA256:
        raise ValueError("Model hash mismatch.")
    if _sha256(directory / "feature_schema.json") != FEATURE_SCHEMA_SHA256:
        raise ValueError("Feature Schema hash mismatch.")
    if _sha256(directory / "explanation_contract.json") != EXPLANATION_CONTRACT_SHA256:
        raise ValueError("Explanation contract hash mismatch.")
    metadata = _json_object(directory / "model_metadata.json")
    schema = _json_object(directory / "feature_schema.json")
    explanation = _json_object(directory / "explanation_contract.json")
    names, definitions = _validate_metadata(manifest, metadata, schema, explanation)
    try:
        transform = ScoreTransform.model_validate(
            _json_object(directory / "score_transform.json"),
        )
        mapping = ReasonCodeMapping.model_validate(
            _json_object(directory / "reason_code_mapping.json"),
        )
    except ValidationError as error:
        raise ValueError("Bundle generated contract mismatch.") from error
    _validate_transform(transform)
    if transform.score_transform_version != manifest.score_transform_version:
        raise ValueError("Score transform version mismatch.")
    if mapping.reason_code_mapping_version != manifest.reason_code_mapping_version:
        raise ValueError("Reason-code mapping version mismatch.")
    feature_group_by_name = build_feature_group_mapping(names, definitions)
    _validate_reason_mapping(mapping, set(feature_group_by_name.values()))
    booster = _load_booster(directory / "model.json", manifest.feature_count)
    return LoadedBundle(
        directory=directory,
        manifest=manifest,
        model_metadata=metadata,
        feature_schema=schema,
        explanation_contract=explanation,
        score_transform=transform,
        reason_code_mapping=mapping,
        feature_group_by_name=feature_group_by_name,
        booster=booster,
    )
