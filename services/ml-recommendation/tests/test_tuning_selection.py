"""Validation-only selection and every locked tie-break."""

from __future__ import annotations

import pytest

from smart_recruitment_ml.tuning.selection import rank_trials, select_trial


def _trial(
    config_id: str,
    ndcg10: float,
    ndcg5: float,
    mrr: float,
    estimators: int,
    depth: int,
    train_marker: float = 0.0,
) -> dict:
    return {
        "config_id": config_id,
        "hyperparameters": {"n_estimators": estimators, "max_depth": depth},
        "train_metrics": {"ignored": train_marker},
        "validation_metrics": {
            "NDCG@10": {"macro_mean": ndcg10},
            "NDCG@5": {"macro_mean": ndcg5},
            "MRR": {"macro_mean": mrr},
        },
    }


@pytest.mark.parametrize(
    ("left", "right", "expected"),
    [
        (_trial("T00", 0.8, 0.2, 0.2, 300, 4), _trial("T01", 0.7, 0.9, 0.9, 1, 1), "T00"),
        (_trial("T00", 0.8, 0.7, 0.2, 300, 4), _trial("T01", 0.8, 0.8, 0.1, 1, 1), "T01"),
        (_trial("T00", 0.8, 0.8, 0.7, 300, 4), _trial("T01", 0.8, 0.8, 0.8, 1, 1), "T01"),
        (_trial("T00", 0.8, 0.8, 0.8, 300, 4), _trial("T01", 0.8, 0.8, 0.8, 200, 9), "T01"),
        (_trial("T00", 0.8, 0.8, 0.8, 300, 4), _trial("T01", 0.8, 0.8, 0.8, 300, 3), "T01"),
        (_trial("T00", 0.8, 0.8, 0.8, 300, 4), _trial("T01", 0.8, 0.8, 0.8, 300, 4), "T00"),
    ],
)
def test_locked_selection_stages(left: dict, right: dict, expected: str) -> None:
    selected, trace = select_trial([right, left])
    assert selected["config_id"] == expected
    assert [entry["criterion"] for entry in trace] == [
        "validation_ndcg_at_10_descending",
        "validation_ndcg_at_5_descending",
        "validation_mrr_descending",
        "n_estimators_ascending",
        "max_depth_ascending",
        "config_id_lexicographic_ascending",
    ]


def test_tolerance_train_metrics_and_order_do_not_change_selection() -> None:
    left = _trial("T00", 0.8, 0.8, 0.8, 300, 4, train_marker=-999.0)
    right = _trial("T01", 0.8 + 5e-13, 0.8, 0.8, 400, 4, train_marker=999.0)
    assert select_trial([right, left])[0]["config_id"] == "T00"
    assert [trial["config_id"] for trial in rank_trials([right, left])] == ["T00", "T01"]


def test_empty_selection_is_rejected() -> None:
    with pytest.raises(ValueError, match="At least one"):
        select_trial([])
