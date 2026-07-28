"""Group-aware Phase 8 training Dataset contracts."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import numpy as np
import pytest

from smart_recruitment_ml.training.dataset import (
    FEATURE_COUNT,
    LOCKED_TEST_SHA256,
    RankingDataset,
    load_ranking_dataset,
    sha256_file,
)
from smart_recruitment_ml.training.trainer import EXPECTED_HASHES

SERVICE_ROOT = Path(__file__).resolve().parents[1]
SPLITS = SERVICE_ROOT / "data/splits/v1"


@pytest.fixture(scope="module")
def datasets() -> tuple[RankingDataset, RankingDataset]:
    train = load_ranking_dataset(
        SPLITS / "train.jsonl",
        split="train",
        expected_sha256=EXPECTED_HASHES["train"],
        expected_records=7560,
        expected_candidates=126,
    )
    validation = load_ranking_dataset(
        SPLITS / "validation.jsonl",
        split="validation",
        expected_sha256=EXPECTED_HASHES["validation"],
        expected_records=1620,
        expected_candidates=27,
    )
    return train, validation


def _first_group() -> list[dict[str, Any]]:
    lines = (SPLITS / "train.jsonl").read_text(encoding="utf-8").splitlines()[:60]
    return [json.loads(line) for line in lines]


def _write_group(path: Path, records: list[dict[str, Any]]) -> str:
    path.write_text(
        "".join(
            json.dumps(record, ensure_ascii=False, separators=(",", ":"), sort_keys=True) + "\n"
            for record in records
        ),
        encoding="utf-8",
    )
    return sha256_file(path)


def _load_group(path: Path, digest: str) -> RankingDataset:
    return load_ranking_dataset(
        path,
        split="train",
        expected_sha256=digest,
        expected_records=60,
        expected_candidates=1,
    )


def test_shapes_groups_qids_labels_and_finiteness(
    datasets: tuple[RankingDataset, RankingDataset],
) -> None:
    train, validation = datasets
    assert train.X.shape == (7560, FEATURE_COUNT)
    assert validation.X.shape == (1620, FEATURE_COUNT)
    assert train.y.shape == train.qid.shape == (7560,)
    assert validation.y.shape == validation.qid.shape == (1620,)
    assert train.candidate_count == 126
    assert validation.candidate_count == 27
    assert set(train.group_sizes) == set(validation.group_sizes) == {60}
    assert np.all(np.diff(train.qid) >= 0)
    assert np.all(np.diff(validation.qid) >= 0)
    assert np.array_equal(np.unique(train.qid), np.arange(126))
    assert np.array_equal(np.unique(validation.qid), np.arange(27))
    assert set(train.y.tolist()) <= {0.0, 1.0, 2.0, 3.0}
    assert set(validation.y.tolist()) <= {0.0, 1.0, 2.0, 3.0}
    assert np.all(np.isfinite(train.X))
    assert np.all(np.isfinite(validation.X))
    assert set(train.candidate_ids).isdisjoint(validation.candidate_ids)


def test_identifiers_and_label_are_separate_from_features(
    datasets: tuple[RankingDataset, RankingDataset],
) -> None:
    train, _validation = datasets
    assert train.X.dtype == np.float32
    assert train.y.dtype == np.float32
    assert train.qid.dtype == np.int32
    assert train.X.shape[1] == 103
    assert all(isinstance(value, str) for value in train.pair_ids)
    assert all(isinstance(value, str) for value in train.candidate_ids)
    assert all(isinstance(value, str) for value in train.job_ids)


def test_label_and_metadata_mutation_do_not_change_x(tmp_path: Path) -> None:
    original_records = _first_group()
    original_path = tmp_path / "original.jsonl"
    original = _load_group(original_path, _write_group(original_path, original_records))

    label_records = _first_group()
    label_records[0]["relevance_label"] = (int(label_records[0]["relevance_label"]) + 1) % 4
    label_path = tmp_path / "label.jsonl"
    label_changed = _load_group(label_path, _write_group(label_path, label_records))
    assert np.array_equal(original.X, label_changed.X)
    assert not np.array_equal(original.y, label_changed.y)

    metadata_records = _first_group()
    for index, record in enumerate(metadata_records):
        record["pair_id"] = f"pair_rewritten_{index:03d}"
        record["candidate_id"] = "candidate_rewritten"
        record["job_id"] = f"job_rewritten_{index:03d}"
        record["baseline_score"] = 999.0
    metadata_path = tmp_path / "metadata.jsonl"
    metadata_changed = _load_group(
        metadata_path,
        _write_group(metadata_path, metadata_records),
    )
    assert np.array_equal(original.X, metadata_changed.X)


@pytest.mark.parametrize(
    ("mutation", "message"),
    [
        ("duplicate", "Duplicate Pair ID"),
        ("feature_count", "Feature count must be 103"),
        ("schema", "Feature schema mismatch"),
        ("label", "Label outside 0..3"),
        ("non_finite", "Non-finite feature"),
    ],
)
def test_invalid_records_are_rejected(
    tmp_path: Path,
    mutation: str,
    message: str,
) -> None:
    records = _first_group()
    if mutation == "duplicate":
        records[1]["pair_id"] = records[0]["pair_id"]
    elif mutation == "feature_count":
        records[0]["feature_values"] = records[0]["feature_values"][:-1]
    elif mutation == "schema":
        records[0]["feature_schema_version"] = "wrong"
    elif mutation == "label":
        records[0]["relevance_label"] = 4
    else:
        records[0]["feature_values"][0] = float("nan")
    path = tmp_path / f"{mutation}.jsonl"
    digest = _write_group(path, records)
    with pytest.raises(ValueError, match=message):
        _load_group(path, digest)


def test_source_hash_count_and_order_are_rejected(tmp_path: Path) -> None:
    records = _first_group()
    path = tmp_path / "group.jsonl"
    digest = _write_group(path, records)
    with pytest.raises(ValueError, match="SHA-256 mismatch"):
        _load_group(path, "0" * 64)
    with pytest.raises(ValueError, match="record count"):
        load_ranking_dataset(
            path,
            split="train",
            expected_sha256=digest,
            expected_records=61,
            expected_candidates=1,
        )
    records.reverse()
    reversed_path = tmp_path / "reversed.jsonl"
    with pytest.raises(ValueError, match="Candidate/Job/Pair order"):
        _load_group(reversed_path, _write_group(reversed_path, records))


def test_locked_path_and_locked_hash_are_rejected(tmp_path: Path) -> None:
    named_path = tmp_path / "test.jsonl"
    named_path.write_text("{}\n", encoding="utf-8")
    with pytest.raises(ValueError, match="Locked Test path"):
        load_ranking_dataset(
            named_path,
            split="train",
            expected_sha256=sha256_file(named_path),
            expected_records=1,
            expected_candidates=1,
        )

    locked_copy = tmp_path / "locked-copy.jsonl"
    locked_copy.write_bytes((SPLITS / "test.jsonl").read_bytes())
    assert sha256_file(locked_copy) == LOCKED_TEST_SHA256
    with pytest.raises(ValueError, match="Locked Test content hash"):
        load_ranking_dataset(
            locked_copy,
            split="validation",
            expected_sha256=LOCKED_TEST_SHA256,
            expected_records=1620,
            expected_candidates=27,
        )
