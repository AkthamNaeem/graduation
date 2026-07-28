"""Group-aware loading and validation for Train and Validation only."""

from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass
from typing import TYPE_CHECKING, Final, Literal

import numpy as np

if TYPE_CHECKING:
    from pathlib import Path

    from numpy.typing import NDArray


FEATURE_COUNT: Final[Literal[103]] = 103
FEATURE_SCHEMA_VERSION: Final[Literal["job-rec-features-v1"]] = "job-rec-features-v1"
LOCKED_TEST_SHA256: Final = "79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05"


@dataclass(frozen=True)
class RankingDataset:
    split: Literal["train", "validation"]
    pair_ids: tuple[str, ...]
    candidate_ids: tuple[str, ...]
    job_ids: tuple[str, ...]
    X: NDArray[np.float32]
    y: NDArray[np.float32]
    qid: NDArray[np.int32]
    group_sizes: tuple[int, ...]

    @property
    def record_count(self) -> int:
        return len(self.pair_ids)

    @property
    def candidate_count(self) -> int:
        return len(self.group_sizes)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def reject_locked_test(path: Path) -> None:
    if path.name.casefold() == "test.jsonl":
        raise ValueError("Locked Test path is prohibited before Phase 10.")
    if sha256_file(path) == LOCKED_TEST_SHA256:
        raise ValueError("Locked Test content hash is prohibited before Phase 10.")


def load_ranking_dataset(
    path: Path,
    *,
    split: Literal["train", "validation"],
    expected_sha256: str,
    expected_records: int,
    expected_candidates: int,
) -> RankingDataset:
    reject_locked_test(path)
    actual_hash = sha256_file(path)
    if actual_hash != expected_sha256:
        raise ValueError(f"{split} SHA-256 mismatch: expected {expected_sha256}, got {actual_hash}")

    raw_records: list[dict[str, object]] = []
    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            if not line.strip():
                raise ValueError(f"Blank JSONL line at {path}:{line_number}")
            value = json.loads(line)
            if not isinstance(value, dict):
                raise ValueError(f"JSON object expected at {path}:{line_number}")
            raw_records.append(value)
    if len(raw_records) != expected_records:
        raise ValueError(f"Unexpected {split} record count: {len(raw_records)}")

    order_keys = [
        (
            str(record["candidate_id"]),
            str(record["job_id"]),
            str(record["pair_id"]),
        )
        for record in raw_records
    ]
    if order_keys != sorted(order_keys):
        raise ValueError(f"{split} records are not in Candidate/Job/Pair order.")

    pair_ids: list[str] = []
    candidate_ids: list[str] = []
    job_ids: list[str] = []
    labels: list[float] = []
    features: list[list[float]] = []
    seen_pairs: set[str] = set()
    candidate_order: list[str] = []
    last_candidate: str | None = None
    closed_candidates: set[str] = set()

    for record in raw_records:
        pair_id = str(record["pair_id"])
        candidate_id = str(record["candidate_id"])
        job_id = str(record["job_id"])
        if pair_id in seen_pairs:
            raise ValueError(f"Duplicate Pair ID: {pair_id}")
        seen_pairs.add(pair_id)
        if record.get("feature_schema_version") != FEATURE_SCHEMA_VERSION:
            raise ValueError(f"Feature schema mismatch for {pair_id}")
        label_value = record["relevance_label"]
        if not isinstance(label_value, int) or isinstance(label_value, bool):
            raise ValueError(f"Integer label expected for {pair_id}")
        label = label_value
        if label not in {0, 1, 2, 3}:
            raise ValueError(f"Label outside 0..3 for {pair_id}")
        vector_value = record["feature_values"]
        if not isinstance(vector_value, list) or len(vector_value) != FEATURE_COUNT:
            raise ValueError(f"Feature count must be 103 for {pair_id}")
        vector = [float(value) for value in vector_value]
        if not all(math.isfinite(value) for value in vector):
            raise ValueError(f"Non-finite feature for {pair_id}")
        if candidate_id != last_candidate:
            if candidate_id in closed_candidates:
                raise ValueError(f"Candidate group is not contiguous: {candidate_id}")
            if last_candidate is not None:
                closed_candidates.add(last_candidate)
            candidate_order.append(candidate_id)
            last_candidate = candidate_id

        pair_ids.append(pair_id)
        candidate_ids.append(candidate_id)
        job_ids.append(job_id)
        labels.append(float(label))
        features.append(vector)

    if candidate_order != sorted(candidate_order):
        raise ValueError(f"{split} Candidate groups are not ascending.")
    if len(candidate_order) != expected_candidates:
        raise ValueError(f"Unexpected {split} Candidate group count.")
    candidate_to_qid = {candidate_id: qid for qid, candidate_id in enumerate(candidate_order)}
    qid_values = np.asarray(
        [candidate_to_qid[candidate_id] for candidate_id in candidate_ids],
        dtype=np.int32,
    )
    group_sizes = tuple(
        int(np.count_nonzero(qid_values == qid)) for qid in range(expected_candidates)
    )
    if set(group_sizes) != {60}:
        raise ValueError(f"Every {split} Candidate group must contain 60 records.")
    if np.any(np.diff(qid_values) < 0):
        raise ValueError(f"{split} qid must be nondecreasing.")
    if tuple(np.unique(qid_values).tolist()) != tuple(range(expected_candidates)):
        raise ValueError(f"{split} qid must be contiguous from zero.")

    X = np.asarray(features, dtype=np.float32)
    y = np.asarray(labels, dtype=np.float32)
    if X.shape != (expected_records, FEATURE_COUNT):
        raise ValueError(f"Unexpected {split} X shape: {X.shape}")
    return RankingDataset(
        split=split,
        pair_ids=tuple(pair_ids),
        candidate_ids=tuple(candidate_ids),
        job_ids=tuple(job_ids),
        X=X,
        y=y,
        qid=qid_values,
        group_sizes=group_sizes,
    )
