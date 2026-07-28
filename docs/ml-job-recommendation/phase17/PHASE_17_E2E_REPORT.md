# Phase 17 — End-to-End Integration and Failure Testing

## 1. Baseline

The pre-change gate verified branch `master` at
`6cd51f733d5197e0c3f6b7dfb3711c2860ffef71`. The protected baseline
artifact SHA-256 was
`CB3959FF2550064CA4F7D82953A1E6E0A539A1C32A2BC97ABB1E64F09FDBCAC0`,
its 864 records were ordinal and unique, and its recomputed aggregate was
`8837B0CABFB1145F808613FA3D8B77253C7CB8236DC5D281F630CCE2F3871189`.
The pre-change live mismatch count was zero. The baseline does not include
itself. The historical Phase 16 mismatch count remains indeterminate under
the accepted evidence-limitation waiver.

## 2. Existing End-to-End Architecture

The tested path was the existing public
`GET /api/v1/jobs/recommended` route:

`JobPostingController -> RecommendationOrchestrator -> eligibility/context fingerprint -> cache -> recommendation persistence -> RecommendationMlClient or MatchingService 2.0 fallback -> persistence/cache -> RecommendedJobResource`.

Phase 17 added no layer, endpoint, ranking rule, eligibility rule, cache
driver, migration, queue, or user-facing feature. Phase 13–16 production
bindings and contracts were exercised as found.

## 3. Files Changed

Existing files modified:

- `BACKEND_IMPLEMENTATION_REPORT.md`: one Phase 17 evidence section.

E2E tests:

- `tests/Feature/Api/V1/RecommendationEndToEndTest.php`
- `tests/Feature/Api/V1/RecommendationFailureMatrixTest.php`
- `tests/Feature/Api/V1/RecommendationConcurrencyTest.php`

Failure harness and scripts:

- `scripts/phase17/fault_server.py`
- `scripts/phase17/run-e2e.ps1`
- `scripts/phase17/README.md`

Machine-readable artifact and documentation:

- `docs/ml-job-recommendation/phase17/E2E_TEST_MATRIX.json`
- `docs/ml-job-recommendation/phase17/PHASE_17_E2E_REPORT.md`

Production fixes: none. No application source, ML source, model, Bundle,
contract, Docker packaging, migration, or existing behavior was modified.

## 4. Test Environment

The coordinator used Laravel 12's local HTTP server on loopback port 8090,
the existing image `workeyx/ml-recommendation:0.2.0-phase16` on loopback
port 8100, and a standard-library fault server on loopback port 8110.
Persistence and cache used a newly migrated temporary SQLite database. All
credentials and fixture markers were generated per run, remained process
local, were redacted from output, and were deleted in `finally`. No image
was built or replaced.

## 5. Canonical Fixture

The fixture contained one active job seeker with profile, skills, experience,
and education; an approved employer; five eligible published jobs with
skills; and excluded jobs covering draft, expired, unapproved-company, and
already-applied states. Unique synthetic contact markers existed only to
prove privacy scanning. No actual user PII or production data was used.

## 6. ML Cold Success

The first public request returned HTTP 200 with
`recommendation_engine=ml_xgbranker`. Exactly one provider request was
made. Exactly one recommendation run and five complete recommendation items
were persisted. MatchingService was not called.

## 7. Cache Hit

The immediate repeat returned the identical canonical public body. It made
zero ML calls, zero MatchingService calls, zero new run writes, and zero new
item writes.

## 8. Persistence Hit

After an explicit cache flush, the same public request returned the identical
body from the existing database run, created no run or items, and warmed the
cache pointer again without an ML or Matching call.

## 9. Invalidation Matrix

| Mutation | Context changed | Recomputed | Old result reused |
|---|---:|---:|---:|
| Job-seeker profile headline | yes | yes | no |
| Eligible job ranking description | yes | yes | no |
| Prior application changes candidate eligibility | yes | yes | no |

The real loopback E2E run observed the first row. Phase 17 Laravel E2E tests
cover the second and third rows through the actual orchestrator and public
resource flow.

## 10. Eligibility Verification

The five valid jobs were included. Draft, expired, unapproved-company, and
already-applied jobs were excluded before ranking and absent publicly. The
prior-application mutation reduced the eligible set without changing the
eligibility implementation.

## 11. Container-Down Fallback

With the primary container stopped and a new context, the public endpoint
returned HTTP 200 with `matching_v2_fallback`. One failed provider attempt,
one MatchingService call, and one complete fallback run occurred. The stored
safe code was `ML_TRANSPORT_FAILURE`; no provider detail was exposed.

## 12. Authentication and Bundle Failures

A wrong service token produced one provider attempt, HTTP 200 publicly,
`matching_v2_fallback`, and `ML_AUTHENTICATION_FAILURE`. An isolated
container start with an intentionally invalid Bundle mount remained live
(200), was not ready (503), and caused the public endpoint to return safe
fallback with `ML_MODEL_UNAVAILABLE`. The primary image and repository
Bundle were never changed.

## 13. Network Failure Matrix

| Failure | Provider calls | Retry | Public HTTP | Engine | Safe code |
|---|---:|---:|---:|---|---|
| Connection refused | 1 | 0 | 200 | matching_v2_fallback | ML_TRANSPORT_FAILURE |
| Timeout | 1 | 0 | 200 | matching_v2_fallback | ML_TRANSPORT_FAILURE |
| HTTP 401 | 1 | 0 | 200 | matching_v2_fallback | ML_AUTHENTICATION_FAILURE |
| HTTP 422 | 1 | 0 | 200 | matching_v2_fallback | ML_PROVIDER_VALIDATION_FAILURE |
| HTTP 429 | 1 | 0 | 200 | matching_v2_fallback | ML_RATE_LIMITED |
| HTTP 500 | 1 | 0 | 200 | matching_v2_fallback | ML_MODEL_UNAVAILABLE |
| HTTP 503 | 1 | 0 | 200 | matching_v2_fallback | ML_MODEL_UNAVAILABLE |
| Empty body | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Invalid JSON | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Version mismatch | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Request ID mismatch | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Missing prediction | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Extra prediction | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Duplicate job | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Rank gap | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Invalid score | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Invalid reason | 1 | 0 | 200 | matching_v2_fallback | ML_CONTRACT_FAILURE |
| Abrupt close | 1 | 0 | 200 | matching_v2_fallback | ML_TRANSPORT_FAILURE |

All 18 fault cases passed. Each failure produced one attempt and no retry.

## 14. Candidate-Pool Boundaries

Existing public-flow tests passed for zero eligible jobs, exactly the
configured maximum, and over maximum. Zero skips both engines; exact maximum
uses one ML call; over maximum skips ML and uses the complete MatchingService
fallback set. No truncation was introduced before the existing fallback
decision.

## 15. Stale and Corrupt State

An invalid cache pointer was ignored and the valid persistence run was reused
with no new work. An out-of-range persisted item score was rejected after
cache flush, then exactly one complete five-item result was recomputed. The
existing suite also passed expired-run, missing-run-pointer, invalid-run,
atomic rollback, and persistence/cache exception cases. No partial response
or partial run was observed.

## 16. Deterministic Ranking

Repeated cold, cache, persistence, warm-concurrent, and cold-concurrent
responses were canonical-byte equivalent for their context. Existing
orchestrator tests passed Laravel final ordering for raw score descending,
published date descending with null last, stable ID tie-break, final limit,
and contiguous ranks.

## 17. Concurrency

Ten warm client-concurrent requests produced ten HTTP 200 responses, identical
bodies, zero new runs, and zero partial runs. Five cold client-concurrent
requests produced five HTTP 200 responses, identical bodies, one equivalent
run, zero duplicate-equivalent runs, and zero partial runs. The PHP
development server serializes application handling; this is a bounded safety
observation, not a production race/load proof.

## 18. Privacy and Logs

The run scanned public bodies, Laravel stdout/stderr, accumulated ML container
logs, fault-server logs/events, and recommendation persistence. Runtime ML
tokens, the wrong-token fixture, the Sanctum token, and unique name/email/phone
markers produced zero hits. The fault server records only the selected fault
mode, never request bodies or headers. Public responses exposed no provider
payload or internal exception.

## 19. Database Behavior

Cold success wrote one run and five items. Cache and persistence hits wrote no
new recommendation records. Invalidation wrote one fresh complete run.
Corrupt-cache recovery wrote none; corrupt-persistence recovery wrote one
complete run. Each fault/fallback context wrote one complete fallback run.
Existing query-shape tests passed their bounded query and exact write-table
assertions. No production database was contacted.

## 20. Public API Compatibility

The route remains `GET|HEAD api/v1/jobs/recommended`, named
`v1.jobs.recommended`, with `api`, `auth:sanctum`, active-user, and
approved-company middleware. The success flag, message
`Recommended jobs retrieved successfully.`, envelope, resource keys,
engine values, reasons, ranks, and fallback flag remain unchanged.

## 21. Measurements

These are one local observation, not an SLA:

| Path | min ms | median ms | p95 ms | max ms |
|---|---:|---:|---:|---:|
| Cold ML | 473.487 | 473.487 | 473.487 | 473.487 |
| Warm cache | 305.423 | 305.423 | 305.423 | 305.423 |
| Persistence hit | 395.886 | 395.886 | 395.886 | 395.886 |
| Container-down fallback | 1400.494 | 1400.494 | 1400.494 | 1400.494 |
| Warm concurrent (10) | 241.281 | 1350.433 | 2538.830 | 2538.830 |
| Cold concurrent (5) | 378.351 | 907.739 | 1397.699 | 1397.699 |

## 22. Tests Executed

Exact successful commands included:

```text
powershell -ExecutionPolicy Bypass -File scripts/phase17/run-e2e.ps1
php artisan test --compact --do-not-cache-result --filter=RecommendationEndToEnd
php artisan test --compact --do-not-cache-result --filter=RecommendationFailureMatrix
php artisan test --compact --do-not-cache-result --filter=RecommendationConcurrency
php artisan test --compact --do-not-cache-result --filter=Recommendation
php artisan test --compact --do-not-cache-result --filter=Matching
php artisan test --compact --do-not-cache-result
.venv/Scripts/python.exe -m coverage run --rcfile=NUL -m pytest -q --basetemp <phase17-temp>
.venv/Scripts/python.exe -m coverage report --rcfile=NUL --include=src/smart_recruitment_ml/* --fail-under=90
.venv/Scripts/ruff.exe check .
.venv/Scripts/ruff.exe format --check src tests container
.venv/Scripts/mypy.exe --python-version 3.12 --no-incremental --cache-dir=<phase17-temp> --disable-error-code=no-untyped-def --disable-error-code=type-arg --disable-error-code=no-untyped-call --disable-error-code=no-any-return --disable-error-code=attr-defined --disable-error-code=redundant-cast src tests
.venv/Scripts/python.exe -m compileall -q src
.venv/Scripts/python.exe -m pip check
vendor/bin/pint --test tests/Feature/Api/V1/RecommendationEndToEndTest.php tests/Feature/Api/V1/RecommendationFailureMatrixTest.php tests/Feature/Api/V1/RecommendationConcurrencyTest.php
composer audit --locked --no-interaction
git diff --check
```

The fault server additionally passed Ruff lint and format check. Temporary
coverage, pytest, Mypy, and bytecode paths were outside protected service
content and were removed.

## 23. Test Results

- Phase 17 E2E: 6 passed, 2866 assertions.
- Failure matrix: 18 passed, 522 assertions.
- Concurrency: 3 passed, 54 assertions.
- Recommendation filter: 211 passed, 1 opt-in integration skipped, 4271 assertions.
- Matching filter: 52 passed, 414 assertions.
- Full Laravel: 761 passed, 2 opt-in integrations skipped, 8537 assertions.
- Python: 362 passed with one upstream Starlette deprecation warning.
- Safe whole-package line coverage: 91.17823564712943% (4558/4999 statements).
- Ruff lint: passed.
- Ruff format check: 108 protected Python files and the new fault server already formatted.
- Mypy compatibility check: no issues in 106 source files.
- Compileall: passed with bytecode redirected to and removed from a temporary path.
- Pip check: no broken requirements.
- Pint: passed for all three new PHP files.
- Composer audit: attempted, but no advisory result is claimed. Local execution could not reach Packagist and the external retry was denied because it would disclose private lockfile dependency metadata.
- Git diff check: reported in the final repository-state section.
- Test failures: zero. Skips: the two expected opt-in integrations in the full Laravel run.
- Initial harness setup defects (a missing C# assembly reference and PowerShell stderr handling) were confined to the new coordinator, reported, fixed, and followed by complete clean reruns.
- Literal `mypy src tests` is not viable in this local environment: the protected config targets Python 3.11 while installed NumPy stubs use Python 3.12 syntax, and the historical SQLite cache is read-only. No protected config, dependency, or source was changed.

## 24. E2E Artifacts

- `E2E_TEST_MATRIX.json`: deterministic 35-scenario matrix, 35 passed and zero failed.
- `PHASE_17_E2E_REPORT.md`: this report.
- `run-e2e.ps1`: repeatable coordinator with redacted JSON output and unconditional cleanup.
- `fault_server.py`: loopback-only, body/header-nonlogging fault provider.
- `README.md`: operator contract for the harness.

| Phase 17 artifact | SHA-256 |
|---|---|
| RecommendationEndToEndTest.php | `62EEF8ABB752AE1462D55D2D1878195DCA25FECA98EA3C2E06CB9A51A0D7FD9D` |
| RecommendationFailureMatrixTest.php | `4B5382879A59D2F1D550A2D3C3B3DC6EB0AD625257D94C53900485F4F5C65CF4` |
| RecommendationConcurrencyTest.php | `34E9E84C64A3EAA6216F5659FD120B52BDAE990D39C877D830D9CA649623CF11` |
| fault_server.py | `2E0E863FA26A9F614DF8C77B069BCAB8F6FC4488BCEFAD11C423210FBAFDC25D` |
| run-e2e.ps1 | `6D31A2FDD21A93F6078D1CED013CC353D869F1A69EC77270AF79BFA9506A1016` |
| scripts/phase17/README.md | `B661FB1185C758D577EBA9C6FEB69644521747711755B88CDC169091B93EAA71` |
| E2E_TEST_MATRIX.json | `039755B90097B2A0AFE34E444F49E143FF9466789247222DBA81FED82FA0006C` |

The report's own hash is reported after final file closure in the repository
state handoff; embedding it here would be self-referential.

## 25. Frozen Integrity

| Artifact | SHA-256 |
|---|---|
| Architecture | `60EB219152CE26B525735ED65564F667D403BF438F29000B4ECE90D65950553F` |
| model.json | `3ABD74137BC8881667643F31A658C790EF6712359D7802EA7FCFFA0C4CF9E26E` |
| feature_schema.json | `AEB260B25F34B55B7164B215E613A0B4327DF33EE65AF95ABC904045849CE4A0` |
| bundle_manifest.json | `1D566E4516724FAE0C08CD6131214C0722DFFCD589A370CF2405B8B0450DFB00` |
| openapi.json | `B73B11B5FA67C40927E5A05AB72E2D2F7B292FA3149F0D945AE74BE08F7CA96D` |
| contract_manifest.json | `A51E8F4E74189CCB086BDB7FE32816C6E56953533F3C77243E50650BE0BF9CB2` |
| container_manifest.json | `1DD6BB89D805544266F04B6EA1C5BD4E71C00DF7AC97E78473DDE6A19B431FD1` |

All eight Bundle files matched the manifest byte hashes. Dockerfile,
dockerignore, healthcheck, and startup hashes stored in the container manifest
also matched. The complete base reference and digest, Python 3.12.10,
UID/GID 10001, and one worker remain exact. Model, Bundle, FastAPI runtime,
feature pipeline, inference, MatchingService, eligibility, and ranking
implementation were not modified.

## 26. Baseline Comparison

The final comparison covers all 864 protected entries. The only approved
existing-file difference is `BACKEND_IMPLEMENTATION_REPORT.md`. New Phase 17
files are outside the pre-phase ledger. Unexpected protected mismatches:
`0`. Missing protected paths: `0`.
The baseline artifact and integrity exception remain unchanged at hashes
`CB3959FF2550064CA4F7D82953A1E6E0A539A1C32A2BC97ABB1E64F09FDBCAC0`
and `B53A3950A53552542C10A6B2DDA2FF5B3BC3C5B25870D8C5310507515514E422`,
respectively.

## 27. Cleanup

Final cleanup status: zero Phase 17 containers, zero harness-owned Laravel/ML/
fault processes, ports 8090/8100/8110 closed, no Phase 17 SQLite/log/token/
coverage/pytest/Mypy/bytecode temporary path, and the primary Phase 16 image
still present. No image was rebuilt or removed.

## 28. Risks

- True simultaneous cold misses can create equivalent runs because no
  distributed lock was added; the bounded local observation produced one.
- The ML path depends on loopback/network availability.
- Fallback intentionally preserves HTTP success and can hide ML outages unless
  operational logs/metrics are monitored.
- Laravel and ML contracts are strictly version-coupled.
- Cache and persistence behavior remains driver-specific; Phase 17 used the
  existing database cache and isolated SQLite.
- Local measurements are not an SLA.
- The model is synthetic-trained and is not a hiring-decision system.
- No production load test, CI/CD automation, or production deployment occurred.
- Composer advisory status is indeterminate under the external-data policy.
- Mypy requires environment/config reconciliation in a future authorized
  maintenance task; Phase 17 did not alter protected configuration.

## 29. Remaining Work

Phase 18 — Final Documentation, Demo, and Handover

## 30. Phase Gate

READY FOR PHASE 18

## 31. Exact Repository State

- Branch: `master`.
- HEAD: `6cd51f733d5197e0c3f6b7dfb3711c2860ffef71`.
- Staged files: `0`.
- Tracked modifications: `5` total; one is the approved Phase 17 report
  update and four are protected pre-Phase-17 Laravel changes.
- Untracked files: `261` total; eight were created by Phase 17.
- Phase 17 files: 9 total (8 created, 1 approved existing report modified).
- Existing files modified by Phase 17: `BACKEND_IMPLEMENTATION_REPORT.md` only.
- Python runtime behavior changed: no.
- Model/Bundle changed: no.
- Laravel production fixes: none.
- Migrations/schema changed: no.
- Docker: only `workeyx/ml-recommendation:0.2.0-phase16`; zero Phase 17 test containers.
- Running test ports/processes: none on 8090, 8100, or 8110.
- Production deployment: none.
- Commit/push: none.
