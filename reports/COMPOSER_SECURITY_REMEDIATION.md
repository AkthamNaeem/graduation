# Composer Security Remediation

## Before

- Timestamp: 2026-07-18 (Asia/Damascus)
- Composer: 2.8.9
- PHP: 8.2.12
- Laravel: 12.58.0
- Audit result: 13 advisories affecting 7 packages
- Test baseline: 295 passed, 2744 assertions, 1 optional S3 integration test skipped

| Advisory | Package | Severity | Affected locked version | Safe line used |
| --- | --- | --- | --- | --- |
| PKSA-m5cs-t1y6-qpcs / GHSA-crmm-hgp2-wgrp | laravel/framework | Medium | 12.58.0 | 12.61.1+ |
| PKSA-3r5d-mb8f-1qw9 / GHSA-5vg9-5847-vvmq | laravel/framework | High | 12.58.0 | 12.60.0+ |
| PKSA-mdq4-51ck-6kdq / CVE-2026-48019 | laravel/framework | Not supplied | 12.58.0 | 12.60.0+ |
| PKSA-y6py-qpv1-h52p / CVE-2026-48736 | symfony/http-foundation | Medium | 7.4.8 | 7.4.13+ |
| PKSA-dw7n-x7f5-zf63 / CVE-2026-45075 | symfony/http-kernel | High | 7.4.8 | 7.4.12+ |
| PKSA-28rh-rzzn-djk4 / CVE-2026-45068 | symfony/mailer | Medium | 7.4.8 | 7.4.12+ |
| PKSA-wtxr-p26d-nn42 / CVE-2026-45070 | symfony/mime | Medium | 7.4.8 | 7.4.12+ |
| PKSA-2n2k-66v2-bwg3 / CVE-2026-45067 | symfony/mime | High | 7.4.8 | 7.4.12+ |
| PKSA-bf7t-jnpz-492k / CVE-2026-48784 | symfony/routing | Medium | 7.4.8 | 7.4.13+ |
| PKSA-yc7t-91v9-99xs / CVE-2026-45065 | symfony/routing | Medium | 7.4.8 | 7.4.12+ |
| PKSA-v5yj-8nmz-sk2q / CVE-2026-45304 | symfony/yaml | Low | 7.4.8 | 7.4.12+ |
| PKSA-ft77-7h5f-p3r6 / CVE-2026-45305 | symfony/yaml | Low | 7.4.8 | 7.4.12+ |
| PKSA-b14r-zh1d-vdrc / CVE-2026-45133 | symfony/yaml | Low | 7.4.8 | 7.4.12+ |

All advisories were classified as `PATCHABLE_DIRECT` for Laravel or `PATCHABLE_TRANSITIVE` for Symfony. No advisory was ignored.

## Upgrade Decisions

| Package/stack | From | To | Reason | Advisory | Constraint change | Regression risk |
| --- | --- | --- | --- | --- | --- | --- |
| laravel/framework | 12.58.0 | 12.64.0 | Latest allowed Laravel 12 release closes three framework advisories | Three Laravel advisories above | None (`^12.0`) | Medium: central runtime |
| symfony/http-foundation | 7.4.8 | 7.4.14 | SSRF-related private subnet fix | CVE-2026-48736 | None | Medium: request/response layer |
| symfony/http-kernel | 7.4.8 | 7.4.14 | Method-filter authorization fix | CVE-2026-45075 | None | Medium: request kernel |
| symfony/mailer | 7.4.8 | 7.4.14 | Sendmail argument injection fix | CVE-2026-45068 | None | Low: mail is not configured as a production feature |
| symfony/mime | 7.4.8 | 7.4.13 | Header and SMTP command injection fixes | CVE-2026-45067, CVE-2026-45070 | None | Medium: mail and streamed responses |
| symfony/routing | 7.4.8 | 7.4.13 | URL generation and route-requirement fixes | CVE-2026-45065, CVE-2026-48784 | None | Medium: all API routes |
| symfony/yaml | 7.4.8 | 7.4.14 | Parser memory, recursion, and ReDoS fixes | CVE-2026-45133, CVE-2026-45304, CVE-2026-45305 | None | Low: build/dev parsing |
| aligned Symfony runtime dependencies | 7.4.8 / contracts 3.6.x | 7.4.9-7.4.14 / contracts 3.7.1 | Composer-resolved Symfony 7.4 component alignment required by the targeted runtime update | Transitive support | None | Low |

The first resolution used `--with-all-dependencies`. A second controlled pass pinned eight unrelated packages to their previous lock versions, leaving only Laravel and the aligned Symfony runtime stack changed.

## Composer Configuration Review

- No custom Composer repository was introduced.
- No `audit.ignore` entry was added.
- No plugin allowance was changed.
- No production database operation is run by Composer scripts; package discovery and asset publication completed.
- No secrets or environment values were added.

## After

- Laravel remains on major version 12: 12.64.0.
- PHP requirement remains `^8.2`.
- `composer.json` constraints are unchanged.
- `composer audit --locked`: **No security vulnerability advisories found.**
- Remaining advisories: 0.
- Added packages: 0.
- Removed packages: 0.
- Application source compatibility changes: none.
- Database schema changes: none.

### Verification evidence

- `composer validate --strict`: passed.
- `composer install --dry-run`: passed.
- `composer install --no-dev --optimize-autoloader --dry-run`: passed (35 dev-only removals were planned; the working copy was not stripped).
- `composer install --no-interaction --prefer-dist`: passed with nothing further to install.
- Optimized autoload generation: passed (8601 classes).
- Config, route, event, and view caches: passed with `CACHE_STORE=array` to avoid touching the external database from the local verification environment.
- Isolated SQLite `migrate:fresh --seed`: passed.
- API route list, event list, schedule list, and local private-storage verification: passed.
- PHP lint: 499 files checked, 0 failures.
- Final full suite: 295 passed, 2744 assertions, 0 failed, 1 optional real-S3 test skipped.
- All requested functional filters passed when run sequentially. The real-S3 filter remained skipped because dedicated test credentials were not supplied.
- Full-project Pint remains failed on 64 historical files; this task changed no PHP source file and did not expand that formatting debt.

## Rollback

The pre-remediation lock state is the `composer.lock` at Git HEAD `88f5b88`. Rolling the lock file back would restore Laravel 12.58.0 and Symfony 7.4.8 and therefore reintroduce all 13 advisories. A rollback must be treated as a temporary emergency action and followed by a corrected forward upgrade. There are no migrations, schema changes, configuration changes, or source compatibility shims to reverse.
