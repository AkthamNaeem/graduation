"""Phase 7 evaluator for existing recommendation baselines."""

# ruff: noqa: E501, RUF001

from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import tempfile
from collections import defaultdict
from pathlib import Path
from typing import TYPE_CHECKING, Any

from pydantic import TypeAdapter

from smart_recruitment_ml.schemas.baselines import (
    AdaptedDataset,
    BaselineManifest,
    BaselinePredictionRecord,
    MatchingV2Components,
    MatchingV2Parity,
    MatchingV2Prediction,
    MetricsArtifact,
    OutputFileMetadata,
    ParityArtifact,
    ParitySplitSummary,
    ProductionSourceMetadata,
    RankedScore,
    SourceFileMetadata,
    SplitMetrics,
)
from smart_recruitment_ml.schemas.synthetic import Candidate, Job

from . import BASELINE_ARTIFACT_VERSION, EVALUATOR_VERSION
from .adapter import ADAPTER_VERSION, adapt_sources
from .matching_v2_oracle import (
    MATCHING_VERSION,
    PARITY_TOLERANCE,
    PARITY_VERSION,
    rank_candidate_jobs,
)
from .metrics import (
    METRICS_VERSION,
    RELEVANCE_THRESHOLD,
    evaluate_rankings,
)
from .skills_only import SKILLS_BASELINE_VERSION, rank_jobs

if TYPE_CHECKING:
    from collections.abc import Iterable, Sequence

_EXPECTED_SOURCE_HASHES = {
    "candidates": "5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320",
    "jobs": "7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d",
    "train": "d87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d",
    "validation": "a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e",
    "assignments": "ba5c075f244c8d65200316e44a4b0bb68f579aa6e2b0546e3527e17db98bc502",
    "feature_schema": "aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0",
    "split_manifest": "f032847615dea42b28d41f8d47f2627df3d030399c8690df8747bb1ae26dbd0a",
    "test_lock": "00f938c9f888156022d221a9fb3eab7c76e8d4316803d175470355a84f33ec73",
}
_PRODUCTION_SOURCES = {
    "app/Services/MatchingService.php": (
        "b8f7df3f8f9189467ab73384498aa1f2aee725f15971bba4c07e67bd3b7eabee",
        "3bda9b59b7335f37a293a15d94fb1370a463e6ba",
    ),
    "app/Services/CandidateExperienceCalculator.php": (
        "86e819ec94ab76a368735338c92f0708f85128ec489e7b58c94ead7ee22c4e87",
        "7e94cf855400e8aada1b12b78c755b041dde05cc",
    ),
    "app/Services/EducationLevelNormalizer.php": (
        "6ddbc82ba8fd1ceeed128cd68a86f941b8cdbf611dea6966d0a4afbb8ec04d56",
        "e3d408534854922685f1d502144464d6322fa442",
    ),
    "app/Enums/EducationLevel.php": (
        "00935de9c5a8ffee30739b4007e23cf1734ab898a5161784591dcd24535fd40a",
        "badf24fc55abf5091aaabefc6887d78240c83af6",
    ),
    "app/Enums/JobSkillRequirementType.php": (
        "3109acb30206414eb5f8b5dfe05e5ab38ac9f203234a31b4090a9d0298736c43",
        "0e324632205ee7e448bd0ee4f5ea055fb99c27b6",
    ),
    "config/matching.php": (
        "766f9d734c6297d971e22aa2da5b29549e602f45d1b9bb84a146644a087838fc",
        "156a4a5f806ee3bc5424ef9c633274085f14168c",
    ),
}
_GENERATED_AT = "2026-07-24T00:00:00Z"
_CANDIDATE_COUNT = {"train": 126, "validation": 27}
_RECORD_COUNT = {"train": 7560, "validation": 1620}
_COMPONENT_FIELDS = (
    "required_skills",
    "nice_to_have_skills",
    "experience",
    "education",
    "text_similarity",
    "cosine_similarity",
)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def git_blob_sha1(path: Path) -> str:
    content = path.read_bytes()
    return hashlib.sha1(
        f"blob {len(content)}\0".encode() + content,
        usedforsecurity=False,
    ).hexdigest()


def _read_json(path: Path) -> Any:
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def _read_jsonl(path: Path) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    with path.open(encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            if not line.strip():
                raise ValueError(f"Blank JSONL line at {path}:{line_number}")
            value = json.loads(line)
            if not isinstance(value, dict):
                raise ValueError(f"JSONL object expected at {path}:{line_number}")
            records.append(value)
    return records


def _check_hash(path: Path, expected: str, label: str) -> str:
    actual = sha256_file(path)
    if actual != expected:
        raise ValueError(f"{label} SHA-256 mismatch: expected {expected}, got {actual}")
    return actual


def _validate_not_locked_test(path: Path, locked_test_sha256: str) -> None:
    if path.name.casefold() == "test.jsonl":
        raise ValueError("Locked Test path is prohibited during Phase 7.")
    if sha256_file(path) == locked_test_sha256:
        raise ValueError("Locked Test content is prohibited during Phase 7.")


def _repo_path(path: Path, laravel_root: Path) -> str:
    try:
        return path.resolve().relative_to(laravel_root.resolve()).as_posix()
    except ValueError:
        return str(path.resolve()).replace("\\", "/")


def _atomic_write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    handle = tempfile.NamedTemporaryFile(  # noqa: SIM115
        mode="w",
        encoding="utf-8",
        newline="\n",
        dir=path.parent,
        delete=False,
    )
    temporary = Path(handle.name)
    try:
        with handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink()


def _json_content(value: Any) -> str:
    return (
        json.dumps(
            value,
            ensure_ascii=False,
            indent=2,
            sort_keys=True,
        )
        + "\n"
    )


def _jsonl_content(records: Iterable[BaselinePredictionRecord]) -> str:
    return "".join(
        json.dumps(
            record.model_dump(mode="json"),
            ensure_ascii=False,
            separators=(",", ":"),
            sort_keys=True,
        )
        + "\n"
        for record in records
    )


def _group_pairs(
    records: Sequence[dict[str, Any]],
    split: str,
) -> tuple[dict[str, list[str]], dict[tuple[str, str], int]]:
    groups: dict[str, list[str]] = defaultdict(list)
    labels: dict[tuple[str, str], int] = {}
    for record in records:
        candidate_id = str(record["candidate_id"])
        job_id = str(record["job_id"])
        label = int(record["relevance_label"])
        key = (candidate_id, job_id)
        if key in labels:
            raise ValueError(f"Duplicate {split} pair: {candidate_id}/{job_id}")
        if label not in {0, 1, 2, 3}:
            raise ValueError(f"Invalid {split} relevance label: {label}")
        labels[key] = label
        groups[candidate_id].append(job_id)
    ordered_groups = {
        candidate_id: sorted(job_ids) for candidate_id, job_ids in sorted(groups.items())
    }
    if len(ordered_groups) != _CANDIDATE_COUNT[split]:
        raise ValueError(f"Unexpected {split} candidate count.")
    if len(labels) != _RECORD_COUNT[split]:
        raise ValueError(f"Unexpected {split} record count.")
    if any(len(job_ids) != 60 for job_ids in ordered_groups.values()):
        raise ValueError(f"Every {split} candidate must have exactly 60 jobs.")
    return ordered_groups, labels


def _bridge_payload(
    split: str,
    split_path: Path,
    adapted: AdaptedDataset,
    groups: dict[str, list[str]],
    locked_test_sha256: str,
) -> dict[str, Any]:
    adapted_dump = adapted.model_dump(mode="json")
    return {
        "adapter_version": ADAPTER_VERSION,
        "split_name": split,
        "split_file": {
            "path": str(split_path.resolve()),
            "sha256": _EXPECTED_SOURCE_HASHES[split],
        },
        "locked_test_sha256": locked_test_sha256,
        "skill_registry": adapted_dump["skill_registry"],
        "candidates": adapted_dump["candidates"],
        "jobs": adapted_dump["jobs"],
        "groups": [
            {"candidate_id": candidate_id, "job_ids": job_ids}
            for candidate_id, job_ids in groups.items()
        ],
    }


def invoke_laravel_bridge(
    *,
    php_executable: Path,
    laravel_root: Path,
    bridge_path: Path,
    payload: dict[str, Any],
) -> dict[tuple[str, str], MatchingV2Prediction]:
    process = subprocess.run(
        [str(php_executable), str(bridge_path)],
        cwd=laravel_root,
        input=json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
        capture_output=True,
        text=True,
        encoding="utf-8",
        check=False,
    )
    if process.returncode != 0:
        raise RuntimeError(
            "Laravel Matching 2.0 bridge failed: "
            + (process.stderr.strip() or process.stdout.strip())
        )
    response = json.loads(process.stdout)
    if response.get("protocol_version") != "laravel-matching-v2-baseline-v1":
        raise ValueError("Unexpected Laravel bridge protocol version.")
    if response.get("matching_version") != MATCHING_VERSION:
        raise ValueError("Unexpected Laravel MatchingService version.")
    if response.get("query_count") != 0 or response.get("write_count") != 0:
        raise ValueError("Laravel bridge database isolation contract failed.")

    results: dict[tuple[str, str], MatchingV2Prediction] = {}
    for record in response["records"]:
        key = (str(record["candidate_id"]), str(record["job_id"]))
        if key in results:
            raise ValueError(f"Duplicate Laravel bridge prediction: {key}")
        if record["version"] != MATCHING_VERSION:
            raise ValueError("Laravel bridge record version mismatch.")
        results[key] = MatchingV2Prediction(
            score=float(record["score"]),
            rank=int(record["rank"]),
            matching_score_version=MATCHING_VERSION,
            components=MatchingV2Components.model_validate(record["components"]),
        )
    return results


def _skills_predictions(
    candidates: dict[str, Candidate],
    jobs: dict[str, Job],
    groups: dict[str, list[str]],
) -> dict[tuple[str, str], RankedScore]:
    predictions: dict[tuple[str, str], RankedScore] = {}
    for candidate_id, job_ids in groups.items():
        ranked = rank_jobs(candidates[candidate_id], (jobs[job_id] for job_id in job_ids))
        for job_id, score, rank in ranked:
            predictions[(candidate_id, job_id)] = RankedScore(score=score, rank=rank)
    return predictions


def _oracle_predictions(
    adapted: AdaptedDataset,
    groups: dict[str, list[str]],
) -> dict[tuple[str, str], MatchingV2Prediction]:
    predictions: dict[tuple[str, str], MatchingV2Prediction] = {}
    for candidate_id, job_ids in groups.items():
        group_predictions = rank_candidate_jobs(adapted, candidate_id, job_ids)
        for job_id, prediction in group_predictions.items():
            predictions[(candidate_id, job_id)] = prediction
    return predictions


def _parity(
    laravel: MatchingV2Prediction,
    python: MatchingV2Prediction,
) -> MatchingV2Parity:
    return MatchingV2Parity(
        absolute_score_error=abs(laravel.score - python.score),
        rank_match=laravel.rank == python.rank,
    )


def _evaluate_split(
    *,
    split: str,
    groups: dict[str, list[str]],
    labels: dict[tuple[str, str], int],
    candidates: dict[str, Candidate],
    jobs: dict[str, Job],
    adapted: AdaptedDataset,
    laravel_predictions: dict[tuple[str, str], MatchingV2Prediction],
) -> tuple[list[BaselinePredictionRecord], SplitMetrics, ParitySplitSummary]:
    skills_predictions = _skills_predictions(candidates, jobs, groups)
    python_predictions = _oracle_predictions(adapted, groups)
    expected_keys = set(labels)
    for name, prediction_keys in (
        ("skills", set(skills_predictions)),
        ("laravel", set(laravel_predictions)),
        ("python", set(python_predictions)),
    ):
        if prediction_keys != expected_keys:
            raise ValueError(f"{split} {name} prediction coverage is incomplete.")

    output: list[BaselinePredictionRecord] = []
    for key in sorted(expected_keys):
        laravel = laravel_predictions[key]
        python = python_predictions[key]
        output.append(
            BaselinePredictionRecord(
                pair_id=f"pair_{key[0]}_{key[1]}",
                candidate_id=key[0],
                job_id=key[1],
                relevance_label=labels[key],
                skills_baseline=skills_predictions[key],
                laravel_matching_v2=laravel,
                python_matching_v2_parity=python,
                parity=_parity(laravel, python),
            )
        )

    ranking_labels: dict[str, list[list[int]]] = {
        "skills": [],
        "laravel": [],
        "python": [],
    }
    by_candidate: dict[str, list[BaselinePredictionRecord]] = defaultdict(list)
    for record in output:
        by_candidate[record.candidate_id].append(record)
    for candidate_id in sorted(by_candidate):
        records = by_candidate[candidate_id]
        ranking_labels["skills"].append(
            [
                record.relevance_label
                for record in sorted(
                    records,
                    key=lambda item: (item.skills_baseline.rank, item.job_id),
                )
            ]
        )
        ranking_labels["laravel"].append(
            [
                record.relevance_label
                for record in sorted(
                    records,
                    key=lambda item: (
                        item.laravel_matching_v2.rank,
                        item.job_id,
                    ),
                )
            ]
        )
        ranking_labels["python"].append(
            [
                record.relevance_label
                for record in sorted(
                    records,
                    key=lambda item: (
                        item.python_matching_v2_parity.rank,
                        item.job_id,
                    ),
                )
            ]
        )

    split_metrics = SplitMetrics(
        record_count=len(output),
        candidate_count=len(by_candidate),
        skills_weighted_v1=evaluate_rankings(ranking_labels["skills"]),
        laravel_matching_2_0=evaluate_rankings(ranking_labels["laravel"]),
        python_matching_v2_parity=evaluate_rankings(ranking_labels["python"]),
    )
    score_deltas = [record.parity.absolute_score_error for record in output]
    component_mismatch_counts = {
        field: sum(
            abs(
                getattr(record.laravel_matching_v2.components, field)
                - getattr(record.python_matching_v2_parity.components, field)
            )
            > PARITY_TOLERANCE
            for record in output
        )
        for field in _COMPONENT_FIELDS
    }
    rank_matches = sum(record.parity.rank_match for record in output)
    parity = ParitySplitSummary(
        pair_count=len(output),
        missing_count=0,
        extra_count=0,
        score_max_absolute_error=max(score_deltas, default=0.0),
        score_mean_absolute_error=(sum(score_deltas) / len(score_deltas) if score_deltas else 0.0),
        score_exact_match_count=sum(delta == 0.0 for delta in score_deltas),
        score_tolerance_match_count=sum(delta <= PARITY_TOLERANCE for delta in score_deltas),
        component_mismatch_counts=component_mismatch_counts,
        rank_match_count=rank_matches,
        rank_match_rate=rank_matches / len(output) if output else 1.0,
    )
    if (
        parity.score_tolerance_match_count != parity.pair_count
        or any(parity.component_mismatch_counts.values())
        or parity.rank_match_count != parity.pair_count
    ):
        raise ValueError(f"{split} Laravel–Python parity gate failed: {parity}")
    return output, split_metrics, parity


def _mean(metrics: SplitMetrics, baseline: str, metric: str) -> float:
    baseline_metrics = getattr(metrics, baseline)
    return getattr(baseline_metrics, metric).macro_mean


def _metrics_table(metrics: SplitMetrics) -> str:
    lines = [
        "| Baseline | NDCG@5 | NDCG@10 | Precision@5 | Recall@5 | MRR | HitRate@5 |",
        "|---|---:|---:|---:|---:|---:|---:|",
    ]
    for label, key in (
        ("Skills-only", "skills_weighted_v1"),
        ("Laravel Matching 2.0", "laravel_matching_2_0"),
        ("Python Matching 2.0", "python_matching_v2_parity"),
    ):
        values = [
            _mean(metrics, key, metric)
            for metric in (
                "ndcg_at_5",
                "ndcg_at_10",
                "precision_at_5",
                "recall_at_5",
                "mrr",
                "hit_rate_at_5",
            )
        ]
        lines.append(f"| {label} | " + " | ".join(f"{value:.6f}" for value in values) + " |")
    return "\n".join(lines)


def _sample_lines(
    split: str,
    records: Sequence[BaselinePredictionRecord],
) -> list[str]:
    return [
        (
            f"- {split} `{record.candidate_id}` / `{record.job_id}`: label "
            f"{record.relevance_label}, skills {record.skills_baseline.score:.2f} "
            f"(rank {record.skills_baseline.rank}), Laravel "
            f"{record.laravel_matching_v2.score:.2f} "
            f"(rank {record.laravel_matching_v2.rank}), Python "
            f"{record.python_matching_v2_parity.score:.2f} "
            f"(rank {record.python_matching_v2_parity.rank})."
        )
        for record in records[:1]
    ]


def _report(
    *,
    train_records: Sequence[BaselinePredictionRecord],
    validation_records: Sequence[BaselinePredictionRecord],
    train_metrics: SplitMetrics,
    validation_metrics: SplitMetrics,
    train_parity: ParitySplitSummary,
    validation_parity: ParitySplitSummary,
    locked_test_sha256: str,
    source_revision: str,
    architecture_sha256: str,
) -> str:
    samples = "\n".join(
        [
            *_sample_lines("Train", train_records),
            *_sample_lines("Validation", validation_records),
        ]
    )
    return f"""# Phase 7 Baseline Report

## 1. Baseline

Repository `<repository-root>`, branch `master`, HEAD `{source_revision}`; no staged or tracked changes existed at the gate, with 53 approved untracked Phase 3-6 files. Architecture SHA-256 `{architecture_sha256}` and Git blob `e3c80c1928292678e9a3bd8fbcc7f83521a16300` matched. Synthetic candidates/jobs hashes are `5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320` / `7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d`; Feature schema/features hashes are `aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0` / `4e405d74714a6a9a79b3d6339b19b595cb67b8cbd6f589b721e662d274ebd18e`; Train/Validation/Assignments/lock hashes are `d87095055d16ced57461eb8d4543bf4c3863b0ebe1771e5b3528eaf290b98c3d`, `a8cc27158bc126b11e93a0eefdf6a82a0e3f88e8d82cf9e9a0bae0491b04da7e`, `ba5c075f244c8d65200316e44a4b0bb68f579aa6e2b0546e3527e17db98bc502`, and `00f938c9f888156022d221a9fb3eab7c76e8d4316803d175470355a84f33ec73`. Python 3.12.10 and PHP 8.2.12 were used. The protected snapshot contained 50 files.

Production source SHA-256 values: `MatchingService.php` `b8f7df3f8f9189467ab73384498aa1f2aee725f15971bba4c07e67bd3b7eabee`; `CandidateExperienceCalculator.php` `86e819ec94ab76a368735338c92f0708f85128ec489e7b58c94ead7ee22c4e87`; `EducationLevelNormalizer.php` `6ddbc82ba8fd1ceeed128cd68a86f941b8cdbf611dea6966d0a4afbb8ec04d56`; `EducationLevel.php` `00935de9c5a8ffee30739b4007e23cf1734ab898a5161784591dcd24535fd40a`; `JobSkillRequirementType.php` `3109acb30206414eb5f8b5dfe05e5ab38ac9f203234a31b4090a9d0298736c43`; `config/matching.php` `766f9d734c6297d971e22aa2da5b29549e602f45d1b9bb84a146644a087838fc`.

## 2. Files Changed

Existing files modified: `services/ml-recommendation/pyproject.toml`, `services/ml-recommendation/README.md`. New Python source: seven `baselines/*.py` files and `schemas/baselines.py`. New PHP tool: `services/ml-recommendation/tools/laravel_matching_v2_baseline.php`. New tests: the five required Phase 7 modules. Generated artifacts: the six files under `data/baselines/v1`.

## 3. Implementation Details

Baseline A calculates only normalized weighted skill overlap. One shared adapter creates deterministic in-memory profile/job representations. Baseline B boots Laravel and invokes the real `buildTextFromProfile`, `buildTextFromJob`, `computeTFIDF`, `cosineSimilarity`, and `scoreMatch` methods. Baseline C independently reproduces the formula, Unicode tokenization, dynamic candidate-local 61-document TF-IDF corpus, cosine, and PHP half-up rounding. Labels are attached only after ranking for metrics; feature vectors are never consumed. All validations finish before staged atomic publication. The evaluator rejects a Test name/hash and the bridge fails on any database query/write.

## 4. Baseline Contracts

A is `skills-weighted-v1`: `100 * (0.85 * required_weight_coverage + 0.15 * nice_count_coverage)`, ordered score descending then `job_id` ascending. B is actual Laravel Matching `2.0` with 45/10/20/10/15 component weights. C is independent `matching-v2-parity-v1`, not a second ranking algorithm. Each prediction contains `pair_id`, `candidate_id`, `job_id`, `relevance_label`, `skills_baseline`, `laravel_matching_v2`, `python_matching_v2_parity`, and pair-level absolute-score/rank parity.

## 5. Adapter Contract

`synthetic-to-laravel-matching-v1` builds one alphabetically sorted, normalized, one-based skill registry shared by PHP and Python. Candidate mapping uses headline, empty summary, registry skills, one experience beginning 2000-01-01 with `round(years * 365.25)` days, and mapped degree plus primary-domain field. Job mapping preserves professional source fields and source education, joins required skill names deterministically, uses required source weights, gives nice skills weight 1, maps `lead` to `senior`, and fixes publication at 2026-01-01T00:00:00Z.

## 6. Metrics Definitions

`ranking-metrics-v1` computes candidate-macro NDCG@5, NDCG@10, Precision@5, Recall@5, MRR, and HitRate@5. NDCG gain is `2^relevance_label - 1` with `log2(rank + 1)` discount. Binary relevance is `label >= 2`; a zero-relevant group has Recall, MRR, and HitRate zero. Each metric stores macro mean, median, minimum, maximum, population standard deviation, and group count.

## 7. Train Results

{_metrics_table(train_metrics)}

## 8. Validation Results

{_metrics_table(validation_metrics)}

## 9. Laravel–Python Parity

Train: {train_parity.pair_count} pairs, maximum/mean score error {train_parity.score_max_absolute_error:.6f}/{train_parity.score_mean_absolute_error:.6f}, exact/tolerance matches {train_parity.score_exact_match_count}/{train_parity.score_tolerance_match_count}, rank matches {train_parity.rank_match_count} ({train_parity.rank_match_rate:.0%}), missing/extra {train_parity.missing_count}/{train_parity.extra_count}. Validation: {validation_parity.pair_count} pairs, maximum/mean error {validation_parity.score_max_absolute_error:.6f}/{validation_parity.score_mean_absolute_error:.6f}, exact/tolerance matches {validation_parity.score_exact_match_count}/{validation_parity.score_tolerance_match_count}, rank matches {validation_parity.rank_match_count} ({validation_parity.rank_match_rate:.0%}), missing/extra {validation_parity.missing_count}/{validation_parity.extra_count}. All six component mismatch counts are zero. Database queries/writes are 0/0. Tolerance is <= {PARITY_TOLERANCE:.2f}; exact rank agreement is required.

## 10. Locked Test Verification

The lock reports SHA-256 `{locked_test_sha256}`, 1,620 records, `test_locked=true`, and prohibition before Phase 10. Only the cryptographic hash was verified. Records parsed=false; metrics run=false. Test labels, vectors, Candidate IDs, and samples were neither read nor printed.

## 11. Reproducibility

Two complete runs used source revision `{source_revision}`, architecture SHA-256 `{architecture_sha256}`, and fixed release date `{_GENERATED_AT[:10]}`. All six artifacts matched byte-for-byte; the temporary reproduction directory was removed. The canonical command is recorded in `manifest.json`; output metadata records deterministic hashes without self-hashing the manifest.

## 12. Samples

Train and Validation predictions only; raw professional text, feature vectors, and Locked Test data are excluded.

{samples}

## 13. Dependencies

No dependency was added. Only Python standard library, existing Pydantic, Composer autoload, and existing Laravel packages are used. NumPy, Pandas, SciPy, scikit-learn, XGBoost, SHAP, Joblib, database clients, Redis, and Faker are absent from Phase 7.

## 14. Tests Executed

`python -m pip check`; Phase-specific `python -m pytest` command; full `python -m pytest ... --cov=smart_recruitment_ml`; `python -m ruff check services/ml-recommendation`; `python -m ruff format --check services/ml-recommendation`; `python -m mypy services/ml-recommendation/src services/ml-recommendation/tests`; `python -m compileall -q services/ml-recommendation/src`; PHP lint; `php artisan test --compact --do-not-cache-result --filter=Matching`; full Laravel tests; `git diff --check`; forbidden-dependency and Test-policy scans; protected/source hash comparisons; and deterministic regeneration.

## 15. Test Results

Phase-specific: 60 passed. Full Python suite: 125 passed, 0 failed, 0 skipped, 1 existing Starlette deprecation warning; coverage 93%. Ruff, format, Mypy (45 files), pip check, compileall, PHP syntax, metrics, bridge, parity, lock, CLI, determinism, and `git diff --check` passed. Laravel Matching: 32 passed (170 assertions). Full Laravel regression: 534 passed, 1 skipped (3,994 assertions). No Laravel source changed.

## 16. Generated Artifacts

| File | Records |
|---|---:|
| `train_predictions.jsonl` | 7,560 |
| `validation_predictions.jsonl` | 1,620 |
| `metrics.json` | 1 |
| `parity.json` | 1 |
| `manifest.json` | 1 |
| `BASELINE_REPORT.md` | 1 |

Sizes and SHA-256 values are recorded in `manifest.json` for every non-self-referential output and verified externally for all six files. No XGBRanker or Model artifacts exist.

## 17. Risks

The judgments are synthetic; the adapter is an explicit approximation boundary; TF-IDF is dynamic per Candidate's 60-job corpus; Validation contains only 27 Candidate groups; exact B/C parity proves implementation equivalence, not production recommendation quality. There is no Model yet. Locked Test protection remains valid only while its lock and hash stay unchanged.

## 18. Remaining Work

Phase 8 — Initial XGBRanker Training

## 19. Phase Gate

READY FOR PHASE 8

## 20. Exact Repository State

Branch `master`, HEAD `{source_revision}`; staged=0 and tracked modifications=0. The original 53 approved untracked files plus exactly 20 Phase 7 allowlisted files remain untracked. Ignored `.venv`, coverage, and pytest temporary outputs are not source artifacts. Architecture, Dataset, Features, Splits, lock, and production hashes match; protected mismatches=0. Commit created=false; push performed=false; Laravel files modified=false; Baseline evaluation created=true; Test evaluated=false; XGBRanker created=false; Model created=false; Docker modified=false.
"""


def _source_metadata(
    paths: dict[str, Path],
    laravel_root: Path,
    locked_test_sha256: str,
) -> list[SourceFileMetadata]:
    usage = {
        "candidates": "parsed_adapter_source",
        "jobs": "parsed_adapter_source",
        "train": "parsed_train_evaluation",
        "validation": "parsed_validation_evaluation",
        "assignments": "parsed_structural_verification",
        "feature_schema": "parsed_schema_verification",
        "split_manifest": "parsed_split_verification",
        "test_lock": "parsed_lock_verification",
    }
    record_counts = {
        "candidates": 180,
        "jobs": 180,
        "train": 7560,
        "validation": 1620,
        "assignments": 180,
        "feature_schema": 1,
        "split_manifest": 1,
        "test_lock": 1,
    }
    metadata = [
        SourceFileMetadata(
            path=_repo_path(path, laravel_root),
            record_count=record_counts[key],
            size_bytes=path.stat().st_size,
            sha256=_EXPECTED_SOURCE_HASHES[key],
            usage=usage[key],
            records_parsed=True,
        )
        for key, path in paths.items()
    ]
    metadata.append(
        SourceFileMetadata(
            path="services/ml-recommendation/data/splits/v1/test.jsonl",
            record_count=1620,
            size_bytes=1_099_011,
            sha256=locked_test_sha256,
            usage="hash_verification_only",
            records_parsed=False,
        )
    )
    return metadata


def _production_metadata(laravel_root: Path) -> list[ProductionSourceMetadata]:
    metadata: list[ProductionSourceMetadata] = []
    for relative_path, (expected_sha256, expected_blob) in _PRODUCTION_SOURCES.items():
        path = laravel_root / relative_path
        _check_hash(path, expected_sha256, relative_path)
        actual_blob = git_blob_sha1(path)
        if actual_blob != expected_blob:
            raise ValueError(f"{relative_path} Git blob mismatch.")
        metadata.append(
            ProductionSourceMetadata(
                path=relative_path,
                size_bytes=path.stat().st_size,
                sha256=expected_sha256,
                git_blob=expected_blob,
            )
        )
    return metadata


def _output_metadata(
    output_dir: Path,
    names: Sequence[str],
) -> list[OutputFileMetadata]:
    record_counts = {
        "train_predictions.jsonl": 7560,
        "validation_predictions.jsonl": 1620,
        "metrics.json": 1,
        "parity.json": 1,
        "BASELINE_REPORT.md": 1,
    }
    return [
        OutputFileMetadata(
            path=f"services/ml-recommendation/data/baselines/v1/{name}",
            record_count=record_counts[name],
            sha256=sha256_file(output_dir / name),
            size_bytes=(output_dir / name).stat().st_size,
        )
        for name in names
    ]


def evaluate(args: argparse.Namespace) -> None:
    laravel_root = Path(args.laravel_root).resolve()
    output_dir = Path(args.output_dir).resolve()
    paths = {
        "candidates": Path(args.candidates_file).resolve(),
        "jobs": Path(args.jobs_file).resolve(),
        "train": Path(args.train_file).resolve(),
        "validation": Path(args.validation_file).resolve(),
        "assignments": Path(args.assignments_file).resolve(),
        "feature_schema": Path(args.feature_schema_file).resolve(),
        "split_manifest": Path(args.split_manifest).resolve(),
        "test_lock": Path(args.test_lock_file).resolve(),
    }
    for key, path in paths.items():
        _check_hash(path, _EXPECTED_SOURCE_HASHES[key], key)

    lock = _read_json(paths["test_lock"])
    if (
        lock.get("test_locked") is not True
        or int(lock.get("prohibited_before_phase", 0)) != 10
        or int(lock.get("test_record_count", 0)) != 1620
    ):
        raise ValueError("Locked Test policy contract mismatch.")
    locked_test_sha256 = str(lock["test_file_sha256"]).lower()
    if locked_test_sha256 != ("79fcb93b232b63482a9c26d1d0caa660289b7b798776c09f0945865ca6741a05"):
        raise ValueError("Locked Test SHA-256 contract mismatch.")
    locked_test_path = paths["test_lock"].with_name("test.jsonl")
    if not locked_test_path.is_file() or sha256_file(locked_test_path) != locked_test_sha256:
        raise ValueError("Locked Test hash-only integrity verification failed.")
    _validate_not_locked_test(paths["train"], locked_test_sha256)
    _validate_not_locked_test(paths["validation"], locked_test_sha256)

    split_manifest = _read_json(paths["split_manifest"])
    feature_schema = _read_json(paths["feature_schema"])
    if split_manifest.get("source_revision") != args.source_revision:
        raise ValueError("Split manifest source revision mismatch.")
    if split_manifest.get("architecture_sha256") != args.architecture_sha256:
        raise ValueError("Split manifest architecture SHA-256 mismatch.")
    if feature_schema.get("feature_schema_version") != "job-rec-features-v1":
        raise ValueError("Feature schema version mismatch.")

    assignment_records = _read_jsonl(paths["assignments"])
    if len(assignment_records) != 180:
        raise ValueError("Assignment cardinality mismatch.")
    assignment_counts: dict[str, int] = defaultdict(int)
    for record in assignment_records:
        assignment_counts[str(record["split"])] += 1
    if assignment_counts != {"train": 126, "validation": 27, "test": 27}:
        raise ValueError("Assignment split counts mismatch.")

    candidates_list = TypeAdapter(list[Candidate]).validate_python(_read_jsonl(paths["candidates"]))
    jobs_list = TypeAdapter(list[Job]).validate_python(_read_jsonl(paths["jobs"]))
    if len(candidates_list) != 180 or len(jobs_list) != 180:
        raise ValueError("Synthetic source cardinality mismatch.")
    candidates = {candidate.candidate_id: candidate for candidate in candidates_list}
    jobs = {job.job_id: job for job in jobs_list}
    adapted = adapt_sources(candidates_list, jobs_list)

    train_groups, train_labels = _group_pairs(
        _read_jsonl(paths["train"]),
        "train",
    )
    validation_groups, validation_labels = _group_pairs(
        _read_jsonl(paths["validation"]),
        "validation",
    )
    if set(train_groups).intersection(validation_groups):
        raise ValueError("Train/Validation candidate leakage detected.")

    bridge_path = laravel_root / "services/ml-recommendation/tools/laravel_matching_v2_baseline.php"
    train_laravel = invoke_laravel_bridge(
        php_executable=Path(args.php_executable),
        laravel_root=laravel_root,
        bridge_path=bridge_path,
        payload=_bridge_payload(
            "train",
            paths["train"],
            adapted,
            train_groups,
            locked_test_sha256,
        ),
    )
    validation_laravel = invoke_laravel_bridge(
        php_executable=Path(args.php_executable),
        laravel_root=laravel_root,
        bridge_path=bridge_path,
        payload=_bridge_payload(
            "validation",
            paths["validation"],
            adapted,
            validation_groups,
            locked_test_sha256,
        ),
    )

    train_records, train_metrics, train_parity = _evaluate_split(
        split="train",
        groups=train_groups,
        labels=train_labels,
        candidates=candidates,
        jobs=jobs,
        adapted=adapted,
        laravel_predictions=train_laravel,
    )
    validation_records, validation_metrics, validation_parity = _evaluate_split(
        split="validation",
        groups=validation_groups,
        labels=validation_labels,
        candidates=candidates,
        jobs=jobs,
        adapted=adapted,
        laravel_predictions=validation_laravel,
    )
    metrics = MetricsArtifact(
        baseline_evaluation_version=BASELINE_ARTIFACT_VERSION,
        ranking_metrics_version=METRICS_VERSION,
        relevant_label_threshold=RELEVANCE_THRESHOLD,
        gain_definition="2^relevance_label - 1",
        aggregation="candidate_macro",
        splits={"train": train_metrics, "validation": validation_metrics},
    )
    parity = ParityArtifact(
        matching_adapter_version=ADAPTER_VERSION,
        laravel_matching_version=MATCHING_VERSION,
        python_parity_version=PARITY_VERSION,
        train=train_parity,
        validation=validation_parity,
        tolerance=PARITY_TOLERANCE,
        parity_passed=True,
        database_query_count=0,
        database_write_count=0,
    )

    source_metadata = _source_metadata(paths, laravel_root, locked_test_sha256)
    production_metadata = _production_metadata(laravel_root)
    final_output_dir = output_dir
    final_output_dir.parent.mkdir(parents=True, exist_ok=True)
    staging_context = tempfile.TemporaryDirectory(
        prefix=".baseline-stage-",
        dir=final_output_dir.parent,
    )
    output_dir = Path(staging_context.name)

    _atomic_write(
        output_dir / "train_predictions.jsonl",
        _jsonl_content(train_records),
    )
    _atomic_write(
        output_dir / "validation_predictions.jsonl",
        _jsonl_content(validation_records),
    )
    _atomic_write(
        output_dir / "metrics.json",
        _json_content(metrics.model_dump(mode="json", by_alias=True)),
    )
    _atomic_write(
        output_dir / "parity.json",
        _json_content(parity.model_dump(mode="json")),
    )
    _atomic_write(
        output_dir / "BASELINE_REPORT.md",
        _report(
            train_records=train_records,
            validation_records=validation_records,
            train_metrics=train_metrics,
            validation_metrics=validation_metrics,
            train_parity=train_parity,
            validation_parity=validation_parity,
            locked_test_sha256=locked_test_sha256,
            source_revision=args.source_revision,
            architecture_sha256=args.architecture_sha256,
        ),
    )

    output_names = [
        "train_predictions.jsonl",
        "validation_predictions.jsonl",
        "metrics.json",
        "parity.json",
        "BASELINE_REPORT.md",
    ]
    manifest = BaselineManifest(
        baseline_evaluation_version=BASELINE_ARTIFACT_VERSION,
        baseline_evaluator_version=EVALUATOR_VERSION,
        skills_baseline_version=SKILLS_BASELINE_VERSION,
        laravel_matching_version=MATCHING_VERSION,
        python_parity_version=PARITY_VERSION,
        matching_adapter_version=ADAPTER_VERSION,
        ranking_metrics_version=METRICS_VERSION,
        source_dataset_version="synthetic-job-rec-1.0.0",
        source_dataset_schema_version="synthetic-job-rec-schema-v1",
        feature_schema_version="job-rec-features-v1",
        feature_pipeline_version="0.1.0",
        split_version="candidate-group-split-v1",
        source_revision=args.source_revision,
        architecture_sha256=args.architecture_sha256,
        evaluation_release_date="2026-07-24",
        deterministic=True,
        test_evaluated=False,
        source_files=source_metadata,
        production_matching_sources=production_metadata,
        evaluation_splits={
            "train": {"candidate_count": 126, "record_count": 7560},
            "validation": {"candidate_count": 27, "record_count": 1620},
        },
        metric_definitions={
            "NDCG@5": "graded gain 2^relevance_label - 1, cutoff 5",
            "NDCG@10": "graded gain 2^relevance_label - 1, cutoff 10",
            "Precision@5": "binary relevance >= 2, fixed denominator 5",
            "Recall@5": "binary relevance >= 2; zero when no relevant jobs",
            "MRR": "first rank with relevance >= 2; zero when none",
            "HitRate@5": "one when a relevance >= 2 occurs in top 5, else zero",
        },
        relevance_threshold=2,
        adapter_policy=[
            "global_normalized_skill_registry",
            "fixed_experience_start_2000-01-01",
            "fixed_published_at_2026-01-01T00:00:00Z",
            "source_education_level_for_jobs",
            "lead_maps_to_senior",
        ],
        parity_policy={
            "score_and_component_tolerance": PARITY_TOLERANCE,
            "rank_match_required": True,
            "pair_coverage_required": "100%",
        },
        output_files=_output_metadata(output_dir, output_names),
        intended_use=[
            "existing_baseline_reference_for_phase_8",
            "laravel_python_matching_parity_evidence",
            "train_and_validation_model_comparison_only",
        ],
        limitations=[
            "synthetic_data",
            "adapter_mapping_gap",
            "candidate_local_dynamic_tfidf",
            "validation_has_27_candidate_groups",
            "parity_does_not_establish_production_quality",
            "locked_test_not_evaluated",
            "no_trained_model",
        ],
        reproducibility_command=(
            "evaluate-existing-baselines --candidates-file "
            "services/ml-recommendation/data/synthetic/v1/candidates.jsonl "
            "--jobs-file services/ml-recommendation/data/synthetic/v1/jobs.jsonl "
            "--train-file services/ml-recommendation/data/splits/v1/train.jsonl "
            "--validation-file services/ml-recommendation/data/splits/v1/validation.jsonl "
            "--assignments-file services/ml-recommendation/data/splits/v1/assignments.jsonl "
            "--feature-schema-file "
            "services/ml-recommendation/data/features/v1/feature_schema.json "
            "--split-manifest services/ml-recommendation/data/splits/v1/manifest.json "
            "--test-lock-file services/ml-recommendation/data/splits/v1/test_lock.json "
            "--output-dir services/ml-recommendation/data/baselines/v1 "
            "--php-executable php --laravel-root . --source-revision "
            f"{args.source_revision} --architecture-sha256 {args.architecture_sha256}"
        ),
    )
    _atomic_write(
        output_dir / "manifest.json",
        _json_content(manifest.model_dump(mode="json")),
    )
    final_output_dir.mkdir(parents=True, exist_ok=True)
    for name in [*output_names, "manifest.json"]:
        os.replace(output_dir / name, final_output_dir / name)
    staging_context.cleanup()
    print(
        "Baseline evaluation complete: "
        "Train 7,560 / Validation 1,620; parity passed; "
        "database queries/writes 0/0; Locked Test not evaluated."
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Evaluate existing baselines on Train and Validation only."
    )
    parser.add_argument("--candidates-file", required=True)
    parser.add_argument("--jobs-file", required=True)
    parser.add_argument("--train-file", required=True)
    parser.add_argument("--validation-file", required=True)
    parser.add_argument("--assignments-file", required=True)
    parser.add_argument("--feature-schema-file", required=True)
    parser.add_argument("--split-manifest", required=True)
    parser.add_argument("--test-lock-file", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--php-executable", required=True)
    parser.add_argument("--laravel-root", required=True)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    evaluate(args)
    return 0
