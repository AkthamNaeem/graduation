"""Allocation, leakage, integrity, and artifact tests for Phase 6 splitting."""

from __future__ import annotations

import hashlib
import inspect
import json
import math
import shutil
from collections import Counter
from pathlib import Path
from typing import TYPE_CHECKING

import pytest

from smart_recruitment_ml.features.pipeline import FEATURE_NAMES
from smart_recruitment_ml.schemas.features import FeatureDatasetRecord
from smart_recruitment_ml.schemas.splits import (
    CandidateSplitAssignment,
    LockedTestGuard,
    SplitManifest,
)
from smart_recruitment_ml.splits.splitter import (
    EXPECTED_SOURCE_HASHES,
    SPLIT_SEED,
    _candidate_ids_hash,
    _validate_ratios,
    allocate_candidate_groups,
    build_candidate_group_split,
    load_split_sources,
    partition_feature_records,
)

if TYPE_CHECKING:
    import smart_recruitment_ml.splits.splitter as splitter_module

SERVICE_DIR = Path(__file__).resolve().parents[1]
FEATURES_DIR = SERVICE_DIR / "data" / "features" / "v1"
CANDIDATES_FILE = SERVICE_DIR / "data" / "synthetic" / "v1" / "candidates.jsonl"
FEATURE_SOURCE_NAMES = (
    "feature_schema.json",
    "features.jsonl",
    "manifest.json",
    "FEATURE_SCHEMA_CARD.md",
)
OUTPUT_NAMES = (
    "train.jsonl",
    "validation.jsonl",
    "test.jsonl",
    "assignments.jsonl",
    "test_lock.json",
    "manifest.json",
    "SPLIT_CARD.md",
)


@pytest.fixture(scope="module")
def loaded_sources() -> splitter_module.LoadedSplitSources:
    return load_split_sources(FEATURES_DIR, CANDIDATES_FILE)


def _groups(
    sources: splitter_module.LoadedSplitSources,
) -> tuple[tuple[str, str], ...]:
    return tuple(
        (candidate.candidate_id, candidate.primary_domain) for candidate in sources.candidates
    )


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _copy_sources(root: Path) -> tuple[Path, Path]:
    features = root / "features"
    features.mkdir(parents=True)
    for name in FEATURE_SOURCE_NAMES:
        shutil.copyfile(FEATURES_DIR / name, features / name)
    candidate_path = root / "candidates.jsonl"
    shutil.copyfile(CANDIDATES_FILE, candidate_path)
    return features, candidate_path


def _replace_jsonl(path: Path, records: list[dict[str, object]]) -> None:
    path.write_text(
        "\n".join(json.dumps(record, sort_keys=True, separators=(",", ":")) for record in records)
        + "\n",
        encoding="utf-8",
    )


def test_allocation_is_deterministic_seeded_exact_and_target_independent(
    loaded_sources: splitter_module.LoadedSplitSources,
) -> None:
    groups = _groups(loaded_sources)
    first = allocate_candidate_groups(groups, SPLIT_SEED)
    second = allocate_candidate_groups(tuple(reversed(groups)), SPLIT_SEED)
    changed_seed = allocate_candidate_groups(groups, SPLIT_SEED + 1)
    assert first == second
    assert first != changed_seed
    assert tuple(item.candidate_id for item in first) == tuple(
        sorted(item.candidate_id for item in first),
    )
    assert set(inspect.signature(allocate_candidate_groups).parameters) == {
        "candidate_groups",
        "seed",
    }
    assert all(
        set(item.model_dump()) == {"candidate_id", "primary_domain", "split"} for item in first
    )

    counts = Counter(item.split for item in first)
    assert counts == {"train": 126, "validation": 27, "test": 27}
    domains = sorted({item.primary_domain for item in first})
    by_split_domain = {
        split: Counter(item.primary_domain for item in first if item.split == split)
        for split in ("train", "validation", "test")
    }
    assert all(set(by_split_domain[split]) == set(domains) for split in by_split_domain)
    assert sum(value == 11 for value in by_split_domain["train"].values()) == 6
    assert sum(value == 3 for value in by_split_domain["validation"].values()) == 3
    assert sum(value == 3 for value in by_split_domain["test"].values()) == 3
    assert {
        (
            by_split_domain["train"][domain],
            by_split_domain["validation"][domain],
            by_split_domain["test"][domain],
        )
        for domain in domains
    } <= {(11, 2, 2), (10, 3, 2), (10, 2, 3)}


def test_partition_has_no_group_or_pair_leakage_and_preserves_records(
    loaded_sources: splitter_module.LoadedSplitSources,
) -> None:
    assignments = allocate_candidate_groups(_groups(loaded_sources))
    first = partition_feature_records(loaded_sources.feature_records, assignments)
    reordered = partition_feature_records(
        tuple(reversed(loaded_sources.feature_records)),
        assignments,
    )
    assert first == reordered
    expected_counts = {
        "train": (126, 7560),
        "validation": (27, 1620),
        "test": (27, 1620),
    }
    candidate_sets: dict[str, set[str]] = {}
    pair_sets: dict[str, set[str]] = {}
    for split, records in first.items():
        candidate_sets[split] = {record.candidate_id for record in records}
        pair_sets[split] = {record.pair_id for record in records}
        assert (len(candidate_sets[split]), len(records)) == expected_counts[split]
        assert set(Counter(record.candidate_id for record in records).values()) == {60}
        assert records == tuple(
            sorted(
                records,
                key=lambda item: (item.candidate_id, item.job_id, item.pair_id),
            ),
        )
        assert all(len(record.feature_values) == len(FEATURE_NAMES) for record in records)
        assert all(math.isfinite(value) for record in records for value in record.feature_values)
    assert candidate_sets["train"].isdisjoint(candidate_sets["validation"])
    assert candidate_sets["train"].isdisjoint(candidate_sets["test"])
    assert candidate_sets["validation"].isdisjoint(candidate_sets["test"])
    assert pair_sets["train"].isdisjoint(pair_sets["validation"])
    assert pair_sets["train"].isdisjoint(pair_sets["test"])
    assert pair_sets["validation"].isdisjoint(pair_sets["test"])
    assert set().union(*candidate_sets.values()) == {
        candidate.candidate_id for candidate in loaded_sources.candidates
    }
    assert set().union(*pair_sets.values()) == {
        record.pair_id for record in loaded_sources.feature_records
    }
    source_by_pair = {record.pair_id: record for record in loaded_sources.feature_records}
    assert all(
        record == source_by_pair[record.pair_id] for records in first.values() for record in records
    )


def test_full_build_artifacts_lock_and_byte_reproducibility(tmp_path: Path) -> None:
    first_dir = tmp_path / "first"
    second_dir = tmp_path / "second"
    first = build_candidate_group_split(FEATURES_DIR, CANDIDATES_FILE, first_dir)
    second = build_candidate_group_split(FEATURES_DIR, CANDIDATES_FILE, second_dir)
    assert set(first.file_hashes) == set(OUTPUT_NAMES)
    assert first.file_hashes == second.file_hashes
    assert all(
        (first_dir / name).read_bytes() == (second_dir / name).read_bytes() for name in OUTPUT_NAMES
    )
    manifest = SplitManifest.model_validate_json(
        (first_dir / "manifest.json").read_text(encoding="utf-8"),
    )
    assert manifest.candidate_count == 180
    assert manifest.record_count == 10800
    assert manifest.feature_count == 103
    assert manifest.candidate_overlap_counts == {
        "candidate_train_validation": 0,
        "candidate_train_test": 0,
        "candidate_validation_test": 0,
    }
    assert manifest.pair_overlap_counts == {
        "pair_train_validation": 0,
        "pair_train_test": 0,
        "pair_validation_test": 0,
    }
    assert set(manifest.candidate_domain_distribution) == {
        "train",
        "validation",
        "test",
    }
    assert all(
        len(distribution) == 12 for distribution in manifest.candidate_domain_distribution.values()
    )
    for output in manifest.output_files:
        assert output.sha256 == _sha256(first_dir / output.path)
        assert output.size_bytes == (first_dir / output.path).stat().st_size

    guard = LockedTestGuard.model_validate_json(
        (first_dir / "test_lock.json").read_text(encoding="utf-8"),
    )
    assert guard.test_locked is True
    assert guard.created_for_phase == 6
    assert guard.prohibited_before_phase == 10
    assert guard.test_record_count == 1620
    assert guard.test_file_sha256 == _sha256(first_dir / "test.jsonl")
    assignments = [
        CandidateSplitAssignment.model_validate_json(line)
        for line in (first_dir / "assignments.jsonl").read_text(encoding="utf-8").splitlines()
    ]
    locked_ids = [item.candidate_id for item in assignments if item.split == "test"]
    assert guard.test_candidate_ids_sha256 == _candidate_ids_hash(locked_ids)
    card = (first_dir / "SPLIT_CARD.md").read_text(encoding="utf-8")
    assert all(candidate_id not in card for candidate_id in locked_ids)
    assert "final locked evaluation" in card

    forbidden = {
        "split",
        "primary_domain",
        "scenario",
        "rationale_codes",
        "noise_applied",
        "timestamp",
    }
    for split in ("train", "validation", "test"):
        records = [
            FeatureDatasetRecord.model_validate_json(line)
            for line in (first_dir / f"{split}.jsonl").read_text(encoding="utf-8").splitlines()
        ]
        assert all(set(record.model_dump()).isdisjoint(forbidden) for record in records)


def test_source_hash_schema_candidate_and_pair_validation(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    original_schema_hash = EXPECTED_SOURCE_HASHES["feature_schema.json"]
    original_candidate_hash = EXPECTED_SOURCE_HASHES["candidates.jsonl"]
    tampered_features, tampered_candidates = _copy_sources(tmp_path / "hash")
    with (tampered_features / "features.jsonl").open("a", encoding="utf-8") as handle:
        handle.write("\n")
    with pytest.raises(ValueError, match="Source hash mismatch"):
        load_split_sources(tampered_features, tampered_candidates)

    schema_features, schema_candidates = _copy_sources(tmp_path / "schema")
    schema_path = schema_features / "feature_schema.json"
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    schema["feature_schema_version"] = "unsupported"
    schema_path.write_text(
        json.dumps(schema, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    with pytest.raises(ValueError, match="Source hash mismatch: feature_schema"):
        load_split_sources(schema_features, schema_candidates)
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "feature_schema.json", _sha256(schema_path))
    with pytest.raises(ValueError, match="Unsupported feature schema"):
        load_split_sources(schema_features, schema_candidates)
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "feature_schema.json", original_schema_hash)

    candidate_features, duplicate_candidates = _copy_sources(tmp_path / "candidate")
    first_candidate = duplicate_candidates.read_text(encoding="utf-8").splitlines()[0]
    with duplicate_candidates.open("a", encoding="utf-8") as handle:
        handle.write(first_candidate + "\n")
    monkeypatch.setitem(
        EXPECTED_SOURCE_HASHES,
        "candidates.jsonl",
        _sha256(duplicate_candidates),
    )
    with pytest.raises(ValueError, match="Duplicate Candidate IDs"):
        load_split_sources(candidate_features, duplicate_candidates)
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "candidates.jsonl", original_candidate_hash)

    pair_features, pair_candidates = _copy_sources(tmp_path / "pair")
    feature_path = pair_features / "features.jsonl"
    first_record = feature_path.read_text(encoding="utf-8").splitlines()[0]
    with feature_path.open("a", encoding="utf-8") as handle:
        handle.write(first_record + "\n")
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "features.jsonl", _sha256(feature_path))
    with pytest.raises(ValueError, match="Duplicate Pair IDs"):
        load_split_sources(pair_features, pair_candidates)


def test_unknown_and_missing_candidate_references_fail(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    original_features_hash = EXPECTED_SOURCE_HASHES["features.jsonl"]
    unknown_features, unknown_candidates = _copy_sources(tmp_path / "unknown")
    unknown_path = unknown_features / "features.jsonl"
    unknown_records = [
        json.loads(line) for line in unknown_path.read_text(encoding="utf-8").splitlines()
    ]
    unknown_records[0]["candidate_id"] = "cand_9999"
    _replace_jsonl(unknown_path, unknown_records)
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "features.jsonl", _sha256(unknown_path))
    with pytest.raises(ValueError, match="Unknown Candidate reference"):
        load_split_sources(unknown_features, unknown_candidates)
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "features.jsonl", original_features_hash)

    missing_features, missing_candidates = _copy_sources(tmp_path / "missing")
    missing_path = missing_features / "features.jsonl"
    missing_records = [
        json.loads(line) for line in missing_path.read_text(encoding="utf-8").splitlines()
    ]
    for record in missing_records:
        if record["candidate_id"] == "cand_0001":
            record["candidate_id"] = "cand_0002"
    _replace_jsonl(missing_path, missing_records)
    monkeypatch.setitem(EXPECTED_SOURCE_HASHES, "features.jsonl", _sha256(missing_path))
    with pytest.raises(ValueError, match="Candidate missing from Feature records"):
        load_split_sources(missing_features, missing_candidates)


@pytest.mark.parametrize(
    "ratios",
    [
        (0.0, 0.5, 0.5),
        (float("nan"), 0.5, 0.5),
        (0.7, 0.2, 0.2),
        (0.6, 0.2, 0.2),
    ],
)
def test_invalid_or_unlocked_ratios_fail(ratios: tuple[float, float, float]) -> None:
    with pytest.raises(ValueError, match=r"ratios|sum|Unsupported"):
        _validate_ratios(*ratios)


def test_impossible_allocation_missing_assignment_and_failed_build_leave_no_output(
    loaded_sources: splitter_module.LoadedSplitSources,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    with pytest.raises(ValueError, match="expected exactly 180"):
        allocate_candidate_groups(_groups(loaded_sources)[:-1])
    duplicate_groups = list(_groups(loaded_sources))
    duplicate_groups[-1] = duplicate_groups[0]
    with pytest.raises(ValueError, match="Duplicate Candidate IDs"):
        allocate_candidate_groups(duplicate_groups)

    assignments = allocate_candidate_groups(_groups(loaded_sources))
    with pytest.raises(ValueError, match="Missing Candidate assignment"):
        partition_feature_records(loaded_sources.feature_records, assignments[:-1])

    output_dir = tmp_path / "must-not-exist"
    with pytest.raises(ValueError, match="Missing source files"):
        build_candidate_group_split(
            tmp_path / "missing-features",
            tmp_path / "missing-candidates.jsonl",
            output_dir,
        )
    assert not output_dir.exists()

    def reject_output(artifacts: object) -> None:
        assert artifacts
        raise ValueError("Output validation failure")

    monkeypatch.setattr(
        "smart_recruitment_ml.splits.splitter._validate_artifacts",
        reject_output,
    )
    validation_output = tmp_path / "validation-must-not-exist"
    with pytest.raises(ValueError, match="Output validation failure"):
        build_candidate_group_split(
            FEATURES_DIR,
            CANDIDATES_FILE,
            validation_output,
        )
    assert not validation_output.exists()
