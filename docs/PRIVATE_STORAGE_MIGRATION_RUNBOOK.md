# Private Storage Migration Runbook

This runbook preserves and migrates CVs, test-answer files, and application-information response attachments. The database disk/path columns are authoritative.

## Critical preservation warning

Current Render instance-local files may disappear on restart or redeploy. Before changing environment variables or deploying a release that triggers restart:

1. Open a shell on the current running release.
2. Inventory database references and existing local objects.
3. Export a secure archive or copy current files outside the instance if the new inventory command is not yet deployed.
4. Compare record counts with preserved objects.
5. Record missing objects explicitly. New code cannot recover files already lost.

Do not claim a missing source was migrated.

## Inventory

The command is read-only and does not print raw paths by default:

```bash
php artisan storage:inventory-private-files --disk=local --format=table --strict
php artisan storage:inventory-private-files --disk=local --format=csv --output=private-inventory.csv --strict
```

Review totals by disk, existing objects, missing objects, size mismatches, and unsupported disks. Store detailed reports securely because record IDs and path hashes are operational metadata.

## Dry run

Migration defaults to dry-run:

```bash
php artisan storage:migrate-private-files \
  --source=local --target=s3 --domain=all --batch=100 \
  --report=private-storage-dry-run.csv
```

Available domains are `cv`, `test-answers`, `information-attachments`, and `all`. Use `--limit` for a canary batch.

## Execute canary

```bash
php artisan storage:migrate-private-files \
  --source=local --target=s3 --domain=cv --limit=10 --execute \
  --report=private-storage-canary.csv
```

For each record the command verifies the source, writes with streams, verifies size and SHA-256, locks and rechecks the row, updates disk/path, and only then optionally deletes the source. Target paths use deterministic UUID keys so interrupted runs are resumable without creating duplicate objects.

Statuses include `DRY_RUN_READY`, `MIGRATED`, `TARGET_EXISTS_VERIFIED`, `MISSING_SOURCE`, `TARGET_VERIFICATION_FAILED`, `SOURCE_READ_FAILED`, `TARGET_WRITE_FAILED`, `ROW_CHANGED`, `DB_UPDATE_FAILED`, and `CLEANUP_FAILED`.

Any failure returns a non-zero exit code. Investigate before continuing.

## Full migration

1. Execute without `--delete-source`.
2. Re-run inventory on both disks.
3. Test authorized and unauthorized downloads.
4. Run queued CV parsing against migrated CVs.
5. Verify database record disk/path, sizes, counts, and reports.
6. Perform staging restart durability and recovery drills.
7. Keep local sources through the agreed safety window.
8. Only then consider a separately approved run with `--delete-source`.

## Idempotency and concurrent changes

- Rows no longer on the source disk are skipped.
- A deterministic target that already exists must match source size and SHA-256 before the database can be updated.
- A row changed after copy is reported as `ROW_CHANGED`; the newly created target is cleaned up.
- Source deletion occurs only after database commit.
- Re-running the same source/target command is safe.

## Reverse migration

The same verification algorithm supports a controlled reverse direction:

```bash
php artisan storage:migrate-private-files --source=s3 --target=local --domain=all
```

Run inventory and dry-run first. Reverse migration is not an automatic production rollback.

## Audit handling

Use the migration report rather than creating thousands of application audit rows. Reports contain no credentials, bucket, endpoint, raw path, or file content.
