# Localized Key/Value Contract Audit

Date: 2026-07-30
Scope: Laravel 12 API backend, `master` working tree
Contract: every system-controlled presentation value is `{key,value}`

## Baseline

The earlier localization pass already negotiated `en`/`ar` from
`Accept-Language`, localized messages and validation, and returned
`Content-Language` plus `Vary: Accept-Language`. Most enum- and workflow-backed
response fields still exposed raw strings, however, and a legacy label helper
could synthesize an English headline when a translation was missing.

The audit covered all 55 API Resource classes, direct controller response
arrays, Admin report aggregation, request validation/normalization, service
filters, enum definitions, both Postman collections, tests, and existing
localization documentation.

## Implementation

`App\Support\LocalizedValue` is the single response serializer:

- input: a backed enum, string key, or null;
- output: exactly `['key' => <stable key>, 'value' => <localized text>]`;
- null remains null;
- catalog lookup is locale-specific and strict;
- missing keys throw `LogicException`;
- no `Str::headline`, raw-key, or fallback-locale presentation fallback exists.

English and Arabic labels are organized in matching
`lang/en/options.php` and `lang/ar/options.php` catalogs. The catalog covers:

1. user roles and statuses;
2. company approval, roles, membership, invitations, and permissions;
3. job modes, employment/experience/education levels, states, and skill
   requirement types;
4. application workflow and information-request states;
5. screening question types;
6. profile source values;
7. CV parsing, review mode/status, and next actions;
8. profile suggestion entity/action/status/source/display groups;
9. test question/grading/assignment values;
10. interview types, modes, statuses, confirmations, attendance, and
    recommendations;
11. audit entities and actions.

Thirty-four Resource classes now call the strict serializer. The remaining
Resource classes contain identifiers, numbers, dates, links, booleans,
user-generated content, or nested Resources and therefore need no option
serialization.

## Response contract changes

Representative conversion:

```json
{
  "status": {
    "key": "interview_scheduled",
    "value": "Interview scheduled"
  }
}
```

Arabic:

```json
{
  "status": {
    "key": "interview_scheduled",
    "value": "تم تحديد المقابلة"
  }
}
```

Stable input and filtering contract:

```json
{
  "employment_type": "full_time",
  "experience_level": "mid_level"
}
```

The API never accepts a translated `value` as a decision key.

`ApplicationStatusResource` now emits `id`, `key`, `value`, and timestamps.
The former human `name` and duplicate raw `slug` fields are replaced by this
uniform presentation contract. All other converted fields retain their
existing names; their value changes from a raw system string to the localized
object. This is an intentional API presentation-contract change and consumers
must read `.key` for logic and `.value` for display.

Admin distributions no longer use localized text as map keys. They return:

```json
[
  {
    "key": "active",
    "value": "Active",
    "count": 12
  }
]
```

This prevents translated labels from becoming object keys and preserves stable
client decisions.

## Canonicalization and database compatibility

Canonical employment keys:

- `full_time`
- `part_time`
- `contract`
- `internship`

Canonical experience keys:

- `entry_level`
- `junior`
- `mid_level`
- `senior`

Known legacy aliases remain accepted:

- `full-time` → `full_time`
- `part-time` → `part_time`
- `entry`, `entry-level` → `entry_level`
- `mid`, `mid-level` → `mid_level`

Store/update requests normalize aliases before validation and persistence.
Index filters normalize the requested key, while the query matches both the
canonical value and its known legacy database aliases. Matching configuration
keeps compatibility weights for old representations. No migration was added:
the API now writes canonical values and safely reads/filters known legacy
rows.

## Modules and files audited

- Jobs/Home: job posting and home job Resources, request validators, posting
  service, matching compatibility.
- Users/companies: user, company, team, employer, member, invitation Resources
  and direct invitation responses.
- Applications: status, history, information request, application, and test
  assignment Resources.
- CV/profile: CV file/review, experience, education, skill, and suggestion
  Resources.
- Tests: screening, catalog question, candidate question, answer, grading,
  attempt, assignment series, and result Resources.
- Interviews: interview, evaluation, status history, and schedule history
  Resources.
- Admin: reports and audit-log Resources.

All affected Feature expectations were updated to assert `.key` and, where
presentation matters, `.value`. Fingerprint protection allowlists were
extended only for the reviewed presentation-contract files; protected
baselines were not regenerated.

The complete Resource inventory reviewed was:

- Applications: `ApplicationInformationRequestItemResource`,
  `ApplicationInformationRequestResource`,
  `ApplicationInformationResponseAttachmentResource`,
  `ApplicationInformationResponseResource`,
  `ApplicationInternalNoteAuthorResource`,
  `ApplicationInternalNoteResource`,
  `ApplicationInternalNoteRevisionResource`,
  `ApplicationStatusHistoryResource`, `ApplicationStatusResource`,
  `ApplicationTestAssignmentResource`, `JobApplicationResource`, and
  `JobApplicationScreeningQuestionResource`.
- Companies/users/profile: `CompanyInvitationResource`,
  `CompanyMemberResource`, `CompanyResource`, `CompanyTeamResource`,
  `EmployerProfileResource`, `UserResource`, `JobSeekerProfileResource`,
  `ExperienceResource`, `EducationResource`, `SkillResource`, and
  `ProfileChangeSuggestionResource`.
- CV: `CVFileResource`, `CVParsingResultResource`, and `CVReviewResource`.
- Jobs/Home/recommendations: `JobPostingResource`,
  `JobScreeningQuestionResource`, `JobScreeningQuestionOptionResource`,
  `HomeActionResource`, `HomeCompanyResource`, `HomeJobResource`,
  `RankedCandidateResource`, and `RecommendedJobResource`.
- Tests: `CandidateApplicationTestAssignmentResource`,
  `CandidateAssignedTestSummaryResource`, `CandidateTestOptionResource`,
  `CandidateTestQuestionResource`, `TestAnswerGradingResource`,
  `TestAnswerResource`, `TestAssignmentDeadlineChangeResource`,
  `TestAssignmentSeriesResource`, `TestAttemptResource`,
  `TestAttemptResultResource`, `TestOptionResource`,
  `TestQuestionResource`, and `TestResource`.
- Interviews/audit/notifications: `InterviewEvaluationItemResource`,
  `InterviewEvaluationResource`, `InterviewResource`,
  `InterviewScheduleChangeResource`, `InterviewStatusHistoryResource`,
  `AuditLogResource`, and `NotificationResource`.
- Shared Resource concern: `Concerns/ResolvesResourceViewer`.

## Deliberate raw values

These remain raw because they are technical identifiers rather than display
labels:

- notification `type`;
- recommendation engine, model, model version, and AI reason `code`;
- audit `entity_type` (a localized sibling `entity` is provided);
- audit before/after snapshots, which preserve machine values for exact
  forensic comparison;
- Home viewer/action/navigation `type` and recommendation `source`
  discriminators; their human title/label is already localized separately;
- MIME types, extensions, URLs, storage/provider identifiers;
- error codes and all machine contracts.

These remain raw because they are authored content:

- names, job titles/descriptions/requirements;
- company descriptions and locations;
- interview messages and internal notes;
- screening/test questions, options, answers, and feedback;
- CV/profile text entered by the candidate or extracted from their document.

Textual `location` remains free text. A future localized location model would
require separate `country_code`, `city_code`, and `address_text` fields; that
schema redesign is outside this presentation-contract task.

## Postman and tests

Both Web and Mobile collections:

- inject `Accept-Language: {{locale}}`;
- validate `Content-Language` and `Vary`;
- validate human message locale and stable error-code form;
- recursively inspect known system presentation fields for exactly
  `{key,value}`;
- retain per-request/per-field locale snapshots and compare the counterpart
  `en`/`ar` run to prove the `key` stays unchanged.

Automated tests cover catalog parity/non-empty values, enum coverage, strict
missing-key failure, aliases, bilingual Resource families, an actual bilingual
Jobs API request, canonical database storage, and free-text stability.

Final verification:

- focused Localization/key-value suite: **32 passed, 9,823 assertions**;
- complete Laravel suite: **875 passed, 2 expected opt-in skips, 26,671
  assertions**;
- Pint: full formatter command completed; only pre-existing out-of-scope
  formatting changes were reverted, then all changed PHP files passed
  `pint --test`;
- `composer validate --strict`: passed;
- Composer defines no PHPStan, Psalm, or other static-analysis script;
- both Postman documents parse and have `locale=en`;
- `git diff --check`: passed.

No migration, staging, commit, or push was performed.
