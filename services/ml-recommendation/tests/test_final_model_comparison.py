"""Metric reuse, deltas, disposition, ranks, and parity aggregation."""

from __future__ import annotations

import copy
import json
from pathlib import Path
from types import SimpleNamespace

import numpy as np
from pydantic import TypeAdapter

from smart_recruitment_ml.baselines.adapter import adapt_sources
from smart_recruitment_ml.evaluation.final_evaluator import (
    _bridge_payload,
    _comparison,
    _metrics,
    _model_predictions,
    _oracle_predictions,
    _parity_evidence,
    _parity_from_records,
    _rank_scores,
    _skills_predictions,
    _write_derived,
)
from smart_recruitment_ml.evaluation.final_evaluator import (
    _prediction_records as build_prediction_records,
)
from smart_recruitment_ml.evaluation.locked_test import LockedTestDataset
from smart_recruitment_ml.schemas.baselines import (
    MatchingV2Components,
    MatchingV2Prediction,
)
from smart_recruitment_ml.schemas.final_evaluation import (
    FinalTestPrediction,
    SystemScore,
)
from smart_recruitment_ml.schemas.synthetic import Candidate, Job

SERVICE_ROOT = Path(__file__).resolve().parents[1]


def _prediction_records() -> list[FinalTestPrediction]:
    records: list[FinalTestPrediction] = []
    for candidate in range(1, 28):
        for job in range(1, 61):
            rank = job
            tuned_rank = 61 - job
            label = 3 if job <= 5 else 0
            records.append(
                FinalTestPrediction(
                    pair_id=f"pair_cand_{candidate:04d}_job_{job:04d}",
                    candidate_id=f"cand_{candidate:04d}",
                    job_id=f"job_{job:04d}",
                    relevance_label=label,
                    skills_only=SystemScore(score=61 - rank, rank=rank),
                    laravel_matching_2_0=SystemScore(score=61 - rank, rank=rank),
                    python_matching_2_0=SystemScore(score=61 - rank, rank=rank),
                    initial_xgbranker=SystemScore(score=61 - rank, rank=rank),
                    tuned_xgbranker=SystemScore(score=tuned_rank, rank=rank),
                )
            )
    return records


def _locked_dataset() -> LockedTestDataset:
    candidate_ids = tuple(
        f"cand_{candidate:04d}" for candidate in range(1, 28) for _job in range(1, 61)
    )
    job_ids = tuple(f"job_{job:04d}" for _candidate in range(1, 28) for job in range(1, 61))
    return LockedTestDataset(
        pair_ids=tuple(
            f"pair_{candidate_id}_{job_id}"
            for candidate_id, job_id in zip(candidate_ids, job_ids, strict=True)
        ),
        candidate_ids=candidate_ids,
        job_ids=job_ids,
        X=np.zeros((1620, 103), dtype=np.float32),
        y=np.zeros(1620, dtype=np.float32),
        qid=np.repeat(np.arange(27, dtype=np.int32), 60),
        group_sizes=(60,) * 27,
    )


def test_metrics_reuse_all_six_metrics_with_27_groups() -> None:
    metrics = _metrics(_prediction_records())
    for system in (
        "skills_only",
        "laravel_matching_2_0",
        "python_matching_2_0",
        "initial_xgbranker",
        "tuned_xgbranker",
    ):
        assert set(metrics[system]) == {
            "NDCG@5",
            "NDCG@10",
            "Precision@5",
            "Recall@5",
            "MRR",
            "HitRate@5",
        }
        assert all(value["group_count"] == 27 for value in metrics[system].values())


def test_quality_disposition_and_deltas_follow_predeclared_rules() -> None:
    metrics = _metrics(_prediction_records())
    held = _comparison(metrics)
    assert held["quality_conditions"]["beats_matching_primary"] is False
    assert held["quality_disposition"] == "HOLD_MODEL_CANDIDATE"
    promoted_metrics = copy.deepcopy(metrics)
    promoted_metrics["laravel_matching_2_0"]["NDCG@10"]["macro_mean"] -= 0.1
    comparison = _comparison(promoted_metrics)
    assert all(comparison["quality_conditions"].values())
    assert comparison["quality_disposition"] == "PROMOTE_TO_EXPLAINABILITY"
    assert comparison["model_changed_after_test"] is False
    assert comparison["training_run_after_test"] is False
    assert comparison["feature_change_after_test"] is False


def test_parity_uses_saved_scores_ranks_and_component_evidence() -> None:
    records = _prediction_records()
    evidence = {
        "component_mismatch_counts": {
            "required_skills": 0,
            "nice_to_have_skills": 0,
            "experience": 0,
            "education": 0,
            "text_similarity": 0,
            "cosine_similarity": 0,
        },
        "database_query_count": 0,
        "database_write_count": 0,
        "extra_count": 0,
        "missing_count": 0,
        "parity_passed": True,
    }
    parity = _parity_from_records(records, evidence)
    assert parity["pair_count"] == 1620
    assert parity["score_max_absolute_error"] == 0.0
    assert parity["rank_match_rate"] == 1.0
    assert parity["database_query_count"] == 0
    assert parity["database_write_count"] == 0


def test_prediction_records_normalize_matching_prediction_types() -> None:
    dataset = _locked_dataset()
    values = {
        (candidate_id, job_id): SimpleNamespace(
            score=np.float32(0.5),
            rank=np.int64(index % 60 + 1),
        )
        for index, (candidate_id, job_id) in enumerate(
            zip(dataset.candidate_ids, dataset.job_ids, strict=True)
        )
    }
    records = build_prediction_records(
        dataset,
        {
            "skills_only": values,
            "laravel_matching_2_0": values,
            "python_matching_2_0": values,
            "initial_xgbranker": values,
            "tuned_xgbranker": values,
        },
    )
    assert len(records) == 1620
    assert records[0].laravel_matching_2_0 == SystemScore(score=0.5, rank=1)
    assert json.loads(records[0].model_dump_json())["laravel_matching_2_0"] == {
        "score": 0.5,
        "rank": 1,
    }


def test_rank_and_parity_helpers_cover_all_pairs() -> None:
    dataset = _locked_dataset()
    ranks = _rank_scores(dataset, np.tile(np.arange(60), 27).astype(np.float32))
    assert all(set(ranks[index : index + 60]) == set(range(1, 61)) for index in range(0, 1620, 60))

    components = MatchingV2Components(
        required_skills=1.0,
        nice_to_have_skills=2.0,
        experience=3.0,
        education=4.0,
        text_similarity=5.0,
        cosine_similarity=0.5,
    )
    predictions = {
        key: MatchingV2Prediction(
            score=50.0,
            rank=index % 60 + 1,
            components=components,
        )
        for index, key in enumerate(zip(dataset.candidate_ids, dataset.job_ids, strict=True))
    }
    evidence = _parity_evidence(set(predictions), predictions, predictions)
    assert evidence["parity_passed"] is True
    assert not any(evidence["component_mismatch_counts"].values())


def test_derived_writer_is_deterministic_from_saved_predictions(tmp_path: Path) -> None:
    prediction_path = tmp_path / "test_predictions.jsonl"
    prediction_path.write_text("{}\n", encoding="utf-8")
    receipt = {
        "parity_evidence": {
            "component_mismatch_counts": {
                "required_skills": 0,
                "nice_to_have_skills": 0,
                "experience": 0,
                "education": 0,
                "text_similarity": 0,
                "cosine_similarity": 0,
            },
            "database_query_count": 0,
            "database_write_count": 0,
            "extra_count": 0,
            "missing_count": 0,
            "parity_passed": True,
        }
    }
    summary = _write_derived(
        directory=tmp_path,
        records=_prediction_records(),
        receipt=receipt,
        manifest_base={"evaluation_session_version": "locked-final-test-v1"},
        predictions_path=prediction_path,
    )
    assert summary == {
        "quality_disposition": "HOLD_MODEL_CANDIDATE",
        "parity_passed": "true",
    }
    assert (tmp_path / "manifest.json").is_file()
    assert (tmp_path / "FINAL_TEST_REPORT.md").is_file()
    report = (tmp_path / "FINAL_TEST_REPORT.md").read_text(encoding="utf-8")
    assert "## Controlled Recovery Disclosure" in report
    assert "No further Test execution is permitted." in report
    assert (
        'Component mismatches: `{"cosine_similarity": 0, "education": 0, '
        '"experience": 0, "nice_to_have_skills": 0, "required_skills": 0, '
        '"text_similarity": 0}`'
    ) in report


def test_frozen_system_helpers_on_non_test_synthetic_inputs() -> None:
    candidate_value = json.loads(
        (SERVICE_ROOT / "data/synthetic/v1/candidates.jsonl")
        .read_text(encoding="utf-8")
        .splitlines()[0]
    )
    job_value = json.loads(
        (SERVICE_ROOT / "data/synthetic/v1/jobs.jsonl").read_text(encoding="utf-8").splitlines()[0]
    )
    candidates = TypeAdapter(list[Candidate]).validate_python([candidate_value])
    jobs = TypeAdapter(list[Job]).validate_python([job_value])
    groups = {candidates[0].candidate_id: [jobs[0].job_id]}
    skills = _skills_predictions(
        {candidates[0].candidate_id: candidates[0]},
        {jobs[0].job_id: jobs[0]},
        groups,
    )
    adapted = adapt_sources(candidates, jobs)
    oracle = _oracle_predictions(adapted, groups)
    payload = _bridge_payload(
        adapted,
        groups,
        SERVICE_ROOT / "data/splits/v1/validation.jsonl",
    )
    assert set(skills) == set(oracle)
    assert payload["groups"] == [
        {
            "candidate_id": candidates[0].candidate_id,
            "job_ids": [jobs[0].job_id],
        }
    ]

    dataset = _locked_dataset()
    model_predictions = _model_predictions(
        dataset,
        SERVICE_ROOT / "data/models/initial/v1/model.json",
    )
    assert len(model_predictions) == 1620
    assert all(np.isfinite(value.score) for value in model_predictions.values())
