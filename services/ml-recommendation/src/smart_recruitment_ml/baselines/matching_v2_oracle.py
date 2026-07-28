"""Independent Python parity oracle for Laravel MatchingService 2.0."""

from __future__ import annotations

import math
import unicodedata
from collections import Counter
from decimal import ROUND_HALF_UP, Decimal
from typing import TYPE_CHECKING, Final, Literal

from smart_recruitment_ml.schemas.baselines import (
    AdaptedCandidate,
    AdaptedDataset,
    AdaptedJob,
    MatchingV2Components,
    MatchingV2Prediction,
)

if TYPE_CHECKING:
    from collections.abc import Iterable

MATCHING_VERSION: Final[Literal["2.0"]] = "2.0"
PARITY_VERSION: Final[Literal["matching-v2-parity-v1"]] = "matching-v2-parity-v1"
PARITY_TOLERANCE = 0.01
_WEIGHTS = {
    "required_skills": 45.0,
    "nice_to_have_skills": 10.0,
    "experience": 20.0,
    "education": 10.0,
    "text_similarity": 15.0,
}
_EXPERIENCE_YEARS = {
    "entry": 0.0,
    "entry-level": 0.0,
    "junior": 1.0,
    "mid": 3.0,
    "mid-level": 3.0,
    "senior": 5.0,
}
_EDUCATION_RANK = {
    "high school": 1,
    "high_school": 1,
    "diploma": 2,
    "associate": 2,
    "bachelor": 3,
    "master": 4,
    "doctorate": 5,
}


def php_round(value: float, precision: int = 2) -> float:
    quantum = Decimal(1).scaleb(-precision)
    return float(Decimal(str(value)).quantize(quantum, rounding=ROUND_HALF_UP))


def tokenize(document: str) -> list[str]:
    normalized = "".join(
        character.lower() if unicodedata.category(character)[0] in {"L", "N"} else " "
        for character in document
    )
    return normalized.split()


def compute_tfidf(documents: dict[str, str]) -> dict[str, dict[str, float]]:
    tokenized = {key: tokenize(document) for key, document in documents.items()}
    document_frequencies: Counter[str] = Counter()
    for tokens in tokenized.values():
        document_frequencies.update(dict.fromkeys(tokens).keys())

    document_count = len(documents)
    vectors: dict[str, dict[str, float]] = {}
    for key, tokens in tokenized.items():
        if not tokens:
            vectors[key] = {}
            continue
        counts = Counter(tokens)
        vector: dict[str, float] = {}
        for term, count in counts.items():
            term_frequency = count / len(tokens)
            inverse_document_frequency = (
                math.log((document_count + 1) / (document_frequencies.get(term, 0) + 1)) + 1
            )
            vector[term] = term_frequency * inverse_document_frequency
        vectors[key] = vector
    return vectors


def cosine_similarity(
    vector_a: dict[str, float],
    vector_b: dict[str, float],
) -> float:
    if not vector_a or not vector_b:
        return 0.0
    magnitude_a = math.sqrt(sum(value**2 for value in vector_a.values()))
    magnitude_b = math.sqrt(sum(value**2 for value in vector_b.values()))
    if magnitude_a == 0.0 or magnitude_b == 0.0:
        return 0.0
    smallest, largest = (
        (vector_a, vector_b) if len(vector_a) <= len(vector_b) else (vector_b, vector_a)
    )
    dot_product = 0.0
    for term, value in smallest.items():
        dot_product += value * largest.get(term, 0.0)
    return max(0.0, min(1.0, dot_product / (magnitude_a * magnitude_b)))


def _join_parts(parts: Iterable[str | None]) -> str:
    return " ".join(str(part).strip() for part in parts if part and str(part).strip())


def _reverse_registry(dataset: AdaptedDataset) -> dict[int, str]:
    return {skill_id: name for name, skill_id in dataset.skill_registry.items()}


def profile_text(dataset: AdaptedDataset, candidate: AdaptedCandidate) -> str:
    skill_names = _reverse_registry(dataset)
    sections = {
        "core": _join_parts([candidate.headline, candidate.summary]),
        "skills": " ".join(skill_names[skill_id] for skill_id in candidate.skill_ids),
        "experience": _join_parts([candidate.experience_title, None]),
        "education": _join_parts(
            [None, candidate.education_degree, candidate.education_field, None]
        ),
    }
    return _join_parts(sections.values())


def job_text(dataset: AdaptedDataset, job: AdaptedJob) -> str:
    skill_names = _reverse_registry(dataset)
    sections = {
        "core": _join_parts(
            [
                job.title,
                job.department,
                job.description,
                job.responsibilities,
                job.requirements,
            ]
        ),
        "skills": " ".join(skill_names[requirement.skill_id] for requirement in job.skills),
        "experience": _join_parts([job.experience_level]),
        "education": _join_parts([job.education_level]),
    }
    return _join_parts(sections.values())


def _skill_component(
    candidate_skill_ids: set[int],
    job: AdaptedJob,
    requirement_type: str,
    maximum: float,
    not_applicable_when_empty: bool,
) -> float:
    requirements = [
        requirement
        for requirement in job.skills
        if requirement.requirement_type == requirement_type
    ]
    total_weight = sum(requirement.weight for requirement in requirements)
    matched_weight = sum(
        requirement.weight
        for requirement in requirements
        if requirement.skill_id in candidate_skill_ids
    )
    if total_weight == 0:
        percentage = 100.0 if not_applicable_when_empty else 0.0
    else:
        percentage = php_round(100.0 * matched_weight / total_weight)
    return php_round(maximum * percentage / 100.0)


def _experience_component(candidate: AdaptedCandidate, job: AdaptedJob) -> float:
    required_years = _EXPERIENCE_YEARS[job.experience_level]
    candidate_years = php_round(candidate.experience_duration_days / 365.25)
    percentage = (
        100.0
        if required_years <= 0
        else php_round(100.0 * min(1.0, candidate_years / required_years))
    )
    return php_round(_WEIGHTS["experience"] * percentage / 100.0)


def _education_component(candidate: AdaptedCandidate, job: AdaptedJob) -> float:
    candidate_rank = _EDUCATION_RANK.get(candidate.education_degree)
    required_rank = _EDUCATION_RANK[job.education_level]
    if candidate_rank is None:
        score = 0.0
    elif candidate_rank >= required_rank:
        score = _WEIGHTS["education"]
    elif candidate_rank == required_rank - 1:
        score = _WEIGHTS["education"] / 2.0
    else:
        score = 0.0
    return php_round(score)


def score_pair(
    candidate: AdaptedCandidate,
    job: AdaptedJob,
    similarity: float,
) -> tuple[float, MatchingV2Components]:
    candidate_skill_ids = set(candidate.skill_ids)
    required_score = _skill_component(
        candidate_skill_ids,
        job,
        "required",
        _WEIGHTS["required_skills"],
        False,
    )
    nice_score = _skill_component(
        candidate_skill_ids,
        job,
        "nice_to_have",
        _WEIGHTS["nice_to_have_skills"],
        True,
    )
    experience_score = _experience_component(candidate, job)
    education_score = _education_component(candidate, job)
    clamped_similarity = max(0.0, min(1.0, similarity))
    text_score = php_round(clamped_similarity * _WEIGHTS["text_similarity"])
    score = php_round(required_score + nice_score + experience_score + education_score + text_score)
    components = MatchingV2Components(
        required_skills=required_score,
        nice_to_have_skills=nice_score,
        experience=experience_score,
        education=education_score,
        text_similarity=text_score,
        cosine_similarity=php_round(clamped_similarity),
    )
    return max(0.0, min(100.0, score)), components


def rank_candidate_jobs(
    dataset: AdaptedDataset,
    candidate_id: str,
    job_ids: list[str],
) -> dict[str, MatchingV2Prediction]:
    candidate = dataset.candidates[candidate_id]
    documents = {"anchor": profile_text(dataset, candidate)}
    for job_id in job_ids:
        documents[job_id] = job_text(dataset, dataset.jobs[job_id])
    vectors = compute_tfidf(documents)

    scored: list[tuple[str, float, MatchingV2Components]] = []
    for job_id in job_ids:
        score, components = score_pair(
            candidate,
            dataset.jobs[job_id],
            cosine_similarity(vectors["anchor"], vectors[job_id]),
        )
        scored.append((job_id, score, components))
    scored.sort(key=lambda item: (-item[1], item[0]))
    return {
        job_id: MatchingV2Prediction(
            score=score,
            rank=rank,
            components=components,
        )
        for rank, (job_id, score, components) in enumerate(scored, start=1)
    }
