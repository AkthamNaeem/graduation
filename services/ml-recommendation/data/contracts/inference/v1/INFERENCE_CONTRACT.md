# Internal Recommendation Inference Contract

## Identity and scope

- API contract: `recommendation-ranking-api-v1`
- Service: `ml-recommendation` `0.2.0`
- Bundle: `job-rec-inference-bundle-v1`
- Release date: `2026-07-25`
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
{
  "request_id": null,
  "error": {
    "code": "REQUEST_VALIDATION_FAILED",
    "message": "Request validation failed.",
    "details": {}
  }
}
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
