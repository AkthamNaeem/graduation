"""Single-pass loading and validation for the Phase 10 Locked Test."""

from __future__ import annotations

import json
import math
from dataclasses import dataclass
from typing import TYPE_CHECKING, Final

import numpy as np

if TYPE_CHECKING:
    from collections.abc import Callable
    from pathlib import Path

    from numpy.typing import NDArray

TEST_SHA256: Final = "79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05"
FEATURE_SCHEMA_VERSION: Final = "job-rec-features-v1"
FEATURE_COUNT: Final = 103
TEST_RECORD_COUNT: Final = 1620
TEST_CANDIDATE_COUNT: Final = 27


@dataclass(frozen=True)
class LockedTestDataset:
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


def load_locked_test(
    path: Path,
    *,
    expected_sha256: str,
    sha256_file: Callable[[Path], str],
    allowed_candidate_ids: set[str],
    prohibited_candidate_ids: set[str],
    prohibited_pair_ids: set[str] | None = None,
) -> LockedTestDataset:
    """Parse the Locked Test exactly once after the caller completes every gate."""
    digest = sha256_file(path)
    if expected_sha256 != TEST_SHA256 or digest != TEST_SHA256:
        raise ValueError("Locked Test SHA-256 mismatch.")

    raw_records: list[dict[str, object]] = []
    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            if not line.strip():
                raise ValueError(f"Blank Locked Test line {line_number}.")
            value = json.loads(line)
            if not isinstance(value, dict):
                raise ValueError(f"Locked Test object expected at line {line_number}.")
            raw_records.append(value)
    if len(raw_records) != TEST_RECORD_COUNT:
        raise ValueError("Locked Test must contain exactly 1,620 records.")

    order_keys = [
        (str(record["candidate_id"]), str(record["job_id"]), str(record["pair_id"]))
        for record in raw_records
    ]
    if order_keys != sorted(order_keys):
        raise ValueError("Locked Test records are not in Candidate/Job/Pair order.")

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
            raise ValueError(f"Duplicate Locked Test Pair ID: {pair_id}")
        if prohibited_pair_ids is not None and pair_id in prohibited_pair_ids:
            raise ValueError(f"Train/Validation Pair ID overlap: {pair_id}")
        seen_pairs.add(pair_id)
        if record.get("feature_schema_version") != FEATURE_SCHEMA_VERSION:
            raise ValueError(f"Feature schema mismatch for {pair_id}.")
        label = record["relevance_label"]
        if not isinstance(label, int) or isinstance(label, bool) or label not in {0, 1, 2, 3}:
            raise ValueError(f"Invalid relevance label for {pair_id}.")
        vector_value = record["feature_values"]
        if not isinstance(vector_value, list) or len(vector_value) != FEATURE_COUNT:
            raise ValueError(f"Feature count mismatch for {pair_id}.")
        vector = [float(value) for value in vector_value]
        if not all(math.isfinite(value) for value in vector):
            raise ValueError(f"Non-finite feature for {pair_id}.")
        if candidate_id != last_candidate:
            if candidate_id in closed_candidates:
                raise ValueError(f"Non-contiguous Candidate group: {candidate_id}.")
            if last_candidate is not None:
                closed_candidates.add(last_candidate)
            candidate_order.append(candidate_id)
            last_candidate = candidate_id
        pair_ids.append(pair_id)
        candidate_ids.append(candidate_id)
        job_ids.append(job_id)
        labels.append(float(label))
        features.append(vector)

    candidate_set = set(candidate_order)
    if (
        len(candidate_order) != TEST_CANDIDATE_COUNT
        or candidate_set != allowed_candidate_ids
        or candidate_set.intersection(prohibited_candidate_ids)
    ):
        raise ValueError("Locked Test Candidate assignment or overlap mismatch.")
    candidate_to_qid = {candidate_id: index for index, candidate_id in enumerate(candidate_order)}
    qid = np.asarray([candidate_to_qid[value] for value in candidate_ids], dtype=np.int32)
    group_sizes = tuple(
        int(np.count_nonzero(qid == group_id)) for group_id in range(TEST_CANDIDATE_COUNT)
    )
    X = np.asarray(features, dtype=np.float32)
    y = np.asarray(labels, dtype=np.float32)
    if (
        set(group_sizes) != {60}
        or X.shape != (TEST_RECORD_COUNT, FEATURE_COUNT)
        or y.shape != (TEST_RECORD_COUNT,)
        or tuple(np.unique(qid).tolist()) != tuple(range(TEST_CANDIDATE_COUNT))
        or np.any(np.diff(qid) < 0)
    ):
        raise ValueError("Locked Test shape or qid contract mismatch.")
    return LockedTestDataset(
        pair_ids=tuple(pair_ids),
        candidate_ids=tuple(candidate_ids),
        job_ids=tuple(job_ids),
        X=X,
        y=y,
        qid=qid,
        group_sizes=group_sizes,
    )
