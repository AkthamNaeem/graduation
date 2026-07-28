"""Integration tests for the database-isolated production Laravel bridge."""

import json
import subprocess
from pathlib import Path

from pydantic import TypeAdapter

from smart_recruitment_ml.baselines.adapter import ADAPTER_VERSION, adapt_sources
from smart_recruitment_ml.baselines.matching_v2_oracle import rank_candidate_jobs
from smart_recruitment_ml.schemas.baselines import AdaptedDataset
from smart_recruitment_ml.schemas.synthetic import Candidate, Job

REPOSITORY_ROOT = Path(__file__).parents[3]
ML_ROOT = REPOSITORY_ROOT / "services/ml-recommendation"
BRIDGE = ML_ROOT / "tools/laravel_matching_v2_baseline.php"
TRAIN = ML_ROOT / "data/splits/v1/train.jsonl"
LOCKED_TEST = ML_ROOT / "data/splits/v1/test.jsonl"
TRAIN_HASH = "d87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d"
TEST_HASH = "79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05"


def source_models():
    candidate_value = json.loads(
        (ML_ROOT / "data/synthetic/v1/candidates.jsonl").read_text(encoding="utf-8").splitlines()[0]
    )
    job_value = json.loads(
        (ML_ROOT / "data/synthetic/v1/jobs.jsonl").read_text(encoding="utf-8").splitlines()[0]
    )
    candidates = TypeAdapter(list[Candidate]).validate_python([candidate_value])
    jobs = TypeAdapter(list[Job]).validate_python([job_value])
    return candidates, jobs


def payload() -> tuple[dict, AdaptedDataset]:
    candidates, jobs = source_models()
    adapted = adapt_sources(candidates, jobs)
    dump = adapted.model_dump(mode="json")
    request = {
        "adapter_version": ADAPTER_VERSION,
        "split_name": "train",
        "split_file": {"path": str(TRAIN), "sha256": TRAIN_HASH},
        "locked_test_sha256": TEST_HASH,
        "skill_registry": dump["skill_registry"],
        "candidates": dump["candidates"],
        "jobs": dump["jobs"],
        "groups": [{"candidate_id": "cand_0001", "job_ids": ["job_0001"]}],
    }
    return request, adapted


def invoke(request: dict) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", str(BRIDGE)],
        cwd=REPOSITORY_ROOT,
        input=json.dumps(request),
        capture_output=True,
        text=True,
        encoding="utf-8",
        check=False,
    )


def test_bridge_boots_laravel_and_uses_actual_matching_service() -> None:
    request, adapted = payload()
    process = invoke(request)
    assert process.returncode == 0, process.stderr
    response = json.loads(process.stdout)
    assert response["matching_version"] == "2.0"
    assert response["query_count"] == 0
    assert response["write_count"] == 0
    assert len(response["records"]) == 1
    oracle = rank_candidate_jobs(adapted, "cand_0001", ["job_0001"])["job_0001"]
    assert response["records"][0]["score"] == oracle.score
    assert response["records"][0]["rank"] == oracle.rank


def test_bridge_output_is_deterministic() -> None:
    request, _ = payload()
    assert invoke(request).stdout == invoke(request).stdout


def test_bridge_rejects_locked_test_path() -> None:
    request, _ = payload()
    request["split_file"] = {"path": str(LOCKED_TEST), "sha256": TEST_HASH}
    process = invoke(request)
    assert process.returncode != 0
    assert "prohibited" in process.stderr


def test_bridge_rejects_hash_mismatch() -> None:
    request, _ = payload()
    request["split_file"]["sha256"] = "0" * 64
    process = invoke(request)
    assert process.returncode != 0
    assert "hash mismatch" in process.stderr.lower()


def test_label_and_feature_mutations_do_not_affect_bridge_scores() -> None:
    request, _ = payload()
    original = invoke(request).stdout
    request["ignored_relevance_label"] = 0
    request["ignored_feature_values"] = [999.0]
    assert invoke(request).stdout == original


def test_bridge_source_has_no_persistence_calls() -> None:
    source = BRIDGE.read_text(encoding="utf-8")
    assert "MatchingService::class" in source
    assert "DB::listen" in source
    assert "->save(" not in source
    assert "::create(" not in source
