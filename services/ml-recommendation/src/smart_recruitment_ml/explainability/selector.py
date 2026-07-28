"""Frozen validation-origin local-explanation selection and rendering."""

from __future__ import annotations

import json
from collections import Counter, defaultdict
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any, Final, Literal, cast

import numpy as np

from smart_recruitment_ml.schemas.explainability import LocalExplanation, LocalFactor

from . import ATTRIBUTION_METHOD_VERSION, EXPLANATION_CONTRACT_VERSION, MODEL_VERSION

if TYPE_CHECKING:
    from pathlib import Path

    from .engine import CombinedDataset, ContributionResult

SELECTED_RANKS: Final = (1, 5, 10, 60)


@dataclass(frozen=True)
class FrozenPrediction:
    pair_id: str
    candidate_id: str
    job_id: str
    source_split: str
    model_rank: int
    model_score: float


def select_frozen_predictions(
    path: Path,
    *,
    expected_pair_ids: set[str],
) -> list[FrozenPrediction]:
    records: list[FrozenPrediction] = []
    all_pairs: set[str] = set()
    by_candidate: dict[str, list[FrozenPrediction]] = defaultdict(list)
    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            value = json.loads(line)
            if not isinstance(value, dict):
                raise ValueError(f"Prediction object expected at line {line_number}.")
            pair_id = str(value.get("pair_id"))
            if pair_id in all_pairs:
                raise ValueError(f"Duplicate frozen prediction Pair ID: {pair_id}.")
            all_pairs.add(pair_id)
            if value.get("model_version") != MODEL_VERSION:
                raise ValueError("Frozen prediction model version mismatch.")
            if value.get("source_split") == "validation":
                record = FrozenPrediction(
                    pair_id=pair_id,
                    candidate_id=str(value.get("candidate_id")),
                    job_id=str(value.get("job_id")),
                    source_split="validation",
                    model_rank=int(value.get("rank", 0)),
                    model_score=float(value.get("prediction_score", float("nan"))),
                )
                by_candidate[record.candidate_id].append(record)
    if all_pairs != expected_pair_ids or len(all_pairs) != 9180:
        raise ValueError("Frozen prediction Pair coverage mismatch.")
    if len(by_candidate) != 27:
        raise ValueError("Expected 27 validation-origin Candidate groups.")
    for candidate_id, candidates in sorted(by_candidate.items()):
        ranks = Counter(record.model_rank for record in candidates)
        if len(candidates) != 60 or set(ranks) != set(range(1, 61)) or set(ranks.values()) != {1}:
            raise ValueError(f"Incomplete frozen ranks for {candidate_id}.")
        records.extend(record for record in candidates if record.model_rank in SELECTED_RANKS)
    records.sort(key=lambda item: (item.candidate_id, item.model_rank))
    if len(records) != 108:
        raise ValueError("Expected exactly 108 local explanation selections.")
    return records


def _factor(
    *,
    index: int,
    value: float,
    contribution: float,
    feature_names: list[str],
    feature_groups: dict[str, str],
) -> LocalFactor:
    return LocalFactor(
        feature_index=index,
        feature_name=feature_names[index],
        feature_group=feature_groups[feature_names[index]],
        feature_value=value,
        contribution=contribution,
        direction=("increases_model_score" if contribution > 0 else "decreases_model_score"),
    )


def build_local_explanations(
    *,
    selections: list[FrozenPrediction],
    dataset: CombinedDataset,
    result: ContributionResult,
    feature_names: list[str],
    feature_groups: dict[str, str],
) -> tuple[list[LocalExplanation], dict[str, Any]]:
    pair_to_index = {pair_id: index for index, pair_id in enumerate(dataset.pair_ids)}
    records: list[LocalExplanation] = []
    missing_pairs = 0
    score_mismatches = 0
    for selected in selections:
        index = pair_to_index.get(selected.pair_id)
        if index is None:
            missing_pairs += 1
            continue
        if (
            dataset.source_splits[index] != "validation"
            or dataset.candidate_ids[index] != selected.candidate_id
            or dataset.job_ids[index] != selected.job_id
        ):
            raise ValueError(f"Local explanation source mismatch: {selected.pair_id}.")
        model_score = float(result.scores[index])
        if abs(model_score - selected.model_score) > 1e-12:
            score_mismatches += 1
        feature_contributions = result.contributions[index, :103]
        positive_indices = sorted(
            (item for item in range(103) if feature_contributions[item] > 0),
            key=lambda item: (-float(feature_contributions[item]), item),
        )[:5]
        negative_indices = sorted(
            (item for item in range(103) if feature_contributions[item] < 0),
            key=lambda item: (float(feature_contributions[item]), item),
        )[:5]
        bias = float(result.contributions[index, 103])
        reconstructed = float(feature_contributions.sum(dtype=np.float64) + bias)
        records.append(
            LocalExplanation(
                pair_id=selected.pair_id,
                candidate_id=selected.candidate_id,
                job_id=selected.job_id,
                source_split="validation",
                model_version=MODEL_VERSION,
                model_rank=cast("Literal[1, 5, 10, 60]", selected.model_rank),
                model_score=selected.model_score,
                relevance_label=int(dataset.y[index]),
                bias=bias,
                raw_margin=float(result.margins[index]),
                reconstructed_margin=reconstructed,
                additivity_error=float(result.errors[index]),
                top_positive_factors=[
                    _factor(
                        index=factor_index,
                        value=float(dataset.X[index, factor_index]),
                        contribution=float(feature_contributions[factor_index]),
                        feature_names=feature_names,
                        feature_groups=feature_groups,
                    )
                    for factor_index in positive_indices
                ],
                top_negative_factors=[
                    _factor(
                        index=factor_index,
                        value=float(dataset.X[index, factor_index]),
                        contribution=float(feature_contributions[factor_index]),
                        feature_names=feature_names,
                        feature_groups=feature_groups,
                    )
                    for factor_index in negative_indices
                ],
                positive_contribution_sum=float(
                    feature_contributions[feature_contributions > 0].sum(dtype=np.float64)
                ),
                negative_contribution_sum=float(
                    feature_contributions[feature_contributions < 0].sum(dtype=np.float64)
                ),
                zero_contribution_count=int(np.count_nonzero(feature_contributions == 0)),
                explanation_contract_version=EXPLANATION_CONTRACT_VERSION,
                attribution_method_version=ATTRIBUTION_METHOD_VERSION,
            )
        )
    if missing_pairs or score_mismatches or len(records) != 108:
        raise ValueError(
            "Local explanation validation failed: "
            f"missing={missing_pairs}, score_mismatches={score_mismatches}."
        )
    return records, {
        "candidate_count": 27,
        "records": 108,
        "ranks_selected": list(SELECTED_RANKS),
        "missing_pairs": missing_pairs,
        "score_mismatches": score_mismatches,
    }
