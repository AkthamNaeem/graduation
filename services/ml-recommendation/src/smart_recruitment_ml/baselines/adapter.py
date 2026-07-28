"""Shared synthetic-to-Laravel Matching 2.0 adapter contract."""

from __future__ import annotations

import datetime as dt
from decimal import ROUND_HALF_UP, Decimal
from typing import TYPE_CHECKING, Final, Literal

from smart_recruitment_ml.schemas.baselines import (
    AdaptedCandidate,
    AdaptedDataset,
    AdaptedJob,
    AdaptedSkillRequirement,
)

from .skills_only import normalize_skill_name

if TYPE_CHECKING:
    from smart_recruitment_ml.schemas.synthetic import Candidate, Job

ADAPTER_VERSION: Final[Literal["synthetic-to-laravel-matching-v1"]] = (
    "synthetic-to-laravel-matching-v1"
)
_EDUCATION_MAP = {
    "high_school": "high school",
    "diploma": "diploma",
    "bachelor": "bachelor",
    "master": "master",
    "doctorate": "doctorate",
}
_CAREER_MAP = {
    "entry": "entry",
    "entry-level": "entry",
    "junior": "junior",
    "mid": "mid",
    "mid-level": "mid",
    "senior": "senior",
    "lead": "senior",
}


def _round_half_up(value: Decimal) -> int:
    return int(value.quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def build_skill_registry(
    candidates: list[Candidate],
    jobs: list[Job],
) -> dict[str, int]:
    names: set[str] = set()
    for candidate in candidates:
        names.update(normalize_skill_name(skill.name) for skill in candidate.skills)
    for job in jobs:
        names.update(normalize_skill_name(item.name) for item in job.required_skills)
        names.update(normalize_skill_name(skill) for skill in job.nice_to_have_skills)
    return {name: index for index, name in enumerate(sorted(names), start=1)}


def adapt_sources(candidates: list[Candidate], jobs: list[Job]) -> AdaptedDataset:
    registry = build_skill_registry(candidates, jobs)
    adapted_candidates: dict[str, AdaptedCandidate] = {}
    adapted_jobs: dict[str, AdaptedJob] = {}

    start = dt.date(2000, 1, 1)
    for candidate in sorted(candidates, key=lambda item: item.candidate_id):
        duration_days = _round_half_up(
            Decimal(str(candidate.total_experience_years)) * Decimal("365.25")
        )
        end = start + dt.timedelta(days=duration_days)
        skill_ids = sorted(
            {registry[normalize_skill_name(skill.name)] for skill in candidate.skills}
        )
        adapted_candidates[candidate.candidate_id] = AdaptedCandidate(
            source_id=candidate.candidate_id,
            headline=candidate.headline,
            skill_ids=skill_ids,
            experience_title=candidate.headline,
            experience_end=end.isoformat(),
            experience_duration_days=duration_days,
            education_degree=_EDUCATION_MAP[candidate.education_level],
            education_field=candidate.primary_domain,
        )

    for job in sorted(jobs, key=lambda item: item.job_id):
        required_by_name = {
            normalize_skill_name(item.name): item.weight for item in job.required_skills
        }
        nice_names = {normalize_skill_name(skill) for skill in job.nice_to_have_skills} - set(
            required_by_name
        )
        skills = [
            AdaptedSkillRequirement(
                skill_id=registry[name],
                requirement_type="required",
                weight=required_by_name[name],
            )
            for name in sorted(required_by_name)
        ]
        skills.extend(
            AdaptedSkillRequirement(
                skill_id=registry[name],
                requirement_type="nice_to_have",
                weight=1,
            )
            for name in sorted(nice_names)
        )
        skills.sort(key=lambda item: item.skill_id)
        adapted_jobs[job.job_id] = AdaptedJob(
            source_id=job.job_id,
            title=job.title,
            department=job.department,
            description=job.description,
            responsibilities=" ".join(job.responsibilities),
            requirements=" ".join(sorted(required_by_name)),
            skills=skills,
            experience_level=_CAREER_MAP[job.career_level],
            education_level=job.education_level,
        )

    return AdaptedDataset(
        adapter_version=ADAPTER_VERSION,
        skill_registry=registry,
        candidates=adapted_candidates,
        jobs=adapted_jobs,
    )
