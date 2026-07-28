"""Locked Test single-pass data-contract validation on synthetic fixtures."""

from __future__ import annotations

import json
from typing import TYPE_CHECKING

import numpy as np
import pytest

from smart_recruitment_ml.evaluation.locked_test import (
    TEST_SHA256,
    load_locked_test,
)

if TYPE_CHECKING:
    from pathlib import Path


def _records() -> list[dict[str, object]]:
    return [
        {
            "candidate_id": f"cand_{candidate:04d}",
            "feature_schema_version": "job-rec-features-v1",
            "feature_values": [float(candidate + job)] * 103,
            "job_id": f"job_{job:04d}",
            "pair_id": f"pair_cand_{candidate:04d}_job_{job:04d}",
            "relevance_label": (candidate + job) % 4,
        }
        for candidate in range(1, 28)
        for job in range(1, 61)
    ]


def _write(path: Path, records: list[dict[str, object]]) -> None:
    path.write_text(
        "".join(json.dumps(record, sort_keys=True) + "\n" for record in records),
        encoding="utf-8",
    )


def _load(path: Path):
    return load_locked_test(
        path,
        expected_sha256=TEST_SHA256,
        sha256_file=lambda _path: TEST_SHA256,
        allowed_candidate_ids={f"cand_{value:04d}" for value in range(1, 28)},
        prohibited_candidate_ids={"cand_9999"},
    )


def test_locked_test_shape_qid_labels_finiteness_and_identity(tmp_path: Path) -> None:
    path = tmp_path / "locked.jsonl"
    _write(path, _records())
    dataset = _load(path)
    assert dataset.record_count == 1620
    assert dataset.candidate_count == 27
    assert dataset.X.shape == (1620, 103)
    assert dataset.y.shape == (1620,)
    assert set(dataset.group_sizes) == {60}
    assert np.all(np.isfinite(dataset.X))
    assert set(dataset.y.tolist()) == {0.0, 1.0, 2.0, 3.0}
    assert np.array_equal(np.unique(dataset.qid), np.arange(27))
    assert np.all(np.diff(dataset.qid) >= 0)
    assert len(set(dataset.pair_ids)) == 1620


@pytest.mark.parametrize(
    ("mutation", "message"),
    [
        ("duplicate", "Duplicate"),
        ("non_finite", "Non-finite"),
        ("feature_count", "Feature count"),
        ("schema", "Feature schema"),
        ("label", "relevance label"),
    ],
)
def test_locked_test_rejects_invalid_records(
    tmp_path: Path,
    mutation: str,
    message: str,
) -> None:
    records = _records()
    if mutation == "duplicate":
        records[1]["pair_id"] = records[0]["pair_id"]
    elif mutation == "non_finite":
        records[0]["feature_values"] = [float("nan")] * 103
    elif mutation == "feature_count":
        records[0]["feature_values"] = [0.0]
    elif mutation == "schema":
        records[0]["feature_schema_version"] = "wrong"
    else:
        records[0]["relevance_label"] = 4
    path = tmp_path / f"{mutation}.jsonl"
    _write(path, records)
    with pytest.raises(ValueError, match=message):
        _load(path)


def test_locked_test_hash_and_candidate_overlap_are_rejected(tmp_path: Path) -> None:
    path = tmp_path / "locked.jsonl"
    _write(path, _records())
    with pytest.raises(ValueError, match="SHA-256"):
        load_locked_test(
            path,
            expected_sha256=TEST_SHA256,
            sha256_file=lambda _path: "0" * 64,
            allowed_candidate_ids={f"cand_{value:04d}" for value in range(1, 28)},
            prohibited_candidate_ids=set(),
        )
    with pytest.raises(ValueError, match="assignment or overlap"):
        load_locked_test(
            path,
            expected_sha256=TEST_SHA256,
            sha256_file=lambda _path: TEST_SHA256,
            allowed_candidate_ids={f"cand_{value:04d}" for value in range(1, 28)},
            prohibited_candidate_ids={"cand_0001"},
        )
    with pytest.raises(ValueError, match="Pair ID overlap"):
        load_locked_test(
            path,
            expected_sha256=TEST_SHA256,
            sha256_file=lambda _path: TEST_SHA256,
            allowed_candidate_ids={f"cand_{value:04d}" for value in range(1, 28)},
            prohibited_candidate_ids=set(),
            prohibited_pair_ids={"pair_cand_0001_job_0001"},
        )
