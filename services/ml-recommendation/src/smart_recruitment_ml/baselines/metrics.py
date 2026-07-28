"""Candidate-macro ranking metrics used by the Phase 7 baselines."""

from __future__ import annotations

import math
import statistics
from typing import TYPE_CHECKING, Final, Literal

from smart_recruitment_ml.schemas.baselines import BaselineMetrics, MetricSummary

if TYPE_CHECKING:
    from collections.abc import Iterable, Sequence

METRICS_VERSION: Final[Literal["ranking-metrics-v1"]] = "ranking-metrics-v1"
RELEVANCE_THRESHOLD: Final[Literal[2]] = 2


def _dcg(labels: Sequence[int], k: int) -> float:
    return sum(
        (2.0**label - 1.0) / math.log2(rank + 1.0) for rank, label in enumerate(labels[:k], start=1)
    )


def ndcg_at_k(labels: Sequence[int], k: int) -> float:
    actual = _dcg(labels, k)
    ideal = _dcg(sorted(labels, reverse=True), k)
    return actual / ideal if ideal else 0.0


def precision_at_k(labels: Sequence[int], k: int) -> float:
    relevant = sum(label >= RELEVANCE_THRESHOLD for label in labels[:k])
    return relevant / k


def recall_at_k(labels: Sequence[int], k: int) -> float:
    total_relevant = sum(label >= RELEVANCE_THRESHOLD for label in labels)
    if total_relevant == 0:
        return 0.0
    return sum(label >= RELEVANCE_THRESHOLD for label in labels[:k]) / total_relevant


def reciprocal_rank(labels: Sequence[int]) -> float:
    for rank, label in enumerate(labels, start=1):
        if label >= RELEVANCE_THRESHOLD:
            return 1.0 / rank
    return 0.0


def hit_rate_at_k(labels: Sequence[int], k: int) -> float:
    return float(any(label >= RELEVANCE_THRESHOLD for label in labels[:k]))


def summarize(values: Iterable[float]) -> MetricSummary:
    data = list(values)
    if not data:
        return MetricSummary(
            macro_mean=0.0,
            median=0.0,
            minimum=0.0,
            maximum=0.0,
            standard_deviation=0.0,
            group_count=0,
        )
    return MetricSummary(
        macro_mean=statistics.fmean(data),
        median=statistics.median(data),
        minimum=min(data),
        maximum=max(data),
        standard_deviation=statistics.pstdev(data),
        group_count=len(data),
    )


def evaluate_rankings(ranked_labels: Iterable[Sequence[int]]) -> BaselineMetrics:
    groups = [list(labels) for labels in ranked_labels]
    return BaselineMetrics(
        ndcg_at_5=summarize(ndcg_at_k(labels, 5) for labels in groups),
        ndcg_at_10=summarize(ndcg_at_k(labels, 10) for labels in groups),
        precision_at_5=summarize(precision_at_k(labels, 5) for labels in groups),
        recall_at_5=summarize(recall_at_k(labels, 5) for labels in groups),
        mrr=summarize(reciprocal_rank(labels) for labels in groups),
        hit_rate_at_5=summarize(hit_rate_at_k(labels, 5) for labels in groups),
    )
