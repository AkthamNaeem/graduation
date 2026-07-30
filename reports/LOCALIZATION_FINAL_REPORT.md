# Laravel API localization final report

Date: 2026-07-30  
Repository branch: `master`  
Commit/push performed: **no**

## 1. Summary

The complete Laravel 12 API now negotiates `en`/`ar` from
`Accept-Language`, localizes API, validation, authorization, Home,
notification, workflow, enum-label, and structured recommendation display
text, and preserves codes, enum values, field names, HTTP statuses, and
business rules.

## 2. Baseline before the change

The application had no project `lang/` tree, one isolated translation-helper
call, 43 files/210 API response calls, 65 files containing display-field
candidates, and 60 files/234 exception or abort sites. The final residual audit
before closure contained 47 files and 215 candidate literals.

## 3. Existing localization system

Laravel's native translator and `config('app.locale')` /
`config('app.fallback_locale')` were retained. No third-party or parallel
translation subsystem was introduced.

## 4. Problems discovered

- No unified API locale middleware.
- Regional tags and q-values were not negotiated.
- API messages, validation attributes, exceptions, Resources, Home copy,
  workflow reasons, notifications, and recommendation explanations contained
  display literals.
- Known domain errors could fall back to one generic Arabic message.
- English and Arabic validation catalogs were not key-complete.
- Phase 17/18 protected tests did not yet approve localization-only
  presentation changes.

## 5. Hardcoded-string inventory

All **215** final-pass candidates are classified in
`reports/LOCALIZATION_HARDCODED_STRING_INVENTORY.md`. Classification totals:

| Classification | Count |
|---|---:|
| API user-facing | 108 |
| Validation user-facing | 62 |
| CLI only | 7 |
| Internal log | 2 |
| Provider diagnostic | 8 |
| Schema/AI instruction | 1 |
| Technical constant | 27 |
| Notification/User-generated/Test-fixture candidates in this heuristic set | 0 |

User-facing candidates without a translation key: **0**.

## 6. Modules fixed

Authentication/OTP, profiles, CV lifecycle/review, jobs, applications,
screening, tests, interviews, companies/membership/invitations, admin,
notifications, Home, enum labels, matching and ML recommendation presentation,
validation, exception rendering, Postman, and documentation.

## 7. Middleware and locale selection

`SetRequestLocale` is prepended to the Laravel API middleware group only. It:

1. Parses all `Accept-Language` members.
2. Validates q-values and ignores malformed/zero-quality candidates.
3. Normalizes regional tags such as `en-US → en` and `ar-SY → ar`.
4. Sorts by quality and original order.
5. Calls `app()->setLocale($locale)`.
6. Adds `Content-Language` and merges `Accept-Language` into `Vary`.

## 8. Default and fallback

Missing header uses `config('app.locale')`. A present header with no supported
candidate uses `config('app.fallback_locale')`; if that is unsupported it uses
the configured default and finally the first supported locale.

## 9. Translation catalogs

The same 21 files exist under `lang/en` and `lang/ar`:

`admin`, `ai`, `api`, `applications`, `auth`, `companies`, `cv`,
`domain_errors`, `enums`, `errors`, `home`, `interviews`, `jobs`,
`notifications`, `pagination`, `passwords`, `profile`, `system`, `tests`,
`validation`, and `validation_domain`.

## 10. Modified files

The generated complete path/status list is
`reports/LOCALIZATION_CHANGED_FILES.md` (139 paths at final generation).

## 11. Validation

Laravel's complete validation catalog is present in both languages. Custom
messages and human-readable attributes are translated, including nested
arrays. Error object keys remain the original request field names.

## 12. Enums and labels

Enum values and status slugs remain stable. `EnumLabel` and existing Resources
translate human display labels from `enums.values.*`; no translated value is
used for decisions.

## 13. Notifications and email

In-app notification titles/bodies use `notifications.*` keys and placeholders.
No User locale preference exists, and no migration was added. Notifications
outside an HTTP request therefore use the configured default; request-scoped
notifications use the negotiated locale. The project has no separate Mailables
requiring additional conversion.

## 14. AI-generated reasons

Matching/ML returns stable reason codes and structured values. Resources
translate `ai.reasons.CODE`; persisted structured reasons are translated after
hydration. Free user content is not translated. `MatchingService` is
byte-identical to the protected baseline.

## 15. Cache isolation

No Home/public response cache stores localized envelopes. Recommendation cache
entries store codes and structured data; translation occurs in Resources after
cache hydration. Sequential Arabic/English tests prove locale state does not
leak.

## 16. Remaining literals

The 147 scanner-visible residual literals are all classified. They are either:

- translated by stable domain/reason codes in the API renderer or Resource;
- legacy system text translated only at serialization;
- internal logs, CLI text, provider/parser diagnostics, AI schema guidance,
  SQL fragments, or technical invariants.

User-authored job/profile/note/test/interview content is deliberately unchanged.

## 17. Response examples

English:

```json
{
  "success": false,
  "code": "INTERVIEW_ALREADY_ACTIVE_FOR_TYPE",
  "message": "An active interview of this type already exists for the application.",
  "errors": []
}
```

Arabic:

```json
{
  "success": false,
  "code": "INTERVIEW_ALREADY_ACTIVE_FOR_TYPE",
  "message": "توجد مقابلة نشطة من هذا النوع لطلب التوظيف.",
  "errors": []
}
```

Validation English:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email address field is required."]
  }
}
```

Validation Arabic:

```json
{
  "success": false,
  "message": "البيانات المدخلة غير صالحة.",
  "errors": {
    "email": ["حقل البريد الإلكتروني مطلوب."]
  }
}
```

## 18. Localization tests

`php artisan test tests/Feature/Api/V1/Localization`

- **25 passed**
- **6193 assertions**
- **0 failed**

Coverage includes negotiation, headers, validation/attributes, sequential
locale isolation, structured AI reasons, 14 exact bilingual domain cases,
source domain-code catalog coverage, catalog key parity, non-empty values, and
placeholder parity.

## 19. Complete test suite

`php artisan test`

- **868 passed**
- **2 expected opt-in skips**
- **23232 assertions**
- **0 failed**

## 20. Formatting and quality

- `vendor/bin/pint --dirty --test`: passed.
- `composer validate --strict`: passed.
- `git diff --check`: passed.
- All Postman JSON files parse successfully.
- Composer defines no PHPStan/Psalm/static-analysis command.

## 21. Postman and documentation

Web and Mobile collections send `Accept-Language: {{locale}}`. The environment
defaults to `en` and documents `ar`; collection tests check localization
headers and stable machine values. README and the backend implementation report
document negotiation, defaults, fallback, and response contracts.

## 22. Constraints and deferred work

- There is no stored per-user locale preference. Queued/out-of-request
  notifications use the configured default.
- No translation migration was added.
- User-generated and provider diagnostic content is not machine translated.
- Optional real S3 integration tests remain intentionally skipped unless their
  dedicated environment flag and test credentials are supplied.

## 23. Phase 17/18 integrity and Git confirmation

The baseline JSON and aggregate hashes were not changed. Eighty-seven
localization-only protected paths were added to the project's existing explicit
post-handover allowlists; non-approved files and all baseline integrity checks
remain enforced. Per-file evidence is in
`reports/LOCALIZATION_PHASE17_18_FINGERPRINT_AUDIT.md`.

No files were staged. No commit or push was performed.
