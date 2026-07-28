"""Phase 11 exact Tree SHAP artifact builder for the frozen tuned ranker."""

from __future__ import annotations

import argparse
import json
import os
import platform
import shutil
import tempfile
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final

import numpy as np
import scipy  # type: ignore[import-untyped]
import xgboost
from pydantic import TypeAdapter

from smart_recruitment_ml.schemas.explainability import (
    ExplainabilityChecks,
    FeatureGroupRecord,
    GlobalFeatureRecord,
    LocalExplanation,
)
from smart_recruitment_ml.training.dataset import sha256_file

from . import (
    ATTRIBUTION_METHOD_VERSION,
    EXPLAINABILITY_RELEASE_DATE,
    EXPLANATION_CONTRACT_VERSION,
    EXPLANATION_PIPELINE_VERSION,
    EXPLANATION_VERSION,
    FEATURE_GROUP_MAPPING_VERSION,
    LOCAL_SELECTION_POLICY_VERSION,
    MODEL_VERSION,
)
from .aggregation import (
    aggregate_global_importance,
    aggregate_group_importance,
    stability_checks,
)
from .engine import (
    FEATURE_SCHEMA_SHA256,
    MODEL_SHA256,
    compute_exact_contributions,
    load_booster,
    load_combined_dataset,
    load_feature_schema,
    validate_frozen_inputs,
)
from .feature_groups import build_feature_group_mapping
from .selector import build_local_explanations, select_frozen_predictions

if TYPE_CHECKING:
    from collections.abc import Iterable, Sequence

SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
OUTPUT_COUNTS: Final = {
    "global_feature_importance.json": 103,
    "feature_group_importance.json": 10,
    "local_explanations.jsonl": 108,
    "explainability_checks.json": 1,
    "explanation_contract.json": 1,
    "MODEL_EXPLAINABILITY_REPORT.md": 1,
}
EXPECTED_FILES: Final = {*OUTPUT_COUNTS, "manifest.json"}
TEST_NON_USAGE: Final = {
    "test_features_read": False,
    "test_predictions_read": False,
    "test_explanations_generated": False,
    "test_evaluation_rerun": False,
}


def _json_content(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def _jsonl_content(records: Iterable[LocalExplanation]) -> str:
    return "".join(
        json.dumps(
            record.model_dump(mode="json"),
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        )
        + "\n"
        for record in records
    )


def _write_text(path: Path, content: str) -> None:
    path.write_text(content, encoding="utf-8", newline="\n")


def _artifact_entry(path: Path, record_count: int) -> dict[str, Any]:
    return {
        "path": path.name,
        "record_count": record_count,
        "size_bytes": path.stat().st_size,
        "sha256": sha256_file(path),
    }


def _portable_path(path: Path) -> str:
    normalized = path.resolve().as_posix()
    marker = "/services/ml-recommendation/"
    if marker in normalized:
        return "services/ml-recommendation/" + normalized.split(marker, 1)[1]
    return path.as_posix()


def _source_entry(path: Path, record_count: int, usage: str) -> dict[str, Any]:
    return {
        "path": _portable_path(path),
        "record_count": record_count,
        "records_parsed": True,
        "sha256": sha256_file(path),
        "size_bytes": path.stat().st_size,
        "usage": usage,
    }


def _explanation_contract() -> dict[str, Any]:
    return {
        "explanation_contract_version": EXPLANATION_CONTRACT_VERSION,
        "model_version": MODEL_VERSION,
        "attribution_method_version": ATTRIBUTION_METHOD_VERSION,
        "feature_schema_version": "job-rec-features-v1",
        "score_semantics": (
            "A raw XGBoost ranking score on the model margin scale; it is not a probability "
            "or acceptance likelihood."
        ),
        "positive_factor_semantics": (
            "Positive exact Tree SHAP contributions increase this pair's model score "
            "relative to the bias."
        ),
        "negative_factor_semantics": (
            "Negative exact Tree SHAP contributions decrease this pair's model score "
            "relative to the bias."
        ),
        "bias_semantics": "The XGBoost contribution bias on the raw margin scale.",
        "additivity_semantics": (
            "The feature contributions plus bias reconstruct the raw model margin "
            "within absolute tolerance 1e-5."
        ),
        "local_factor_limit": 5,
        "local_selection_policy": LOCAL_SELECTION_POLICY_VERSION,
        "supported_source_splits": ["validation"],
        "consumer_contract": {
            "top_positive_factors": "Display at most five score-increasing factors.",
            "top_negative_factors": "Display at most five score-decreasing factors.",
            "model_score": "Display only as a ranking score.",
            "model_rank": "Display as the within-candidate rank from the frozen artifact.",
            "explanation_note": (
                "AI assistant only: attribution supports human review and never makes "
                "an automatic hiring decision."
            ),
        },
        "prohibited_interpretations": [
            "not a probability",
            "not an acceptance prediction",
            "not a causal explanation",
            "not a fairness certification",
            "not an automatic hiring decision",
        ],
        "limitations": [
            "synthetic development data",
            "in-sample final model explanations",
            "handcrafted features",
            "feature importance may hide interactions",
            "no fairness guarantee",
            "no Test explanations",
            "no Production traffic validation",
        ],
    }


def _report(
    features: list[GlobalFeatureRecord],
    groups: list[FeatureGroupRecord],
    locals_: list[LocalExplanation],
    checks: ExplainabilityChecks,
) -> str:
    additivity = checks.additivity
    stability = checks.stability
    lines = [
        "# Model Explainability Report",
        "",
        "## 1. Scope",
        "",
        "Phase 11 explains the frozen tuned ranker using Train and Validation only.",
        "",
        "## 2. Frozen model details",
        "",
        f"- Model: `{MODEL_VERSION}`",
        f"- SHA-256: `{MODEL_SHA256}`",
        "- Objective: `rank:ndcg`",
        "- Selected configuration: `T06`",
        "",
        "## 3. Explainability method",
        "",
        "Exact native XGBoost Tree SHAP contributions were requested with "
        "`pred_contribs=True`, `approx_contribs=False`, and `strict_shape=True`. "
        "No SHAP dependency or interaction values were used.",
        "",
        "## 4. Exact SHAP contribution contract",
        "",
        "The 103 feature contributions exclude the separate bias term. Values and "
        "model scores are on the raw ranking-margin scale.",
        "",
        "## 5. Additivity verification",
        "",
        f"- Rows: {additivity['rows_checked']}",
        f"- Shape: `{checks.contribution_contract['actual_shape']}`",
        f"- Maximum absolute error: {additivity['maximum_absolute_error']:.12g}",
        f"- Mean absolute error: {additivity['mean_absolute_error']:.12g}",
        f"- Failed rows: {additivity['failed_rows']}",
        "",
        "## 6. Global top Features",
        "",
        "| Rank | Feature | Group | Mean Abs | Share | Mean Signed |",
        "| ---: | --- | --- | ---: | ---: | ---: |",
    ]
    lines.extend(
        (
            f"| {item.rank} | `{item.feature_name}` | `{item.feature_group}` | "
            f"{item.combined.mean_absolute_contribution:.10g} | "
            f"{item.combined.normalized_importance_share:.10g} | "
            f"{item.combined.mean_signed_contribution:.10g} |"
        )
        for item in features[:20]
    )
    lines.extend(
        [
            "",
            "## 7. Feature Group importance",
            "",
            "| Rank | Group | Features | Mean Abs Sum | Share | Mean Signed |",
            "| ---: | --- | ---: | ---: | ---: | ---: |",
        ]
    )
    lines.extend(
        (
            f"| {item.rank} | `{item.feature_group}` | "
            f"{item.combined.feature_count} | "
            f"{item.combined.sum_mean_absolute_contribution:.10g} | "
            f"{item.combined.normalized_importance_share:.10g} | "
            f"{item.combined.mean_signed_contribution:.10g} |"
        )
        for item in groups
    )
    lines.extend(
        [
            "",
            "## 8. Train/Validation stability",
            "",
            f"- Spearman: {stability['spearman']:.10g}",
            f"- Top-10 overlap/Jaccard: {stability['top_10_overlap']} / "
            f"{stability['top_10_jaccard']:.10g}",
            f"- Top-20 overlap/Jaccard: {stability['top_20_overlap']} / "
            f"{stability['top_20_jaccard']:.10g}",
            "- Descriptive only; no model decision was made from stability.",
            "",
            "## 9. Local selection policy",
            "",
            "For all 27 validation-origin candidates, frozen ranks 1, 5, 10, and 60 "
            "produce 108 explanations. Selection did not use labels or contributions.",
            "",
            "## 10. Local explanation examples",
            "",
        ]
    )
    examples = [next(item for item in locals_ if item.model_rank == rank) for rank in (1, 10, 60)]
    for item in examples:
        positive = ", ".join(f"`{factor.feature_name}`" for factor in item.top_positive_factors)
        negative = ", ".join(f"`{factor.feature_name}`" for factor in item.top_negative_factors)
        lines.extend(
            [
                f"- Rank {item.model_rank}, pair `{item.pair_id}`: score "
                f"{item.model_score:.10g}; positive [{positive}]; negative [{negative}].",
            ]
        )
    lines.extend(
        [
            "",
            "## 11. Explanation Contract",
            "",
            f"`{EXPLANATION_CONTRACT_VERSION}` is prepared for Phase 12 consumers. "
            "It limits each direction to five traceable factors.",
            "",
            "## 12. Final Test aggregate disposition",
            "",
            "`PROMOTE_TO_EXPLAINABILITY` (aggregate Phase 10 artifact only).",
            "",
            "## 13. Test non-usage confirmation",
            "",
            "Test features and saved Test predictions were not read; no Test explanations "
            "were generated and the Final Test evaluation was not rerun.",
            "",
            "## 14. No model or Feature modification",
            "",
            "Training, tuning, model modification, feature modification, calibration, "
            "and selection changes were not executed.",
            "",
            "## 15. No SHAP interaction values",
            "",
            "Only single-feature exact contributions were computed.",
            "",
            "## 16. No new dependency",
            "",
            "Native XGBoost contributions were used; no dependency was added.",
            "",
            "## 17. Limitations",
            "",
            "Synthetic development data, in-sample final-model explanation, handcrafted "
            "features, hidden interactions, no Test explanations, and no Production traffic.",
            "",
            "## 18. Fairness disclaimer",
            "",
            "These artifacts do not certify fairness.",
            "",
            "## 19. Non-causal interpretation",
            "",
            "SHAP values attribute the model score; they do not establish causality.",
            "",
            "## 20. AI assistant-only rule",
            "",
            "Explanations support human review and must not make automatic hiring decisions.",
            "",
            "## 21. Readiness for Phase 12",
            "",
            "READY FOR PHASE 12",
            "",
        ]
    )
    return "\n".join(lines)


def _validate_artifacts(directory: Path) -> None:
    if {path.name for path in directory.iterdir()} != EXPECTED_FILES:
        raise ValueError("Explainability artifact set mismatch.")
    global_value = json.loads((directory / "global_feature_importance.json").read_text("utf-8"))
    group_value = json.loads((directory / "feature_group_importance.json").read_text("utf-8"))
    checks_value = json.loads((directory / "explainability_checks.json").read_text("utf-8"))
    TypeAdapter(list[GlobalFeatureRecord]).validate_python(global_value["features"])
    TypeAdapter(list[FeatureGroupRecord]).validate_python(group_value["feature_groups"])
    ExplainabilityChecks.model_validate(checks_value)
    with (directory / "local_explanations.jsonl").open(encoding="utf-8") as handle:
        local_values = [LocalExplanation.model_validate_json(line) for line in handle]
    if len(local_values) != 108:
        raise ValueError("Local explainability artifact count mismatch.")
    manifest = json.loads((directory / "manifest.json").read_text("utf-8"))
    for output in manifest.get("output_files", []):
        path = directory / str(output["path"])
        if (
            not path.is_file()
            or sha256_file(path) != output["sha256"]
            or path.stat().st_size != output["size_bytes"]
        ):
            raise ValueError(f"Explainability manifest mismatch: {path}.")


def explain(args: argparse.Namespace) -> dict[str, Any]:
    if (
        args.explanation_version != EXPLANATION_VERSION
        or args.source_revision != SOURCE_REVISION
        or args.architecture_sha256 != ARCHITECTURE_SHA256
    ):
        raise ValueError("Locked Phase 11 version or provenance mismatch.")
    if (
        platform.python_version() != "3.12.10"
        or np.__version__ != "2.5.1"
        or scipy.__version__ != "1.18.0"
        or xgboost.__version__ != "3.3.0"
    ):
        raise ValueError("Pinned Phase 11 runtime mismatch.")

    train_path = Path(args.train_file).resolve()
    validation_path = Path(args.validation_file).resolve()
    schema_path = Path(args.feature_schema_file).resolve()
    model_dir = Path(args.tuned_model_dir).resolve()
    predictions_path = Path(args.final_train_validation_predictions_file).resolve()
    comparison_path = Path(args.final_test_comparison_file).resolve()
    receipt_path = Path(args.final_test_receipt_file).resolve()
    final_manifest_path = Path(args.final_test_manifest_file).resolve()
    output_dir = Path(args.output_dir).resolve()

    feature_names, definitions, _schema = load_feature_schema(schema_path)
    feature_groups = build_feature_group_mapping(feature_names, definitions)
    frozen = validate_frozen_inputs(
        tuned_model_dir=model_dir,
        predictions_path=predictions_path,
        comparison_path=comparison_path,
        receipt_path=receipt_path,
        final_manifest_path=final_manifest_path,
    )
    dataset = load_combined_dataset(train_path, validation_path)
    if (
        dataset.X.shape != (9180, 103)
        or dataset.candidate_count != 153
        or not np.isfinite(dataset.X).all()
    ):
        raise ValueError("Combined explainability data contract mismatch.")
    booster = load_booster(model_dir / "model.json", feature_names)
    result = compute_exact_contributions(booster, dataset, feature_names)
    features = aggregate_global_importance(
        result.contributions,
        train_count=dataset.train_count,
        feature_names=feature_names,
        feature_groups=feature_groups,
    )
    groups = aggregate_group_importance(features)
    stability = stability_checks(features)
    selections = select_frozen_predictions(
        predictions_path,
        expected_pair_ids=set(dataset.pair_ids),
    )
    locals_, local_checks = build_local_explanations(
        selections=selections,
        dataset=dataset,
        result=result,
        feature_names=feature_names,
        feature_groups=feature_groups,
    )
    feature_share = sum(item.combined.normalized_importance_share for item in features)
    group_share = sum(item.combined.normalized_importance_share for item in groups)
    checks = ExplainabilityChecks(
        explanation_version=EXPLANATION_VERSION,
        model_version=MODEL_VERSION,
        attribution_method_version=ATTRIBUTION_METHOD_VERSION,
        input_contract={
            "train_records": dataset.train_count,
            "validation_records": dataset.validation_count,
            "combined_records": len(dataset.pair_ids),
            "candidate_groups": dataset.candidate_count,
            "feature_count": dataset.X.shape[1],
        },
        contribution_contract={
            "expected_shape": [9180, 1, 104],
            "actual_shape": list(result.original_shape),
            "finite_count": int(np.isfinite(result.contributions).sum()),
            "nonfinite_count": int(np.size(result.contributions))
            - int(np.isfinite(result.contributions).sum()),
        },
        additivity={
            "rows_checked": len(result.errors),
            "maximum_absolute_error": float(result.errors.max()),
            "mean_absolute_error": float(result.errors.mean()),
            "failed_rows": int(np.count_nonzero(result.errors > 1e-5)),
            "tolerance": 1e-5,
            "passed": bool(np.all(result.errors <= 1e-5)),
        },
        bias={
            "minimum": float(result.contributions[:, 103].min()),
            "maximum": float(result.contributions[:, 103].max()),
            "mean": float(np.mean(result.contributions[:, 103], dtype=np.float64)),
            "standard_deviation": float(np.std(result.contributions[:, 103], dtype=np.float64)),
        },
        importance_normalization={
            "feature_share_sum": feature_share,
            "group_share_sum": group_share,
            "passed": abs(feature_share - 1.0) <= 1e-10 and abs(group_share - 1.0) <= 1e-10,
        },
        stability=stability,
        local_explanations=local_checks,
        frozen_state={
            "training_executed": False,
            "tuning_executed": False,
            "model_modified": False,
            "feature_schema_modified": False,
        },
        test_non_usage=TEST_NON_USAGE,
    )
    if not checks.additivity["passed"] or not checks.importance_normalization["passed"]:
        raise ValueError("Explainability validation failed before publication.")

    output_dir.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}-stage-", dir=output_dir.parent))
    backup = output_dir.with_name(f".{output_dir.name}-backup")
    published = False
    try:
        _write_text(
            stage / "global_feature_importance.json",
            _json_content(
                {
                    "explanation_version": EXPLANATION_VERSION,
                    "feature_count": 103,
                    "features": [item.model_dump(mode="json") for item in features],
                }
            ),
        )
        _write_text(
            stage / "feature_group_importance.json",
            _json_content(
                {
                    "explanation_version": EXPLANATION_VERSION,
                    "feature_group_mapping_version": FEATURE_GROUP_MAPPING_VERSION,
                    "feature_groups": [item.model_dump(mode="json") for item in groups],
                }
            ),
        )
        _write_text(stage / "local_explanations.jsonl", _jsonl_content(locals_))
        _write_text(
            stage / "explainability_checks.json",
            _json_content(checks.model_dump(mode="json")),
        )
        _write_text(stage / "explanation_contract.json", _json_content(_explanation_contract()))
        _write_text(
            stage / "MODEL_EXPLAINABILITY_REPORT.md",
            _report(features, groups, locals_, checks),
        )
        output_files = [
            _artifact_entry(stage / name, count) for name, count in OUTPUT_COUNTS.items()
        ]
        manifest = {
            "explanation_version": EXPLANATION_VERSION,
            "explanation_pipeline_version": EXPLANATION_PIPELINE_VERSION,
            "attribution_method_version": ATTRIBUTION_METHOD_VERSION,
            "feature_group_mapping_version": FEATURE_GROUP_MAPPING_VERSION,
            "local_selection_policy_version": LOCAL_SELECTION_POLICY_VERSION,
            "explanation_contract_version": EXPLANATION_CONTRACT_VERSION,
            "model_version": MODEL_VERSION,
            "model_sha256": MODEL_SHA256,
            "feature_schema_version": "job-rec-features-v1",
            "feature_schema_sha256": FEATURE_SCHEMA_SHA256,
            "source_revision": SOURCE_REVISION,
            "architecture_sha256": ARCHITECTURE_SHA256,
            "explainability_release_date": EXPLAINABILITY_RELEASE_DATE,
            "deterministic": True,
            "dependencies": {
                "numpy": np.__version__,
                "python": platform.python_version(),
                "scipy": scipy.__version__,
                "xgboost": xgboost.__version__,
            },
            "source_files": [
                _source_entry(train_path, 7560, "parsed_source"),
                _source_entry(validation_path, 1620, "parsed_source"),
                _source_entry(schema_path, 1, "parsed_source"),
                _source_entry(model_dir / "model.json", 1, "model_loading"),
                _source_entry(model_dir / "model_metadata.json", 1, "contract_validation"),
                _source_entry(model_dir / "manifest.json", 1, "integrity_validation"),
                _source_entry(predictions_path, 9180, "local_selection_and_score_validation"),
                _source_entry(comparison_path, 1, "aggregate_disposition_validation"),
                _source_entry(receipt_path, 1, "aggregate_recovery_validation"),
                _source_entry(final_manifest_path, 1, "aggregate_integrity_validation"),
            ],
            "model_contract": {
                "feature_count": 103,
                "objective": "rank:ndcg",
                "selected_config_id": "T06",
                "training_executed": False,
                "tuning_executed": False,
                "model_modified": False,
            },
            "data_contract": {
                "source_training_contract": "train-plus-validation-v1",
                "source_record_count": 9180,
                "source_candidate_count": 153,
                "feature_count": 103,
                "supported_source_splits": ["train", "validation"],
            },
            "attribution_contract": {
                "exact": True,
                "approximate": False,
                "interactions": False,
                "strict_shape": True,
                "expected_shape": [9180, 1, 104],
                "additivity_tolerance": 1e-5,
            },
            "feature_group_contract": {
                "mapping_source": "frozen_schema_family_metadata",
                "groups": [
                    {
                        "feature_group": item.feature_group,
                        "feature_names": item.feature_names,
                    }
                    for item in sorted(groups, key=lambda value: value.feature_group)
                ],
                "all_features_mapped_once": True,
            },
            "local_selection_contract": {
                "policy": LOCAL_SELECTION_POLICY_VERSION,
                "candidate_count": 27,
                "ranks": [1, 5, 10, 60],
                "record_count": 108,
                "labels_used_for_selection": False,
                "contributions_used_for_selection": False,
            },
            "test_non_usage": TEST_NON_USAGE,
            "phase_10_aggregate_disposition": frozen["comparison"]["quality_disposition"],
            "output_files": output_files,
            "intended_use": [
                "phase_12_explanation_contract_input",
                "offline_human_assisted_recruitment_research",
            ],
            "limitations": _explanation_contract()["limitations"],
        }
        _write_text(stage / "manifest.json", _json_content(manifest))
        _validate_artifacts(stage)
        if backup.exists():
            raise FileExistsError(f"Backup path already exists: {backup}.")
        if output_dir.exists():
            os.replace(output_dir, backup)
        try:
            os.replace(stage, output_dir)
            published = True
        except Exception:
            if backup.exists() and not output_dir.exists():
                os.replace(backup, output_dir)
            raise
        if backup.exists():
            shutil.rmtree(backup)
    finally:
        if stage.exists():
            shutil.rmtree(stage)
        if not published and backup.exists() and not output_dir.exists():
            os.replace(backup, output_dir)
    return {
        "output_dir": str(output_dir),
        "records": 9180,
        "features": 103,
        "groups": len(groups),
        "local_explanations": len(locals_),
        "maximum_additivity_error": checks.additivity["maximum_absolute_error"],
        "test_features_read": False,
        "test_predictions_read": False,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Explain the frozen tuned XGBoost ranker using Train and Validation only.",
    )
    parser.add_argument("--train-file", required=True)
    parser.add_argument("--validation-file", required=True)
    parser.add_argument("--feature-schema-file", required=True)
    parser.add_argument("--tuned-model-dir", required=True)
    parser.add_argument("--final-train-validation-predictions-file", required=True)
    parser.add_argument("--final-test-comparison-file", required=True)
    parser.add_argument("--final-test-receipt-file", required=True)
    parser.add_argument("--final-test-manifest-file", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--explanation-version", required=True)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:  # pragma: no cover
    summary = explain(build_parser().parse_args(argv))
    print(
        "Explainability complete: "
        f"records={summary['records']}; features={summary['features']}; "
        f"groups={summary['groups']}; local={summary['local_explanations']}; "
        f"max_additivity_error={summary['maximum_additivity_error']:.12g}; "
        f"output={summary['output_dir']}."
    )
    return 0
