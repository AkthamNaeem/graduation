# ML Job Recommendation — Final Handover

## 1. Executive Summary

The Smart Recruitment Platform now contains a complete, versioned ML Job
Recommendation implementation from deterministic synthetic data generation
through Laravel integration, safe fallback, durable persistence, container
packaging, and end-to-end verification. The public contract remains
`GET /api/v1/jobs/recommended`. Laravel remains the business authority; the
ML service is a bounded decision-support component.

Completion means the 18-phase academic implementation plan is complete. It
does not mean production deployment, production load validation, fairness
certification, or future operational work is complete.

## 2. Project and Feature Scope

In scope is job recommendation for an authenticated job seeker. Laravel
discovers eligible jobs, sends professional facts for those jobs to the
internal ML service when enabled, reconciles the response, applies final
deterministic ordering, persists the result, caches a compact pointer, and
returns the existing public resource.

Candidate Ranking for employers was not moved into ML. Existing candidate
matching behavior remains Laravel-owned. No automatic hiring, rejection,
shortlisting, notification, or profile mutation is performed.

## 3. Completed Implementation Phases

| Phase | Outcome |
|---:|---|
| 1–3 | Problem framing, repository audit, and frozen architecture |
| 4 | Deterministic synthetic Dataset |
| 5 | Shared versioned Feature Pipeline |
| 6 | Candidate-grouped Train/Validation/Locked Test split |
| 7 | Skills-only and MatchingService 2.0 baselines |
| 8 | Initial XGBRanker |
| 9 | Bounded validation-only tuning and frozen T06 selection |
| 10 | One-time Locked Test evaluation |
| 11 | SHAP explainability and reason-code contract |
| 12 | Strict FastAPI inference service and Bundle |
| 13 | Laravel ML client |
| 14 | RecommendationOrchestrator and MatchingService 2.0 fallback |
| 15 | Durable persistence, cache, invalidation, and pruning |
| 16 | Hardened Docker packaging and deployment runbook |
| 17 | Public-HTTP E2E and failure verification |
| 18 | Final documentation, traceability, demo, and handover |

Locked Test records were not parsed, predicted, or evaluated after Phase 10.
The final historical-reproduction regression obtains their recorded SHA-256
from the frozen initial-model manifest and enforces a zero-open guard. It runs
real synthetic Train/Validation training only in a pytest temporary directory
and reproduced all eight historical artifacts byte-for-byte. Current trainer
provenance identifies the portable Phase 7 manifest, while the historical
manifest hash is supplied only inside that reproduction test. No production
training, tuning, model rebuild, or re-evaluation occurred.

Both the Phase 17 and Phase 18 integrity tests recognize the two approved
post-handover Python maintenance paths explicitly. No wildcard or directory
exemption was introduced, and all other protected paths retain their size and
SHA-256 enforcement.

## 4. High-Level Architecture

```text
Client
→ Laravel public API
→ Authentication and eligibility
→ RecommendationOrchestrator
→ Context fingerprint
→ Cache pointer
→ Durable recommendation run
→ RecommendationMlClient
→ FastAPI Container
→ Feature Pipeline
→ XGBRanker
→ Explainability reason codes
→ Laravel reconciliation and sorting
→ Persistence and cache
→ RecommendedJobResource
```

A valid cache or persistence hit returns before the ML call. An unavailable or
invalid ML result uses the unchanged MatchingService 2.0 fallback. The
authoritative architecture decisions are in
[ARCHITECTURE.md](../ARCHITECTURE.md).

## 5. Laravel Responsibilities

Laravel owns:

- Sanctum authentication, authorization, and account/company middleware.
- Eligibility and prior-application exclusion.
- Job discovery and the candidate pool supplied to ML.
- Context fingerprinting and invalidation.
- ML transport, version coupling, and response reconciliation.
- Final sorting, tie-breaking, limiting, and public serialization.
- Atomic recommendation persistence and compact cache pointers.
- MatchingService 2.0 fallback and safe fallback codes.
- Retention and pruning of expired recommendation runs.

Laravel is the only eligibility authority. FastAPI may rank only jobs Laravel
already deemed eligible.

## 6. FastAPI Responsibilities

FastAPI owns:

- Strict request and recursive privacy validation.
- Construction of the frozen `job-rec-features-v1` feature vector.
- Frozen `xgbranker-tuned-v1` inference.
- Validation-derived `display_score` conversion.
- SHAP-based allowlisted attribution and reason codes.
- Version and Bundle contract enforcement.
- Live, ready, model-metadata, and ranking endpoints.

FastAPI has no database, performs no user/job discovery, makes no business
decision, and performs no runtime training or tuning.

## 7. Model Lifecycle

The model lifecycle is intentionally separated:

1. Phase 4 generated 180 synthetic candidates, 180 jobs, and 10,800 labeled
   pairs.
2. Phase 6 split by candidate into 7,560 Train, 1,620 Validation, and 1,620
   Locked Test records.
3. Phase 8 trained the initial XGBRanker.
4. Phase 9 selected T06 using Validation only and froze
   `xgbranker-tuned-v1`.
5. Phase 10 performed the single authorized Locked Test evaluation.
6. Phase 11 derived explainability without changing the model.
7. Phase 12 packaged the frozen model into
   `job-rec-inference-bundle-v1`.

The model is trained on synthetic data. Any future retraining requires a new,
controlled policy and new versioned artifacts.

## 8. Feature Pipeline

`FeaturePipelineV1` produces 103 ordered features under
`job-rec-features-v1`. Training, evaluation, explainability, and runtime
inference share this implementation and schema. Identity, contact,
demographic, CV, assessment, interview, credential, session, and database
secret fields are outside the feature contract.

The canonical schema is
[feature_schema.json](../../../services/ml-recommendation/data/features/v1/feature_schema.json).

## 9. Explainability

Explanations use SHAP contribution values grouped into an allowlisted
recommendation reason contract. They provide model attribution, not causality.
A positive or negative attribution describes how a feature group affected the
model margin for that pair; it does not prove why a person will succeed or why
a job is appropriate.

`display_score` is a bounded relevance display value. It is not a
probability, acceptance likelihood, fairness measure, or guaranteed outcome.
See the
[Model Explainability Report](../../../services/ml-recommendation/data/explainability/tuned/v1/MODEL_EXPLAINABILITY_REPORT.md).

## 10. Public Recommendation Flow

1. The authenticated seeker calls `GET /api/v1/jobs/recommended`.
2. Laravel evaluates eligibility.
3. Laravel computes a content-addressed context fingerprint.
4. Laravel checks a cache pointer, then durable persistence.
5. On a miss, Laravel maps professional facts to the internal contract.
6. The ML service validates, constructs features, infers, and explains.
7. Laravel reconciles job identities and versions.
8. Laravel applies final deterministic ranking.
9. Laravel persists a complete run and items, then caches the pointer.
10. `RecommendedJobResource` returns the existing public envelope.

## 11. Eligibility

Eligibility is evaluated once per request by
`RecommendationEligibilityProvider`. The rules include job state,
publication/deadline conditions, approved employer state, and exclusion of
jobs already applied to. The ML service cannot add jobs, query jobs, or
override eligibility.

## 12. Fallback

MatchingService 2.0 is the stable fallback. It is used when ML is disabled,
misconfigured, over candidate capacity, unreachable, unauthenticated,
rate-limited, unavailable, or returns a contract-invalid response. The public
endpoint remains available and records a safe code where applicable.

The public engine identifiers are `ml_xgbranker`, `matching_v2`, and
`matching_v2_fallback`. Because fallback intentionally hides provider
outages from clients, operations must monitor fallback rates.

## 13. Persistence and Cache

`recommendation_runs` stores profile, context, engine, versions, counts,
fallback state, and generation/expiry metadata.
`recommendation_items` stores unique job/rank rows, display/raw scores,
matching version, breakdown, and reasons. Writes are atomic and cascade on
run deletion.

The cache stores only a versioned run pointer, context hash, limit, and expiry.
Default TTLs are 900 seconds for ML success and 60 seconds for fallback or an
empty result. A content change produces a new context hash. The prune command
is `php artisan recommendations:prune --dry-run` or, after review,
`php artisan recommendations:prune`.

## 14. Docker Packaging

The existing image is
`workeyx/ml-recommendation:0.2.0-phase16`. It uses Python 3.12.10, UID/GID
10001, one worker, a read-only root filesystem contract, a writable temporary
filesystem, dropped capabilities, and live/ready health checks. The Bundle is
verified during startup.

Phase 18 did not rebuild the image. Packaging details are in
[CONTAINER_CARD.md](../../../services/ml-recommendation/deployment/container/v1/CONTAINER_CARD.md).

## 15. Security and Privacy

- Public access requires Sanctum plus existing account middleware.
- Internal ranking requires a service token of at least 32 random characters.
- The service token is never stored in documentation or artifacts.
- Outbound Laravel payloads contain professional facts and opaque references,
  not identity/contact data.
- FastAPI recursively rejects sensitive fields.
- Errors and logs use safe codes without provider bodies, payloads, secrets,
  paths, or stack traces.
- The container runs non-root with a restricted filesystem and capabilities.
- AI output is decision-support only; a human remains responsible.

## 16. API Contracts

Locked versions are:

| Contract | Version |
|---|---|
| Model | `xgbranker-tuned-v1` |
| Bundle | `job-rec-inference-bundle-v1` |
| Feature Schema | `job-rec-features-v1` |
| Internal API | `recommendation-ranking-api-v1` |
| Explanation | `recommendation-explanation-contract-v1` |
| Matching fallback | `MatchingService 2.0` |

The detailed internal request, response, error, privacy, and endpoint contract
is [INFERENCE_CONTRACT.md](../../../services/ml-recommendation/data/contracts/inference/v1/INFERENCE_CONTRACT.md).

## 17. Database Schema

The Phase 15 migration creates only `recommendation_runs` and
`recommendation_items`, with foreign keys, lookup/expiry indexes, unique
run-job and run-rank constraints, and cascades. No Phase 18 migration or schema
change exists.

## 18. Configuration

Laravel configuration uses environment names under
`ML_RECOMMENDATION_*` and `RECOMMENDATION_*`, including enablement, base
URL, service token, timeouts, request limits, locked versions, TTLs, and
retention. FastAPI uses the documented `ML_*` environment names. Secrets
belong in the deployment secret store, never source control.

The source of truth is `config/recommendation_ml.php` and the runtime
contract in the container manifest.

## 19. Local Setup

Use a non-production database and runtime-generated tokens:

```powershell
composer install
php artisan migrate
$env:ML_SERVICE_TOKEN = '<temporary-token>'
docker compose -f compose.ml.yml up -d --no-build
php artisan serve
```

Configure Laravel with the same temporary internal token and use a separately
created `<sanctum-token>` for the public request. Never reuse production
credentials or data. The repeatable integrated proof is:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/phase17/run-e2e.ps1
```

## 20. Testing Summary

Phase 17 recorded 35 passed E2E matrix scenarios and zero failures. It proved
cold ML, cache hit, persistence hit, invalidation, eligibility, stopped
container, wrong token, invalid Bundle, 18 provider/network faults, safe
corruption recovery, bounded concurrency, privacy, and cleanup.

The final verification commands and current results are consolidated in
[FINAL_VERIFICATION_REPORT.md](FINAL_VERIFICATION_REPORT.md).

## 21. Deployment Sequence

No production deployment has occurred. A future controlled deployment should:

1. Provision an internal-only runtime and secret-managed service token.
2. Apply the existing recommendation migration with normal backup controls.
3. Deploy the pinned image and verify image/Bundle identities.
4. Verify liveness, readiness, metadata, and a protected synthetic rank call.
5. Configure Laravel locked versions and timeouts with ML disabled.
6. Deploy Laravel and verify MatchingService 2.0 behavior.
7. Enable ML for a controlled cohort.
8. Monitor readiness, latency, fallback rate, contract failures, and DB/cache.
9. Expand only after explicit operational acceptance.

Use [DEPLOYMENT.md](../../../services/ml-recommendation/DEPLOYMENT.md) as the
deployment source of truth.

## 22. Rollback

Rollback does not require a model rebuild:

1. Set `ML_RECOMMENDATION_ENABLED=false`.
2. Clear Laravel configuration cache through the normal deployment process.
3. Confirm the public endpoint uses MatchingService 2.0.
4. If required, restore the last approved image/Bundle and locked versions.
5. Preserve recommendation history for diagnosis; prune only by retention
   policy.
6. Verify the public contract and investigate safe internal codes.

## 23. Token Rotation

1. Generate a new random token in the approved secret manager.
2. Configure the ML service to accept the new token during a controlled
   overlap where the platform supports it, or schedule an atomic rotation.
3. Update Laravel's secret reference.
4. Restart/reload both sides through the deployment process.
5. Verify ready and protected rank endpoints.
6. Revoke the old token and scan logs only for safe failure codes.

Never place a token value in a command history, report, manifest, or URL.

## 24. Monitoring Recommendations

Track:

- Liveness/readiness and restart count.
- ML call latency and timeout rate.
- `ml_xgbranker` versus `matching_v2_fallback` share.
- Safe failure-code counts by category.
- Contract/version mismatch count.
- Candidate and returned-count distributions.
- Cache-hit and persistence-hit ratios.
- Run/item write failures and prune volume.
- Model input drift using approved non-sensitive aggregate statistics.
- Human review and fairness indicators after a governed real-data program.

Production dashboards, alerts, SLOs, and drift thresholds remain future work.

## 25. Known Limitations

- Training and evaluation data are synthetic.
- No production fairness evaluation has been performed.
- No production load or race test has been performed.
- Bounded local concurrency used the Laravel development server.
- Cold concurrent misses may create equivalent runs without a distributed
  lock.
- Fallback can mask ML outages unless monitored.
- The one-worker image requires horizontal scaling for higher throughput.
- No production vulnerability scan result is claimed.
- Composer advisory lookup was externally unavailable during Phase 17.
- Current Mypy/NumPy stubs require explicit Python 3.12 compatibility mode.
- No production deployment, monitoring, alerting, CI/CD, or drift automation
  is included.

## 26. Future Improvements

Future operational work includes a consented real-world labeled Dataset,
fairness evaluation, production monitoring and alerting, distributed locking
if measurements justify it, CI/CD, external vulnerability scanning, production
load testing, managed key/secret integration, drift monitoring, and a
controlled retraining/promotion/rollback policy.

These are not missing items from the completed academic 18-phase plan.

## 27. Source-of-Truth Document Index

| Topic | Source of truth |
|---|---|
| Architecture and locked decisions | [ARCHITECTURE.md](../ARCHITECTURE.md) |
| Laravel phases 13–18 | [BACKEND_IMPLEMENTATION_REPORT.md](../../../BACKEND_IMPLEMENTATION_REPORT.md) |
| ML phases 4–12 | [ML service README](../../../services/ml-recommendation/README.md) |
| Internal API | [INFERENCE_CONTRACT.md](../../../services/ml-recommendation/data/contracts/inference/v1/INFERENCE_CONTRACT.md) |
| Model/Bundle identity | [BUNDLE_CARD.md](../../../services/ml-recommendation/data/bundles/recommendation/v1/BUNDLE_CARD.md) |
| Explainability | [MODEL_EXPLAINABILITY_REPORT.md](../../../services/ml-recommendation/data/explainability/tuned/v1/MODEL_EXPLAINABILITY_REPORT.md) |
| Container and operations | [DEPLOYMENT.md](../../../services/ml-recommendation/DEPLOYMENT.md) |
| Phase 17 evidence | [PHASE_17_E2E_REPORT.md](../phase17/PHASE_17_E2E_REPORT.md) |
| Requirements | [REQUIREMENTS_TRACEABILITY_MATRIX.json](REQUIREMENTS_TRACEABILITY_MATRIX.json) |
| Demo | [DEMO_RUNBOOK.md](DEMO_RUNBOOK.md) |
| Final verification | [FINAL_VERIFICATION_REPORT.md](FINAL_VERIFICATION_REPORT.md) |
| Machine handover | [FINAL_HANDOVER_MANIFEST.json](FINAL_HANDOVER_MANIFEST.json) |

## 28. Handover Checklist

- [x] Architecture and responsibility boundary documented.
- [x] Frozen model, Bundle, Feature Schema, and contracts identified.
- [x] Laravel eligibility, fallback, persistence, cache, and pruning documented.
- [x] Security, privacy, explainability, and AI limitations documented.
- [x] Local demo and failure behavior documented.
- [x] Requirements mapped to implementation, tests, and evidence.
- [x] Phase 18 cryptographic baseline established.
- [x] Production deployment explicitly recorded as not performed.
- [x] Operational future work separated from academic completion.

The detailed checklist is
[PROJECT_COMPLETION_CHECKLIST.md](PROJECT_COMPLETION_CHECKLIST.md).

## 29. Completion Status

**ML Job Recommendation implementation plan: 100% complete.**

**Production deployment: not performed.**

**AI role: decision-support only.**

The system recommends and explains; it does not make hiring decisions.
