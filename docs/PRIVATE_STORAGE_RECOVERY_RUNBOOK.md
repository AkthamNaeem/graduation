# Private Storage Recovery Runbook

Object storage provides durable primary storage, not a complete backup by itself. Enable provider versioning/retention and maintain an independently verified recovery policy.

## Database row exists, object missing

1. Do not auto-restore in the request path.
2. Confirm the stored disk and path using restricted operator access.
3. Check version history, backup inventory, migration reports, and preserved local sources.
4. Restore into the exact referenced key or migrate the row through a reviewed repair procedure.
5. Verify size/hash and authorized download.
6. Record the incident without logging content or raw paths in general logs.

If no verified source exists, mark the file missing; do not claim recovery.

## Object exists, database row missing

Treat it as an orphan. Compare provider inventory with database records using path hashes. Do not recreate domain records automatically. Quarantine or delete only after retention, legal, and ownership review.

## Provider outage

- Upload/read operations return safe 503 storage error codes.
- Do not switch records to another disk or create partial database state.
- Monitor provider status and application structured logs.
- Retry queued parsing after service recovery.
- Avoid mass retries that amplify the outage.

## Credential rotation

1. Create new least-privilege credentials.
2. Apply them to staging web and worker services.
3. Run `storage:verify-private` and an authorized download/parser smoke test.
4. Apply to production web and worker services.
5. Revoke old credentials only after verification.

Never print or store credential values in reports.

## Bucket/provider migration

Use separate source and target disks and the migration command's dry-run/execute process. Keep the old provider readable until inventory, restart, and restore verification pass.

## Accidental deletion and restore drill

1. Select a non-sensitive staging fixture.
2. Record ID, size, and hash.
3. Confirm versioning or backup contains the object.
4. Delete the operational object.
5. Restore it through provider recovery controls.
6. Verify hash and authorized API download.
7. Clean up the fixture and document duration and result.

Status for this implementation session: **NOT EXECUTED**. Provider backup/versioning and restore access were not available.

## Orphan cleanup

Cleanup is never automatic in request processing. Produce an inventory, apply a retention window, obtain approval, then delete explicit verified orphan keys. A failed post-commit replacement cleanup is logged with operation, entity type/ID, disk, path hash, exception class, and retryability.

## Recovery evidence

A production-ready recovery sign-off must include provider policy, backup/versioning configuration, responsible owner, RPO/RTO, a dated successful restore drill, and proof that web and queue worker can read the restored object.
