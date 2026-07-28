"""Read-only Feature transformation, ranking, and safe explanation generation."""

from __future__ import annotations

import math
import time
from dataclasses import dataclass
from typing import TYPE_CHECKING, Final, cast

import numpy as np
import xgboost as xgb
from pydantic import ValidationError

from smart_recruitment_ml.core.errors import ServiceError
from smart_recruitment_ml.features.pipeline import FeaturePipelineV1
from smart_recruitment_ml.schemas.features import CandidateFeatureInput, JobFeatureInput
from smart_recruitment_ml.schemas.inference import (
    ExplanationFactor,
    Prediction,
    RankRequest,
    RankResponse,
)

if TYPE_CHECKING:
    from collections.abc import Mapping, Sequence
    from typing import Literal

    from smart_recruitment_ml.bundle.loader import LoadedBundle

API_CONTRACT_VERSION: Final = "recommendation-ranking-api-v1"
EXPLANATION_NOTE: Final = "Model attribution only; not a probability or hiring decision."
ADDITIVITY_TOLERANCE: Final = 1e-5


@dataclass(frozen=True, slots=True)
class RuntimeState:
    """Immutable application runtime state created once at startup."""

    bundle: LoadedBundle | None
    ready: bool
    not_ready_code: str | None
    load_count: int


def unavailable_state(code: str) -> RuntimeState:
    """Create an immutable not-ready state."""
    return RuntimeState(bundle=None, ready=False, not_ready_code=code, load_count=0)


def ready_state(bundle: LoadedBundle) -> RuntimeState:
    """Create an immutable ready state after exactly one successful load."""
    return RuntimeState(bundle=bundle, ready=True, not_ready_code=None, load_count=1)


def build_feature_matrix(request: RankRequest, bundle: LoadedBundle) -> np.ndarray:
    """Use the shared offline Feature Pipeline for every requested pair."""
    try:
        candidate = CandidateFeatureInput.model_validate(
            request.candidate.professional_facts.model_dump(mode="python"),
        )
        jobs = [
            JobFeatureInput.model_validate(job.professional_facts.model_dump(mode="python"))
            for job in request.jobs
        ]
        pipeline = FeaturePipelineV1()
        vectors = [pipeline.transform(candidate, job) for job in jobs]
    except (TypeError, ValidationError, ValueError) as error:
        raise ServiceError(
            code="FEATURE_PIPELINE_FAILED",
            status_code=500,
            request_id=request.request_id,
        ) from error
    names = tuple(bundle.feature_schema["feature_names"])
    if any(vector.feature_names != names for vector in vectors):
        raise ServiceError(
            code="INFERENCE_CONTRACT_FAILED",
            status_code=500,
            request_id=request.request_id,
        )
    matrix = np.asarray([vector.feature_values for vector in vectors], dtype=np.float32)
    if matrix.shape != (len(request.jobs), bundle.manifest.feature_count):
        raise ServiceError(
            code="INFERENCE_CONTRACT_FAILED",
            status_code=500,
            request_id=request.request_id,
        )
    if not np.isfinite(matrix).all():
        raise ServiceError(
            code="INFERENCE_CONTRACT_FAILED",
            status_code=500,
            request_id=request.request_id,
        )
    return matrix


def display_score(raw_score: float, minimum: float, maximum: float) -> float:
    """Apply the frozen, monotonic Validation min-max display transform."""
    normalized = (raw_score - minimum) / (maximum - minimum)
    clipped = min(1.0, max(0.0, normalized))
    return round(100.0 * clipped, 2)


def _reason_codes(bundle: LoadedBundle) -> Mapping[str, tuple[str, str]]:
    return {
        item.feature_group: (item.positive, item.negative)
        for item in bundle.reason_code_mapping.groups
    }


def _group_contributions(
    feature_names: Sequence[str],
    contributions: np.ndarray,
    feature_group_by_name: Mapping[str, str],
) -> dict[str, float]:
    grouped: dict[str, float] = {}
    for name, contribution in zip(feature_names, contributions, strict=True):
        group = feature_group_by_name[name]
        grouped[group] = grouped.get(group, 0.0) + float(contribution)
    return grouped


def _factors(
    grouped: Mapping[str, float],
    codes: Mapping[str, tuple[str, str]],
    *,
    positive: bool,
) -> list[ExplanationFactor]:
    total = sum(abs(value) for value in grouped.values())
    if total == 0:
        return []
    selected = [
        (group, value) for group, value in grouped.items() if (value > 0 if positive else value < 0)
    ]
    selected.sort(key=lambda item: ((-item[1] if positive else item[1]), item[0]))
    direction = cast(
        "Literal['increases_model_score', 'decreases_model_score']",
        "increases_model_score" if positive else "decreases_model_score",
    )
    index = 0 if positive else 1
    return [
        ExplanationFactor(
            code=codes[group][index],
            feature_group=group,
            direction=direction,
            contribution=round(value, 8),
            strength=round(min(1.0, max(0.0, abs(value) / total)), 6),
        )
        for group, value in selected[:3]
    ]


def _predict(
    bundle: LoadedBundle,
    matrix: np.ndarray,
) -> tuple[np.ndarray, np.ndarray]:
    names = list(bundle.feature_schema["feature_names"])
    data = xgb.DMatrix(matrix, feature_names=names)
    raw_scores = np.asarray(
        bundle.booster.predict(data, output_margin=True),
        dtype=np.float64,
    ).reshape(-1)
    shaped = np.asarray(
        bundle.booster.predict(
            data,
            pred_contribs=True,
            approx_contribs=False,
            strict_shape=True,
        ),
        dtype=np.float64,
    )
    expected = (matrix.shape[0], 1, bundle.manifest.feature_count + 1)
    if shaped.shape != expected:
        raise ValueError("Contribution shape mismatch.")
    contributions = shaped[:, 0, :]
    if not np.isfinite(raw_scores).all() or not np.isfinite(contributions).all():
        raise ValueError("Inference produced non-finite values.")
    maximum_error = float(np.max(np.abs(contributions.sum(axis=1) - raw_scores)))
    if maximum_error > ADDITIVITY_TOLERANCE:
        raise ValueError("Exact contribution additivity mismatch.")
    return raw_scores, contributions


def rank_jobs(request: RankRequest, runtime: RuntimeState) -> RankResponse:
    """Rank every Job and return deterministic scores plus safe group reasons."""
    if not runtime.ready or runtime.bundle is None:
        raise ServiceError(
            code="MODEL_BUNDLE_NOT_READY",
            status_code=503,
            request_id=request.request_id,
        )
    bundle = runtime.bundle
    started = time.perf_counter()
    matrix = build_feature_matrix(request, bundle)
    try:
        raw_scores, contributions = _predict(bundle, matrix)
    except (TypeError, ValueError, xgb.core.XGBoostError) as error:
        raise ServiceError(
            code="INFERENCE_CONTRACT_FAILED",
            status_code=500,
            request_id=request.request_id,
        ) from error
    transform = bundle.score_transform
    feature_names = list(bundle.feature_schema["feature_names"])
    codes = _reason_codes(bundle)
    rows: list[tuple[int, int, float, list[ExplanationFactor], list[ExplanationFactor]]] = []
    for index, (job, raw_score) in enumerate(
        zip(request.jobs, raw_scores, strict=True),
    ):
        numeric_score = float(raw_score)
        if not math.isfinite(numeric_score):
            raise ServiceError(
                code="INFERENCE_CONTRACT_FAILED",
                status_code=500,
                request_id=request.request_id,
            )
        grouped = _group_contributions(
            feature_names,
            contributions[index, :-1],
            bundle.feature_group_by_name,
        )
        rows.append(
            (
                index,
                job.job_id,
                numeric_score,
                _factors(grouped, codes, positive=True),
                _factors(grouped, codes, positive=False),
            ),
        )
    rows.sort(key=lambda item: (-item[2], item[1]))
    predictions = [
        Prediction(
            job_id=job_id,
            rank=rank,
            raw_score=raw_score,
            display_score=display_score(
                raw_score,
                transform.minimum_raw_score,
                transform.maximum_raw_score,
            ),
            top_positive_factors=positive,
            top_negative_factors=negative,
        )
        for rank, (_index, job_id, raw_score, positive, negative) in enumerate(
            rows,
            start=1,
        )
    ]
    latency_ms = round((time.perf_counter() - started) * 1000.0, 3)
    return RankResponse(
        request_id=request.request_id,
        api_contract_version=API_CONTRACT_VERSION,
        bundle_version=bundle.manifest.bundle_version,
        model_version=bundle.manifest.model_version,
        dataset_version=bundle.manifest.dataset_version,
        feature_schema_version=bundle.manifest.feature_schema_version,
        model_source_revision=bundle.manifest.model_source_revision,
        score_transform_version=bundle.manifest.score_transform_version,
        explanation_contract_version=bundle.manifest.explanation_contract_version,
        requested_limit=request.limit,
        prediction_count=len(predictions),
        predictions=predictions,
        explanation_note=EXPLANATION_NOTE,
        latency_ms=latency_ms,
    )
