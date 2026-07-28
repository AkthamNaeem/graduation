"""Deterministic feature, group, and stability aggregation."""

from __future__ import annotations

from collections import defaultdict
from typing import TYPE_CHECKING, Any

import numpy as np
from scipy.stats import spearmanr  # type: ignore[import-untyped]

from smart_recruitment_ml.schemas.explainability import (
    ContributionStatistics,
    FeatureGroupRecord,
    GlobalFeatureRecord,
    GroupStatistics,
    TopFeature,
)

if TYPE_CHECKING:
    from numpy.typing import NDArray


def _statistics(values: NDArray[np.float32], denominator: float) -> ContributionStatistics:
    absolute_mean = float(np.mean(np.abs(values), dtype=np.float64))
    return ContributionStatistics(
        mean_absolute_contribution=absolute_mean,
        mean_signed_contribution=float(np.mean(values, dtype=np.float64)),
        standard_deviation=float(np.std(values, dtype=np.float64)),
        positive_fraction=float(np.mean(values > 0)),
        negative_fraction=float(np.mean(values < 0)),
        zero_fraction=float(np.mean(values == 0)),
        normalized_importance_share=absolute_mean / denominator if denominator else 0.0,
    )


def aggregate_global_importance(
    contributions: NDArray[np.float32],
    *,
    train_count: int,
    feature_names: list[str],
    feature_groups: dict[str, str],
) -> list[GlobalFeatureRecord]:
    feature_values = contributions[:, :103]
    split_values = {
        "combined": feature_values,
        "train": feature_values[:train_count],
        "validation": feature_values[train_count:],
    }
    denominators = {
        key: float(np.mean(np.abs(values), axis=0, dtype=np.float64).sum())
        for key, values in split_values.items()
    }
    per_feature: list[dict[str, Any]] = []
    for index, name in enumerate(feature_names):
        per_feature.append(
            {
                "feature_index": index,
                "feature_name": name,
                "feature_group": feature_groups[name],
                "combined": _statistics(
                    split_values["combined"][:, index],
                    denominators["combined"],
                ),
                "train": _statistics(split_values["train"][:, index], denominators["train"]),
                "validation": _statistics(
                    split_values["validation"][:, index],
                    denominators["validation"],
                ),
            }
        )
    ordered = sorted(
        per_feature,
        key=lambda item: (
            -item["combined"].mean_absolute_contribution,
            item["feature_index"],
        ),
    )
    return [GlobalFeatureRecord(rank=rank, **item) for rank, item in enumerate(ordered, start=1)]


def _group_statistics(
    feature_records: list[GlobalFeatureRecord],
    split: str,
) -> GroupStatistics:
    stats = [getattr(record, split) for record in feature_records]
    top = sorted(
        feature_records,
        key=lambda record: (
            -getattr(record, split).mean_absolute_contribution,
            record.feature_index,
        ),
    )[:5]
    return GroupStatistics(
        feature_count=len(feature_records),
        sum_mean_absolute_contribution=sum(item.mean_absolute_contribution for item in stats),
        mean_feature_absolute_contribution=float(
            np.mean([item.mean_absolute_contribution for item in stats])
        ),
        normalized_importance_share=sum(item.normalized_importance_share for item in stats),
        mean_signed_contribution=sum(item.mean_signed_contribution for item in stats),
        top_features=[
            TopFeature(
                feature_index=record.feature_index,
                feature_name=record.feature_name,
                mean_absolute_contribution=getattr(
                    record,
                    split,
                ).mean_absolute_contribution,
            )
            for record in top
        ],
    )


def aggregate_group_importance(
    features: list[GlobalFeatureRecord],
) -> list[FeatureGroupRecord]:
    grouped: dict[str, list[GlobalFeatureRecord]] = defaultdict(list)
    for feature in sorted(features, key=lambda item: item.feature_index):
        grouped[feature.feature_group].append(feature)
    values: list[dict[str, Any]] = []
    for group_name, records in sorted(grouped.items()):
        values.append(
            {
                "feature_group": group_name,
                "feature_names": [record.feature_name for record in records],
                "combined": _group_statistics(records, "combined"),
                "train": _group_statistics(records, "train"),
                "validation": _group_statistics(records, "validation"),
            }
        )
    ordered = sorted(
        values,
        key=lambda item: (
            -item["combined"].normalized_importance_share,
            item["feature_group"],
        ),
    )
    return [FeatureGroupRecord(rank=rank, **item) for rank, item in enumerate(ordered, start=1)]


def stability_checks(features: list[GlobalFeatureRecord]) -> dict[str, Any]:
    train_order = sorted(
        features,
        key=lambda item: (-item.train.mean_absolute_contribution, item.feature_index),
    )
    validation_order = sorted(
        features,
        key=lambda item: (-item.validation.mean_absolute_contribution, item.feature_index),
    )
    train_ranks = {item.feature_name: rank for rank, item in enumerate(train_order, start=1)}
    validation_ranks = {
        item.feature_name: rank for rank, item in enumerate(validation_order, start=1)
    }
    names = [item.feature_name for item in sorted(features, key=lambda item: item.feature_index)]
    correlation = float(
        spearmanr(
            [train_ranks[name] for name in names],
            [validation_ranks[name] for name in names],
        ).statistic
    )
    result: dict[str, Any] = {
        "spearman": correlation,
        "descriptive_only": True,
        "model_changed_based_on_stability": False,
    }
    for size in (10, 20):
        train_top = {item.feature_name for item in train_order[:size]}
        validation_top = {item.feature_name for item in validation_order[:size]}
        overlap = len(train_top & validation_top)
        result[f"top_{size}_overlap"] = overlap
        result[f"top_{size}_jaccard"] = overlap / len(train_top | validation_top)
    return result
