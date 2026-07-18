# MySQL Compatibility Audit

Audit date: 2026-07-18  
Target: Laravel 12.58.0 backend at commit 06eb7bd  
Production database family: MySQL  
Execution verdict: **NOT_EXECUTED**  
Static compatibility verdict: **PASS WITH WARNINGS**

## Executive conclusion

No isolated MySQL server was available: the mysql CLI was absent, no mysqld process was running, and 127.0.0.1:3306 was closed. The configured external database was deliberately not changed. Consequently, this audit does not claim that migrations or the full suite pass on MySQL.

Static review found no obvious SQLite-only construct in application queries or migrations. Laravel schema builder is used consistently; the raw fragments found (DATE(), LOWER(), CASE ordering and aggregate selectRaw) are MySQL-compatible. This is useful evidence, not an execution substitute.

## Evidence

- 50 migration files were inspected.
- Disposable SQLite lifecycle passed: migrate:fresh, migrate:status, rollback --step=1, and migrate.
- Full SQLite-backed test suite passed: 281 tests and 2658 assertions.
- MySQL execution: NOT_EXECUTED because no isolated local MySQL was available.
- The application environment reports the mysql driver, but the configured remote database was not used for destructive testing.

## Static DDL review

| Area | Result | Notes |
|---|---|---|
| Table creation and alteration | PASS | Uses Laravel Blueprint/schema builder. |
| Foreign keys | PASS WITH WARNINGS | Ordering is coherent and SQLite fresh migration passed; MySQL restrict/cascade/null actions still require execution. |
| Indexes and unique constraints | PASS WITH WARNINGS | Core foreign/filter columns are generally indexed. Cardinality and redundant-index cost require production EXPLAIN evidence. |
| JSON columns | PASS | MySQL supports the declared JSON columns. |
| Decimal scores/salaries | PASS | Explicit precision is used; grading invariant tests pass. |
| Timestamp ordering and after() clauses | LIKELY | Supported by MySQL; not executed here. |
| Backfill migrations | LIKELY | Primary-CV and score normalization use portable query-builder/raw CASE constructs; lock duration on real data is unknown. |
| Rollback definitions | PASS WITH WARNINGS | One-step SQLite rollback/reapply passed; full MySQL rollback was not executed. |

## Raw-query review

The notable fragments are compatible with MySQL:

- DATE(job_applications.created_at) in admin reporting.
- LOWER(name) equality checks for case-insensitive skill validation.
- CASE expressions used to select a primary CV.
- COUNT(*) grouped by requirement type.

Remaining concerns are semantic rather than dialect errors: production collation controls case sensitivity, DATE() can prevent index-only range use, and migration backfills may lock large tables.

## Required isolated MySQL verification

Use a disposable MySQL 8.x database only. Never point these commands at production.

1. Create a uniquely named empty audit database and least-privilege audit user.
2. Export DB_CONNECTION=mysql plus the isolated host, port, database and credentials without logging secrets.
3. Run php artisan migrate:fresh --force.
4. Run php artisan migrate:status.
5. Run php artisan test.
6. Run php artisan migrate:rollback --step=1 --force, then php artisan migrate --force.
7. Run representative EXPLAIN plans for public jobs, employer applications, notifications, audit logs, matching and reports.
8. Drop only the exact isolated database after verifying its resolved name.

## Acceptance gate

MySQL compatibility remains NOT_EXECUTED until the supported production MySQL version completes fresh migration, full tests, rollback/reapply, seed-safety checks and representative query plans. Per the audit rules, this alone caps a non-failing verdict at PASS WITH WARNINGS; other confirmed blockers already make the overall verdict FAIL.
