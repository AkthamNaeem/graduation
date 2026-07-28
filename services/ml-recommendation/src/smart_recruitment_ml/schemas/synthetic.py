"""Typed contracts for synthetic Job Recommendation data."""

from typing import Literal

from pydantic import BaseModel, Field, field_validator

CareerLevel = Literal["entry", "junior", "mid", "senior", "lead"]
EducationLevel = Literal["high_school", "diploma", "bachelor", "master", "doctorate"]
WorkMode = Literal["remote", "hybrid", "onsite"]
EmploymentType = Literal["full_time", "part_time", "contract"]
Scenario = Literal[
    "strong_match",
    "good_match",
    "adjacent_domain",
    "borderline",
    "hard_negative",
    "clear_mismatch",
    "noise_injected",
]


def _validate_normalized_skill(value: str) -> str:
    normalized = " ".join(value.strip().lower().split())
    if value != normalized or not normalized:
        msg = "Skill names must be non-empty, lowercase, and whitespace-normalized."
        raise ValueError(msg)
    return value


class CandidateSkill(BaseModel):
    """Raw professional skill fact for a synthetic Candidate."""

    name: str
    proficiency: int = Field(ge=1, le=5)
    years_experience: float = Field(ge=0)

    _normalized_name = field_validator("name")(_validate_normalized_skill)


class Candidate(BaseModel):
    """Synthetic professional Candidate without identity or demographic data."""

    candidate_id: str = Field(pattern=r"^cand_\d{4}$")
    primary_domain: str
    adjacent_domains: list[str]
    headline: str
    career_level: CareerLevel
    total_experience_years: float = Field(ge=0)
    education_level: EducationLevel
    skills: list[CandidateSkill] = Field(min_length=1)
    preferred_work_modes: list[WorkMode] = Field(min_length=1)
    preferred_employment_types: list[EmploymentType] = Field(min_length=1)


class RequiredSkill(BaseModel):
    """Weighted professional requirement attached to a synthetic Job."""

    name: str
    weight: int = Field(ge=1, le=5)

    _normalized_name = field_validator("name")(_validate_normalized_skill)


class Job(BaseModel):
    """Synthetic Job already assumed eligible through Laravel filtering."""

    job_id: str = Field(pattern=r"^job_\d{4}$")
    domain: str
    title: str
    department: str
    description: str
    responsibilities: list[str] = Field(min_length=1)
    required_skills: list[RequiredSkill] = Field(min_length=1)
    nice_to_have_skills: list[str]
    minimum_experience_years: float = Field(ge=0)
    education_level: EducationLevel
    career_level: CareerLevel
    work_mode: WorkMode
    employment_type: EmploymentType

    _normalized_nice_skills = field_validator("nice_to_have_skills")(
        lambda values: [_validate_normalized_skill(value) for value in values],
    )


class RelevancePair(BaseModel):
    """Synthetic relevance judgment for one Candidate-Job pair."""

    pair_id: str = Field(pattern=r"^pair_cand_\d{4}_job_\d{4}$")
    candidate_id: str = Field(pattern=r"^cand_\d{4}$")
    job_id: str = Field(pattern=r"^job_\d{4}$")
    relevance_label: int = Field(ge=0, le=3)
    scenario: Scenario
    rationale_codes: list[str] = Field(min_length=1)
    noise_applied: bool


class FileArtifact(BaseModel):
    """Integrity metadata for one generated JSONL artifact."""

    path: str
    record_count: int = Field(ge=0)
    size_bytes: int = Field(ge=0)
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class DatasetManifest(BaseModel):
    """Versioned manifest for the deterministic synthetic Dataset."""

    dataset_version: str
    dataset_schema_version: str
    generator_version: str
    random_seed: int
    source_revision: str
    architecture_sha256: str
    dataset_release_date: str
    synthetic: Literal[True]
    deterministic: Literal[True]
    eligibility_scope: Literal["laravel_pre_filtered_eligible_jobs"]
    candidate_count: int
    job_count: int
    pair_count: int
    pairs_per_candidate: int
    domain_count: int
    domains: list[str]
    label_distribution: dict[str, int]
    scenario_distribution: dict[str, int]
    noise_count: int
    noise_rate: float
    generation_config: dict[str, int | str]
    excluded_sensitive_fields: list[str]
    intended_use: list[str]
    limitations: list[str]
    files: list[FileArtifact]
