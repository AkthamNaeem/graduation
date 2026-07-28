"""Deterministic OpenAPI and example contract artifact export."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import sys
from pathlib import Path
from typing import TYPE_CHECKING, Any, Final

from pydantic import ValidationError

from smart_recruitment_ml.bundle.loader import load_bundle
from smart_recruitment_ml.core.config import Settings
from smart_recruitment_ml.core.inference import API_CONTRACT_VERSION, rank_jobs, ready_state
from smart_recruitment_ml.main import create_app
from smart_recruitment_ml.schemas.inference import RankRequest

if TYPE_CHECKING:
    from collections.abc import Mapping, Sequence

SERVICE_VERSION: Final = "0.2.0"
CONTRACT_RELEASE_DATE: Final = "2026-07-25"
BUNDLE_VERSION: Final = "job-rec-inference-bundle-v1"
SOURCE_REVISION: Final = "6cd51f733d5197e0c3f6b7dfb3711c2860ffef71"
ARCHITECTURE_SHA256: Final = "60eb219152ce26b525735ed65564f667d403bf438f29000b4ece90d65950553f"
CANDIDATES_SHA256: Final = "5d0ddbe461437afd80576e4b36044c94e083adfe2d232c05e4653a9fa54ef320"
JOBS_SHA256: Final = "7aa398a1957c8851fb4fea4743f953be3f915177ae19266970ccf2d61440e74d"
EXAMPLE_CANDIDATE_ID: Final = "cand_0003"
EXAMPLE_JOB_IDS: Final = ("job_0121", "job_0122", "job_0123")
EXAMPLE_REQUEST_ID: Final = "00000000-0000-4000-8000-000000000012"
EXPECTED_ROUTES: Final = (
    "/health/live",
    "/health/ready",
    "/v1/model/metadata",
    "/v1/recommendations/rank",
)


def _sha256(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def _read_locked(path: Path, expected_hash: str, label: str) -> bytes:
    content = path.read_bytes()
    if _sha256(content) != expected_hash:
        raise ValueError(f"{label} checksum mismatch.")
    return content


def _json_bytes(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")


def _jsonl_objects(content: bytes, label: str) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for line_number, line in enumerate(content.splitlines(), start=1):
        try:
            value = json.loads(line)
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise ValueError(f"{label} line {line_number} is invalid.") from error
        if not isinstance(value, dict):
            raise ValueError(f"{label} line {line_number} is not an object.")
        records.append(value)
    return records


def _request_example(
    candidates_file: Path,
    jobs_file: Path,
) -> tuple[dict[str, Any], dict[str, Any]]:
    candidate_bytes = _read_locked(candidates_file, CANDIDATES_SHA256, "Candidates")
    job_bytes = _read_locked(jobs_file, JOBS_SHA256, "Jobs")
    candidates = {
        str(item.get("candidate_id")): item
        for item in _jsonl_objects(candidate_bytes, "Candidates")
    }
    jobs = {str(item.get("job_id")): item for item in _jsonl_objects(job_bytes, "Jobs")}
    if EXAMPLE_CANDIDATE_ID not in candidates or any(
        job_id not in jobs for job_id in EXAMPLE_JOB_IDS
    ):
        raise ValueError("Frozen Contract example records are unavailable.")
    candidate = dict(candidates[EXAMPLE_CANDIDATE_ID])
    candidate.pop("candidate_id", None)
    example_jobs: list[dict[str, Any]] = []
    for synthetic_id in EXAMPLE_JOB_IDS:
        professional_facts = dict(jobs[synthetic_id])
        professional_facts.pop("job_id", None)
        example_jobs.append(
            {
                "job_id": int(synthetic_id.split("_", maxsplit=1)[1]),
                "professional_facts": professional_facts,
            },
        )
    request = {
        "request_id": EXAMPLE_REQUEST_ID,
        "feature_schema_version": "job-rec-features-v1",
        "candidate": {
            "profile_ref": "contract-example-profile",
            "professional_facts": candidate,
        },
        "jobs": example_jobs,
        "limit": 3,
    }
    source_metadata = {
        "candidates": {
            "path": "services/ml-recommendation/data/synthetic/v1/candidates.jsonl",
            "bytes": len(candidate_bytes),
            "sha256": CANDIDATES_SHA256,
        },
        "jobs": {
            "path": "services/ml-recommendation/data/synthetic/v1/jobs.jsonl",
            "bytes": len(job_bytes),
            "sha256": JOBS_SHA256,
        },
    }
    return request, source_metadata


def _contract_document() -> bytes:
    return f"""# Internal Recommendation Inference Contract

## Identity and scope

- API contract: `{API_CONTRACT_VERSION}`
- Service: `ml-recommendation` `{SERVICE_VERSION}`
- Bundle: `{BUNDLE_VERSION}`
- Release date: `{CONTRACT_RELEASE_DATE}`
- Intended exposure: internal only

The service has no database, cache, external HTTP calls, runtime training, or
public endpoint assumption. Laravel will own eligibility, reconciliation,
published-at/ID tie-breaking, final limit application, persistence, and public
authorization in later phases.

## Endpoints and authentication

| Endpoint | Authentication | Success |
| --- | --- | ---: |
| `GET /health/live` | none | 200 |
| `GET /health/ready` | none | 200 |
| `GET /v1/model/metadata` | `X-ML-Service-Token` | 200 |
| `POST /v1/recommendations/rank` | `X-ML-Service-Token` | 200 |

Missing and incorrect tokens both return HTTP 401 with
`SERVICE_AUTHENTICATION_FAILED`. Health responses never expose secrets or
local paths.

## Request

`RankRequest` requires a UUID request ID, Feature Schema
`job-rec-features-v1`, one Candidate, 1-500 unique positive Job IDs, and limit
1-100 no greater than Job count. All models forbid extra fields and use strict
validation. API callers send professional facts, never Feature vectors.

Candidate facts: primary/adjacent domains, headline, career level, total
experience, education, skills with proficiency and years, preferred work modes,
and preferred employment types.

Job facts: domain, title, department, description, responsibilities, required
skills with weights, nice-to-have skills, minimum experience, education,
career level, work mode, and employment type.

Text, list, and numeric bounds are authoritative in OpenAPI. Non-finite values,
unknown fields, invalid UUIDs, duplicate IDs, unsupported schemas, and
limit/count violations are rejected.

## Privacy

A recursive denylist rejects identity, contact, demographic, CV, application,
assessment, interview, internal-note, credential, session, and database-secret
keys with HTTP 422 `SENSITIVE_FIELD_NOT_ALLOWED`. Legitimate nested skill
`name` is the sole contextual exception. Sensitive input values are never
reflected in errors.

## Response and ranking

The service returns one prediction for every supplied Job:
`prediction_count = job count`. `limit` is echoed as `requested_limit` and does
not truncate predictions. Ordering is `raw_score DESC`, then `job_id ASC`, with
complete ranks `1..job_count`.

`raw_score` is the frozen XGBoost ranking margin. `display_score` is a clipped
0-100 Validation min-max relevance indicator; it is not a calibrated
probability or acceptance prediction.

Each prediction contains at most three positive and three negative exact Tree
SHAP group factors. Codes are allowlisted, strengths are in `[0,1]`, and raw
Feature names and values are never returned. Attribution is not causality,
fairness certification, or a hiring decision.

## Errors

All controlled errors use:

```json
{{
  "request_id": null,
  "error": {{
    "code": "REQUEST_VALIDATION_FAILED",
    "message": "Request validation failed.",
    "details": {{}}
  }}
}}
```

Stable codes: `REQUEST_VALIDATION_FAILED`, `SENSITIVE_FIELD_NOT_ALLOWED`,
`FEATURE_SCHEMA_VERSION_UNSUPPORTED`, `DUPLICATE_JOB_ID`,
`JOB_LIMIT_EXCEEDED`, `SERVICE_AUTHENTICATION_FAILED`,
`MODEL_BUNDLE_NOT_READY`, `MODEL_METADATA_UNAVAILABLE`,
`FEATURE_PIPELINE_FAILED`, and `INFERENCE_CONTRACT_FAILED`.

No response contains a stack trace, raw payload, token, or artifact path.

## Frozen state

The Bundle is loaded once at startup. Requests do not reload, fit, train,
modify, or save the Model. Phase 12 reads no locked Test features or saved Test
predictions and performs no Test inference or evaluation rerun.
""".replace("\r\n", "\n").encode("utf-8")


def _publish_atomically(output_dir: Path, artifacts: Mapping[str, bytes]) -> None:
    parent = output_dir.resolve(strict=False).parent
    parent.mkdir(parents=True, exist_ok=True)
    temporary = parent / f".{output_dir.name}.phase12-tmp"
    backup = parent / f".{output_dir.name}.phase12-backup"
    if temporary.exists() or backup.exists():
        raise ValueError("Atomic Contract export workspace is not clean.")
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


def export_contract(
    *,
    output_dir: Path,
    candidates_file: Path,
    jobs_file: Path,
    bundle_dir: Path,
    service_token: str,
    source_revision: str,
    architecture_sha256: str,
) -> dict[str, Any]:
    """Generate OpenAPI, validated examples, documentation, and manifest."""
    if source_revision.casefold() != SOURCE_REVISION:
        raise ValueError("Source revision mismatch.")
    if architecture_sha256.casefold() != ARCHITECTURE_SHA256:
        raise ValueError("Architecture checksum mismatch.")
    bundle = load_bundle(bundle_dir)
    request_example, source_files = _request_example(candidates_file, jobs_file)
    request = RankRequest.model_validate(request_example)
    response = rank_jobs(request, ready_state(bundle)).model_dump(mode="json")
    response["latency_ms"] = 0.0
    settings = Settings(
        bundle_dir=bundle_dir,
        service_token=service_token,
        docs_enabled=True,
    )
    openapi = create_app(settings).openapi()
    if tuple(sorted(openapi.get("paths", {}))) != tuple(sorted(EXPECTED_ROUTES)):
        raise ValueError("OpenAPI route set mismatch.")
    outputs = {
        "INFERENCE_CONTRACT.md": _contract_document(),
        "openapi.json": _json_bytes(openapi),
        "request.example.json": _json_bytes(request_example),
        "response.example.json": _json_bytes(response),
    }
    forbidden_secret = service_token.encode("utf-8")
    if any(forbidden_secret in content for content in outputs.values()):
        raise ValueError("Service token leaked into Contract artifacts.")
    manifest = {
        "api_contract_version": API_CONTRACT_VERSION,
        "service_version": SERVICE_VERSION,
        "contract_release_date": CONTRACT_RELEASE_DATE,
        "deterministic": True,
        "bundle_version": BUNDLE_VERSION,
        "request_schema": "RankRequest",
        "response_schema": "RankResponse",
        "error_schema": "ErrorEnvelope",
        "routes": list(EXPECTED_ROUTES),
        "authentication": {
            "header": "X-ML-Service-Token",
            "protected_routes": [
                "/v1/model/metadata",
                "/v1/recommendations/rank",
            ],
            "health_routes_unauthenticated": True,
        },
        "limits": {
            "minimum_jobs": 1,
            "maximum_jobs": 500,
            "minimum_limit": 1,
            "maximum_limit": 100,
            "prediction_policy": "one_prediction_per_job_without_limit_truncation",
        },
        "source_revision": SOURCE_REVISION,
        "architecture_sha256": ARCHITECTURE_SHA256,
        "source_files": source_files,
        "output_files": [
            {
                "path": name,
                "records": 1,
                "bytes": len(content),
                "sha256": _sha256(content),
            }
            for name, content in sorted(outputs.items())
        ],
        "test_non_usage": {
            "test_features_read": False,
            "test_predictions_read": False,
            "test_inference_run": False,
            "test_evaluation_rerun": False,
        },
    }
    artifacts = dict(outputs)
    artifacts["contract_manifest.json"] = _json_bytes(manifest)
    _publish_atomically(output_dir, artifacts)
    return manifest


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Export deterministic internal inference Contract artifacts.",
    )
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--candidates-file", type=Path, required=True)
    parser.add_argument("--jobs-file", type=Path, required=True)
    parser.add_argument("--source-revision", required=True)
    parser.add_argument("--architecture-sha256", required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    """CLI entry point using ML_BUNDLE_DIR and ML_SERVICE_TOKEN settings."""
    args = _parser().parse_args(argv)
    try:
        settings = Settings()
        if settings.service_token is None:
            raise ValueError("ML_SERVICE_TOKEN is required for Contract export.")
        manifest = export_contract(
            output_dir=args.output_dir,
            candidates_file=args.candidates_file,
            jobs_file=args.jobs_file,
            bundle_dir=settings.bundle_dir,
            service_token=settings.service_token,
            source_revision=args.source_revision,
            architecture_sha256=args.architecture_sha256,
        )
    except (OSError, ValidationError, ValueError) as error:
        print(f"Inference Contract export failed: {error}", file=sys.stderr)
        return 2
    print(
        f"Exported {manifest['api_contract_version']} at {args.output_dir}",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
