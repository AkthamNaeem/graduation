"""Tests for the intentionally simple weighted-skills baseline."""

from collections.abc import Sequence

import pytest

from smart_recruitment_ml.baselines.skills_only import rank_jobs, score_candidate_job
from smart_recruitment_ml.schemas.synthetic import (
    Candidate,
    CandidateSkill,
    Job,
    RequiredSkill,
)


def candidate(skill_names: Sequence[str] = ("python",)) -> Candidate:
    return Candidate(
        candidate_id="cand_0001",
        primary_domain="Backend Engineering",
        adjacent_domains=[],
        headline="Engineer",
        career_level="junior",
        total_experience_years=1.0,
        education_level="bachelor",
        skills=[
            CandidateSkill(name=name, proficiency=3, years_experience=1.0) for name in skill_names
        ],
        preferred_work_modes=["remote"],
        preferred_employment_types=["full_time"],
    )


def job(
    job_id: str = "job_0001",
    required: Sequence[tuple[str, int]] = (("python", 5),),
    nice: Sequence[str] = (),
) -> Job:
    return Job(
        job_id=job_id,
        domain="Backend Engineering",
        title="Engineer",
        department="Engineering",
        description="Build services.",
        responsibilities=["Build services."],
        required_skills=[RequiredSkill(name=name, weight=weight) for name, weight in required],
        nice_to_have_skills=list(nice),
        minimum_experience_years=1.0,
        education_level="bachelor",
        career_level="junior",
        work_mode="remote",
        employment_type="full_time",
    )


def test_full_required_and_nice_match_scores_one_hundred() -> None:
    assert (
        score_candidate_job(
            candidate(("python", "sql")),
            job(required=(("python", 5),), nice=("sql",)),
        )
        == 100.0
    )


def test_weighted_partial_required_match() -> None:
    score = score_candidate_job(
        candidate(("python",)),
        job(required=(("python", 4), ("sql", 1))),
    )
    assert score == pytest.approx(68.0)


def test_nice_to_have_contributes_fifteen_percent() -> None:
    score = score_candidate_job(
        candidate(("sql",)),
        job(required=(("python", 5),), nice=("sql",)),
    )
    assert score == 15.0


def test_no_required_skills_has_zero_required_coverage() -> None:
    empty_required = job().model_copy(update={"required_skills": []})
    assert score_candidate_job(candidate(), empty_required) == 0.0


def test_no_nice_skills_has_zero_nice_coverage() -> None:
    assert score_candidate_job(candidate(), job()) == 85.0


def test_duplicate_candidate_skills_do_not_inflate_score() -> None:
    assert score_candidate_job(candidate(("python", "python")), job()) == 85.0


def test_normalization_matches_case_and_whitespace() -> None:
    altered = candidate().model_copy(
        update={
            "skills": [
                CandidateSkill.model_construct(
                    name="  PYTHON  ",
                    proficiency=3,
                    years_experience=1.0,
                )
            ]
        }
    )
    assert score_candidate_job(altered, job()) == 85.0


def test_score_is_bounded() -> None:
    assert 0.0 <= score_candidate_job(candidate(), job(nice=("sql",))) <= 100.0


def test_ties_are_ordered_by_job_id() -> None:
    ranked = rank_jobs(candidate(), [job("job_0002"), job("job_0001")])
    assert [item[0] for item in ranked] == ["job_0001", "job_0002"]


def test_scores_do_not_accept_labels_or_vectors() -> None:
    assert set(score_candidate_job.__annotations__) == {"candidate", "job", "return"}


def test_non_skill_job_facts_do_not_change_score() -> None:
    original = job()
    changed = original.model_copy(
        update={
            "domain": "Design",
            "minimum_experience_years": 20,
            "education_level": "doctorate",
            "work_mode": "onsite",
            "description": "Unrelated text.",
        }
    )
    assert score_candidate_job(candidate(), original) == score_candidate_job(candidate(), changed)
