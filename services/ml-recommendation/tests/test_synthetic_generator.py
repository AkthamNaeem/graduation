"""Constraint and reproducibility tests for the synthetic Dataset generator."""

import hashlib
import json
from collections import Counter
from dataclasses import replace
from pathlib import Path
from typing import Any

import pytest

from smart_recruitment_ml.data.catalog import DOMAINS
from smart_recruitment_ml.data.generator import (
    ARCHITECTURE_SHA256,
    SOURCE_REVISION,
    DatasetRecords,
    GenerationConfig,
    _publish_atomically,
    generate_dataset,
    summarize_dataset,
    validate_dataset,
    write_dataset,
)
from smart_recruitment_ml.data.generator import (
    _contains_sensitive_key as _generator_contains_sensitive_key,
)

POSITIVE_CODES = {
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
}
NEGATIVE_CODES = {
    "CRITICAL_SKILL_MISSING",
    "EXPERIENCE_GAP",
    "SENIORITY_MISMATCH",
    "EDUCATION_BELOW_REQUIREMENT",
    "DOMAIN_MISMATCH",
    "WORK_MODE_CONFLICT",
    "EMPLOYMENT_TYPE_CONFLICT",
}
SENSITIVE_KEYS = {
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
}


@pytest.fixture(scope="module")
def compact_records() -> DatasetRecords:
    return generate_dataset(
        GenerationConfig(seed=101, candidate_count=24, job_count=24, pairs_per_candidate=20),
    )


def _serialized(records: DatasetRecords) -> str:
    payload = {
        "candidates": [item.model_dump(mode="json") for item in records.candidates],
        "jobs": [item.model_dump(mode="json") for item in records.jobs],
        "pairs": [item.model_dump(mode="json") for item in records.pairs],
    }
    return json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def _contains_sensitive_key(value: object) -> bool:
    if isinstance(value, dict):
        return bool(set(value) & SENSITIVE_KEYS) or any(
            _contains_sensitive_key(item) for item in value.values()
        )
    if isinstance(value, list):
        return any(_contains_sensitive_key(item) for item in value)
    return False


def test_same_seed_is_identical_and_different_seed_changes_records() -> None:
    config = GenerationConfig(
        seed=2026,
        candidate_count=24,
        job_count=24,
        pairs_per_candidate=20,
    )

    first = _serialized(generate_dataset(config))
    second = _serialized(generate_dataset(config))
    changed = _serialized(
        generate_dataset(
            GenerationConfig(
                seed=2027,
                candidate_count=24,
                job_count=24,
                pairs_per_candidate=20,
            ),
        ),
    )

    assert first == second
    assert changed != first


def test_counts_ids_pair_coverage_and_deterministic_order(
    compact_records: DatasetRecords,
) -> None:
    summary = summarize_dataset(compact_records)
    candidate_ids = [candidate.candidate_id for candidate in compact_records.candidates]
    job_ids = [job.job_id for job in compact_records.jobs]
    pair_ids = [pair.pair_id for pair in compact_records.pairs]
    pair_keys = [(pair.candidate_id, pair.job_id) for pair in compact_records.pairs]

    assert summary["candidate_count"] == 24
    assert summary["job_count"] == 24
    assert summary["pair_count"] == 480
    assert len(candidate_ids) == len(set(candidate_ids))
    assert len(job_ids) == len(set(job_ids))
    assert len(pair_ids) == len(set(pair_ids))
    assert len(pair_keys) == len(set(pair_keys))
    assert summary["pairs_per_candidate_min"] == 20
    assert summary["pairs_per_candidate_max"] == 20
    assert summary["job_appearances_min"] == 20
    assert summary["job_appearances_max"] == 20
    assert pair_keys == sorted(pair_keys)


def test_domains_labels_scenarios_noise_and_rationales(
    compact_records: DatasetRecords,
) -> None:
    summary = summarize_dataset(compact_records)
    candidate_domain_counts = Counter(
        candidate.primary_domain for candidate in compact_records.candidates
    )
    labels = {pair.relevance_label for pair in compact_records.pairs}
    hard_negatives = [pair for pair in compact_records.pairs if pair.scenario == "hard_negative"]
    borderline = [pair for pair in compact_records.pairs if pair.scenario == "borderline"]
    candidates_by_id = {
        candidate.candidate_id: candidate for candidate in compact_records.candidates
    }
    jobs_by_id = {job.job_id: job for job in compact_records.jobs}

    assert len(DOMAINS) == 12
    assert len(candidate_domain_counts) == 12
    assert max(candidate_domain_counts.values()) - min(candidate_domain_counts.values()) <= 1
    assert labels == {0, 1, 2, 3}
    assert all(0.05 <= count / 480 <= 0.65 for count in summary["label_distribution"].values())
    assert summary["scenario_distribution"]["hard_negative"] / 480 >= 0.10
    assert summary["scenario_distribution"]["borderline"] / 480 >= 0.10
    assert 0.05 <= summary["noise_rate"] <= 0.20
    assert all(pair.rationale_codes for pair in compact_records.pairs)
    assert all(
        set(pair.rationale_codes) & POSITIVE_CODES and set(pair.rationale_codes) & NEGATIVE_CODES
        for pair in hard_negatives
    )
    assert all(
        jobs_by_id[pair.job_id].domain
        in {
            candidates_by_id[pair.candidate_id].primary_domain,
            *candidates_by_id[pair.candidate_id].adjacent_domains,
        }
        and pair.relevance_label <= 1
        for pair in hard_negatives
    )
    assert all(
        set(pair.rationale_codes) & POSITIVE_CODES and set(pair.rationale_codes) & NEGATIVE_CODES
        for pair in borderline
    )
    assert len({pair.relevance_label for pair in borderline}) >= 2


def test_professional_schema_constraints_and_privacy(
    compact_records: DatasetRecords,
) -> None:
    payload: list[dict[str, Any]] = [
        *(candidate.model_dump(mode="json") for candidate in compact_records.candidates),
        *(job.model_dump(mode="json") for job in compact_records.jobs),
        *(pair.model_dump(mode="json") for pair in compact_records.pairs),
    ]

    assert not any(_contains_sensitive_key(record) for record in payload)
    assert all(
        1 <= skill.proficiency <= 5 and skill.years_experience >= 0
        for candidate in compact_records.candidates
        for skill in candidate.skills
    )
    assert all(
        1 <= skill.weight <= 5 for job in compact_records.jobs for skill in job.required_skills
    )
    assert all(candidate.total_experience_years >= 0 for candidate in compact_records.candidates)
    assert all(
        "latent_score" not in pair.model_dump() and "feature_vector" not in pair.model_dump()
        for pair in compact_records.pairs
    )


def test_manifest_counts_hashes_versions_and_byte_reproducibility(tmp_path: Path) -> None:
    config = GenerationConfig(
        seed=303,
        candidate_count=24,
        job_count=24,
        pairs_per_candidate=20,
    )
    first_dir = tmp_path / "first"
    second_dir = tmp_path / "second"

    first = write_dataset(config, first_dir)
    second = write_dataset(config, second_dir)
    overwritten = write_dataset(config, first_dir)

    assert first.file_hashes == second.file_hashes
    assert overwritten.file_hashes == first.file_hashes
    assert set(first.file_hashes) == {
        "DATASET_CARD.md",
        "candidates.jsonl",
        "jobs.jsonl",
        "manifest.json",
        "pairs.jsonl",
    }
    assert first.manifest.candidate_count == 24
    assert first.manifest.job_count == 24
    assert first.manifest.pair_count == 480
    assert first.manifest.random_seed == 303
    assert first.manifest.source_revision == SOURCE_REVISION
    assert first.manifest.architecture_sha256 == ARCHITECTURE_SHA256
    assert first.manifest.eligibility_scope == "laravel_pre_filtered_eligible_jobs"
    for file_info in first.manifest.files:
        content = (first_dir / file_info.path).read_bytes()
        assert file_info.record_count in {24, 480}
        assert file_info.size_bytes == len(content)
        assert file_info.sha256 == hashlib.sha256(content).hexdigest()
        assert content == (second_dir / file_info.path).read_bytes()


def test_invalid_configuration_does_not_leave_partial_directory(tmp_path: Path) -> None:
    output_dir = tmp_path / "invalid"

    with pytest.raises(ValueError, match="cannot exceed"):
        GenerationConfig(candidate_count=24, job_count=24, pairs_per_candidate=25)

    assert not output_dir.exists()


@pytest.mark.parametrize(
    ("overrides", "message"),
    [
        ({"candidate_count": 11}, "at least 12"),
        (
            {"candidate_count": 20, "job_count": 21, "pairs_per_candidate": 20},
            "20 appearances",
        ),
        ({"source_revision": "not-a-revision"}, "source_revision"),
        ({"architecture_sha256": "not-a-digest"}, "architecture_sha256"),
    ],
)
def test_invalid_configuration_constraints(
    overrides: dict[str, int | str],
    message: str,
) -> None:
    with pytest.raises(ValueError, match=message):
        GenerationConfig(**overrides)  # type: ignore[arg-type]


def test_validation_rejects_corrupted_record_sets(
    compact_records: DatasetRecords,
) -> None:
    config = GenerationConfig(seed=101, candidate_count=24, job_count=24, pairs_per_candidate=20)

    invalid_records = [
        (replace(compact_records, candidates=compact_records.candidates[:-1]), "Candidate count"),
        (replace(compact_records, jobs=compact_records.jobs[:-1]), "Job count"),
        (replace(compact_records, pairs=compact_records.pairs[:-1]), "Pair count"),
        (
            replace(
                compact_records,
                candidates=(
                    compact_records.candidates[0].model_copy(
                        update={"candidate_id": compact_records.candidates[1].candidate_id}
                    ),
                    *compact_records.candidates[1:],
                ),
            ),
            "Duplicate Candidate IDs",
        ),
        (
            replace(
                compact_records,
                jobs=(
                    compact_records.jobs[0].model_copy(
                        update={"job_id": compact_records.jobs[1].job_id}
                    ),
                    *compact_records.jobs[1:],
                ),
            ),
            "Duplicate Job IDs",
        ),
        (
            replace(
                compact_records,
                pairs=(
                    compact_records.pairs[0].model_copy(
                        update={"pair_id": compact_records.pairs[1].pair_id}
                    ),
                    *compact_records.pairs[1:],
                ),
            ),
            "Duplicate Pair IDs",
        ),
        (
            replace(
                compact_records,
                pairs=(
                    compact_records.pairs[0].model_copy(
                        update={
                            "candidate_id": compact_records.pairs[1].candidate_id,
                            "job_id": compact_records.pairs[1].job_id,
                        }
                    ),
                    *compact_records.pairs[1:],
                ),
            ),
            "Duplicate Candidate-Job pair",
        ),
        (
            replace(
                compact_records,
                candidates=tuple(
                    candidate.model_copy(update={"primary_domain": "Backend Engineering"})
                    for candidate in compact_records.candidates
                ),
            ),
            "12 professional domains",
        ),
        (
            replace(
                compact_records,
                pairs=tuple(
                    pair.model_copy(update={"relevance_label": 0}) for pair in compact_records.pairs
                ),
            ),
            "labels 0..3",
        ),
        (
            replace(
                compact_records,
                pairs=tuple(
                    pair.model_copy(update={"relevance_label": index if index < 4 else 0})
                    for index, pair in enumerate(compact_records.pairs)
                ),
            ),
            "outside 5%..65%",
        ),
        (
            replace(
                compact_records,
                pairs=tuple(
                    pair.model_copy(update={"scenario": "clear_mismatch"})
                    for pair in compact_records.pairs
                ),
            ),
            "Every scenario",
        ),
        (
            replace(
                compact_records,
                pairs=tuple(
                    pair.model_copy(update={"noise_applied": False})
                    for pair in compact_records.pairs
                ),
            ),
            "Noise rate",
        ),
    ]

    for records, message in invalid_records:
        with pytest.raises(ValueError, match=message):
            validate_dataset(records, config)


def test_sensitive_key_helper_and_atomic_failure_cleanup(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    assert _generator_contains_sensitive_key({"profile": [{"email": "synthetic@example.invalid"}]})
    assert not _generator_contains_sensitive_key({"skills": [{"name": "python"}]})

    output_dir = tmp_path / "atomic-failure"
    original_write_bytes = Path.write_bytes

    def fail_write(path: Path, content: bytes) -> int:
        if path.parent.name == ".atomic-failure.tmp":
            raise OSError("simulated write failure")
        return original_write_bytes(path, content)

    monkeypatch.setattr(Path, "write_bytes", fail_write)
    with pytest.raises(OSError, match="simulated"):
        _publish_atomically(output_dir, {"artifact.txt": b"content"})

    assert not output_dir.exists()
    assert not (tmp_path / ".atomic-failure.tmp").exists()
