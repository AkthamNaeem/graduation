# ML Recommendation Deployment Runbook

This runbook is provider-neutral. It packages and verifies the internal
FastAPI service but does not authorize or perform a production deployment.

## 1. Prerequisites

- Docker Engine with Buildx and Compose.
- Access to the private image registry selected by the operator.
- A cryptographically random shared token of at least 32 characters held in a
  runtime secret manager.
- Laravel Phase 15 migrations deployed through the existing database backup
  and migration policy.
- Internal service networking or platform ingress restricted to Laravel.

## 2. Build and tag policy

Build immutable release tags from the repository root:

```powershell
docker build `
  --pull `
  --build-arg SOURCE_REVISION=6cd51f733d5197e0c3f6b7dfb3711c2860ffef71 `
  --build-arg BUILD_DATE=2026-07-25 `
  --tag workeyx/ml-recommendation:0.2.0-phase16 `
  services/ml-recommendation
```

Use a service-version plus packaging-release tag in a registry. Do not deploy
mutable `latest` as the only rollback reference.

## 3. Runtime environment

| Name | Required | Purpose |
| --- | --- | --- |
| `ML_SERVICE_TOKEN` | Yes | Shared Laravel caller credential; runtime secret only |
| `PORT` | No | Listener port, default `8100` |
| `ML_BUNDLE_DIR` | No | Embedded Bundle path; do not override normally |
| `ML_MAX_JOBS_PER_REQUEST` | No | Maximum request pool, default `500` |
| `ML_MAX_RESULTS` | No | Maximum requested result limit, default `100` |
| `ML_DOCS_ENABLED` | No | Documentation exposure; image default is disabled |
| `ML_LOG_LEVEL` | No | Safe application log level |
| `ML_ENVIRONMENT` | No | Environment label |

Do not provide database, Redis, Laravel session, or production user data to
the ML container.

## 4. Local run and Compose

Pass an already-populated local shell variable without placing its value in
the command history:

```powershell
docker run --rm `
  --name workeyx-ml `
  --read-only `
  --tmpfs /tmp:rw,noexec,nosuid,size=64m `
  --cap-drop ALL `
  --security-opt no-new-privileges:true `
  --env ML_SERVICE_TOKEN `
  --publish 127.0.0.1:8100:8100 `
  workeyx/ml-recommendation:0.2.0-phase16
```

Or use the dedicated Compose file after populating the same shell variable:

```powershell
docker compose -f compose.ml.yml config --quiet
docker compose -f compose.ml.yml up --build --detach
docker compose -f compose.ml.yml down
```

## 5. Health, metadata, and rank smoke checks

Verify `/health/live` first, then `/health/ready`. Readiness must report
Bundle `job-rec-inference-bundle-v1`, Model `xgbranker-tuned-v1`, and Feature
Schema `job-rec-features-v1`. Call `/v1/model/metadata` and the frozen Phase 12
rank example with the caller header supplied by a secret-aware client. Never
print the header, token, full container environment, or request facts in
deployment logs.

## 6. Laravel configuration

Laravel on the host uses `http://127.0.0.1:8100`. A future shared Compose
network uses `http://ml-recommendation:8100`. A hosted deployment uses the
platform's internal service URL. Configure Laravel's existing:

- `ML_RECOMMENDATION_ENABLED`
- `ML_RECOMMENDATION_BASE_URL`
- `ML_RECOMMENDATION_SERVICE_TOKEN`
- Connection and request timeouts
- Request/result limits and frozen contract versions
- Phase 15 cache and persistence settings

Keep ML disabled until the container and Laravel fallback have both been
verified.

## 7. Safe rollout and enablement

1. Back up and verify the database under the existing policy.
2. Deploy the Phase 15 migrations.
3. Deploy Laravel with ML disabled.
4. Deploy the immutable ML image with its runtime secret.
5. Verify live, ready, metadata, Bundle hashes, and rank smoke behavior.
6. Verify the Laravel recommendation endpoint succeeds through Matching.
7. Configure the internal URL and the coordinated caller token in Laravel.
8. Enable ML.
9. Verify the public recommendation endpoint, persistence, and cache behavior.
10. Monitor safe fallback events, readiness, latency, memory, and restarts.

## 8. Rollback

For image failure, Bundle corruption, elevated latency, or unexpected errors,
disable ML in Laravel. Matching 2.0 keeps the public endpoint available and no
recommendation-data rollback is required. Redeploy the last known-good image.
Do not modify the embedded Model or Bundle inside a running container.

If Laravel itself is rolled back, preserve the Phase 15 tables unless the
normal migration compatibility review explicitly authorizes otherwise. The
prune command remains optional operational maintenance.

## 9. Token rotation

Create a new secret in the secret manager without printing it. Update the ML
runtime and Laravel in a coordinated maintenance window, verify readiness and
one authenticated smoke request, then revoke the previous secret. A mismatch
causes safe Laravel fallback; diagnostics must record only authentication
status and safe codes.

## 10. Image and Bundle updates

Build a new immutable tag, verify the deterministic container manifest and all
Bundle hashes, run the complete container contract, then follow the rollout
sequence. Never rebuild the Bundle in the Docker build or download a Model at
startup.

## 11. Logs and measurements

Collect health transitions, safe request IDs, aggregate counts, latency,
memory, CPU, and fallback codes. Do not collect tokens, request bodies,
professional facts, explanations, environment dumps, or stack traces in
structured deployment logs. Phase 16 measurements are local observations,
not production capacity guarantees or SLAs.

## 12. Known limitations

- Exactly one worker runs per container; scale horizontally.
- Internal networking and ingress controls vary by hosting platform.
- The shared secret must be protected from environment inspection.
- Matching fallback can intentionally mask an ML outage from public clients.
- The Model uses synthetic training data.
- No production load test, Kubernetes configuration, or CI/CD automation is
  included.
