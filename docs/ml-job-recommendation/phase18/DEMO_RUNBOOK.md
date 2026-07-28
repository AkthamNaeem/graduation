# ML Job Recommendation — 10–15 Minute Demo Runbook

## Safety and Preconditions

Use only a disposable local environment, `<local-database>`, synthetic or
demo records, a runtime-generated `<temporary-token>`, and a separately
generated `<sanctum-token>`. Do not use production URLs, databases, users,
tokens, or PII. Keep the image
`workeyx/ml-recommendation:0.2.0-phase16`; do not build.

Use separate PowerShell terminals for Docker and Laravel where indicated.
Before starting, verify ports 8090, 8100, and 8110 are unused.

## 1. Present the Architecture

- **Goal:** Establish the Laravel/ML responsibility boundary.
- **Command:** `Get-Content docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md`
- **Expected:** The high-level text diagram and component responsibilities are
  visible.
- **Defense point:** Laravel owns authentication, eligibility, final sorting,
  persistence, cache, fallback, and the public response; FastAPI owns strict
  validation, frozen feature construction, inference, and attribution.
- **Fallback:** Open
  `docs/ml-job-recommendation/ARCHITECTURE.md` and explain ADR-ML-002,
  ADR-ML-003, ADR-ML-004, and ADR-ML-013.

## 2. Show the Public Endpoint

- **Goal:** Prove the feature uses the existing public contract.
- **Command:** `php artisan route:list --path=api/v1/jobs/recommended -v`
- **Expected:** One `GET|HEAD api/v1/jobs/recommended` route named
  `v1.jobs.recommended` with API, Sanctum, active-user, and company
  middleware.
- **Defense point:** No Phase 18 endpoint or public payload change exists.
- **Fallback:** Show the route assertion in
  `tests/Feature/Api/V1/RecommendationEndToEndTest.php`.

## 3. Start the Existing ML Container

- **Goal:** Start the frozen Phase 16 runtime without rebuilding.
- **Command:**

  ```powershell
  $env:ML_SERVICE_TOKEN = '<temporary-token>'
  docker compose -f compose.ml.yml up -d --no-build
  ```

- **Expected:** The existing image starts on loopback port 8100 and transitions
  to healthy.
- **Defense point:** The container is non-root, read-only, single-worker, and
  Bundle-verified.
- **Fallback:** If the image is absent, do not build during the defense. Use
  the Phase 16 container card and Phase 17 verified evidence.

## 4. Verify Liveness and Readiness

- **Goal:** Distinguish process liveness from model/Bundle readiness.
- **Command:**

  ```powershell
  Invoke-RestMethod http://127.0.0.1:8100/health/live
  Invoke-RestMethod http://127.0.0.1:8100/health/ready
  ```

- **Expected:** Both checks report ready operational state.
- **Defense point:** Liveness can succeed while readiness fails if the token or
  Bundle contract is invalid.
- **Fallback:** Run `docker compose -f compose.ml.yml ps` and show the
  invalid-Bundle evidence in the Phase 17 report.

## 5. Make a Cold ML Recommendation

- **Goal:** Exercise the public Laravel HTTP path on a fresh demo context.
- **Command:**

  ```powershell
  $headers = @{ Authorization = 'Bearer <sanctum-token>'; Accept = 'application/json' }
  Invoke-RestMethod -Headers $headers `
    http://127.0.0.1:8090/api/v1/jobs/recommended?limit=5
  ```

- **Expected:** HTTP 200, eligible jobs only,
  `recommendation_engine=ml_xgbranker`, contiguous ranks, and no PII.
- **Defense point:** Laravel selected the candidate pool; ML ranked only that
  pool.
- **Fallback:** Run the repeatable isolated proof:
  `powershell -ExecutionPolicy Bypass -File scripts/phase17/run-e2e.ps1`.

## 6. Explain Score and Reason Codes

- **Goal:** Explain output semantics accurately.
- **Command:** Inspect `data.*.score`, `data.*.reasons`, and
  `data.*.recommendation_engine` in the response.
- **Expected:** A bounded display score and allowlisted positive/negative
  reason factors.
- **Defense point:** `display_score` is not a probability; SHAP attribution is
  not causality, fairness certification, or a hiring decision.
- **Fallback:** Show
  `services/ml-recommendation/data/explainability/tuned/v1/MODEL_EXPLAINABILITY_REPORT.md`.

## 7. Repeat for a Cache Hit

- **Goal:** Demonstrate warm reuse.
- **Command:** Repeat the exact request from Step 5 without changing data.
- **Expected:** The public body is equivalent and no new ML request or
  recommendation run is created.
- **Defense point:** The cache contains a compact pointer, not the complete
  recommendation payload or token.
- **Fallback:** Show scenario A02 in the Phase 17 matrix.

## 8. Clear Cache for Persistence Reuse

- **Goal:** Prove durable database reuse independently of the cache.
- **Command:**

  ```powershell
  php artisan cache:clear
  Invoke-RestMethod -Headers $headers `
    http://127.0.0.1:8090/api/v1/jobs/recommended?limit=5
  ```

- **Expected:** The same persisted run is returned, no new ML call occurs, and
  the cache pointer is warmed.
- **Defense point:** Durable persistence makes recommendation reuse survive
  cache eviction.
- **Fallback:** Show scenario A03 and its database assertions in the Phase 17
  report.

## 9. Demonstrate Content-Addressed Invalidation

- **Goal:** Prove a scoring-context change cannot reuse a stale result.
- **Command:** In the disposable fixture only:

  ```powershell
  php artisan tinker --execute="App\Models\JobSeekerProfile::query()->whereKey(<demo-profile-id>)->update(['headline' => '<demo-headline-v2>']);"
  Invoke-RestMethod -Headers $headers `
    http://127.0.0.1:8090/api/v1/jobs/recommended?limit=5
  ```

- **Expected:** A different context hash and a newly computed complete run.
- **Defense point:** The fingerprint covers relevant profile, eligible-job,
  version, and configuration content.
- **Fallback:** Show the three-row invalidation matrix in the Phase 17 report.

## 10. Stop the ML Container

- **Goal:** Create a real provider outage safely.
- **Command:** `docker compose -f compose.ml.yml stop ml-recommendation`
- **Expected:** Port 8100 becomes unavailable while Laravel remains running.
- **Defense point:** The public endpoint is designed to fail open to
  MatchingService 2.0.
- **Fallback:** If Docker cannot be controlled, use the verified
  container-down scenario D01.

## 11. Prove MatchingService 2.0 Fallback

- **Goal:** Verify availability and safe failure semantics.
- **Command:** Change only the disposable demo context again, then repeat the
  public request.
- **Expected:** HTTP 200,
  `recommendation_engine=matching_v2_fallback`, `fallback_used=true`, and no
  provider detail.
- **Defense point:** MatchingService 2.0 is stable fallback; monitoring must
  detect that ML is unavailable.
- **Fallback:** Show `ML_TRANSPORT_FAILURE` in the Phase 17 report and matrix.

## 12. Show Durable Runs and Items

- **Goal:** Connect the public response to persisted evidence without exposing
  PII.
- **Command:**

  ```powershell
  php artisan tinker --execute="dump(App\Models\RecommendationRun::query()->latest('id')->first(['id','engine','fallback_used','candidate_count','returned_count','generated_at','expires_at']));"
  php artisan tinker --execute="dump(App\Models\RecommendationItem::query()->latest('id')->limit(5)->get(['recommendation_run_id','job_posting_id','rank','score','matching_score_version']));"
  ```

- **Expected:** One complete run and contiguous item ranks with the expected
  engine.
- **Defense point:** Runs are immutable evidence; items have unique run-job and
  run-rank constraints.
- **Fallback:** Show the migration and Phase 15 persistence tests.

## 13. Run Prune Dry-Run

- **Goal:** Demonstrate retention without deleting data.
- **Command:** `php artisan recommendations:prune --dry-run`
- **Expected:** `deleted_runs=<count>` is displayed and no row is deleted.
- **Defense point:** Execution requires omitting `--dry-run` intentionally;
  cascades remove items with pruned runs.
- **Fallback:** Show
  `app/Console/Commands/PruneRecommendationRunsCommand.php`.

## 14. Show Verification Evidence

- **Goal:** Present reproducible coverage rather than anecdotal success.
- **Command:**

  ```powershell
  php artisan test --compact --do-not-cache-result --filter=FinalHandoverDocumentation
  php artisan test --compact --do-not-cache-result --filter=Recommendation
  ```

- **Expected:** Documentation and recommendation suites pass.
- **Defense point:** Phase 17 contains 35 machine-readable E2E scenarios,
  including 18 provider/network failures.
- **Fallback:** Open `FINAL_VERIFICATION_REPORT.md` and
  `../phase17/E2E_TEST_MATRIX.json`.

## 15. Cleanup

- **Goal:** Leave no demo process, container, token, or temporary state.
- **Command:**

  ```powershell
  docker compose -f compose.ml.yml down
  Remove-Item Env:ML_SERVICE_TOKEN -ErrorAction SilentlyContinue
  ```

- **Expected:** No demo container; ports 8090, 8100, and 8110 are not
  listening; the primary image remains.
- **Defense point:** Cleanup is part of the acceptance contract and does not
  delete the image, repository artifacts, or production data.
- **Fallback:** Stop only processes started for the demo, then verify Docker
  and port state manually.

## Repeatable Full Smoke

For a clean isolated proof that generates its own token, SQLite database,
fixture, processes, and cleanup:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/phase17/run-e2e.ps1
```

Its redacted JSON summary is the preferred fallback evidence for cold ML,
cache, persistence, invalidation, corruption recovery, provider faults,
concurrency, privacy, and cleanup.
