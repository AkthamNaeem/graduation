"""Shared deterministic Feature Pipeline v1 and Dataset build CLI."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import shutil
import sys
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Final, TypeVar

from pydantic import BaseModel, ValidationError

from smart_recruitment_ml.data.catalog import DOMAINS
from smart_recruitment_ml.features.normalization import (
    merge_candidate_skills,
    normalize_categories,
    normalize_job_skills,
    normalize_text,
    tokenize,
)
from smart_recruitment_ml.schemas.features import (
    ArtifactFile,
    CandidateFeatureInput,
    FeatureDatasetManifest,
    FeatureDatasetRecord,
    FeatureDefinition,
    FeatureSchemaArtifact,
    FeatureVector,
    JobFeatureInput,
)
from smart_recruitment_ml.schemas.synthetic import (
    Candidate,
    DatasetManifest,
    Job,
    RelevancePair,
)

if TYPE_CHECKING:
    from collections.abc import Iterable, Mapping, Sequence

FEATURE_SCHEMA_VERSION: Final = "job-rec-features-v1"
FEATURE_PIPELINE_VERSION: Final = "0.1.0"
SOURCE_DATASET_VERSION: Final = "synthetic-job-rec-1.0.0"
SOURCE_DATASET_SCHEMA_VERSION: Final = "synthetic-job-rec-schema-v1"
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
FEATURE_RELEASE_DATE: Final = "2026-07-24"
CRITICAL_SKILL_WEIGHT_THRESHOLD: Final = 4.0
EXPERIENCE_CAP_YEARS: Final = 30.0
SKILL_COUNT_CAP: Final = 20.0
TRANSFERABLE_OVERLAP_CAP: Final = 10.0
TEXT_TOKEN_LIMITS: Final = {
    "candidate_headline": 32,
    "job_title": 32,
    "job_description": 256,
    "job_responsibilities": 256,
}
UNKNOWN: Final = "__unknown__"
EXPECTED_SOURCE_HASHES: Final = {
    "candidates.jsonl": "5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320",
    "jobs.jsonl": "7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d",
    "pairs.jsonl": "31a2e7c6f26e0c9840674cd7caff465be70ec4f753c13333f61ae3593998ecb1",
    "manifest.json": "05916dbbc0eb066c146386f16cf677c714ea0f08dc0eee59c508c274c1ce6dc1",
}
EXCLUDED_INPUT_FIELDS: Final = (
    "candidate_id",
    "job_id",
    "pair_id",
    "relevance_label",
    "scenario",
    "rationale_codes",
    "noise_applied",
    "hidden_affinity",
    "latent_score",
    "name",
    "email",
    "phone",
    "birth_date",
    "age",
    "gender",
    "nationality",
    "marital_status",
    "address",
    "cv_file",
    "raw_cv",
    "application_history",
    "application_status",
    "cover_letter",
    "screening_answers",
    "tests",
    "interviews",
    "internal_notes",
    "company_identity",
    "authentication_tokens",
    "application_outcome",
)

DOMAIN_VOCABULARY: Final = (
    UNKNOWN,
    *(normalize_text(domain.name) for domain in sorted(DOMAINS, key=lambda item: item.name)),
)
CAREER_LEVEL_VOCABULARY: Final = (UNKNOWN, "entry", "junior", "mid", "senior", "lead")
EDUCATION_LEVEL_VOCABULARY: Final = (
    UNKNOWN,
    "high_school",
    "diploma",
    "bachelor",
    "master",
    "doctorate",
)
WORK_MODE_VOCABULARY: Final = (UNKNOWN, "remote", "hybrid", "onsite")
EMPLOYMENT_TYPE_VOCABULARY: Final = (UNKNOWN, "full_time", "part_time", "contract")
VOCABULARIES: Final = {
    "domains": DOMAIN_VOCABULARY,
    "career_levels": CAREER_LEVEL_VOCABULARY,
    "education_levels": EDUCATION_LEVEL_VOCABULARY,
    "work_modes": WORK_MODE_VOCABULARY,
    "employment_types": EMPLOYMENT_TYPE_VOCABULARY,
}
_DOMAIN_BY_NORMALIZED_NAME: Final = {normalize_text(domain.name): domain for domain in DOMAINS}
_CAREER_ORDER: Final = {value: index for index, value in enumerate(CAREER_LEVEL_VOCABULARY[1:])}
_EDUCATION_ORDER: Final = {
    value: index for index, value in enumerate(EDUCATION_LEVEL_VOCABULARY[1:])
}


def _slug(value: str) -> str:
    return "_".join(
        value.replace("__", "")
        .replace("/", " ")
        .replace("-", " ")
        .replace("(", " ")
        .replace(")", " ")
        .split(),
    )


def _one_hot_names(prefix: str, vocabulary: Sequence[str]) -> tuple[str, ...]:
    return tuple(f"{prefix}__{_slug(value)}" for value in vocabulary)


FEATURE_FAMILIES: Final = {
    "domain_compatibility": (
        "domain_exact_match",
        "domain_adjacent_match",
        "domain_mismatch",
        *_one_hot_names("candidate_domain", DOMAIN_VOCABULARY),
        *_one_hot_names("job_domain", DOMAIN_VOCABULARY),
    ),
    "required_skills": (
        "required_skill_overlap_ratio",
        "weighted_required_skill_coverage",
        "critical_required_skill_coverage",
        "missing_required_skill_ratio",
        "matched_required_skill_count_normalized",
        "matched_required_skill_mean_proficiency",
        "matched_required_skill_mean_years",
    ),
    "nice_transferable_skills": (
        "nice_to_have_skill_coverage",
        "transferable_skill_coverage",
        "transferable_skill_overlap_normalized",
        "candidate_skill_count_normalized",
        "candidate_to_required_skill_count_ratio",
    ),
    "experience": (
        "candidate_experience_normalized",
        "job_minimum_experience_normalized",
        "experience_gap_signed",
        "experience_shortfall",
        "experience_surplus",
        "experience_requirement_met",
    ),
    "career_level": (
        "career_level_exact_match",
        "career_level_distance",
        "seniority_requirement_met",
        "career_overqualification",
        *_one_hot_names("candidate_career_level", CAREER_LEVEL_VOCABULARY),
        *_one_hot_names("job_career_level", CAREER_LEVEL_VOCABULARY),
    ),
    "education": (
        "education_requirement_met",
        "education_level_distance",
        *_one_hot_names("candidate_education_level", EDUCATION_LEVEL_VOCABULARY),
        *_one_hot_names("job_education_level", EDUCATION_LEVEL_VOCABULARY),
    ),
    "preferences": (
        "work_mode_match",
        "employment_type_match",
        *_one_hot_names("job_work_mode", WORK_MODE_VOCABULARY),
        *_one_hot_names("job_employment_type", EMPLOYMENT_TYPE_VOCABULARY),
    ),
    "text_alignment": (
        "headline_title_token_jaccard",
        "candidate_skills_in_job_description_ratio",
        "candidate_skills_in_job_responsibilities_ratio",
        "required_skills_in_candidate_headline_ratio",
    ),
    "interactions": (
        "domain_skill_coverage_interaction",
        "critical_skill_experience_interaction",
        "skill_career_alignment_interaction",
        "skill_work_mode_interaction",
    ),
    "missing_indicators": (
        "candidate_skills_missing",
        "candidate_experience_missing",
        "candidate_education_missing",
        "candidate_career_level_missing",
        "job_required_skills_missing",
        "job_minimum_experience_missing",
        "job_education_missing",
        "job_career_level_missing",
    ),
}
FEATURE_NAMES: Final = tuple(
    name for family_names in FEATURE_FAMILIES.values() for name in family_names
)
_SIGNED_FEATURES: Final = {
    "experience_gap_signed",
    "career_level_distance",
    "education_level_distance",
}


@dataclass(frozen=True, slots=True)
class _LoadedSource:
    candidates: tuple[Candidate, ...]
    jobs: tuple[Job, ...]
    pairs: tuple[RelevancePair, ...]
    manifest: DatasetManifest
    source_files: tuple[ArtifactFile, ...]


@dataclass(frozen=True, slots=True)
class WrittenFeatureDataset:
    """Summary returned after publishing a complete feature Dataset."""

    output_dir: Path
    manifest: FeatureDatasetManifest
    file_hashes: Mapping[str, str]


def _clip(value: float, lower: float = 0.0, upper: float = 1.0) -> float:
    return min(upper, max(lower, value))


def _category(value: str | None, vocabulary: Sequence[str]) -> str:
    normalized = normalize_text(value)
    return normalized if normalized in vocabulary else UNKNOWN


def _one_hot(value: str, vocabulary: Sequence[str]) -> tuple[float, ...]:
    return tuple(float(value == item) for item in vocabulary)


def _ordered_distance(
    candidate_value: str,
    job_value: str,
    ordering: Mapping[str, int],
) -> tuple[float, float, float, float]:
    if candidate_value not in ordering or job_value not in ordering:
        return (0.0, 0.0, 0.0, 0.0)
    candidate_index = ordering[candidate_value]
    job_index = ordering[job_value]
    maximum = max(1, len(ordering) - 1)
    distance = (candidate_index - job_index) / maximum
    return (
        float(candidate_index == job_index),
        distance,
        float(candidate_index >= job_index),
        float(candidate_index > job_index),
    )


def _contains_phrase(text_tokens: Sequence[str], phrase: str) -> bool:
    phrase_tokens = tokenize(phrase)
    width = len(phrase_tokens)
    if width == 0 or width > len(text_tokens):
        return False
    return any(
        tuple(text_tokens[index : index + width]) == phrase_tokens
        for index in range(len(text_tokens) - width + 1)
    )


def _phrase_coverage(
    phrases: Sequence[str],
    text_tokens: Sequence[str],
) -> float:
    if not phrases:
        return 0.0
    return sum(_contains_phrase(text_tokens, phrase) for phrase in phrases) / len(phrases)


class FeaturePipelineV1:
    """Stateless transformer shared unchanged by future training and inference."""

    feature_schema_version: str = FEATURE_SCHEMA_VERSION
    pipeline_version: str = FEATURE_PIPELINE_VERSION
    feature_names: tuple[str, ...] = FEATURE_NAMES

    def transform(
        self,
        candidate: CandidateFeatureInput,
        job: JobFeatureInput,
    ) -> FeatureVector:
        """Transform professional Candidate and Job facts only."""
        candidate_domain = _category(candidate.primary_domain, DOMAIN_VOCABULARY)
        job_domain = _category(job.domain, DOMAIN_VOCABULARY)
        adjacent_domains = normalize_categories(candidate.adjacent_domains)
        domain_known = candidate_domain != UNKNOWN and job_domain != UNKNOWN
        domain_exact = float(domain_known and candidate_domain == job_domain)
        domain_adjacent = float(
            domain_known and not domain_exact and job_domain in adjacent_domains,
        )
        domain_mismatch = float(domain_known and not domain_exact and not domain_adjacent)
        values: list[float] = [
            domain_exact,
            domain_adjacent,
            domain_mismatch,
            *_one_hot(candidate_domain, DOMAIN_VOCABULARY),
            *_one_hot(job_domain, DOMAIN_VOCABULARY),
        ]

        candidate_skills = merge_candidate_skills(candidate.skills)
        required_skills, nice_skills = normalize_job_skills(
            job.required_skills,
            job.nice_to_have_skills,
        )
        candidate_by_name = {skill.name: skill for skill in candidate_skills}
        matched_required = tuple(
            (requirement, candidate_by_name[requirement.name])
            for requirement in required_skills
            if requirement.name in candidate_by_name
        )
        required_count = len(required_skills)
        overlap = len(matched_required) / required_count if required_count else 0.0
        total_weight = sum(skill.weight for skill in required_skills)
        matched_weight = sum(requirement.weight for requirement, _ in matched_required)
        weighted_coverage = matched_weight / total_weight if total_weight else 0.0
        critical = tuple(
            skill for skill in required_skills if skill.weight >= CRITICAL_SKILL_WEIGHT_THRESHOLD
        )
        matched_names = {requirement.name for requirement, _ in matched_required}
        critical_coverage = (
            sum(skill.name in matched_names for skill in critical) / len(critical)
            if critical
            else float(bool(required_skills))
        )
        mean_proficiency = (
            sum(skill.proficiency for _, skill in matched_required) / len(matched_required) / 5.0
            if matched_required
            else 0.0
        )
        mean_years = (
            sum(skill.years_experience for _, skill in matched_required)
            / len(matched_required)
            / EXPERIENCE_CAP_YEARS
            if matched_required
            else 0.0
        )
        values.extend(
            (
                overlap,
                weighted_coverage,
                critical_coverage,
                1.0 - overlap if required_skills else 0.0,
                _clip(len(matched_required) / SKILL_COUNT_CAP),
                _clip(mean_proficiency),
                _clip(mean_years),
            ),
        )

        candidate_skill_names = tuple(skill.name for skill in candidate_skills)
        nice_coverage = (
            sum(name in candidate_by_name for name in nice_skills) / len(nice_skills)
            if nice_skills
            else 0.0
        )
        catalog = _DOMAIN_BY_NORMALIZED_NAME.get(job_domain)
        transferable_names = (
            tuple(normalize_text(skill) for skill in catalog.transferable_skills) if catalog else ()
        )
        transferable_overlap = sum(name in candidate_by_name for name in transferable_names)
        transferable_coverage = (
            transferable_overlap / len(transferable_names) if transferable_names else 0.0
        )
        candidate_required_ratio = (
            _clip((len(candidate_skills) / required_count) / 2.0) if required_count else 0.0
        )
        values.extend(
            (
                nice_coverage,
                transferable_coverage,
                _clip(transferable_overlap / TRANSFERABLE_OVERLAP_CAP),
                _clip(len(candidate_skills) / SKILL_COUNT_CAP),
                candidate_required_ratio,
            ),
        )

        candidate_experience = float(candidate.total_experience_years or 0.0)
        job_experience = float(job.minimum_experience_years or 0.0)
        experience_known = (
            candidate.total_experience_years is not None
            and job.minimum_experience_years is not None
        )
        experience_gap = _clip(
            (candidate_experience - job_experience) / EXPERIENCE_CAP_YEARS,
            -1.0,
            1.0,
        )
        experience_met = float(experience_known and candidate_experience >= job_experience)
        values.extend(
            (
                _clip(candidate_experience / EXPERIENCE_CAP_YEARS),
                _clip(job_experience / EXPERIENCE_CAP_YEARS),
                experience_gap,
                _clip(max(job_experience - candidate_experience, 0.0) / EXPERIENCE_CAP_YEARS),
                _clip(max(candidate_experience - job_experience, 0.0) / EXPERIENCE_CAP_YEARS),
                experience_met,
            ),
        )

        candidate_career = _category(candidate.career_level, CAREER_LEVEL_VOCABULARY)
        job_career = _category(job.career_level, CAREER_LEVEL_VOCABULARY)
        career_exact, career_distance, seniority_met, overqualified = _ordered_distance(
            candidate_career,
            job_career,
            _CAREER_ORDER,
        )
        values.extend(
            (
                career_exact,
                career_distance,
                seniority_met,
                overqualified,
                *_one_hot(candidate_career, CAREER_LEVEL_VOCABULARY),
                *_one_hot(job_career, CAREER_LEVEL_VOCABULARY),
            ),
        )

        candidate_education = _category(
            candidate.education_level,
            EDUCATION_LEVEL_VOCABULARY,
        )
        job_education = _category(job.education_level, EDUCATION_LEVEL_VOCABULARY)
        (
            _education_exact,
            education_distance,
            education_met,
            _education_overqualified,
        ) = _ordered_distance(candidate_education, job_education, _EDUCATION_ORDER)
        values.extend(
            (
                education_met,
                education_distance,
                *_one_hot(candidate_education, EDUCATION_LEVEL_VOCABULARY),
                *_one_hot(job_education, EDUCATION_LEVEL_VOCABULARY),
            ),
        )

        candidate_work_modes = normalize_categories(candidate.preferred_work_modes)
        candidate_employment_types = normalize_categories(
            candidate.preferred_employment_types,
        )
        job_work_mode = _category(job.work_mode, WORK_MODE_VOCABULARY)
        job_employment_type = _category(job.employment_type, EMPLOYMENT_TYPE_VOCABULARY)
        work_mode_match = float(
            job_work_mode != UNKNOWN and job_work_mode in candidate_work_modes,
        )
        employment_type_match = float(
            job_employment_type != UNKNOWN and job_employment_type in candidate_employment_types,
        )
        values.extend(
            (
                work_mode_match,
                employment_type_match,
                *_one_hot(job_work_mode, WORK_MODE_VOCABULARY),
                *_one_hot(job_employment_type, EMPLOYMENT_TYPE_VOCABULARY),
            ),
        )

        headline_tokens = tokenize(
            candidate.headline,
            limit=TEXT_TOKEN_LIMITS["candidate_headline"],
        )
        title_tokens = tokenize(job.title, limit=TEXT_TOKEN_LIMITS["job_title"])
        headline_set = set(headline_tokens)
        title_set = set(title_tokens)
        token_union = headline_set | title_set
        headline_title_jaccard = (
            len(headline_set & title_set) / len(token_union) if token_union else 0.0
        )
        description_tokens = tokenize(
            job.description,
            limit=TEXT_TOKEN_LIMITS["job_description"],
        )
        responsibility_tokens = tokenize(
            " ".join(job.responsibilities),
            limit=TEXT_TOKEN_LIMITS["job_responsibilities"],
        )
        candidate_in_description = _phrase_coverage(
            candidate_skill_names,
            description_tokens,
        )
        candidate_in_responsibilities = _phrase_coverage(
            candidate_skill_names,
            responsibility_tokens,
        )
        required_in_headline = _phrase_coverage(
            tuple(skill.name for skill in required_skills),
            headline_tokens,
        )
        values.extend(
            (
                headline_title_jaccard,
                candidate_in_description,
                candidate_in_responsibilities,
                required_in_headline,
            ),
        )

        domain_compatibility = max(domain_exact, domain_adjacent)
        career_alignment = (
            1.0 - abs(career_distance)
            if candidate_career != UNKNOWN and job_career != UNKNOWN
            else 0.0
        )
        values.extend(
            (
                domain_compatibility * weighted_coverage,
                critical_coverage * experience_met,
                weighted_coverage * career_alignment,
                weighted_coverage * work_mode_match,
            ),
        )
        values.extend(
            (
                float(not candidate.skills),
                float(candidate.total_experience_years is None),
                float(not normalize_text(candidate.education_level)),
                float(not normalize_text(candidate.career_level)),
                float(not job.required_skills),
                float(job.minimum_experience_years is None),
                float(not normalize_text(job.education_level)),
                float(not normalize_text(job.career_level)),
            ),
        )
        bounded = tuple(
            _clip(float(value), -1.0, 1.0) if name in _SIGNED_FEATURES else _clip(float(value))
            for name, value in zip(self.feature_names, values, strict=True)
        )
        if len(bounded) != len(self.feature_names) or not all(map(math.isfinite, bounded)):
            raise ValueError("Feature Pipeline produced an invalid vector")
        return FeatureVector(
            feature_schema_version=self.feature_schema_version,
            feature_names=self.feature_names,
            feature_values=bounded,
        )

    def transform_many(
        self,
        inputs: Iterable[tuple[CandidateFeatureInput, JobFeatureInput]],
    ) -> tuple[FeatureVector, ...]:
        """Apply exactly the same individual transform to many input pairs."""
        return tuple(self.transform(candidate, job) for candidate, job in inputs)


def _feature_family(name: str) -> str:
    for family, family_names in FEATURE_FAMILIES.items():
        if name in family_names:
            return family
    raise ValueError(f"Feature has no family: {name}")


def _feature_definitions() -> list[FeatureDefinition]:
    definitions: list[FeatureDefinition] = []
    for name in FEATURE_NAMES:
        bounds = (-1.0, 1.0) if name in _SIGNED_FEATURES else (0.0, 1.0)
        is_indicator = (
            name.endswith(("_match", "_met", "_missing", "_mismatch", "_overqualification"))
            or "__" in name
        )
        definitions.append(
            FeatureDefinition(
                name=name,
                family=_feature_family(name),
                description=name.replace("_", " "),
                type="indicator" if is_indicator else "float",
                bounds=bounds,
                missing_semantics=(
                    "unknown bucket"
                    if "__unknown" in name
                    else "neutral zero; paired missing indicator where defined"
                ),
            ),
        )
    return definitions


def feature_schema_artifact() -> FeatureSchemaArtifact:
    """Build the canonical Feature Schema v1 artifact."""
    definitions = _feature_definitions()
    return FeatureSchemaArtifact(
        feature_schema_version=FEATURE_SCHEMA_VERSION,
        feature_pipeline_version=FEATURE_PIPELINE_VERSION,
        source_dataset_version=SOURCE_DATASET_VERSION,
        source_dataset_schema_version=SOURCE_DATASET_SCHEMA_VERSION,
        source_revision=SOURCE_REVISION,
        architecture_sha256=ARCHITECTURE_SHA256,
        feature_release_date=FEATURE_RELEASE_DATE,
        deterministic=True,
        feature_count=len(FEATURE_NAMES),
        feature_names=list(FEATURE_NAMES),
        feature_definitions=definitions,
        feature_bounds={definition.name: definition.bounds for definition in definitions},
        vocabularies={key: list(values) for key, values in VOCABULARIES.items()},
        normalization_policy={
            "unicode": "NFKC",
            "case": "casefold",
            "whitespace": "collapse and trim",
            "hyphens": "normalize Unicode hyphens to ASCII hyphen",
            "tokenization": "Unicode-aware word tokens; no stemming or external NLP",
            "sorting": "lexicographic after normalization",
        },
        missing_value_policy={
            "string": "normalized empty string",
            "list": "empty list",
            "numeric": "0.0 plus an indicator where defined",
            "categorical": UNKNOWN,
            "dataset_imputation": "none",
        },
        unknown_category_policy={
            "bucket": UNKNOWN,
            "feature_dimensionality": "unchanged",
        },
        skill_merge_policy={
            "candidate_duplicates": "maximum proficiency and maximum years independently",
            "required_duplicates": "maximum weight",
            "nice_duplicates": "deduplicate",
            "precedence": "required over nice-to-have",
        },
        text_token_limits=TEXT_TOKEN_LIMITS,
        critical_skill_weight_threshold=CRITICAL_SKILL_WEIGHT_THRESHOLD,
        experience_cap_years=EXPERIENCE_CAP_YEARS,
        excluded_input_fields=list(EXCLUDED_INPUT_FIELDS),
        label_separation_policy=(
            "transform accepts CandidateFeatureInput and JobFeatureInput only; "
            "the target and identifiers are attached by the Dataset exporter"
        ),
    )


TModel = TypeVar("TModel", bound=BaseModel)


def _read_jsonl(path: Path, model_type: type[TModel]) -> tuple[TModel, ...]:
    records: list[TModel] = []
    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, 1):
            if not line.strip():
                raise ValueError(f"{path.name}:{line_number} is blank")
            try:
                records.append(model_type.model_validate_json(line))
            except ValidationError as error:
                raise ValueError(f"{path.name}:{line_number} has an invalid schema") from error
    return tuple(records)


def _sha256(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def _file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _artifact_file(path: str, content: bytes, record_count: int) -> ArtifactFile:
    return ArtifactFile(
        path=path,
        record_count=record_count,
        bytes=len(content),
        sha256=_sha256(content),
    )


def _load_source(input_dir: Path) -> _LoadedSource:
    required_paths = {
        name: input_dir / name
        for name in ("candidates.jsonl", "jobs.jsonl", "pairs.jsonl", "manifest.json")
    }
    missing = [name for name, path in required_paths.items() if not path.is_file()]
    if missing:
        raise ValueError(f"Missing source files: {', '.join(sorted(missing))}")
    actual_hashes = {name: _file_sha256(path) for name, path in required_paths.items()}
    for name, expected_hash in EXPECTED_SOURCE_HASHES.items():
        if actual_hashes[name] != expected_hash:
            raise ValueError(f"Source hash mismatch: {name}")

    try:
        source_manifest = DatasetManifest.model_validate_json(
            required_paths["manifest.json"].read_text(encoding="utf-8"),
        )
    except ValidationError as error:
        raise ValueError("Source manifest has an invalid schema") from error
    if source_manifest.dataset_version != SOURCE_DATASET_VERSION:
        raise ValueError("Source Dataset version mismatch")
    if source_manifest.dataset_schema_version != SOURCE_DATASET_SCHEMA_VERSION:
        raise ValueError("Source Dataset schema version mismatch")
    if source_manifest.source_revision != SOURCE_REVISION:
        raise ValueError("Source revision mismatch")
    if source_manifest.architecture_sha256 != ARCHITECTURE_SHA256:
        raise ValueError("Source Architecture hash mismatch")
    manifest_files = {artifact.path: artifact for artifact in source_manifest.files}
    for name in ("candidates.jsonl", "jobs.jsonl", "pairs.jsonl"):
        if name not in manifest_files or manifest_files[name].sha256 != actual_hashes[name]:
            raise ValueError(f"Source manifest file hash mismatch: {name}")

    candidates = _read_jsonl(required_paths["candidates.jsonl"], Candidate)
    jobs = _read_jsonl(required_paths["jobs.jsonl"], Job)
    pairs = _read_jsonl(required_paths["pairs.jsonl"], RelevancePair)
    if len(candidates) != source_manifest.candidate_count:
        raise ValueError("Source Candidate count mismatch")
    if len(jobs) != source_manifest.job_count:
        raise ValueError("Source Job count mismatch")
    if len(pairs) != source_manifest.pair_count:
        raise ValueError("Source pair count mismatch")
    if len({record.candidate_id for record in candidates}) != len(candidates):
        raise ValueError("Duplicate Candidate IDs")
    if len({record.job_id for record in jobs}) != len(jobs):
        raise ValueError("Duplicate Job IDs")
    if len({record.pair_id for record in pairs}) != len(pairs):
        raise ValueError("Duplicate Pair IDs")
    pair_keys = {(record.candidate_id, record.job_id) for record in pairs}
    if len(pair_keys) != len(pairs):
        raise ValueError("Duplicate Candidate-Job pairs")
    source_counts = {
        "candidates.jsonl": len(candidates),
        "jobs.jsonl": len(jobs),
        "pairs.jsonl": len(pairs),
        "manifest.json": 1,
    }
    source_files = tuple(
        ArtifactFile(
            path=name,
            record_count=source_counts[name],
            bytes=required_paths[name].stat().st_size,
            sha256=actual_hashes[name],
        )
        for name in sorted(required_paths)
    )
    return _LoadedSource(candidates, jobs, pairs, source_manifest, source_files)


def _candidate_input(candidate: Candidate) -> CandidateFeatureInput:
    return CandidateFeatureInput.model_validate(
        candidate.model_dump(exclude={"candidate_id"}),
    )


def _job_input(job: Job) -> JobFeatureInput:
    return JobFeatureInput.model_validate(job.model_dump(exclude={"job_id"}))


def _json_bytes(model: BaseModel) -> bytes:
    return (
        json.dumps(
            model.model_dump(mode="json"),
            ensure_ascii=False,
            indent=2,
            sort_keys=True,
        )
        + "\n"
    ).encode()


def _jsonl_bytes(records: Sequence[FeatureDatasetRecord]) -> bytes:
    return (
        "\n".join(
            json.dumps(
                record.model_dump(mode="json"),
                ensure_ascii=False,
                sort_keys=True,
                separators=(",", ":"),
            )
            for record in records
        )
        + "\n"
    ).encode()


def _feature_schema_card() -> bytes:
    card = f"""# Feature Schema Card

## Identity and purpose

- Feature Schema: `{FEATURE_SCHEMA_VERSION}`
- Feature Pipeline: `{FEATURE_PIPELINE_VERSION}`
- Source Dataset: `{SOURCE_DATASET_VERSION}` / `{SOURCE_DATASET_SCHEMA_VERSION}`
- Release date: `{FEATURE_RELEASE_DATE}` (fixed release metadata)
- Feature count: `{len(FEATURE_NAMES)}`

This schema converts Candidate and Job professional facts into the same fixed,
ordered vector for later training, validation, locked testing, and FastAPI
inference. It is not a ranking Model and does not make hiring decisions.

## Inputs and normalization

`CandidateFeatureInput` accepts domain, adjacent domains, headline, career
level, experience, education, skills, and work preferences.
`JobFeatureInput` accepts domain, title, department, description,
responsibilities, skills, experience, education, career level, work mode, and
employment type. Identity, audit, outcome, company, and sensitive facts are
forbidden.

Text uses Unicode NFKC, `casefold()`, whitespace collapse, normalized hyphens,
and bounded Unicode-aware word tokens. There is no stemming, locale-dependent
processing, external NLP, TF-IDF, or embedding.

Candidate duplicate skills keep maximum proficiency and years independently.
Required duplicates keep maximum weight; nice-to-have values are de-duplicated,
and required skills take precedence. Critical required skills have weight
`>= {CRITICAL_SKILL_WEIGHT_THRESHOLD:g}`.

## Missing and unknown values

Missing strings and lists become empty values. Missing numeric values become
`0.0`, with explicit indicators for important facts. Unknown categorical
values use `{UNKNOWN}` and never change vector length. No Dataset mean, label,
split, or distribution is used for imputation.

## Vocabularies, families, bounds, and order

The fixed vocabularies are stored in `feature_schema.json` for domains, career
levels, education levels, work modes, and employment types. Families cover
domain compatibility, required and transferable skills, experience, career
level, education, preferences, deterministic text alignment, four bounded
interactions, and missing indicators.

Ratios and indicators are bounded to `[0,1]`. Signed experience and ordered
level distances are bounded to `[-1,1]`. Experience is capped at
`{EXPERIENCE_CAP_YEARS:g}` years. Token limits are headline/title `32`,
description `256`, and combined responsibilities `256`.

The exact immutable feature order and per-feature definitions are authoritative
in `feature_schema.json`.

## Label separation, leakage, and privacy

`FeaturePipelineV1.transform()` accepts only the two professional-fact input
schemas. The relevance target and three synthetic IDs are attached only by the
Dataset exporter, outside `feature_values`. Scenario, rationale, controlled
noise, generator-only factors, identity, contact, demographic, CV,
application, assessment, interview, company, internal-note, and authentication
data are excluded. Exported feature records contain no raw input text or audit
metadata.

## Reproducibility

From the repository root:

```powershell
& services/ml-recommendation/.venv/Scripts/python.exe -m smart_recruitment_ml.features `
    --input-dir services/ml-recommendation/data/synthetic/v1 `
    --output-dir services/ml-recommendation/data/features/v1 `
    --feature-schema-version {FEATURE_SCHEMA_VERSION} `
    --pipeline-version {FEATURE_PIPELINE_VERSION} `
    --source-revision {SOURCE_REVISION} `
    --architecture-sha256 {ARCHITECTURE_SHA256}
```

The four outputs are byte-for-byte deterministic for identical locked inputs.

## Intended and non-intended use

Intended use is Phase 6+ offline ranking research and later shared inference
transformation. There is no train/validation/test split yet, baseline
evaluation, learned text representation, trained Model, calibration, SHAP,
inference endpoint, or production-quality guarantee. The handcrafted catalog
and labels are synthetic and static.
"""
    return card.replace("\r\n", "\n").encode()


def _build_feature_artifacts(
    source: _LoadedSource,
) -> tuple[dict[str, bytes], FeatureDatasetManifest]:
    pipeline = FeaturePipelineV1()
    candidates = {record.candidate_id: record for record in source.candidates}
    jobs = {record.job_id: record for record in source.jobs}
    records: list[FeatureDatasetRecord] = []
    for pair in sorted(
        source.pairs,
        key=lambda item: (item.candidate_id, item.job_id, item.pair_id),
    ):
        candidate = candidates.get(pair.candidate_id)
        if candidate is None:
            raise ValueError(f"Missing Candidate reference: {pair.candidate_id}")
        job = jobs.get(pair.job_id)
        if job is None:
            raise ValueError(f"Missing Job reference: {pair.job_id}")
        vector = pipeline.transform(_candidate_input(candidate), _job_input(job))
        records.append(
            FeatureDatasetRecord(
                pair_id=pair.pair_id,
                candidate_id=pair.candidate_id,
                job_id=pair.job_id,
                relevance_label=pair.relevance_label,
                feature_schema_version=FEATURE_SCHEMA_VERSION,
                feature_values=list(vector.feature_values),
            ),
        )

    schema_bytes = _json_bytes(feature_schema_artifact())
    features_bytes = _jsonl_bytes(records)
    card_bytes = _feature_schema_card()
    outputs_without_manifest = {
        "FEATURE_SCHEMA_CARD.md": card_bytes,
        "feature_schema.json": schema_bytes,
        "features.jsonl": features_bytes,
    }
    output_counts = {
        "FEATURE_SCHEMA_CARD.md": 1,
        "feature_schema.json": 1,
        "features.jsonl": len(records),
    }
    output_files = [
        _artifact_file(name, content, output_counts[name])
        for name, content in sorted(outputs_without_manifest.items())
    ]
    label_distribution = dict(
        sorted(Counter(str(record.relevance_label) for record in records).items()),
    )
    manifest = FeatureDatasetManifest(
        feature_schema_version=FEATURE_SCHEMA_VERSION,
        feature_pipeline_version=FEATURE_PIPELINE_VERSION,
        source_dataset_version=SOURCE_DATASET_VERSION,
        source_dataset_schema_version=SOURCE_DATASET_SCHEMA_VERSION,
        source_revision=SOURCE_REVISION,
        architecture_sha256=ARCHITECTURE_SHA256,
        feature_release_date=FEATURE_RELEASE_DATE,
        deterministic=True,
        candidate_count=len(source.candidates),
        job_count=len(source.jobs),
        record_count=len(records),
        feature_count=len(FEATURE_NAMES),
        label_distribution=label_distribution,
        source_files=list(source.source_files),
        output_files=output_files,
        feature_schema_sha256=_sha256(schema_bytes),
        generation_config={
            "feature_schema_version": FEATURE_SCHEMA_VERSION,
            "pipeline_version": FEATURE_PIPELINE_VERSION,
            "source_revision": SOURCE_REVISION,
            "architecture_sha256": ARCHITECTURE_SHA256,
        },
        excluded_fields=list(EXCLUDED_INPUT_FIELDS),
        intended_use=[
            "shared_offline_and_inference_feature_transformation",
            "future_candidate_grouped_ranking_research",
        ],
        limitations=[
            "fully_synthetic_source_dataset",
            "handcrafted_features",
            "static_vocabularies",
            "no_learned_text_representation",
            "no_split_evaluation_or_model",
            "no_production_quality_guarantee",
        ],
    )
    artifacts = dict(outputs_without_manifest)
    artifacts["manifest.json"] = _json_bytes(manifest)
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


def build_feature_dataset(input_dir: Path, output_dir: Path) -> WrittenFeatureDataset:
    """Validate locked source data and atomically publish all four outputs."""
    source = _load_source(input_dir)
    artifacts, manifest = _build_feature_artifacts(source)
    _publish_atomically(output_dir, artifacts)
    return WrittenFeatureDataset(
        output_dir=output_dir,
        manifest=manifest,
        file_hashes={name: _sha256(content) for name, content in sorted(artifacts.items())},
    )


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Build deterministic Shared Feature Dataset v1.",
    )
    parser.add_argument(
        "--input-dir",
        type=Path,
        default=Path("services/ml-recommendation/data/synthetic/v1"),
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("services/ml-recommendation/data/features/v1"),
    )
    parser.add_argument("--feature-schema-version", default=FEATURE_SCHEMA_VERSION)
    parser.add_argument("--pipeline-version", default=FEATURE_PIPELINE_VERSION)
    parser.add_argument("--source-revision", default=SOURCE_REVISION)
    parser.add_argument("--architecture-sha256", default=ARCHITECTURE_SHA256)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    """CLI entry point returning nonzero on contract or integrity failure."""
    args = _parser().parse_args(argv)
    locked_arguments = {
        "feature schema version": (args.feature_schema_version, FEATURE_SCHEMA_VERSION),
        "pipeline version": (args.pipeline_version, FEATURE_PIPELINE_VERSION),
        "source revision": (args.source_revision, SOURCE_REVISION),
        "Architecture hash": (args.architecture_sha256, ARCHITECTURE_SHA256),
    }
    try:
        for name, (actual, expected) in locked_arguments.items():
            if actual != expected:
                raise ValueError(f"Locked {name} mismatch")
        written = build_feature_dataset(args.input_dir, args.output_dir)
    except (OSError, ValidationError, ValueError) as error:
        print(f"Feature Dataset generation failed: {error}", file=sys.stderr)
        return 2
    print(
        f"Generated {written.manifest.record_count} feature records "
        f"with {written.manifest.feature_count} features at {written.output_dir}",
    )
    return 0
