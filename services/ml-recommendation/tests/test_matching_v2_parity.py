"""Adapter and independent Python Matching 2.0 oracle tests."""

import json
from pathlib import Path

import pytest
from pydantic import TypeAdapter

from smart_recruitment_ml.baselines.adapter import adapt_sources
from smart_recruitment_ml.baselines.matching_v2_oracle import (
    compute_tfidf,
    cosine_similarity,
    php_round,
    rank_candidate_jobs,
    score_pair,
    tokenize,
)
from smart_recruitment_ml.schemas.baselines import AdaptedDataset
from smart_recruitment_ml.schemas.synthetic import Candidate, Job

REPOSITORY_ROOT = Path(__file__).parents[3]
ML_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"


def load_candidates(path: Path) -> list[Candidate]:
    values = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]
    return TypeAdapter(list[Candidate]).validate_python(values)


def load_jobs(path: Path) -> list[Job]:
    values = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]
    return TypeAdapter(list[Job]).validate_python(values)


@pytest.fixture(scope="module")
def adapted() -> AdaptedDataset:
    candidates = load_candidates(ML_ROOT / "data/synthetic/v1/candidates.jsonl")
    jobs = load_jobs(ML_ROOT / "data/synthetic/v1/jobs.jsonl")
    return adapt_sources(candidates, jobs)


def test_skill_registry_is_sorted_and_one_based(adapted) -> None:
    assert list(adapted.skill_registry) == sorted(adapted.skill_registry)
    assert list(adapted.skill_registry.values()) == list(range(1, len(adapted.skill_registry) + 1))


def test_candidate_experience_dates_are_deterministic(adapted) -> None:
    candidate = adapted.candidates["cand_0001"]
    assert candidate.experience_start == "2000-01-01"
    assert candidate.experience_duration_days == 365
    assert candidate.experience_end == "2000-12-31"


@pytest.mark.parametrize(
    ("candidate_id", "degree"),
    [
        ("cand_0001", "bachelor"),
        ("cand_0002", "master"),
    ],
)
def test_candidate_education_mapping(adapted, candidate_id: str, degree: str) -> None:
    assert adapted.candidates[candidate_id].education_degree == degree


def test_lead_jobs_map_to_senior(adapted) -> None:
    lead_jobs = [
        job
        for source_id, job in adapted.jobs.items()
        if source_id and job.experience_level == "senior"
    ]
    assert lead_jobs


def test_nice_to_have_weight_is_one(adapted) -> None:
    nice = [
        requirement.weight
        for job in adapted.jobs.values()
        for requirement in job.skills
        if requirement.requirement_type == "nice_to_have"
    ]
    assert nice
    assert set(nice) == {1}


def test_fixed_publication_date(adapted) -> None:
    assert {job.published_at for job in adapted.jobs.values()} == {"2026-01-01T00:00:00Z"}


def test_php_compatible_half_up_rounding() -> None:
    assert php_round(1.005) == 1.01


def test_empty_text_tokenization_and_cosine() -> None:
    assert tokenize("") == []
    assert cosine_similarity({}, {}) == 0.0


def test_tfidf_identical_documents_have_unit_cosine() -> None:
    vectors = compute_tfidf({"a": "python sql", "b": "python sql"})
    assert cosine_similarity(vectors["a"], vectors["b"]) == pytest.approx(1.0)


def test_empty_nice_to_have_receives_not_applicable_score(adapted) -> None:
    candidate = adapted.candidates["cand_0001"]
    source_job = adapted.jobs["job_0001"]
    without_nice = source_job.model_copy(
        update={
            "skills": [item for item in source_job.skills if item.requirement_type == "required"]
        }
    )
    _, components = score_pair(candidate, without_nice, 0.0)
    assert components.nice_to_have_skills == 10.0


def test_required_experience_education_and_total_components_are_bounded(adapted) -> None:
    score, components = score_pair(
        adapted.candidates["cand_0001"],
        adapted.jobs["job_0001"],
        0.5,
    )
    assert 0 <= components.required_skills <= 45
    assert 0 <= components.experience <= 20
    assert 0 <= components.education <= 10
    assert 0 <= score <= 100


def test_group_ranking_is_deterministic_and_isolated(adapted) -> None:
    job_ids = ["job_0001", "job_0002", "job_0003"]
    first = rank_candidate_jobs(adapted, "cand_0001", job_ids)
    rank_candidate_jobs(adapted, "cand_0002", job_ids)
    second = rank_candidate_jobs(adapted, "cand_0001", job_ids)
    assert first == second


def test_generated_pair_level_parity_is_exact() -> None:
    parity = json.loads((ML_ROOT / "data/baselines/v1/parity.json").read_text(encoding="utf-8"))
    assert parity["parity_passed"] is True
    assert parity["train"]["score_max_absolute_error"] == 0.0
    assert parity["validation"]["rank_match_rate"] == 1.0


def test_laravel_and_python_metrics_are_equal() -> None:
    metrics = json.loads((ML_ROOT / "data/baselines/v1/metrics.json").read_text(encoding="utf-8"))
    for split in ("train", "validation"):
        assert (
            metrics["splits"][split]["laravel_matching_2.0"]
            == (metrics["splits"][split]["python_matching_v2_parity"])
        )
