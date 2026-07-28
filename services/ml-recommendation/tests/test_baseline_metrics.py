"""Unit tests for the locked Phase 7 ranking metric definitions."""

import math

import pytest

from smart_recruitment_ml.baselines.metrics import (
    evaluate_rankings,
    hit_rate_at_k,
    ndcg_at_k,
    precision_at_k,
    recall_at_k,
    reciprocal_rank,
    summarize,
)


def test_perfect_ranking_has_unit_ndcg() -> None:
    assert ndcg_at_k([3, 2, 1, 0], 5) == 1.0


def test_reversed_ranking_has_lower_ndcg() -> None:
    assert ndcg_at_k([0, 1, 2, 3], 5) < ndcg_at_k([3, 2, 1, 0], 5)


@pytest.mark.parametrize("cutoff", [5, 10])
def test_ndcg_cutoffs_are_bounded(cutoff: int) -> None:
    assert 0.0 <= ndcg_at_k([3, 0, 2, 1, 0, 2], cutoff) <= 1.0


def test_precision_at_five_uses_fixed_denominator() -> None:
    assert precision_at_k([3, 2, 1, 0, 2], 5) == 0.6


def test_recall_at_five_uses_all_relevant_jobs() -> None:
    assert recall_at_k([3, 0, 0, 0, 2, 2], 5) == pytest.approx(2 / 3)


def test_reciprocal_rank_uses_first_relevant_position() -> None:
    assert reciprocal_rank([0, 1, 2, 3]) == pytest.approx(1 / 3)


def test_hit_rate_at_five() -> None:
    assert hit_rate_at_k([0, 1, 0, 2, 0], 5) == 1.0


@pytest.mark.parametrize(
    ("label", "expected"),
    [(0, 0.0), (1, 0.0), (2, 1.0), (3, 1.0)],
)
def test_binary_relevance_threshold(label: int, expected: float) -> None:
    assert hit_rate_at_k([label], 5) == expected


def test_zero_relevant_group_has_zero_binary_metrics() -> None:
    labels = [0, 1, 0, 1, 0]
    assert recall_at_k(labels, 5) == 0.0
    assert reciprocal_rank(labels) == 0.0
    assert hit_rate_at_k(labels, 5) == 0.0


def test_macro_aggregation_is_per_candidate() -> None:
    metrics = evaluate_rankings([[3, 0, 0, 0, 0], [0, 0, 0, 0, 0]])
    assert metrics.hit_rate_at_5.macro_mean == 0.5
    assert metrics.hit_rate_at_5.group_count == 2


def test_population_standard_deviation_is_deterministic() -> None:
    result = summarize([0.0, 1.0])
    assert result.standard_deviation == 0.5
    assert result.median == 0.5


def test_empty_macro_summary_is_zeroed() -> None:
    result = summarize([])
    assert result.macro_mean == 0.0
    assert result.group_count == 0


def test_graded_gain_uses_exponential_relevance() -> None:
    expected = (7 / math.log2(2) + 3 / math.log2(3)) / (7 / math.log2(2) + 3 / math.log2(3))
    assert ndcg_at_k([3, 2], 5) == expected
