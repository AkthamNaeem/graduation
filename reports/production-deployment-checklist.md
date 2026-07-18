# Production Deployment Checklist

Audit date: 2026-07-18  
Current release decision: **NO-GO / FAIL**

Legend: PASS, FAIL, NOT EXECUTED, REQUIRED.

## Blocking release gates

- [ ] FAIL — Replace local persistent-file storage with S3-compatible object storage and migrate/verify all CVs, information-response attachments and test-answer files.
- [ ] FAIL — Upgrade dependencies until Composer has no unresolved applicable High/Critical advisories.
- [ ] FAIL — Make the production /up health endpoint return HTTP 200 reliably.
- [ ] FAIL — Define and run the database queue worker that processes ParseCVFileJob.
- [ ] FAIL — Establish database backup/PITR and object-storage versioning plus a successful restore drill.
- [ ] NOT EXECUTED — Prove fresh migration, full tests and rollback/reapply on an isolated supported MySQL version.

## Build and artifact

- [x] PASS — Docker image uses PHP 8.3 Apache and installs required PHP extensions.
- [x] PASS — Composer production install excludes dev packages and optimizes autoloading.
- [x] PASS — composer.json validates.
- [ ] FAIL — composer audit currently reports 19 advisories affecting 10 packages.
- [ ] FAIL — Pint test currently reports formatting violations.
- [ ] REQUIRED — Pin/record the image digest and retain a previously known-good rollback artifact.
- [ ] REQUIRED — Add image smoke test before registry publication.
- [ ] REQUIRED — Prevent CI image publication unless tests, migrations, audit and formatting gates pass.

## Runtime environment

- [ ] REQUIRED — APP_ENV=production.
- [ ] REQUIRED — APP_DEBUG=false.
- [ ] REQUIRED — APP_KEY supplied from Render secrets and never baked into the image.
- [ ] REQUIRED — APP_URL uses the final HTTPS origin.
- [ ] REQUIRED — LOG_CHANNEL/LOG_LEVEL are production appropriate and exclude sensitive payloads.
- [ ] REQUIRED — Define trusted proxies/hosts for Render.
- [ ] REQUIRED — Explicit least-privilege CORS origins and methods.
- [ ] REQUIRED — HSTS, content-type, frame, referrer and permissions headers at proxy or app.
- [x] PASS — .env is ignored and is not tracked.
- [x] PASS — High-confidence tracked-file secret scan found no matches.
- [ ] REQUIRED — Add CI secret scanning and verify git history separately.

## Database and release migration

- [ ] NOT EXECUTED — MySQL 8 fresh migration.
- [ ] NOT EXECUTED — MySQL full PHPUnit suite.
- [ ] NOT EXECUTED — MySQL rollback/reapply.
- [ ] REQUIRED — Use a release/pre-deploy migration step with --force, not every web-container boot.
- [ ] REQUIRED — Back up before schema changes and document rollback/roll-forward decisions.
- [ ] REQUIRED — Estimate lock time for backfills/ALTER statements using production-like data.
- [ ] REQUIRED — Separate reference-data seeding from SampleUserSeeder.
- [ ] REQUIRED — Hard-block sample user seeding in production.
- [ ] REQUIRED — Confirm connection TLS/certificate verification and least-privilege DB user.

## Files and storage

- [ ] FAIL — FILESYSTEM_DISK must not be local on ephemeral Render instances.
- [ ] REQUIRED — Configure private S3-compatible bucket, credentials, region/endpoint and server-side encryption.
- [ ] REQUIRED — Deny public listing and direct anonymous reads.
- [ ] REQUIRED — Use short-lived authorized downloads or application streaming.
- [ ] REQUIRED — Set throw/report behavior so storage failures are observable.
- [ ] REQUIRED — Define retention and deletion policy for candidate data.
- [ ] REQUIRED — Test upload/download/delete, missing object, provider outage and instance restart.
- [ ] REQUIRED — Enable versioning/lifecycle and test restoration.

## Queue and scheduler

- [ ] FAIL — Declare a worker service using php artisan queue:work with bounded timeout, tries and backoff.
- [ ] REQUIRED — Monitor queue depth, oldest-job age and failed_jobs.
- [ ] REQUIRED — Configure graceful worker restart during deployment.
- [ ] REQUIRED — Confirm worker and web use identical release/environment/storage.
- [ ] REQUIRED — Decide and document after_commit semantics for every dispatched job.
- [ ] REQUIRED — Add scheduled pruning/monitoring only where needed.
- [ ] REQUIRED — Configure Render cron or a singleton scheduler with withoutOverlapping/onOneServer semantics.
- [ ] REQUIRED — Verify schedule timezone explicitly; local app currently reports UTC.

## API and security

- [x] PASS — 158 authenticated API routes include active-user enforcement.
- [x] PASS — Admin routes use an explicit admin middleware.
- [x] PASS — Feature tests cover cross-company and candidate/employer privacy scenarios broadly.
- [ ] FAIL — Attach rate limiters to login, registration, password reset, uploads and the general API.
- [ ] REQUIRED — Enforce verified email ownership for sensitive authenticated use.
- [ ] REQUIRED — Validate maximum request/body size at Render/proxy and PHP levels.
- [ ] REQUIRED — Confirm HTTPS redirect, secure cookies if used, and authorization header forwarding.
- [ ] REQUIRED — Run a staging IDOR matrix for every identifier-bearing endpoint.
- [ ] REQUIRED — Verify error responses never expose exceptions with APP_DEBUG=false.

## Health and observability

- [ ] FAIL — /up currently returns HTTP 500 in live production.
- [x] PASS — Public jobs smoke check returned HTTP 200.
- [x] PASS — Unknown API route returned HTTP 404.
- [x] PASS — Invalid login returned HTTP 401.
- [ ] REQUIRED — Add structured request IDs/correlation IDs.
- [ ] REQUIRED — Alert on 5xx rate, latency, queue lag, failed jobs, storage failures and DB saturation.
- [ ] REQUIRED — Keep liveness shallow; add a separately defined readiness check if dependencies must be checked.
- [ ] REQUIRED — Verify logs redact passwords, tokens, reset tokens, CV contents and internal notes.

## Backup, recovery and rollback

- [ ] FAIL — Define database RPO/RTO and enable automated provider backups/PITR.
- [ ] FAIL — Define object-storage backup/versioning and retention.
- [ ] REQUIRED — Store backup credentials separately from runtime credentials.
- [ ] REQUIRED — Perform and timestamp an isolated database restore.
- [ ] REQUIRED — Perform and timestamp a representative object restore.
- [ ] REQUIRED — Document application/image rollback and forward-only migration recovery.
- [ ] REQUIRED — Test recovery with the operations owner, not only developers.

## Final staging verification

- [x] PASS — Local full suite: 281 tests, 2658 assertions.
- [x] PASS — SQLite migration fresh/status/rollback/reapply.
- [x] PASS — Config, route, event and view cache creation.
- [ ] FAIL — Production-style optimize:clear can depend on database cache availability; use explicit safe runbook commands.
- [ ] NOT EXECUTED — Parallel tests (ParaTest absent).
- [ ] NOT EXECUTED — Coverage (Xdebug absent).
- [ ] NOT EXECUTED — PHPStan (package absent).
- [ ] NOT EXECUTED — Newman (command absent).
- [ ] REQUIRED — Execute Postman/Newman smoke against staging with non-production fixtures.
- [ ] REQUIRED — Recheck git diff, audit artifacts and exact release commit before deployment.

Deployment approval is allowed only after all blocking gates are closed with fresh evidence.
