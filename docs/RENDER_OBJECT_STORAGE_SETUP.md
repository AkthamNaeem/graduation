# Render Private Object Storage Setup

This application uses an S3-compatible private object store for durable user files. A Render persistent disk is not the primary storage solution.

## Provider preparation

1. Create a dedicated private bucket for the environment.
2. Block public access and public ACLs.
3. Enable server-side encryption, versioning, retention, and access logging where supported.
4. Create least-privilege credentials scoped to the application prefixes. Required operations are GetObject, PutObject, DeleteObject, and limited ListBucket if the provider requires it.
5. Use a different bucket or isolated prefix and credentials for integration tests.

The application is provider-neutral. AWS S3 and compatible services can be used; no provider or bucket is hardcoded.

## Render environment

Set secrets in Render, never in Git or Docker build arguments:

```text
PRIVATE_FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_DEFAULT_REGION
AWS_BUCKET
AWS_ENDPOINT
AWS_URL
AWS_USE_PATH_STYLE_ENDPOINT
```

`AWS_ENDPOINT` is commonly required by compatible providers and optional for AWS. `AWS_URL` is not used to expose private files. Web and queue worker services must receive identical storage configuration.

After configuring environment variables:

```bash
php artisan config:clear
php artisan config:cache
php artisan storage:verify-private --disk=s3
```

Do not connect `/up` to the object-store verification command. It performs write/read/delete I/O and is an operator check.

## Safe cutover

1. Deploy code that can read both local and S3 per-record disks while `PRIVATE_FILESYSTEM_DISK=local` remains set.
2. Verify the dedicated staging bucket with `storage:verify-private` and optional S3 integration tests.
3. Before any production restart, inventory and preserve current instance-local files as described in the migration runbook.
4. Set `PRIVATE_FILESYSTEM_DISK=s3` to direct only new uploads to S3.
5. Smoke-test CV upload/download/parsing, test-answer replacement/download/delete, and multi-attachment response submission.
6. Migrate existing local records without deleting sources.
7. Verify inventory, hashes, authorization, a staging restart/redeploy, and a restore drill.
8. Delete local sources only after the agreed verification window.

## Restart durability test

Status for this implementation session: **NOT EXECUTED**. No staging credentials or restart authority were used.

Required staging procedure:

1. Upload a non-sensitive fixture through an authorized API.
2. Record only entity ID, byte size, and local verification hash.
3. Download and verify its hash.
4. Restart or redeploy staging.
5. Download and verify the same hash again.
6. Run the CV parsing job after restart when testing a CV.
7. Delete through the domain API and verify the object is absent.

Record PASS only after the full procedure succeeds.

## Rollback

Changing `PRIVATE_FILESYSTEM_DISK=local` affects new uploads only. Existing S3 records still require a code release that reads `record.disk`; do not roll back to an older incompatible release after S3 writes begin. Data rollback uses the migration command in reverse in a controlled environment:

```bash
php artisan storage:migrate-private-files --source=s3 --target=local --domain=all
```

Review the dry run before adding `--execute`. Never run an automatic production rollback or source deletion.
