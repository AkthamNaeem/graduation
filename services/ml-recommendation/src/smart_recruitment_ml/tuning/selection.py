"""Locked validation-only configuration selection."""

from __future__ import annotations

from typing import Any, Final

TIE_TOLERANCE: Final = 1e-12


def _mean(trial: dict[str, Any], metric: str) -> float:
    return float(trial["validation_metrics"][metric]["macro_mean"])


def select_trial(trials: list[dict[str, Any]]) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    """Select one trial using only the six locked policy stages."""
    if not trials:
        raise ValueError("At least one trial is required for selection.")
    remaining = list(trials)
    trace: list[dict[str, Any]] = []
    for metric in ("NDCG@10", "NDCG@5", "MRR"):
        best = max(_mean(trial, metric) for trial in remaining)
        remaining = [trial for trial in remaining if best - _mean(trial, metric) <= TIE_TOLERANCE]
        trace.append(
            {
                "criterion": f"validation_{metric.lower().replace('@', '_at_')}_descending",
                "best_value": best,
                "remaining_config_ids": [trial["config_id"] for trial in remaining],
                "tolerance": TIE_TOLERANCE,
            }
        )
    for parameter in ("n_estimators", "max_depth"):
        best_int = min(int(trial["hyperparameters"][parameter]) for trial in remaining)
        remaining = [
            trial for trial in remaining if int(trial["hyperparameters"][parameter]) == best_int
        ]
        trace.append(
            {
                "criterion": f"{parameter}_ascending",
                "best_value": best_int,
                "remaining_config_ids": [trial["config_id"] for trial in remaining],
            }
        )
    selected = min(remaining, key=lambda trial: str(trial["config_id"]))
    trace.append(
        {
            "criterion": "config_id_lexicographic_ascending",
            "best_value": selected["config_id"],
            "remaining_config_ids": [selected["config_id"]],
        }
    )
    return selected, trace


def rank_trials(trials: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Return the complete deterministic policy ordering."""
    remaining = list(trials)
    ranked: list[dict[str, Any]] = []
    while remaining:
        selected, _trace = select_trial(remaining)
        ranked.append(selected)
        remaining = [trial for trial in remaining if trial is not selected]
    return ranked
