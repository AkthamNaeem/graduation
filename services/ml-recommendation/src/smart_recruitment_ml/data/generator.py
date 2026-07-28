"""Deterministic synthetic Dataset generator for Job Recommendation."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import random
import shutil
import sys
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final

from pydantic import ValidationError

from smart_recruitment_ml.data.catalog import DOMAINS
from smart_recruitment_ml.schemas.synthetic import (
    Candidate,
    CandidateSkill,
    DatasetManifest,
    FileArtifact,
    Job,
    RelevancePair,
    RequiredSkill,
    Scenario,
)

if TYPE_CHECKING:
    from collections.abc import Mapping, Sequence

DATASET_VERSION: Final = "synthetic-job-rec-1.0.0"
DATASET_SCHEMA_VERSION: Final = "synthetic-job-rec-schema-v1"
GENERATOR_VERSION: Final = "0.1.0"
DEFAULT_RANDOM_SEED: Final = 20260724
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
DATASET_RELEASE_DATE: Final = "2026-07-24"
DEFAULT_CANDIDATE_COUNT: Final = 180
DEFAULT_JOB_COUNT: Final = 180
DEFAULT_PAIRS_PER_CANDIDATE: Final = 60
MIN_JOB_APPEARANCES: Final = 20

CAREER_LEVELS: Final = ("entry", "junior", "mid", "senior", "lead")
EDUCATION_LEVELS: Final = ("high_school", "diploma", "bachelor", "master", "doctorate")
WORK_MODES: Final = ("remote", "hybrid", "onsite")
EMPLOYMENT_TYPES: Final = ("full_time", "part_time", "contract")

POSITIVE_RATIONALES: Final = frozenset(
    {
        "CORE_SKILLS_STRONG",
        "CORE_SKILLS_PARTIAL",
        "EXPERIENCE_ALIGNED",
        "SENIORITY_ALIGNED",
        "EDUCATION_ALIGNED",
        "DOMAIN_ALIGNED",
        "ADJACENT_DOMAIN_TRANSFER",
        "TRANSFERABLE_SKILLS_STRONG",
        "WORK_MODE_ALIGNED",
        "EMPLOYMENT_TYPE_ALIGNED",
    },
)
NEGATIVE_RATIONALES: Final = frozenset(
    {
        "CRITICAL_SKILL_MISSING",
        "EXPERIENCE_GAP",
        "SENIORITY_MISMATCH",
        "EDUCATION_BELOW_REQUIREMENT",
        "DOMAIN_MISMATCH",
        "WORK_MODE_CONFLICT",
        "EMPLOYMENT_TYPE_CONFLICT",
    },
)
SENSITIVE_FIELDS: Final = (
    "personal_name",
    "full_name",
    "first_name",
    "last_name",
    "email",
    "phone",
    "birth_date",
    "age",
    "gender",
    "nationality",
    "marital_status",
    "address",
    "photo",
    "cv",
    "raw_cv",
    "application_status",
    "application_history",
    "cover_letter",
    "screening_answers",
    "tests",
    "interviews",
    "internal_notes",
    "company_name",
    "auth_token",
    "latent_score",
    "feature_vector",
    "application_outcome",
    "acceptance_probability",
)


@dataclass(frozen=True, slots=True)
class GenerationConfig:
    """Validated deterministic generator configuration."""

    seed: int = DEFAULT_RANDOM_SEED
    candidate_count: int = DEFAULT_CANDIDATE_COUNT
    job_count: int = DEFAULT_JOB_COUNT
    pairs_per_candidate: int = DEFAULT_PAIRS_PER_CANDIDATE
    source_revision: str = SOURCE_REVISION
    architecture_sha256: str = ARCHITECTURE_SHA256

    def __post_init__(self) -> None:
        if self.candidate_count < len(DOMAINS) or self.job_count < len(DOMAINS):
            msg = f"candidate_count and job_count must each be at least {len(DOMAINS)}"
            raise ValueError(msg)
        if self.pairs_per_candidate < 20:
            msg = "pairs_per_candidate must be at least 20 for scenario coverage"
            raise ValueError(msg)
        if self.pairs_per_candidate > self.job_count:
            msg = "pairs_per_candidate cannot exceed job_count"
            raise ValueError(msg)
        total_pairs = self.candidate_count * self.pairs_per_candidate
        if total_pairs < self.job_count * MIN_JOB_APPEARANCES:
            msg = "configuration cannot provide at least 20 appearances per Job"
            raise ValueError(msg)
        if len(self.source_revision) != 40 or any(
            character not in "0123456789abcdef" for character in self.source_revision.lower()
        ):
            msg = "source_revision must be a 40-character hexadecimal Git revision"
            raise ValueError(msg)
        if len(self.architecture_sha256) != 64 or any(
            character not in "0123456789abcdef" for character in self.architecture_sha256.lower()
        ):
            msg = "architecture_sha256 must be a 64-character hexadecimal digest"
            raise ValueError(msg)


@dataclass(frozen=True, slots=True)
class DatasetRecords:
    """In-memory typed records before deterministic serialization."""

    candidates: tuple[Candidate, ...]
    jobs: tuple[Job, ...]
    pairs: tuple[RelevancePair, ...]


@dataclass(frozen=True, slots=True)
class WrittenDataset:
    """Summary returned after publishing a complete Dataset directory."""

    output_dir: Path
    manifest: DatasetManifest
    file_hashes: Mapping[str, str]


def _candidate_experience(career_index: int, rng: random.Random) -> float:
    ranges = ((0.0, 1.0), (1.0, 3.0), (3.0, 6.0), (6.0, 10.0), (9.0, 14.0))
    low, high = ranges[career_index]
    return round(rng.uniform(low, high), 1)


def _job_experience(career_index: int, rng: random.Random) -> float:
    bases = (0.0, 1.0, 3.0, 5.0, 8.0)
    return round(bases[career_index] + rng.choice((0.0, 0.5, 1.0)), 1)


def _sample_candidates(config: GenerationConfig, rng: random.Random) -> tuple[Candidate, ...]:
    candidates: list[Candidate] = []
    for index in range(config.candidate_count):
        domain = DOMAINS[index % len(DOMAINS)]
        career_index = index % len(CAREER_LEVELS)
        role_title = domain.titles[index % len(domain.titles)]
        total_experience = _candidate_experience(career_index, rng)
        core_count = 4 + (index % 2)
        core_skills = rng.sample(list(domain.core_skills), core_count)
        transferable = ["communication", rng.choice(domain.transferable_skills)]
        skill_names = sorted(set([*core_skills, *transferable]))
        skills = [
            CandidateSkill(
                name=skill_name,
                proficiency=1 + rng.randrange(5),
                years_experience=round(
                    min(total_experience, rng.uniform(0.0, max(0.5, total_experience))),
                    1,
                ),
            )
            for skill_name in skill_names
        ]
        candidates.append(
            Candidate(
                candidate_id=f"cand_{index + 1:04d}",
                primary_domain=domain.name,
                adjacent_domains=list(domain.adjacent_domains),
                headline=f"{CAREER_LEVELS[career_index].title()} {role_title}",
                career_level=CAREER_LEVELS[career_index],
                total_experience_years=total_experience,
                education_level=EDUCATION_LEVELS[min(4, (index + 2) % 5)],
                skills=skills,
                preferred_work_modes=sorted(
                    {
                        WORK_MODES[index % len(WORK_MODES)],
                        WORK_MODES[(index + 1) % len(WORK_MODES)],
                    },
                ),
                preferred_employment_types=sorted(
                    {
                        EMPLOYMENT_TYPES[index % len(EMPLOYMENT_TYPES)],
                        EMPLOYMENT_TYPES[(index + 1) % len(EMPLOYMENT_TYPES)],
                    },
                ),
            ),
        )
    return tuple(candidates)


def _sample_jobs(config: GenerationConfig, rng: random.Random) -> tuple[Job, ...]:
    jobs: list[Job] = []
    for index in range(config.job_count):
        domain = DOMAINS[index % len(DOMAINS)]
        career_index = (index * 3 + index // len(DOMAINS)) % len(CAREER_LEVELS)
        required_names = rng.sample(list(domain.core_skills), 4)
        if index % 3 == 0:
            required_names[-1] = "communication"
        required_skills = [
            RequiredSkill(name=skill_name, weight=1 + rng.randrange(5))
            for skill_name in sorted(set(required_names))
        ]
        used_names = {skill.name for skill in required_skills}
        nice_names = sorted(
            set(
                [
                    "communication",
                    rng.choice(domain.transferable_skills),
                    rng.choice(domain.core_skills),
                ],
            )
            - used_names,
        )
        jobs.append(
            Job(
                job_id=f"job_{index + 1:04d}",
                domain=domain.name,
                title=domain.titles[index % len(domain.titles)],
                department=domain.name,
                description=(
                    f"Synthetic {CAREER_LEVELS[career_index]} role focused on "
                    f"{domain.core_skills[index % len(domain.core_skills)]}."
                ),
                responsibilities=list(domain.responsibilities),
                required_skills=required_skills,
                nice_to_have_skills=nice_names,
                minimum_experience_years=_job_experience(career_index, rng),
                education_level=EDUCATION_LEVELS[min(4, (index + 1) % 5)],
                career_level=CAREER_LEVELS[career_index],
                work_mode=WORK_MODES[(index * 2) % len(WORK_MODES)],
                employment_type=EMPLOYMENT_TYPES[(index * 2 + 1) % len(EMPLOYMENT_TYPES)],
            ),
        )
    return tuple(jobs)


def _scenario_sort_key(candidate: Candidate, job: Job) -> tuple[float, int, str]:
    """Rank surface fit without using hidden label-generation factors."""
    candidate_skills = {skill.name for skill in candidate.skills}
    required_skills = {skill.name for skill in job.required_skills}
    all_job_skills = required_skills | set(job.nice_to_have_skills)
    required_overlap = len(candidate_skills & required_skills) / len(required_skills)
    transferable_overlap = len(candidate_skills & all_job_skills)
    domain_fit = (
        2.0
        if job.domain == candidate.primary_domain
        else 1.0
        if job.domain in candidate.adjacent_domains
        else 0.0
    )
    surface_fit = domain_fit + required_overlap + min(transferable_overlap, 2) * 0.1
    conflict_count = sum(
        (
            candidate.total_experience_years < job.minimum_experience_years,
            CAREER_LEVELS.index(candidate.career_level) < CAREER_LEVELS.index(job.career_level),
            EDUCATION_LEVELS.index(candidate.education_level)
            < EDUCATION_LEVELS.index(job.education_level),
            job.work_mode not in candidate.preferred_work_modes,
            job.employment_type not in candidate.preferred_employment_types,
            required_overlap < 0.75,
        )
    )
    return surface_fit, conflict_count, job.job_id


def _scenario_assignments(
    candidate: Candidate,
    selected_jobs: Sequence[Job],
) -> dict[str, Scenario]:
    same = [job for job in selected_jobs if job.domain == candidate.primary_domain]
    adjacent = [job for job in selected_jobs if job.domain in candidate.adjacent_domains]
    other = [
        job
        for job in selected_jobs
        if job.domain != candidate.primary_domain and job.domain not in candidate.adjacent_domains
    ]
    assignments: dict[str, Scenario] = {}

    pair_count = len(selected_jobs)
    hard_count = math.ceil(pair_count * 0.10)
    borderline_count = math.ceil(pair_count * 0.10)
    noise_count = max(1, round(pair_count * 0.10))
    transferable_good_count = max(1, pair_count // 10)

    near_domain = [*same, *adjacent]
    reserved_strong = min(
        same,
        key=lambda job: (
            _scenario_sort_key(candidate, job)[1],
            -_scenario_sort_key(candidate, job)[0],
            job.job_id,
        ),
    )
    hard_candidates = sorted(
        (
            job
            for job in near_domain
            if job.job_id != reserved_strong.job_id and _scenario_sort_key(candidate, job)[1] > 0
        ),
        key=lambda job: _scenario_sort_key(candidate, job),
        reverse=True,
    )
    if len(hard_candidates) < hard_count:
        msg = "Insufficient professionally plausible hard-negative Jobs"
        raise ValueError(msg)
    hard_jobs = hard_candidates[:hard_count]
    hard_ids = {job.job_id for job in hard_jobs}
    for job in hard_jobs:
        assignments[job.job_id] = "hard_negative"
    assignments[reserved_strong.job_id] = "strong_match"

    remaining_same = [
        job for job in same if job.job_id not in hard_ids and job.job_id != reserved_strong.job_id
    ]
    remaining_adjacent = [job for job in adjacent if job.job_id not in hard_ids]
    strong_count = len(remaining_same) * 2 // 5
    for job in remaining_same[:strong_count]:
        assignments[job.job_id] = "strong_match"
    for job in remaining_same[strong_count:]:
        assignments[job.job_id] = "good_match"

    adjacent_count = max(1, len(remaining_adjacent) // 2)
    for job in remaining_adjacent[:adjacent_count]:
        assignments[job.job_id] = "adjacent_domain"
    for job in remaining_adjacent[adjacent_count:]:
        assignments[job.job_id] = "good_match"

    ranked_other = sorted(
        other,
        key=lambda job: _scenario_sort_key(candidate, job),
        reverse=True,
    )
    cursor = 0
    special_scenarios: tuple[tuple[Scenario, int], ...] = (
        ("borderline", borderline_count),
        ("good_match", transferable_good_count),
        ("noise_injected", noise_count),
    )
    for scenario, count in special_scenarios:
        for job in ranked_other[cursor : cursor + count]:
            assignments[job.job_id] = scenario
        cursor += count
    for job in ranked_other[cursor:]:
        assignments[job.job_id] = "clear_mismatch"

    if len(assignments) != pair_count:
        msg = "Scenario assignment did not cover every selected Job"
        raise ValueError(msg)
    return assignments


def _professional_signals(
    candidate: Candidate,
    job: Job,
) -> tuple[float, list[str], dict[str, float | bool]]:
    candidate_skills = {skill.name: skill for skill in candidate.skills}
    total_weight = sum(skill.weight for skill in job.required_skills)
    matched = [skill for skill in job.required_skills if skill.name in candidate_skills]
    matched_weight = sum(skill.weight for skill in matched)
    coverage = matched_weight / total_weight
    proficiency = (
        sum(candidate_skills[skill.name].proficiency for skill in matched) / (5 * len(matched))
        if matched
        else 0.0
    )
    experience_aligned = candidate.total_experience_years >= job.minimum_experience_years
    seniority_delta = CAREER_LEVELS.index(job.career_level) - CAREER_LEVELS.index(
        candidate.career_level,
    )
    education_aligned = EDUCATION_LEVELS.index(candidate.education_level) >= EDUCATION_LEVELS.index(
        job.education_level,
    )
    domain_aligned = candidate.primary_domain == job.domain
    adjacent_domain = job.domain in candidate.adjacent_domains
    work_mode_aligned = job.work_mode in candidate.preferred_work_modes
    employment_aligned = job.employment_type in candidate.preferred_employment_types
    all_job_skills = {
        *(skill.name for skill in job.required_skills),
        *job.nice_to_have_skills,
    }
    transferable_overlap = bool(all_job_skills & set(candidate_skills))

    rationales: list[str] = []
    if coverage >= 0.75:
        rationales.append("CORE_SKILLS_STRONG")
    elif coverage >= 0.25:
        rationales.append("CORE_SKILLS_PARTIAL")
    else:
        rationales.append("CRITICAL_SKILL_MISSING")
    if 0.25 <= coverage < 0.75:
        rationales.append("CRITICAL_SKILL_MISSING")
    rationales.append("EXPERIENCE_ALIGNED" if experience_aligned else "EXPERIENCE_GAP")
    rationales.append("SENIORITY_ALIGNED" if seniority_delta <= 0 else "SENIORITY_MISMATCH")
    rationales.append(
        "EDUCATION_ALIGNED" if education_aligned else "EDUCATION_BELOW_REQUIREMENT",
    )
    if domain_aligned:
        rationales.append("DOMAIN_ALIGNED")
    elif adjacent_domain:
        rationales.append("ADJACENT_DOMAIN_TRANSFER")
    else:
        rationales.append("DOMAIN_MISMATCH")
    if transferable_overlap and not domain_aligned:
        rationales.append("TRANSFERABLE_SKILLS_STRONG")
    rationales.append("WORK_MODE_ALIGNED" if work_mode_aligned else "WORK_MODE_CONFLICT")
    rationales.append(
        "EMPLOYMENT_TYPE_ALIGNED" if employment_aligned else "EMPLOYMENT_TYPE_CONFLICT",
    )

    observed_score = (
        coverage * 0.36
        + proficiency * 0.12
        + (0.13 if experience_aligned else -0.09)
        + (0.08 if seniority_delta <= 0 else -0.10)
        + (0.07 if education_aligned else -0.04)
        + (0.14 if domain_aligned else 0.06 if adjacent_domain else -0.12)
        + (0.05 if work_mode_aligned else -0.05)
        + (0.05 if employment_aligned else -0.05)
    )
    signals: dict[str, float | bool] = {
        "coverage": coverage,
        "proficiency": proficiency,
        "experience_aligned": experience_aligned,
        "domain_aligned": domain_aligned,
    }
    return observed_score, sorted(set(rationales)), signals


def _label_pair(
    candidate: Candidate,
    job: Job,
    scenario: Scenario,
    pair_index: int,
    rng: random.Random,
) -> tuple[int, list[str], bool]:
    observed_score, rationales, signals = _professional_signals(candidate, job)
    anchors = {
        "strong_match": 0.83,
        "good_match": 0.70,
        "adjacent_domain": 0.57,
        "borderline": 0.47 + (0.06 if pair_index % 2 else 0.0),
        "hard_negative": 0.31,
        "clear_mismatch": 0.11,
        "noise_injected": 0.48,
    }
    coverage = float(signals["coverage"])
    proficiency = float(signals["proficiency"])
    nonlinear_interaction = coverage * proficiency * 0.09
    hidden_affinity = rng.uniform(-0.045, 0.045)
    bounded_noise = rng.uniform(-0.025, 0.025)
    suitability = (
        anchors[scenario]
        + (observed_score - 0.45) * 0.16
        + nonlinear_interaction
        + hidden_affinity
        + bounded_noise
    )
    if scenario == "strong_match":
        suitability = max(0.71, suitability)
    elif scenario == "hard_negative":
        suitability = min(0.49, suitability)
    elif scenario == "borderline":
        suitability = min(0.69, max(0.28, suitability))
    elif scenario == "clear_mismatch":
        suitability = min(0.24, suitability)

    label = (
        3 if suitability >= 0.70 else 2 if suitability >= 0.50 else 1 if suitability >= 0.25 else 0
    )
    noise_applied = scenario == "noise_injected"
    if noise_applied:
        label = label + 1 if label < 3 and pair_index % 2 else label - 1 if label > 0 else 1
        rationales.append("CONTROLLED_LABEL_NOISE")

    return label, sorted(set(rationales)), noise_applied


def _sample_pairs(
    config: GenerationConfig,
    candidates: Sequence[Candidate],
    jobs: Sequence[Job],
    rng: random.Random,
) -> tuple[RelevancePair, ...]:
    pairs: list[RelevancePair] = []
    for candidate_index, candidate in enumerate(candidates):
        start = candidate_index * config.pairs_per_candidate
        selected_jobs = [
            jobs[(start + offset) % config.job_count]
            for offset in range(config.pairs_per_candidate)
        ]
        assignments = _scenario_assignments(candidate, selected_jobs)
        for selected_index, job in enumerate(selected_jobs):
            scenario = assignments[job.job_id]
            label, rationales, noise_applied = _label_pair(
                candidate,
                job,
                scenario,
                candidate_index * config.pairs_per_candidate + selected_index,
                rng,
            )
            pairs.append(
                RelevancePair(
                    pair_id=f"pair_{candidate.candidate_id}_{job.job_id}",
                    candidate_id=candidate.candidate_id,
                    job_id=job.job_id,
                    relevance_label=label,
                    scenario=scenario,
                    rationale_codes=rationales,
                    noise_applied=noise_applied,
                ),
            )
    return tuple(sorted(pairs, key=lambda pair: (pair.candidate_id, pair.job_id)))


def generate_dataset(config: GenerationConfig) -> DatasetRecords:
    """Generate and validate deterministic in-memory records."""
    rng = random.Random(config.seed)
    candidates = _sample_candidates(config, rng)
    jobs = _sample_jobs(config, rng)
    pairs = _sample_pairs(config, candidates, jobs, rng)
    records = DatasetRecords(candidates=candidates, jobs=jobs, pairs=pairs)
    validate_dataset(records, config)
    return records


def _distribution(values: Sequence[str | int]) -> dict[str, int]:
    return dict(sorted(Counter(str(value) for value in values).items()))


def summarize_dataset(records: DatasetRecords) -> dict[str, Any]:
    """Calculate auditable aggregate constraints without hidden factors."""
    candidate_pair_counts = Counter(pair.candidate_id for pair in records.pairs)
    job_pair_counts = Counter(pair.job_id for pair in records.pairs)
    return {
        "candidate_count": len(records.candidates),
        "job_count": len(records.jobs),
        "pair_count": len(records.pairs),
        "domain_distribution": _distribution(
            [candidate.primary_domain for candidate in records.candidates],
        ),
        "job_domain_distribution": _distribution([job.domain for job in records.jobs]),
        "label_distribution": _distribution(
            [pair.relevance_label for pair in records.pairs],
        ),
        "scenario_distribution": _distribution([pair.scenario for pair in records.pairs]),
        "noise_count": sum(pair.noise_applied for pair in records.pairs),
        "noise_rate": round(
            sum(pair.noise_applied for pair in records.pairs) / len(records.pairs),
            6,
        ),
        "pairs_per_candidate_min": min(candidate_pair_counts.values()),
        "pairs_per_candidate_max": max(candidate_pair_counts.values()),
        "job_appearances_min": min(job_pair_counts.values()),
        "job_appearances_max": max(job_pair_counts.values()),
        "duplicate_pair_count": len(records.pairs)
        - len({(pair.candidate_id, pair.job_id) for pair in records.pairs}),
    }


def _contains_sensitive_key(value: object) -> bool:
    if isinstance(value, dict):
        if set(value).intersection(SENSITIVE_FIELDS):
            return True
        return any(_contains_sensitive_key(item) for item in value.values())
    if isinstance(value, list):
        return any(_contains_sensitive_key(item) for item in value)
    return False


def validate_dataset(records: DatasetRecords, config: GenerationConfig) -> None:
    """Fail generation when any locked Dataset constraint is violated."""
    if len(records.candidates) != config.candidate_count:
        raise ValueError("Candidate count mismatch")
    if len(records.jobs) != config.job_count:
        raise ValueError("Job count mismatch")
    expected_pairs = config.candidate_count * config.pairs_per_candidate
    if len(records.pairs) != expected_pairs:
        raise ValueError("Pair count mismatch")
    if len({candidate.candidate_id for candidate in records.candidates}) != len(
        records.candidates,
    ):
        raise ValueError("Duplicate Candidate IDs")
    if len({job.job_id for job in records.jobs}) != len(records.jobs):
        raise ValueError("Duplicate Job IDs")
    if len({pair.pair_id for pair in records.pairs}) != len(records.pairs):
        raise ValueError("Duplicate Pair IDs")

    summary = summarize_dataset(records)
    if summary["duplicate_pair_count"] != 0:
        raise ValueError("Duplicate Candidate-Job pair")
    if (
        summary["pairs_per_candidate_min"] != config.pairs_per_candidate
        or summary["pairs_per_candidate_max"] != config.pairs_per_candidate
    ):
        raise ValueError("Candidate pair-count constraint failed")
    if summary["job_appearances_min"] < MIN_JOB_APPEARANCES:
        raise ValueError("A Job appears fewer than 20 times")
    if len(summary["domain_distribution"]) < 12:
        raise ValueError("Dataset must include at least 12 professional domains")

    label_distribution = summary["label_distribution"]
    if set(label_distribution) != {"0", "1", "2", "3"}:
        raise ValueError("All relevance labels 0..3 must be present")
    for count in label_distribution.values():
        rate = count / expected_pairs
        if rate < 0.05 or rate > 0.65:
            raise ValueError("Label distribution is outside 5%..65%")

    scenario_distribution = summary["scenario_distribution"]
    expected_scenarios = {
        "strong_match",
        "good_match",
        "adjacent_domain",
        "borderline",
        "hard_negative",
        "clear_mismatch",
        "noise_injected",
    }
    if set(scenario_distribution) != expected_scenarios:
        raise ValueError("Every scenario must be present")
    if scenario_distribution["hard_negative"] / expected_pairs < 0.10:
        raise ValueError("Hard negatives must represent at least 10%")
    if scenario_distribution["borderline"] / expected_pairs < 0.10:
        raise ValueError("Borderline pairs must represent at least 10%")
    if not 0.05 <= summary["noise_rate"] <= 0.20:
        raise ValueError("Noise rate must remain between 5% and 20%")

    for pair in records.pairs:
        rationale_set = set(pair.rationale_codes)
        if pair.scenario in {"hard_negative", "borderline"} and (
            not rationale_set.intersection(POSITIVE_RATIONALES)
            or not rationale_set.intersection(NEGATIVE_RATIONALES)
        ):
            raise ValueError(f"{pair.scenario} must contain positive and negative signals")
    serializable_records = [
        *(candidate.model_dump(mode="json") for candidate in records.candidates),
        *(job.model_dump(mode="json") for job in records.jobs),
        *(pair.model_dump(mode="json") for pair in records.pairs),
    ]
    if any(_contains_sensitive_key(record) for record in serializable_records):
        raise ValueError("A sensitive or forbidden field was found")


def _jsonl_bytes(records: Sequence[Candidate | Job | RelevancePair]) -> bytes:
    lines = [
        json.dumps(
            record.model_dump(mode="json"),
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )
        for record in records
    ]
    return ("\n".join(lines) + "\n").encode()


def _sha256(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def _dataset_card(
    config: GenerationConfig,
    summary: Mapping[str, Any],
    jsonl_files: Sequence[FileArtifact],
) -> bytes:
    domains = "\n".join(f"- {domain.name}" for domain in DOMAINS)
    hashes = "\n".join(f"- `{item.path}`: `{item.sha256}`" for item in jsonl_files)
    card = f"""# Synthetic Job Recommendation Dataset Card

## Identity

- Dataset: `{DATASET_VERSION}`
- Schema: `{DATASET_SCHEMA_VERSION}`
- Generator: `{GENERATOR_VERSION}`
- Release date: `{DATASET_RELEASE_DATE}` (fixed Dataset release metadata)
- Synthetic: **yes, entirely synthetic**
- Fixed seed: `{config.seed}`

## Intended use

This Dataset supports later development and evaluation of Learning-to-Rank for
Job Seeker → Job Recommendation. Candidate IDs are future ranking query groups.
It may be used for pipeline tests, baseline experiments, and model research.

It must not be used as evidence of production quality, hiring suitability,
acceptance probability, application outcome, or a decision to accept or reject
a person. Rationale codes are audit metadata and must not become Phase 5 model
features.

## Counts and schemas

- Candidates: `{summary["candidate_count"]}`
- Jobs: `{summary["job_count"]}`
- Candidate-Job pairs: `{summary["pair_count"]}`
- Pairs per Candidate: `{config.pairs_per_candidate}`

`candidates.jsonl` contains synthetic professional facts: domain, headline,
career level, experience, education, skills, and work preferences.

`jobs.jsonl` contains synthetic professional requirements: domain, title,
responsibilities, required/nice-to-have skills, experience, education,
seniority, work mode, and employment type.

`pairs.jsonl` contains IDs, relevance label `0..3`, scenario, rationale codes,
and a controlled-noise marker. It never contains latent scores or feature
vectors.

## Domains

{domains}

## Labels and scenarios

- `0`: low professional relevance.
- `1`: limited relevance with important gaps.
- `2`: useful relevance with some gaps.
- `3`: strong relevance.

Scenarios are `strong_match`, `good_match`, `adjacent_domain`, `borderline`,
`hard_negative`, `clear_mismatch`, and `noise_injected`. Hard negatives retain
a positive surface signal while exposing a critical professional conflict.
Borderline records include both positive and negative factors near a label
threshold.

Rationale vocabulary includes skill coverage, experience, seniority,
education, domain transfer, work-mode, employment-type, and controlled-noise
codes. Rationales never reveal runtime hidden affinity values.

## Generation approach

The generator combines professional compatibility, weighted skill coverage,
proficiency, experience, seniority, education, work preferences, nonlinear
interactions, hidden Candidate/Job affinity, and bounded random noise. Hidden
factors exist only during generation and are not serialized or available to a
future Feature Pipeline. Labels are not Matching `2.0`, skill counts, or
application outcomes.

Pair sampling is deterministic and stratified across same-domain, adjacent,
borderline, hard-negative, mismatch, and noise scenarios. The final regular
sampling schedule gives every Candidate exactly 60 unique Jobs and balances
Job appearances.

## Privacy and eligibility

No real people, companies, CVs, applications, demographics, contact details,
authentication data, tests, interviews, or internal notes are used. All IDs
are synthetic.

Jobs represent `laravel_pre_filtered_eligible_jobs`. Job status, company
approval, deadline, and prior-application exclusion remain Laravel concerns
and are intentionally not model features.

## Limitations

The catalog and labels are authored synthetic approximations. They have a
synthetic-to-production gap, limited occupational breadth, and provide no
fairness or production-performance guarantee. No train/validation/test split
exists yet, and Feature Pipeline definitions are deferred to Phase 5.

## Reproducibility

From the repository root:

```powershell
& services/ml-recommendation/.venv/Scripts/python.exe -m smart_recruitment_ml.data `
    --output-dir services/ml-recommendation/data/synthetic/v1 `
    --seed {config.seed} `
    --candidate-count {config.candidate_count} `
    --job-count {config.job_count} `
    --pairs-per-candidate {config.pairs_per_candidate} `
    --source-revision {config.source_revision} `
    --architecture-sha256 {config.architecture_sha256}
```

JSONL hashes:

{hashes}

See `manifest.json` for counts, distributions, configuration, and file
integrity metadata.
"""
    return card.replace("\r\n", "\n").encode()


def _build_artifacts(
    records: DatasetRecords,
    config: GenerationConfig,
) -> tuple[dict[str, bytes], DatasetManifest]:
    summary = summarize_dataset(records)
    jsonl: dict[str, bytes] = {
        "candidates.jsonl": _jsonl_bytes(records.candidates),
        "jobs.jsonl": _jsonl_bytes(records.jobs),
        "pairs.jsonl": _jsonl_bytes(records.pairs),
    }
    record_counts = {
        "candidates.jsonl": len(records.candidates),
        "jobs.jsonl": len(records.jobs),
        "pairs.jsonl": len(records.pairs),
    }
    file_entries = [
        FileArtifact(
            path=name,
            record_count=record_counts[name],
            size_bytes=len(content),
            sha256=_sha256(content),
        )
        for name, content in sorted(jsonl.items())
    ]
    manifest = DatasetManifest(
        dataset_version=DATASET_VERSION,
        dataset_schema_version=DATASET_SCHEMA_VERSION,
        generator_version=GENERATOR_VERSION,
        random_seed=config.seed,
        source_revision=config.source_revision,
        architecture_sha256=config.architecture_sha256,
        dataset_release_date=DATASET_RELEASE_DATE,
        synthetic=True,
        deterministic=True,
        eligibility_scope="laravel_pre_filtered_eligible_jobs",
        candidate_count=len(records.candidates),
        job_count=len(records.jobs),
        pair_count=len(records.pairs),
        pairs_per_candidate=config.pairs_per_candidate,
        domain_count=len(DOMAINS),
        domains=[domain.name for domain in DOMAINS],
        label_distribution=summary["label_distribution"],
        scenario_distribution=summary["scenario_distribution"],
        noise_count=summary["noise_count"],
        noise_rate=summary["noise_rate"],
        generation_config={
            "candidate_count": config.candidate_count,
            "job_count": config.job_count,
            "pairs_per_candidate": config.pairs_per_candidate,
            "random_seed": config.seed,
            "source_revision": config.source_revision,
            "architecture_sha256": config.architecture_sha256,
        },
        excluded_sensitive_fields=list(SENSITIVE_FIELDS),
        intended_use=[
            "future_learning_to_rank_research",
            "feature_pipeline_validation",
            "reproducible_baseline_experiments",
        ],
        limitations=[
            "fully_synthetic_labels",
            "synthetic_to_production_gap",
            "no_fairness_guarantee",
            "no_production_performance_guarantee",
            "no_data_split_or_feature_pipeline_yet",
        ],
        files=file_entries,
    )
    artifacts = dict(jsonl)
    artifacts["manifest.json"] = (
        json.dumps(
            manifest.model_dump(mode="json"),
            ensure_ascii=False,
            indent=2,
            sort_keys=True,
        )
        + "\n"
    ).encode()
    artifacts["DATASET_CARD.md"] = _dataset_card(config, summary, file_entries)
    return artifacts, manifest


def _publish_atomically(output_dir: Path, artifacts: Mapping[str, bytes]) -> None:
    parent = output_dir.parent
    parent.mkdir(parents=True, exist_ok=True)
    temporary = parent / f".{output_dir.name}.tmp"
    backup = parent / f".{output_dir.name}.backup"
    for path in (temporary, backup):
        if path.exists():
            shutil.rmtree(path)
    temporary.mkdir()
    try:
        for name, content in sorted(artifacts.items()):
            (temporary / name).write_bytes(content)
        moved_existing = False
        if output_dir.exists():
            os.replace(output_dir, backup)
            moved_existing = True
        try:
            os.replace(temporary, output_dir)
        except OSError:
            if moved_existing:
                os.replace(backup, output_dir)
            raise
        if backup.exists():
            shutil.rmtree(backup)
    except Exception:
        if temporary.exists():
            shutil.rmtree(temporary)
        raise


def write_dataset(config: GenerationConfig, output_dir: Path) -> WrittenDataset:
    """Generate, validate, and atomically publish all five Dataset files."""
    records = generate_dataset(config)
    artifacts, manifest = _build_artifacts(records, config)
    _publish_atomically(output_dir, artifacts)
    return WrittenDataset(
        output_dir=output_dir,
        manifest=manifest,
        file_hashes={name: _sha256(content) for name, content in sorted(artifacts.items())},
    )


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Generate the deterministic synthetic Job Recommendation Dataset.",
    )
    parser.add_argument("--output-dir", type=Path, default=Path("data/synthetic/v1"))
    parser.add_argument("--seed", type=int, default=DEFAULT_RANDOM_SEED)
    parser.add_argument("--candidate-count", type=int, default=DEFAULT_CANDIDATE_COUNT)
    parser.add_argument("--job-count", type=int, default=DEFAULT_JOB_COUNT)
    parser.add_argument(
        "--pairs-per-candidate",
        type=int,
        default=DEFAULT_PAIRS_PER_CANDIDATE,
    )
    parser.add_argument("--source-revision", default=SOURCE_REVISION)
    parser.add_argument("--architecture-sha256", default=ARCHITECTURE_SHA256)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    """CLI entry point returning a nonzero status on validation failure."""
    args = _parser().parse_args(argv)
    try:
        config = GenerationConfig(
            seed=args.seed,
            candidate_count=args.candidate_count,
            job_count=args.job_count,
            pairs_per_candidate=args.pairs_per_candidate,
            source_revision=args.source_revision,
            architecture_sha256=args.architecture_sha256,
        )
        written = write_dataset(config, args.output_dir)
    except (OSError, ValidationError, ValueError) as error:
        print(f"Dataset generation failed: {error}", file=sys.stderr)
        return 2
    print(
        "Generated "
        f"{written.manifest.candidate_count} Candidates, "
        f"{written.manifest.job_count} Jobs, and "
        f"{written.manifest.pair_count} pairs at {written.output_dir}",
    )
    return 0
