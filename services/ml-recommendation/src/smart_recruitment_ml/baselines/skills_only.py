"""Deterministic weighted-skills-only baseline."""

from __future__ import annotations

from typing import TYPE_CHECKING, Final, Literal

if TYPE_CHECKING:
    from collections.abc import Iterable

    from smart_recruitment_ml.schemas.synthetic import Candidate, Job

SKILLS_BASELINE_VERSION: Final[Literal["skills-weighted-v1"]] = "skills-weighted-v1"


def normalize_skill_name(value: str) -> str:
    return " ".join(value.strip().casefold().split())


def score_candidate_job(candidate: Candidate, job: Job) -> float:
    candidate_skills = {normalize_skill_name(skill.name) for skill in candidate.skills}
    required = {normalize_skill_name(item.name): float(item.weight) for item in job.required_skills}
    nice = {normalize_skill_name(skill) for skill in job.nice_to_have_skills}

    required_total = sum(required.values())
    required_coverage = (
        sum(weight for skill, weight in required.items() if skill in candidate_skills)
        / required_total
        if required_total
        else 0.0
    )
    nice_coverage = len(nice.intersection(candidate_skills)) / len(nice) if nice else 0.0
    return 100.0 * (0.85 * required_coverage + 0.15 * nice_coverage)


def rank_jobs(
    candidate: Candidate,
    jobs: Iterable[Job],
) -> list[tuple[str, float, int]]:
    scores = [(job.job_id, score_candidate_job(candidate, job)) for job in jobs]
    scores.sort(key=lambda item: (-item[1], item[0]))
    return [(job_id, score, rank) for rank, (job_id, score) in enumerate(scores, start=1)]
