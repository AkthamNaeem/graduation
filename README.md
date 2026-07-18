# Smart Recruitment Platform Backend

Laravel 12 REST API for candidate profiles and CVs, companies and jobs, applications, information requests, private internal notes, tests and grading, interviews, notifications, matching, audit logs, and administration.

## Local setup

1. Copy `.env.example` to `.env` and generate `APP_KEY`.
2. Configure an isolated local database.
3. Run `composer install`.
4. Run `php artisan migrate --seed` only in local or test environments. The default seeder contains sample accounts and must not be used in production.
5. Start the web process and a database queue worker because CV parsing is asynchronous.

Useful checks:

```bash
php artisan test
php artisan route:list --path=api/v1
php artisan storage:verify-private --disk=local
php artisan storage:inventory-private-files
```

## Private file storage

CV files, test-answer attachments, and application-information response attachments are private. New uploads use `PRIVATE_FILESYSTEM_DISK`; existing records always use their stored per-record disk and path.

Local/test default:

```text
PRIVATE_FILESYSTEM_DISK=local
```

Production:

```text
PRIVATE_FILESYSTEM_DISK=s3
```

The S3-compatible disk supports AWS or another compatible provider through the standard `AWS_*` variables. Objects are not public, original filenames are not used as object keys, and downloads remain authorized backend streams.

Never switch production storage before preserving existing instance-local files. Follow:

- [Render object-storage setup](docs/RENDER_OBJECT_STORAGE_SETUP.md)
- [Private-storage migration runbook](docs/PRIVATE_STORAGE_MIGRATION_RUNBOOK.md)
- [Private-storage recovery runbook](docs/PRIVATE_STORAGE_RECOVERY_RUNBOOK.md)

## Safe migration commands

Inventory is read-only:

```bash
php artisan storage:inventory-private-files --disk=local --strict
```

Migration is a dry run unless `--execute` is provided:

```bash
php artisan storage:migrate-private-files --source=local --target=s3 --domain=all
php artisan storage:migrate-private-files --source=local --target=s3 --domain=all --execute --report=storage-migration.csv
```

Do not use `--delete-source` during the first migration pass. Verify counts, sizes, checksums, downloads, parsing, restart durability, and recovery first.

## Production processes

- Web: serves the API.
- Worker: runs `php artisan queue:work` and must share the same database and object-storage environment.
- Scheduler: currently no application tasks are defined.

The repository Dockerfile starts the web process only. The worker topology remains a separate production deployment task.

## Tests

Standard tests use fake local/S3 disks and require no credentials. Optional real-provider tests are skipped unless `RUN_S3_INTEGRATION_TESTS=true` and dedicated `S3_TEST_*` credentials are supplied. Never use the production bucket for integration tests.

## AI-assisted CV parsing

CV file extraction remains local: PDF/DOCX text is extracted first, and only that text is passed to the configured parser. `CV_PARSER_DRIVER=rules` uses the deterministic legacy parser. `openai` uses the synchronous OpenAI Responses API, while `groq` uses Groq Chat Completions. Both AI drivers use the same strict extraction prompt, JSON Schema, validation, normalization, and bounded timeouts; neither uploads the CV file.

Parsed data is stored as a draft in `cv_parsing_results`. It never writes directly to a profile. The existing confirm, suggestion, accept/reject, and bulk-apply workflow remains the only route into profile data, so manual profile values keep priority.

Required configuration:

```env
CV_PARSER_DRIVER=openai
CV_PARSER_FALLBACK_TO_RULES=true
OPENAI_API_KEY=replace_me
OPENAI_CV_MODEL=gpt-5-mini
OPENAI_CV_TIMEOUT=60
OPENAI_CV_CONNECT_TIMEOUT=10
GROQ_API_KEY=
GROQ_CV_MODEL=openai/gpt-oss-20b
GROQ_CV_TIMEOUT=60
GROQ_CV_CONNECT_TIMEOUT=10
QUEUE_CONNECTION=sync
```

The only valid drivers are `rules`, `openai`, and `groq`; an unknown value fails during service resolution. Fallback is allowed only for timeout, rate-limit, availability, and eligible malformed-content failures, and stores a safe provider-specific reason code in `_meta`. Missing credentials and HTTP 401/403 always fail with `OPENAI_AUTHENTICATION_FAILED` or `GROQ_AUTHENTICATION_FAILED`; they never fall back to rules because that would hide a deployment configuration error. If Groq strict structured output returns `json_validate_failed`, the provider receives exactly one second request using JSON Object Mode before any eligible rules fallback is considered. Groq distinguishes bad requests, refusals, empty content, invalid JSON, and contract mismatches without exposing provider bodies. Raw provider responses, request bodies, API keys, CV text, and parsed personal data are not logged.

With the synchronous queue driver, the CV row and private file are committed before parsing is dispatched. A later parsing failure keeps both, marks the CV as `failed`, and preserves a safe error code; storage compensation remains limited to failures before the database transaction commits.

The JSON contract contains `full_name`, `email`, `phone`, `location`, `birth_date`, `summary`, `experience`, `education`, `skills`, and `languages`. A complete birth date is normalized deterministically to `YYYY-MM-DD`; partial or invalid birth dates become `null`. Experience and education entries include source evidence and confidence. After parsing, deterministic normalization trims strings, deduplicates skills, rejects date-only/prose experiences, enforces date order, removes education without an institution, and removes AI entries whose evidence is absent from the source text.

Local verification never calls the real provider:

```bash
php artisan optimize:clear
php artisan test
./vendor/bin/pint --test
```

## Security

- Do not commit `.env`, access keys, bucket names intended to be secret, provider responses, CV contents, or object paths from production.
- Keep the bucket private and grant only required object operations.
- Do not expose `Storage::url()` or raw object metadata through API resources.
- Production must set `APP_DEBUG=false`.

The production-readiness audit and remaining findings are in `reports/`.
