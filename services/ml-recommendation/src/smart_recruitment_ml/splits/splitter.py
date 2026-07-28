"""Deterministic, domain-aware Candidate-group Dataset splitter."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import random
import shutil
import sys
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import TYPE_CHECKING, Final, TypeVar

from pydantic import BaseModel, ValidationError

from smart_recruitment_ml.schemas.features import (
    FeatureDatasetManifest,
    FeatureDatasetRecord,
    FeatureSchemaArtifact,
)
from smart_recruitment_ml.schemas.splits import (
    CandidateSplitAssignment,
    LockedTestGuard,
    SourceFileMetadata,
    SplitFileMetadata,
    SplitManifest,
    SplitName,
    SplitStatistics,
)
from smart_recruitment_ml.schemas.synthetic import Candidate

if TYPE_CHECKING:
    from collections.abc import Mapping, Sequence

SPLIT_VERSION: Final = "candidate-group-split-v1"
SPLIT_GENERATOR_VERSION: Final = "0.1.0"
SPLIT_SEED: Final = 20260724
TRAIN_RATIO: Final = 0.70
VALIDATION_RATIO: Final = 0.15
TEST_RATIO: Final = 0.15
SOURCE_DATASET_VERSION: Final = "synthetic-job-rec-1.0.0"
SOURCE_DATASET_SCHEMA_VERSION: Final = "synthetic-job-rec-schema-v1"
FEATURE_SCHEMA_VERSION: Final = "job-rec-features-v1"
FEATURE_PIPELINE_VERSION: Final = "0.1.0"
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F"
SPLIT_RELEASE_DATE: Final = "2026-07-24"
GROUP_KEY: Final = "candidate_id"
EXPECTED_CANDIDATE_COUNT: Final = 180
EXPECTED_RECORD_COUNT: Final = 10800
EXPECTED_RECORDS_PER_CANDIDATE: Final = 60
EXPECTED_FEATURE_COUNT: Final = 103
EXPECTED_DOMAIN_COUNT: Final = 12
EXPECTED_CANDIDATES_PER_DOMAIN: Final = 15
TRAIN: Final[SplitName] = "train"
VALIDATION: Final[SplitName] = "validation"
TEST: Final[SplitName] = "test"
EXPECTED_SPLIT_CANDIDATES: Final[dict[SplitName, int]] = {
    TRAIN: 126,
    VALIDATION: 27,
    TEST: 27,
}
EXPECTED_SPLIT_RECORDS: Final[dict[SplitName, int]] = {
    TRAIN: 7560,
    VALIDATION: 1620,
    TEST: 1620,
}
SPLIT_NAMES: Final[tuple[SplitName, ...]] = (TRAIN, VALIDATION, TEST)
EXPECTED_SOURCE_HASHES: Final = {
    "candidates.jsonl": "5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320",
    "feature_schema.json": "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0",
    "features.jsonl": "4e405d74714a6a9a79b3d6339b19b595cb67b8cbd6f589b721e662d274ebd18e",
    "feature_manifest.json": "dd0e79d6ff8d7441c73a0e284140e5588e01a317b64c1d00c741f145b899882e",
    "FEATURE_SCHEMA_CARD.md": "578959a86aafdc45cffcff570b1be8730fc3d93f66c729eb2a6c4813a3b4d771",
}


@dataclass(frozen=True, slots=True)
class LoadedSplitSources:
    """Validated immutable sources needed by the split builder."""

    candidates: tuple[Candidate, ...]
    feature_records: tuple[FeatureDatasetRecord, ...]
    feature_schema: FeatureSchemaArtifact
    feature_manifest: FeatureDatasetManifest
    source_files: tuple[SourceFileMetadata, ...]


@dataclass(frozen=True, slots=True)
class WrittenSplitDataset:
    """Summary returned after publishing a complete split directory."""

    output_dir: Path
    manifest: SplitManifest
    file_hashes: Mapping[str, str]


def _sha256(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def _file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _derived_seed(seed: int, domain: str) -> int:
    payload = f"{seed}\0{domain}".encode()
    return int.from_bytes(hashlib.sha256(payload).digest()[:8], "big")


def allocate_candidate_groups(
    candidate_groups: Sequence[tuple[str, str]],
    seed: int = SPLIT_SEED,
) -> tuple[CandidateSplitAssignment, ...]:
    """Assign Candidates using only ID, primary domain, and the fixed seed."""
    candidate_ids = [candidate_id for candidate_id, _ in candidate_groups]
    if len(candidate_ids) != len(set(candidate_ids)):
        raise ValueError("Duplicate Candidate IDs")
    if len(candidate_groups) != EXPECTED_CANDIDATE_COUNT:
        raise ValueError("Impossible allocation: expected exactly 180 Candidates")

    by_domain: dict[str, list[str]] = defaultdict(list)
    for candidate_id, primary_domain in candidate_groups:
        if not candidate_id or not primary_domain:
            raise ValueError("Candidate ID and primary domain are required")
        by_domain[primary_domain].append(candidate_id)
    domains = sorted(by_domain)
    if len(domains) != EXPECTED_DOMAIN_COUNT:
        raise ValueError("Impossible allocation: expected exactly 12 domains")
    if any(len(ids) != EXPECTED_CANDIDATES_PER_DOMAIN for ids in by_domain.values()):
        raise ValueError("Impossible allocation: expected 15 Candidates per domain")

    rotation = seed % len(domains)
    rotated_domains = domains[rotation:] + domains[:rotation]
    extra_split: dict[str, SplitName] = {
        **{domain: "train" for domain in rotated_domains[:6]},
        **{domain: "validation" for domain in rotated_domains[6:9]},
        **{domain: "test" for domain in rotated_domains[9:]},
    }
    assignments: list[CandidateSplitAssignment] = []
    for domain in domains:
        shuffled = sorted(by_domain[domain])
        random.Random(_derived_seed(seed, domain)).shuffle(shuffled)
        split_by_position: list[SplitName] = [
            *([TRAIN] * 10),
            *([VALIDATION] * 2),
            *([TEST] * 2),
            extra_split[domain],
        ]
        assignments.extend(
            CandidateSplitAssignment(
                candidate_id=candidate_id,
                primary_domain=domain,
                split=split,
            )
            for candidate_id, split in zip(shuffled, split_by_position, strict=True)
        )
    ordered = tuple(sorted(assignments, key=lambda item: item.candidate_id))
    _validate_assignments(ordered)
    return ordered


def _validate_assignments(assignments: Sequence[CandidateSplitAssignment]) -> None:
    ids_by_split = {
        split: {item.candidate_id for item in assignments if item.split == split}
        for split in SPLIT_NAMES
    }
    for split, expected in EXPECTED_SPLIT_CANDIDATES.items():
        if len(ids_by_split[split]) != expected:
            raise ValueError(f"{split} Candidate count mismatch")
    if (
        ids_by_split["train"] & ids_by_split["validation"]
        or ids_by_split["train"] & ids_by_split["test"]
        or ids_by_split["validation"] & ids_by_split["test"]
    ):
        raise ValueError("Candidate group leakage detected")
    domains_by_split = {
        split: {item.primary_domain for item in assignments if item.split == split}
        for split in SPLIT_NAMES
    }
    if any(len(domains) != EXPECTED_DOMAIN_COUNT for domains in domains_by_split.values()):
        raise ValueError("Every split must contain all 12 domains")
    domain_counts = {
        split: Counter(item.primary_domain for item in assignments if item.split == split)
        for split in SPLIT_NAMES
    }
    all_domains = sorted({item.primary_domain for item in assignments})
    allowed = {(11, 2, 2), (10, 3, 2), (10, 2, 3)}
    for domain in all_domains:
        counts = tuple(domain_counts[split][domain] for split in SPLIT_NAMES)
        if counts not in allowed:
            raise ValueError(f"Invalid per-domain allocation for {domain}")
    extra_counts = {
        "train": sum(domain_counts["train"][domain] == 11 for domain in all_domains),
        "validation": sum(domain_counts["validation"][domain] == 3 for domain in all_domains),
        "test": sum(domain_counts["test"][domain] == 3 for domain in all_domains),
    }
    if extra_counts != {"train": 6, "validation": 3, "test": 3}:
        raise ValueError("Domain extras allocation mismatch")


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


def _source_metadata(path: str, file_path: Path, record_count: int) -> SourceFileMetadata:
    return SourceFileMetadata(
        path=path,
        record_count=record_count,
        size_bytes=file_path.stat().st_size,
        sha256=_file_sha256(file_path),
    )


def load_split_sources(features_dir: Path, candidates_file: Path) -> LoadedSplitSources:
    """Validate every locked source before assignment or output construction."""
    paths = {
        "feature_schema.json": features_dir / "feature_schema.json",
        "features.jsonl": features_dir / "features.jsonl",
        "feature_manifest.json": features_dir / "manifest.json",
        "FEATURE_SCHEMA_CARD.md": features_dir / "FEATURE_SCHEMA_CARD.md",
        "candidates.jsonl": candidates_file,
    }
    missing = [name for name, path in paths.items() if not path.is_file()]
    if missing:
        raise ValueError(f"Missing source files: {', '.join(sorted(missing))}")
    actual_hashes = {name: _file_sha256(path) for name, path in paths.items()}
    for name, expected_hash in EXPECTED_SOURCE_HASHES.items():
        if actual_hashes[name] != expected_hash:
            raise ValueError(f"Source hash mismatch: {name}")

    try:
        feature_schema = FeatureSchemaArtifact.model_validate_json(
            paths["feature_schema.json"].read_text(encoding="utf-8"),
        )
        feature_manifest = FeatureDatasetManifest.model_validate_json(
            paths["feature_manifest.json"].read_text(encoding="utf-8"),
        )
    except ValidationError as error:
        raise ValueError("Feature source metadata has an invalid schema") from error
    if feature_schema.feature_schema_version != FEATURE_SCHEMA_VERSION:
        raise ValueError("Unsupported feature schema")
    if feature_schema.feature_pipeline_version != FEATURE_PIPELINE_VERSION:
        raise ValueError("Unsupported Feature Pipeline version")
    if feature_schema.source_dataset_version != SOURCE_DATASET_VERSION:
        raise ValueError("Source Dataset version mismatch")
    if feature_schema.source_dataset_schema_version != SOURCE_DATASET_SCHEMA_VERSION:
        raise ValueError("Source Dataset schema version mismatch")
    if feature_schema.source_revision != SOURCE_REVISION:
        raise ValueError("Source revision mismatch")
    if feature_schema.architecture_sha256 != ARCHITECTURE_SHA256:
        raise ValueError("Source Architecture hash mismatch")
    if feature_manifest.feature_schema_sha256 != actual_hashes["feature_schema.json"]:
        raise ValueError("Feature manifest schema hash mismatch")
    if (
        feature_manifest.record_count != EXPECTED_RECORD_COUNT
        or feature_manifest.candidate_count != EXPECTED_CANDIDATE_COUNT
        or feature_manifest.feature_count != EXPECTED_FEATURE_COUNT
    ):
        raise ValueError("Feature manifest count mismatch")

    candidates = _read_jsonl(candidates_file, Candidate)
    feature_records = _read_jsonl(paths["features.jsonl"], FeatureDatasetRecord)
    candidate_ids = [candidate.candidate_id for candidate in candidates]
    pair_ids = [record.pair_id for record in feature_records]
    if len(candidate_ids) != len(set(candidate_ids)):
        raise ValueError("Duplicate Candidate IDs")
    if len(pair_ids) != len(set(pair_ids)):
        raise ValueError("Duplicate Pair IDs")
    if len(candidates) != EXPECTED_CANDIDATE_COUNT:
        raise ValueError("Candidate count mismatch")
    if len(feature_records) != EXPECTED_RECORD_COUNT:
        raise ValueError("Feature record count mismatch")
    source_candidate_ids = set(candidate_ids)
    referenced_candidate_ids = {record.candidate_id for record in feature_records}
    unknown = referenced_candidate_ids - source_candidate_ids
    missing_references = source_candidate_ids - referenced_candidate_ids
    if unknown:
        raise ValueError("Unknown Candidate reference")
    if missing_references:
        raise ValueError("Candidate missing from Feature records")
    record_counts = Counter(record.candidate_id for record in feature_records)
    if any(count != EXPECTED_RECORDS_PER_CANDIDATE for count in record_counts.values()):
        raise ValueError("Candidate Feature record count mismatch")
    if any(record.feature_schema_version != FEATURE_SCHEMA_VERSION for record in feature_records):
        raise ValueError("Feature record schema version mismatch")
    if any(len(record.feature_values) != EXPECTED_FEATURE_COUNT for record in feature_records):
        raise ValueError("Feature vector length mismatch")
    if any(
        not math.isfinite(value) for record in feature_records for value in record.feature_values
    ):
        raise ValueError("Feature records contain NaN or Infinity")

    source_files = (
        _source_metadata("synthetic/candidates.jsonl", candidates_file, len(candidates)),
        _source_metadata("features/FEATURE_SCHEMA_CARD.md", paths["FEATURE_SCHEMA_CARD.md"], 1),
        _source_metadata("features/feature_schema.json", paths["feature_schema.json"], 1),
        _source_metadata("features/features.jsonl", paths["features.jsonl"], len(feature_records)),
        _source_metadata("features/manifest.json", paths["feature_manifest.json"], 1),
    )
    return LoadedSplitSources(
        candidates=candidates,
        feature_records=feature_records,
        feature_schema=feature_schema,
        feature_manifest=feature_manifest,
        source_files=source_files,
    )


def partition_feature_records(
    records: Sequence[FeatureDatasetRecord],
    assignments: Sequence[CandidateSplitAssignment],
) -> dict[SplitName, tuple[FeatureDatasetRecord, ...]]:
    """Partition unchanged Feature records using external Candidate assignments."""
    split_by_candidate = {item.candidate_id: item.split for item in assignments}
    partitions: dict[SplitName, list[FeatureDatasetRecord]] = {
        "train": [],
        "validation": [],
        "test": [],
    }
    for record in records:
        split = split_by_candidate.get(record.candidate_id)
        if split is None:
            raise ValueError(f"Missing Candidate assignment: {record.candidate_id}")
        partitions[split].append(record)
    return {
        split: tuple(
            sorted(
                split_records,
                key=lambda item: (item.candidate_id, item.job_id, item.pair_id),
            ),
        )
        for split, split_records in partitions.items()
    }


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


def _jsonl_bytes(records: Sequence[BaseModel]) -> bytes:
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


def _file_metadata(path: str, content: bytes, record_count: int) -> SplitFileMetadata:
    return SplitFileMetadata(
        path=path,
        record_count=record_count,
        size_bytes=len(content),
        sha256=_sha256(content),
    )


def _candidate_ids_hash(candidate_ids: Sequence[str]) -> str:
    content = ("\n".join(sorted(candidate_ids)) + "\n").encode()
    return _sha256(content)


def _split_card() -> bytes:
    card = f"""# Candidate-Grouped Split Card

## Identity

- Split: `{SPLIT_VERSION}`
- Generator: `{SPLIT_GENERATOR_VERSION}`
- Source Dataset: `{SOURCE_DATASET_VERSION}`
- Feature Schema: `{FEATURE_SCHEMA_VERSION}`
- Fixed seed: `{SPLIT_SEED}`
- Group key: `{GROUP_KEY}`
- Release date: `{SPLIT_RELEASE_DATE}` (fixed release metadata)

## Purpose and exact allocation

This artifact partitions the Feature Dataset by Candidate, not by
Candidate-Job pair. All 60 records for a Candidate stay in exactly one split.
The exact allocation is Train 126 Candidates / 7,560 records, Validation 27 /
1,620, and locked Test 27 / 1,620.

Each of the 12 domains starts with a 10/2/2 Candidate allocation. One remaining
Candidate per domain is assigned through a deterministic seed-derived domain
rotation: six extras to Train, three to Validation, and three to Test. Candidate
IDs are sorted before a domain-local deterministic shuffle. Labels, feature
values, scenarios, rationales, noise, pair ordering, Job IDs, and metrics are
not inputs to assignment.

Jobs may occur in more than one split; Candidate groups may not. Train supports
Phase 7+ fitting and analysis. Validation supports model selection without
opening Test.

## Locked Test policy

Test is cryptographically locked at Phase 6. Phases 7-9 must not use it for
baseline comparison, feature decisions, hyperparameter tuning, early stopping,
calibration, threshold selection, or promotion decisions. Phase 10 alone may
perform the final locked evaluation. This phase computes only structural
counts, integrity hashes, schema checks, and overlap checks.

The Test Candidate hash is SHA-256 over ascending Candidate IDs, one UTF-8 ID
per line, LF endings, and a final newline. The Candidate list itself is not
printed here.

## Leakage, privacy, and limitations

Candidate and Pair intersections are zero and their unions equal the complete
sources. Split files preserve the original Feature record schema and values.
Assignments contain only Candidate ID, professional primary domain, and split.
No raw Candidate/Job facts, sensitive data, timestamps, or audit metadata are
added.

The data and groups are synthetic. Domain-aware allocation does not guarantee
production balance, Jobs intentionally repeat across splits, and Validation
and Test contain only 27 Candidate groups each.

## Reproducibility

From the repository root:

```powershell
& services/ml-recommendation/.venv/Scripts/python.exe -m smart_recruitment_ml.splits `
    --features-dir services/ml-recommendation/data/features/v1 `
    --candidates-file services/ml-recommendation/data/synthetic/v1/candidates.jsonl `
    --output-dir services/ml-recommendation/data/splits/v1 `
    --split-version {SPLIT_VERSION} `
    --generator-version {SPLIT_GENERATOR_VERSION} `
    --seed {SPLIT_SEED} `
    --train-ratio {TRAIN_RATIO:.2f} `
    --validation-ratio {VALIDATION_RATIO:.2f} `
    --test-ratio {TEST_RATIO:.2f} `
    --source-revision {SOURCE_REVISION} `
    --architecture-sha256 {ARCHITECTURE_SHA256}
```

See `manifest.json` for output hashes. No baseline evaluation, training,
calibration, trained Model, or inference is included.
"""
    return card.replace("\r\n", "\n").encode()


def _overlap_counts(
    partitions: Mapping[SplitName, Sequence[FeatureDatasetRecord]],
    field: str,
) -> dict[str, int]:
    values = {
        split: {str(getattr(record, field)) for record in partitions[split]}
        for split in SPLIT_NAMES
    }
    prefix = "candidate" if field == "candidate_id" else "pair"
    return {
        f"{prefix}_train_validation": len(values["train"] & values["validation"]),
        f"{prefix}_train_test": len(values["train"] & values["test"]),
        f"{prefix}_validation_test": len(values["validation"] & values["test"]),
    }


def _validate_partitions(
    sources: LoadedSplitSources,
    assignments: Sequence[CandidateSplitAssignment],
    partitions: Mapping[SplitName, Sequence[FeatureDatasetRecord]],
) -> None:
    assignment_ids = {item.candidate_id for item in assignments}
    source_candidate_ids = {item.candidate_id for item in sources.candidates}
    if assignment_ids != source_candidate_ids:
        raise ValueError("Assignment Candidate union mismatch")
    for split in SPLIT_NAMES:
        records = partitions[split]
        candidate_ids = {record.candidate_id for record in records}
        if len(candidate_ids) != EXPECTED_SPLIT_CANDIDATES[split]:
            raise ValueError(f"{split} Candidate output count mismatch")
        if len(records) != EXPECTED_SPLIT_RECORDS[split]:
            raise ValueError(f"{split} record output count mismatch")
        counts = Counter(record.candidate_id for record in records)
        if any(count != EXPECTED_RECORDS_PER_CANDIDATE for count in counts.values()):
            raise ValueError(f"{split} Candidate group was split")
    candidate_overlaps = _overlap_counts(partitions, "candidate_id")
    pair_overlaps = _overlap_counts(partitions, "pair_id")
    if any(candidate_overlaps.values()) or any(pair_overlaps.values()):
        raise ValueError("Cross-split leakage detected")
    output_pair_ids = {record.pair_id for split in SPLIT_NAMES for record in partitions[split]}
    source_by_pair = {record.pair_id: record for record in sources.feature_records}
    if output_pair_ids != set(source_by_pair):
        raise ValueError("Feature record union mismatch")
    for split in SPLIT_NAMES:
        for record in partitions[split]:
            if record != source_by_pair[record.pair_id]:
                raise ValueError("Feature record changed during splitting")


def _build_split_artifacts(
    sources: LoadedSplitSources,
    seed: int,
) -> tuple[dict[str, bytes], SplitManifest]:
    assignments = allocate_candidate_groups(
        tuple(
            (candidate.candidate_id, candidate.primary_domain) for candidate in sources.candidates
        ),
        seed,
    )
    partitions = partition_feature_records(sources.feature_records, assignments)
    _validate_partitions(sources, assignments, partitions)

    split_bytes = {f"{split}.jsonl": _jsonl_bytes(partitions[split]) for split in SPLIT_NAMES}
    assignments_bytes = _jsonl_bytes(assignments)
    test_candidate_ids = sorted(
        {record.candidate_id for record in partitions["test"]},
    )
    test_guard = LockedTestGuard(
        split_version=SPLIT_VERSION,
        split_seed=seed,
        test_locked=True,
        group_key="candidate_id",
        test_candidate_count=EXPECTED_SPLIT_CANDIDATES["test"],
        test_record_count=EXPECTED_SPLIT_RECORDS["test"],
        test_candidate_ids_sha256=_candidate_ids_hash(test_candidate_ids),
        test_file_sha256=_sha256(split_bytes["test.jsonl"]),
        source_features_sha256=EXPECTED_SOURCE_HASHES["features.jsonl"],
        feature_schema_sha256=EXPECTED_SOURCE_HASHES["feature_schema.json"],
        source_revision=SOURCE_REVISION,
        created_for_phase=6,
        allowed_future_use=[
            "phase_10_final_locked_evaluation_only",
            "phases_7_to_9_structural_integrity_checks_only",
            "phases_7_to_9_no_baseline_comparison",
            "phases_7_to_9_no_feature_decisions",
            "phases_7_to_9_no_hyperparameter_tuning",
            "phases_7_to_9_no_early_stopping",
            "phases_7_to_9_no_calibration",
            "phases_7_to_9_no_threshold_selection",
            "phases_7_to_9_no_promotion_decision",
        ],
        prohibited_before_phase=10,
    )
    test_lock_bytes = _json_bytes(test_guard)
    card_bytes = _split_card()
    artifacts_without_manifest = {
        **split_bytes,
        "assignments.jsonl": assignments_bytes,
        "test_lock.json": test_lock_bytes,
        "SPLIT_CARD.md": card_bytes,
    }
    record_counts = {
        "train.jsonl": len(partitions["train"]),
        "validation.jsonl": len(partitions["validation"]),
        "test.jsonl": len(partitions["test"]),
        "assignments.jsonl": len(assignments),
        "test_lock.json": 1,
        "SPLIT_CARD.md": 1,
    }
    output_files = [
        _file_metadata(name, content, record_counts[name])
        for name, content in sorted(artifacts_without_manifest.items())
    ]
    metadata_by_name = {item.path: item for item in output_files}
    assignment_by_id = {item.candidate_id: item for item in assignments}
    domain_distribution = {
        split: dict(
            sorted(
                Counter(
                    assignment_by_id[candidate_id].primary_domain
                    for candidate_id in {record.candidate_id for record in partitions[split]}
                ).items(),
            ),
        )
        for split in SPLIT_NAMES
    }
    label_distribution = {
        split: dict(
            sorted(Counter(str(record.relevance_label) for record in partitions[split]).items()),
        )
        for split in SPLIT_NAMES
    }
    split_counts = {
        split: SplitStatistics(
            candidate_count=len({record.candidate_id for record in partitions[split]}),
            record_count=len(partitions[split]),
            unique_job_count=len({record.job_id for record in partitions[split]}),
        )
        for split in SPLIT_NAMES
    }
    manifest = SplitManifest(
        split_version=SPLIT_VERSION,
        split_generator_version=SPLIT_GENERATOR_VERSION,
        split_seed=seed,
        split_release_date=SPLIT_RELEASE_DATE,
        deterministic=True,
        group_key="candidate_id",
        train_ratio=TRAIN_RATIO,
        validation_ratio=VALIDATION_RATIO,
        test_ratio=TEST_RATIO,
        source_dataset_version=SOURCE_DATASET_VERSION,
        source_dataset_schema_version=SOURCE_DATASET_SCHEMA_VERSION,
        feature_schema_version=FEATURE_SCHEMA_VERSION,
        feature_pipeline_version=FEATURE_PIPELINE_VERSION,
        source_revision=SOURCE_REVISION,
        architecture_sha256=ARCHITECTURE_SHA256,
        source_files=list(sources.source_files),
        candidate_count=EXPECTED_CANDIDATE_COUNT,
        record_count=EXPECTED_RECORD_COUNT,
        feature_count=EXPECTED_FEATURE_COUNT,
        split_counts=split_counts,
        candidate_domain_distribution=domain_distribution,
        label_distribution=label_distribution,
        job_coverage={split: split_counts[split].unique_job_count for split in SPLIT_NAMES},
        candidate_overlap_counts=_overlap_counts(partitions, "candidate_id"),
        pair_overlap_counts=_overlap_counts(partitions, "pair_id"),
        test_locked=True,
        test_lock_file=metadata_by_name["test_lock.json"],
        generation_config={
            "split_version": SPLIT_VERSION,
            "generator_version": SPLIT_GENERATOR_VERSION,
            "seed": seed,
            "train_ratio": TRAIN_RATIO,
            "validation_ratio": VALIDATION_RATIO,
            "test_ratio": TEST_RATIO,
            "group_key": GROUP_KEY,
            "source_revision": SOURCE_REVISION,
            "architecture_sha256": ARCHITECTURE_SHA256,
        },
        intended_use=[
            "train_for_future_phase_7_plus_fitting",
            "validation_for_model_selection_without_test_access",
            "test_for_phase_10_final_locked_evaluation_only",
        ],
        limitations=[
            "synthetic_candidate_groups",
            "domain_aware_allocation_does_not_guarantee_production_balance",
            "jobs_repeat_across_splits",
            "validation_and_test_have_27_candidate_groups_each",
            "no_baseline_metrics_training_or_model",
        ],
        output_files=output_files,
    )
    artifacts = dict(artifacts_without_manifest)
    artifacts["manifest.json"] = _json_bytes(manifest)
    _validate_artifacts(artifacts)
    return artifacts, manifest


def _validate_artifacts(artifacts: Mapping[str, bytes]) -> None:
    expected = {
        "train.jsonl",
        "validation.jsonl",
        "test.jsonl",
        "assignments.jsonl",
        "test_lock.json",
        "manifest.json",
        "SPLIT_CARD.md",
    }
    if set(artifacts) != expected:
        raise ValueError("Output artifact inventory mismatch")
    for name, expected_count in EXPECTED_SPLIT_RECORDS.items():
        records = artifacts[f"{name}.jsonl"].decode().splitlines()
        if len(records) != expected_count:
            raise ValueError(f"{name} serialized record count mismatch")
        for line in records:
            FeatureDatasetRecord.model_validate_json(line)
    assignments = artifacts["assignments.jsonl"].decode().splitlines()
    if len(assignments) != EXPECTED_CANDIDATE_COUNT:
        raise ValueError("Serialized assignment count mismatch")
    for line in assignments:
        CandidateSplitAssignment.model_validate_json(line)
    guard = LockedTestGuard.model_validate_json(artifacts["test_lock.json"])
    if guard.test_file_sha256 != _sha256(artifacts["test.jsonl"]):
        raise ValueError("Test lock file hash mismatch")
    SplitManifest.model_validate_json(artifacts["manifest.json"])


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


def build_candidate_group_split(
    features_dir: Path,
    candidates_file: Path,
    output_dir: Path,
    *,
    seed: int = SPLIT_SEED,
) -> WrittenSplitDataset:
    """Validate sources and atomically publish all seven split artifacts."""
    sources = load_split_sources(features_dir, candidates_file)
    artifacts, manifest = _build_split_artifacts(sources, seed)
    _publish_atomically(output_dir, artifacts)
    return WrittenSplitDataset(
        output_dir=output_dir,
        manifest=manifest,
        file_hashes={name: _sha256(content) for name, content in sorted(artifacts.items())},
    )


def _validate_ratios(train: float, validation: float, test: float) -> None:
    ratios = (train, validation, test)
    if any(not math.isfinite(value) or value <= 0.0 or value >= 1.0 for value in ratios):
        raise ValueError("Split ratios must be finite values strictly between 0 and 1")
    if not math.isclose(sum(ratios), 1.0, rel_tol=0.0, abs_tol=1e-12):
        raise ValueError("Split ratios must sum to 1.0")
    if ratios != (TRAIN_RATIO, VALIDATION_RATIO, TEST_RATIO):
        raise ValueError("Unsupported locked split ratios")


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Build deterministic Candidate-grouped Train/Validation/Test splits.",
    )
    parser.add_argument(
        "--features-dir",
        type=Path,
        default=Path("services/ml-recommendation/data/features/v1"),
    )
    parser.add_argument(
        "--candidates-file",
        type=Path,
        default=Path("services/ml-recommendation/data/synthetic/v1/candidates.jsonl"),
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("services/ml-recommendation/data/splits/v1"),
    )
    parser.add_argument("--split-version", default=SPLIT_VERSION)
    parser.add_argument("--generator-version", default=SPLIT_GENERATOR_VERSION)
    parser.add_argument("--seed", type=int, default=SPLIT_SEED)
    parser.add_argument("--train-ratio", type=float, default=TRAIN_RATIO)
    parser.add_argument("--validation-ratio", type=float, default=VALIDATION_RATIO)
    parser.add_argument("--test-ratio", type=float, default=TEST_RATIO)
    parser.add_argument("--source-revision", default=SOURCE_REVISION)
    parser.add_argument("--architecture-sha256", default=ARCHITECTURE_SHA256)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    """CLI entry point returning nonzero on contract or integrity failure."""
    args = _parser().parse_args(argv)
    locked_arguments = {
        "split version": (args.split_version, SPLIT_VERSION),
        "generator version": (args.generator_version, SPLIT_GENERATOR_VERSION),
        "source revision": (args.source_revision, SOURCE_REVISION),
        "Architecture hash": (args.architecture_sha256, ARCHITECTURE_SHA256),
    }
    try:
        for name, (actual, expected) in locked_arguments.items():
            if actual != expected:
                raise ValueError(f"Locked {name} mismatch")
        _validate_ratios(args.train_ratio, args.validation_ratio, args.test_ratio)
        written = build_candidate_group_split(
            args.features_dir,
            args.candidates_file,
            args.output_dir,
            seed=args.seed,
        )
    except (OSError, ValidationError, ValueError) as error:
        print(f"Split generation failed: {error}", file=sys.stderr)
        return 2
    counts = written.manifest.split_counts
    print(
        "Generated Candidate groups "
        f"train={counts['train'].candidate_count}/{counts['train'].record_count}, "
        f"validation={counts['validation'].candidate_count}/"
        f"{counts['validation'].record_count}, "
        f"test={counts['test'].candidate_count}/{counts['test'].record_count} "
        f"at {written.output_dir}",
    )
    return 0
