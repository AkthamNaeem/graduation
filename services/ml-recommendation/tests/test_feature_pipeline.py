"""Contract, integrity, privacy, and reproducibility tests for Feature Pipeline v1."""

from __future__ import annotations

import hashlib
import json
import math
import shutil
from dataclasses import replace
from pathlib import Path

import pytest
from pydantic import ValidationError

from smart_recruitment_ml.features.normalization import (
    merge_candidate_skills,
    merge_required_skills,
    normalize_categories,
    normalize_job_skills,
    normalize_skill_name,
    normalize_text,
    tokenize,
)
from smart_recruitment_ml.features.pipeline import (
    ARCHITECTURE_SHA256,
    EXPECTED_SOURCE_HASHES,
    FEATURE_NAMES,
    FEATURE_PIPELINE_VERSION,
    FEATURE_SCHEMA_VERSION,
    SOURCE_REVISION,
    FeaturePipelineV1,
    _build_feature_artifacts,
    _load_source,
    build_feature_dataset,
    feature_schema_artifact,
)
from smart_recruitment_ml.schemas.features import (
    CandidateFeatureInput,
    CandidateSkillInput,
    FeatureDatasetRecord,
    JobFeatureInput,
    RequiredSkillInput,
)
from smart_recruitment_ml.schemas.synthetic import RelevancePair

SERVICE_DIR = Path(__file__).resolve().parents[1]
SOURCE_DIR = SERVICE_DIR / "data" / "synthetic" / "v1"
SOURCE_NAMES = ("candidates.jsonl", "jobs.jsonl", "pairs.jsonl", "manifest.json")


def _candidate(**overrides: object) -> CandidateFeatureInput:
    values: dict[str, object] = {
        "primary_domain": "Backend Engineering",
        "adjacent_domains": ["Data Engineering", "DevOps / Cloud"],
        "headline": "Senior Python Backend Engineer",
        "career_level": "senior",
        "total_experience_years": 8.0,
        "education_level": "master",
        "skills": [
            {"name": "Python", "proficiency": 5, "years_experience": 7},
            {"name": "SQL", "proficiency": 3, "years_experience": 4},
            {"name": "Communication", "proficiency": 4, "years_experience": 6},
        ],
        "preferred_work_modes": ["remote", "hybrid"],
        "preferred_employment_types": ["full_time"],
    }
    values.update(overrides)
    return CandidateFeatureInput.model_validate(values)


def _job(**overrides: object) -> JobFeatureInput:
    values: dict[str, object] = {
        "domain": "Backend Engineering",
        "title": "Python Backend Engineer",
        "department": "Engineering",
        "description": "Build Python services and reliable SQL APIs.",
        "responsibilities": ["Develop Python APIs.", "Review SQL queries."],
        "required_skills": [
            {"name": "python", "weight": 4},
            {"name": "sql", "weight": 5},
            {"name": "java", "weight": 4},
        ],
        "nice_to_have_skills": ["communication", "python"],
        "minimum_experience_years": 5.0,
        "education_level": "bachelor",
        "career_level": "mid",
        "work_mode": "remote",
        "employment_type": "full_time",
    }
    values.update(overrides)
    return JobFeatureInput.model_validate(values)


def _mapping(
    candidate: CandidateFeatureInput | None = None,
    job: JobFeatureInput | None = None,
) -> dict[str, float]:
    vector = FeaturePipelineV1().transform(candidate or _candidate(), job or _job())
    return dict(zip(vector.feature_names, vector.feature_values, strict=True))


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _copy_source(destination: Path) -> None:
    destination.mkdir()
    for name in SOURCE_NAMES:
        shutil.copyfile(SOURCE_DIR / name, destination / name)


def test_normalization_is_unicode_aware_bounded_and_deterministic() -> None:
    assert normalize_text("  \uff30\uff39\uff34\uff28\uff2f\uff2e\tStra\u00dfe  ") == (
        "python strasse"
    )
    assert normalize_skill_name(" CI \u2014 CD ") == "ci-cd"
    assert normalize_categories([" REMOTE ", "remote", "Hybrid"]) == ("hybrid", "remote")
    assert tokenize("مرحبا، Python—API; naïve_user") == (
        "مرحبا",
        "python",
        "api",
        "naïve",
        "user",
    )
    assert tokenize("one two three", limit=2) == ("one", "two")
    assert tokenize("one", limit=-1) == ()


def test_skill_merge_and_required_precedence() -> None:
    candidate_skills = [
        CandidateSkillInput(name=" Python ", proficiency=2, years_experience=7),
        CandidateSkillInput(name="PYTHON", proficiency=5, years_experience=2),
        CandidateSkillInput(name=None, proficiency=None, years_experience=None),
    ]
    merged_candidate = merge_candidate_skills(candidate_skills)
    assert len(merged_candidate) == 1
    assert merged_candidate[0].name == "python"
    assert merged_candidate[0].proficiency == 5
    assert merged_candidate[0].years_experience == 7

    required = [
        RequiredSkillInput(name="SQL", weight=2),
        RequiredSkillInput(name=" sql ", weight=5),
    ]
    merged_required = merge_required_skills(required)
    assert [(item.name, item.weight) for item in merged_required] == [("sql", 5.0)]
    normalized_required, nice = normalize_job_skills(
        required,
        [" SQL ", "Communication", "communication"],
    )
    assert normalized_required == merged_required
    assert nice == ("communication",)


def test_pipeline_is_fixed_finite_bounded_and_transform_many_is_identical() -> None:
    pipeline = FeaturePipelineV1()
    first = pipeline.transform(_candidate(), _job())
    second = pipeline.transform(_candidate(), _job())
    many = pipeline.transform_many([(_candidate(), _job()), (_candidate(), _job())])
    assert first == second == many[0] == many[1]
    assert first.feature_names == FEATURE_NAMES
    assert first.feature_schema_version == FEATURE_SCHEMA_VERSION
    assert len(FEATURE_NAMES) == len(first.feature_values) == 103
    assert all(math.isfinite(value) for value in first.feature_values)
    for name, value in zip(first.feature_names, first.feature_values, strict=True):
        lower = (
            -1.0
            if name
            in {
                "experience_gap_signed",
                "career_level_distance",
                "education_level_distance",
            }
            else 0.0
        )
        assert lower <= value <= 1.0


def test_domain_skill_and_transferable_semantics() -> None:
    features = _mapping()
    assert features["domain_exact_match"] == 1.0
    assert features["domain_adjacent_match"] == 0.0
    assert features["domain_mismatch"] == 0.0
    assert features["required_skill_overlap_ratio"] == pytest.approx(2 / 3)
    assert features["weighted_required_skill_coverage"] == pytest.approx(9 / 13)
    assert features["critical_required_skill_coverage"] == pytest.approx(2 / 3)
    assert features["missing_required_skill_ratio"] == pytest.approx(1 / 3)
    assert features["matched_required_skill_mean_proficiency"] == pytest.approx(0.8)
    assert features["nice_to_have_skill_coverage"] == 1.0
    assert features["transferable_skill_coverage"] > 0.0
    assert features["domain_skill_coverage_interaction"] == pytest.approx(9 / 13)

    adjacent = _mapping(job=_job(domain="Data Engineering"))
    assert adjacent["domain_exact_match"] == 0.0
    assert adjacent["domain_adjacent_match"] == 1.0
    assert adjacent["domain_mismatch"] == 0.0
    mismatch = _mapping(job=_job(domain="Cybersecurity"))
    assert mismatch["domain_mismatch"] == 1.0


def test_experience_levels_preferences_text_and_interactions() -> None:
    features = _mapping()
    assert features["candidate_experience_normalized"] == pytest.approx(8 / 30)
    assert features["job_minimum_experience_normalized"] == pytest.approx(5 / 30)
    assert features["experience_gap_signed"] == pytest.approx(3 / 30)
    assert features["experience_shortfall"] == 0.0
    assert features["experience_surplus"] == pytest.approx(3 / 30)
    assert features["experience_requirement_met"] == 1.0
    assert features["career_level_distance"] == pytest.approx(1 / 4)
    assert features["seniority_requirement_met"] == 1.0
    assert features["career_overqualification"] == 1.0
    assert features["education_level_distance"] == pytest.approx(1 / 4)
    assert features["education_requirement_met"] == 1.0
    assert features["work_mode_match"] == 1.0
    assert features["employment_type_match"] == 1.0
    assert features["headline_title_token_jaccard"] == pytest.approx(3 / 4)
    assert features["candidate_skills_in_job_description_ratio"] == pytest.approx(2 / 3)
    assert features["candidate_skills_in_job_responsibilities_ratio"] == pytest.approx(2 / 3)
    assert features["required_skills_in_candidate_headline_ratio"] == pytest.approx(1 / 3)
    assert features["critical_skill_experience_interaction"] == pytest.approx(2 / 3)
    assert features["skill_career_alignment_interaction"] == pytest.approx((9 / 13) * 0.75)
    assert features["skill_work_mode_interaction"] == pytest.approx(9 / 13)


def test_missing_and_unknown_policies_keep_vector_length() -> None:
    vector = FeaturePipelineV1().transform(CandidateFeatureInput(), JobFeatureInput())
    features = dict(zip(vector.feature_names, vector.feature_values, strict=True))
    assert len(vector.feature_values) == len(FEATURE_NAMES)
    assert features["candidate_domain__unknown"] == 1.0
    assert features["job_domain__unknown"] == 1.0
    assert features["candidate_career_level__unknown"] == 1.0
    assert features["job_education_level__unknown"] == 1.0
    assert features["candidate_skills_missing"] == 1.0
    assert features["candidate_experience_missing"] == 1.0
    assert features["job_required_skills_missing"] == 1.0
    assert features["job_minimum_experience_missing"] == 1.0
    assert features["domain_exact_match"] == 0.0
    assert features["experience_requirement_met"] == 0.0

    unknown = _mapping(
        candidate=_candidate(
            primary_domain="not-a-domain",
            career_level="wizard",
            education_level="other",
        ),
        job=_job(work_mode="distributed", employment_type="internship"),
    )
    assert unknown["candidate_domain__unknown"] == 1.0
    assert unknown["candidate_career_level__unknown"] == 1.0
    assert unknown["candidate_education_level__unknown"] == 1.0
    assert unknown["job_work_mode__unknown"] == 1.0
    assert unknown["job_employment_type__unknown"] == 1.0


def test_label_audit_ids_sensitive_fields_and_raw_objects_cannot_enter_transform() -> None:
    pipeline = FeaturePipelineV1()
    baseline = pipeline.transform(_candidate(), _job())
    pair_variants = [
        {"relevance_label": 0},
        {"relevance_label": 3},
        {"scenario": "strong_match"},
        {"scenario": "clear_mismatch"},
        {"rationale_codes": ["A"]},
        {"rationale_codes": ["B"]},
        {"noise_applied": False},
        {"noise_applied": True},
        {"candidate_id": "cand_0001"},
        {"candidate_id": "cand_9999"},
        {"job_id": "job_0001"},
        {"job_id": "job_9999"},
        {"pair_id": "pair_cand_0001_job_0001"},
        {"pair_id": "pair_cand_9999_job_9999"},
    ]
    assert all(pipeline.transform(_candidate(), _job()) == baseline for _ in pair_variants)

    forbidden_terms = {
        "candidate_id",
        "job_id",
        "pair_id",
        "relevance_label",
        "scenario",
        "rationale_codes",
        "noise_applied",
        "hidden_affinity",
        "latent_score",
        "email",
        "phone",
        "gender",
        "nationality",
        "raw_cv",
        "application_status",
    }
    schema_fields = set(CandidateFeatureInput.model_fields) | set(JobFeatureInput.model_fields)
    assert schema_fields.isdisjoint(forbidden_terms)
    assert all(not any(term in name for term in forbidden_terms) for name in FEATURE_NAMES)
    with pytest.raises(ValidationError):
        CandidateFeatureInput.model_validate({"candidate_id": "cand_0001"})
    with pytest.raises(ValidationError):
        JobFeatureInput.model_validate({"job_id": "job_0001"})


def test_feature_schema_artifact_is_complete_and_ordered() -> None:
    artifact = feature_schema_artifact()
    assert artifact.feature_schema_version == FEATURE_SCHEMA_VERSION
    assert artifact.feature_pipeline_version == FEATURE_PIPELINE_VERSION
    assert artifact.source_revision == SOURCE_REVISION
    assert artifact.architecture_sha256 == ARCHITECTURE_SHA256
    assert artifact.feature_count == len(FEATURE_NAMES)
    assert tuple(artifact.feature_names) == FEATURE_NAMES
    assert [definition.name for definition in artifact.feature_definitions] == list(FEATURE_NAMES)
    assert artifact.vocabularies["domains"][0] == "__unknown__"
    assert artifact.text_token_limits == {
        "candidate_headline": 32,
        "job_title": 32,
        "job_description": 256,
        "job_responsibilities": 256,
    }
    assert artifact.critical_skill_weight_threshold == 4
    assert artifact.experience_cap_years == 30


def test_full_dataset_build_integrity_and_byte_reproducibility(tmp_path: Path) -> None:
    first_dir = tmp_path / "first"
    second_dir = tmp_path / "second"
    first = build_feature_dataset(SOURCE_DIR, first_dir)
    second = build_feature_dataset(SOURCE_DIR, second_dir)
    names = ("feature_schema.json", "features.jsonl", "manifest.json", "FEATURE_SCHEMA_CARD.md")
    assert all(
        (first_dir / name).read_bytes() == (second_dir / name).read_bytes() for name in names
    )
    assert first.file_hashes == second.file_hashes
    assert first.manifest.candidate_count == 180
    assert first.manifest.job_count == 180
    assert first.manifest.record_count == 10800
    assert first.manifest.label_distribution == {"0": 5534, "1": 2058, "2": 2450, "3": 758}
    assert first.manifest.feature_count == 103
    assert first.manifest.feature_schema_sha256 == _sha256(first_dir / "feature_schema.json")
    assert {item.path: item.sha256 for item in first.manifest.source_files} == {
        name: EXPECTED_SOURCE_HASHES[name] for name in sorted(SOURCE_NAMES)
    }
    for output in first.manifest.output_files:
        assert output.sha256 == _sha256(first_dir / output.path)
        assert output.bytes == (first_dir / output.path).stat().st_size

    records = [
        FeatureDatasetRecord.model_validate_json(line)
        for line in (first_dir / "features.jsonl").read_text(encoding="utf-8").splitlines()
    ]
    assert len(records) == 10800
    assert len({record.pair_id for record in records}) == 10800
    assert records == sorted(
        records,
        key=lambda item: (item.candidate_id, item.job_id, item.pair_id),
    )
    assert {len(record.feature_values) for record in records} == {103}
    assert all(math.isfinite(value) for record in records for value in record.feature_values)
    assert set(records[0].model_dump()) == {
        "pair_id",
        "candidate_id",
        "job_id",
        "relevance_label",
        "feature_schema_version",
        "feature_values",
    }
    output_text = (first_dir / "features.jsonl").read_text(encoding="utf-8")
    assert all(
        forbidden not in output_text
        for forbidden in (
            "scenario",
            "rationale_codes",
            "noise_applied",
            "hidden_affinity",
            "latent_score",
            "headline",
            "description",
            "responsibilities",
        )
    )


def test_source_tampering_invalid_version_and_failure_leave_no_partial_output(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    tampered_dir = tmp_path / "tampered"
    _copy_source(tampered_dir)
    with (tampered_dir / "candidates.jsonl").open("a", encoding="utf-8") as handle:
        handle.write("\n")
    output_dir = tmp_path / "must-not-exist"
    with pytest.raises(ValueError, match="Source hash mismatch"):
        build_feature_dataset(tampered_dir, output_dir)
    assert not output_dir.exists()

    invalid_version_dir = tmp_path / "invalid-version"
    _copy_source(invalid_version_dir)
    manifest_path = invalid_version_dir / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest["dataset_version"] = "invalid"
    manifest_path.write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "manifest.json", _sha256(manifest_path))
    with pytest.raises(ValueError, match="Dataset version mismatch"):
        build_feature_dataset(invalid_version_dir, tmp_path / "invalid-output")
    assert not (tmp_path / "invalid-output").exists()


def test_missing_candidate_and_job_references_fail_before_artifact_output() -> None:
    source = _load_source(SOURCE_DIR)
    original = source.pairs[0]
    missing_candidate = RelevancePair(
        pair_id="pair_cand_9999_job_0001",
        candidate_id="cand_9999",
        job_id=original.job_id,
        relevance_label=original.relevance_label,
        scenario=original.scenario,
        rationale_codes=original.rationale_codes,
        noise_applied=original.noise_applied,
    )
    with pytest.raises(ValueError, match="Missing Candidate reference"):
        _build_feature_artifacts(replace(source, pairs=(missing_candidate,)))

    missing_job = RelevancePair(
        pair_id="pair_cand_0001_job_9999",
        candidate_id=original.candidate_id,
        job_id="job_9999",
        relevance_label=original.relevance_label,
        scenario=original.scenario,
        rationale_codes=original.rationale_codes,
        noise_applied=original.noise_applied,
    )
    with pytest.raises(ValueError, match="Missing Job reference"):
        _build_feature_artifacts(replace(source, pairs=(missing_job,)))
