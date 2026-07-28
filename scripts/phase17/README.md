# Phase 17 local E2E harness

`run-e2e.ps1` coordinates a local-only Laravel HTTP server, a temporary SQLite
database, the existing Phase 16 ML image, and `fault_server.py`. It exercises
the public recommendation endpoint through cold ML, cache, persistence,
invalidation, provider failures, fallback, and bounded client concurrency.

The harness is opt-in and must be run from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/phase17/run-e2e.ps1
```

It generates all credentials at runtime, binds only to `127.0.0.1`, writes
runtime state under the operating-system temporary directory, and removes the
container, processes, SQLite database, logs, and credentials in `finally`.
It does not rebuild or delete the primary image, modify `.env`, access a
production database, or log request bodies.

Any failed setup step or scenario returns a non-zero exit code. The final
standard output is a redacted JSON summary suitable for the Phase 17 report;
it contains no tokens, fixture PII, container IDs, machine paths, or payloads.
