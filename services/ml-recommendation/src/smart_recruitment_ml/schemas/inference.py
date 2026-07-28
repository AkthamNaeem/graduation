"""Strict request and response contracts for ranking inference."""

from __future__ import annotations

import math
import re
import uuid  # noqa: TC003 - Pydantic resolves these annotations at runtime.
from typing import Annotated, Any, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    StringConstraints,
    field_validator,
    model_validator,
)
from pydantic_core import PydanticCustomError

ShortText = Annotated[str, StringConstraints(min_length=1, max_length=128)]
TitleText = Annotated[str, StringConstraints(min_length=1, max_length=256)]
LongText = Annotated[str, StringConstraints(min_length=1, max_length=4000)]
ResponsibilityText = Annotated[str, StringConstraints(min_length=1, max_length=500)]
SkillName = Annotated[str, StringConstraints(min_length=1, max_length=128)]

SENSITIVE_KEYS = frozenset(
    {
        "name",
        "full_name",
        "email",
        "phone",
        "birth_date",
        "date_of_birth",
        "age",
        "gender",
        "sex",
        "nationality",
        "marital_status",
        "personal_address",
        "address",
        "cv_file",
        "cv_path",
        "raw_cv",
        "raw_cv_text",
        "parsed_cv_json",
        "cover_letter",
        "screening_answers",
        "application_status",
        "application_history",
        "test_results",
        "interview_results",
        "internal_notes",
        "auth_token",
        "sanctum_token",
        "cookie",
        "cookies",
        "session",
        "password",
        "secret",
        "db_password",
        "database_url",
    },
)
_PROFILE_EMAIL = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
_PROFILE_PHONE = re.compile(r"^\+?[\d\s().-]{7,}$")


class StrictRequestModel(BaseModel):
    """Forbid undeclared input and implicit scalar coercion."""

    model_config = ConfigDict(extra="forbid", strict=True)


def _normalized_key(value: object) -> str:
    return str(value).strip().casefold().replace("-", "_").replace(" ", "_")


def _scan_sensitive(value: Any, path: tuple[str, ...] = ()) -> None:
    if isinstance(value, dict):
        for raw_key, nested in value.items():
            key = _normalized_key(raw_key)
            skill_name = (
                key == "name"
                and path
                and path[-1]
                in {
                    "skills_item",
                    "required_skills_item",
                }
            )
            if key in SENSITIVE_KEYS and not skill_name:
                raise PydanticCustomError(
                    "sensitive_field_not_allowed",
                    "Sensitive field is not allowed.",
                )
            child = (
                f"{key}_item"
                if key in {"skills", "required_skills"} and isinstance(nested, list)
                else key
            )
            _scan_sensitive(nested, (*path, child))
    elif isinstance(value, list):
        for nested in value:
            _scan_sensitive(nested, path)


def _finite(value: float | None) -> float | None:
    if value is not None and not math.isfinite(value):
        raise ValueError("Numeric professional facts must be finite.")
    return value


class CandidateSkillFact(StrictRequestModel):
    """One professional Candidate skill."""

    name: SkillName
    proficiency: float | None = Field(default=None, ge=0, le=5)
    years_experience: float | None = Field(default=None, ge=0, le=100)

    _finite_proficiency = field_validator("proficiency")(_finite)
    _finite_years = field_validator("years_experience")(_finite)


class RequiredSkillFact(StrictRequestModel):
    """One weighted Job requirement."""

    name: SkillName
    weight: float | None = Field(default=None, ge=0, le=5)

    _finite_weight = field_validator("weight")(_finite)


class CandidateProfessionalFacts(StrictRequestModel):
    """Professional facts consumed by the frozen Feature Pipeline."""

    primary_domain: ShortText | None = None
    adjacent_domains: list[ShortText] = Field(default_factory=list, max_length=20)
    headline: TitleText | None = None
    career_level: ShortText | None = None
    total_experience_years: float | None = Field(default=None, ge=0, le=100)
    education_level: ShortText | None = None
    skills: list[CandidateSkillFact] = Field(default_factory=list, max_length=100)
    preferred_work_modes: list[ShortText] = Field(default_factory=list, max_length=10)
    preferred_employment_types: list[ShortText] = Field(default_factory=list, max_length=10)

    _finite_experience = field_validator("total_experience_years")(_finite)


class JobProfessionalFacts(StrictRequestModel):
    """Professional Job facts consumed by the frozen Feature Pipeline."""

    domain: ShortText | None = None
    title: TitleText | None = None
    department: ShortText | None = None
    description: LongText | None = None
    responsibilities: list[ResponsibilityText] = Field(default_factory=list, max_length=50)
    required_skills: list[RequiredSkillFact] = Field(default_factory=list, max_length=100)
    nice_to_have_skills: list[SkillName] = Field(default_factory=list, max_length=100)
    minimum_experience_years: float | None = Field(default=None, ge=0, le=100)
    education_level: ShortText | None = None
    career_level: ShortText | None = None
    work_mode: ShortText | None = None
    employment_type: ShortText | None = None

    _finite_experience = field_validator("minimum_experience_years")(_finite)


class CandidateInput(StrictRequestModel):
    """Candidate wrapper that keeps identity outside model features."""

    profile_ref: str | None = Field(default=None, min_length=1, max_length=128)
    professional_facts: CandidateProfessionalFacts

    @field_validator("profile_ref")
    @classmethod
    def validate_opaque_profile_ref(cls, value: str | None) -> str | None:
        if value is not None and (
            _PROFILE_EMAIL.fullmatch(value) or _PROFILE_PHONE.fullmatch(value)
        ):
            raise PydanticCustomError(
                "sensitive_field_not_allowed",
                "Sensitive field is not allowed.",
            )
        return value


class JobInput(StrictRequestModel):
    """One eligible Job supplied by Laravel."""

    job_id: int = Field(gt=0)
    professional_facts: JobProfessionalFacts


class RankRequest(StrictRequestModel):
    """Complete internal ranking request."""

    request_id: uuid.UUID = Field(strict=False)
    feature_schema_version: str
    candidate: CandidateInput
    jobs: list[JobInput] = Field(min_length=1, max_length=500)
    limit: int = Field(ge=1, le=100)

    @model_validator(mode="before")
    @classmethod
    def reject_sensitive_fields(cls, value: Any) -> Any:
        _scan_sensitive(value)
        return value

    @model_validator(mode="after")
    def validate_request_contract(self) -> RankRequest:
        if self.feature_schema_version != "job-rec-features-v1":
            raise PydanticCustomError(
                "feature_schema_version_unsupported",
                "Feature Schema version is unsupported.",
            )
        job_ids = [job.job_id for job in self.jobs]
        if len(set(job_ids)) != len(job_ids):
            raise PydanticCustomError("duplicate_job_id", "Job IDs must be unique.")
        if self.limit > len(self.jobs):
            raise ValueError("Limit cannot exceed the Job count.")
        return self


class ExplanationFactor(BaseModel):
    """Safe feature-group attribution returned to consumers."""

    model_config = ConfigDict(extra="forbid")

    code: str
    feature_group: str
    direction: Literal["increases_model_score", "decreases_model_score"]
    contribution: float
    strength: float = Field(ge=0, le=1)


class Prediction(BaseModel):
    """One ranked Job prediction."""

    model_config = ConfigDict(extra="forbid")

    job_id: int
    rank: int = Field(ge=1)
    raw_score: float
    display_score: float = Field(ge=0, le=100)
    top_positive_factors: list[ExplanationFactor] = Field(max_length=3)
    top_negative_factors: list[ExplanationFactor] = Field(max_length=3)


class RankResponse(BaseModel):
    """Complete response; predictions are never truncated by requested limit."""

    model_config = ConfigDict(extra="forbid")

    request_id: uuid.UUID
    api_contract_version: str
    bundle_version: str
    model_version: str
    dataset_version: str
    feature_schema_version: str
    model_source_revision: str
    score_transform_version: str
    explanation_contract_version: str
    requested_limit: int
    prediction_count: int
    predictions: list[Prediction]
    explanation_note: Literal["Model attribution only; not a probability or hiring decision."]
    latency_ms: float = Field(ge=0)
