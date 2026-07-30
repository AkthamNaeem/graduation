# Workey Demo Database

## Current State

Previously, `DatabaseSeeder` ran `ApplicationStatusSeeder` and one
`SampleUserSeeder`. That path created three users, one company, one candidate
profile, and three jobs, but no complete recruitment workflow. Idempotency
depended on `updateOrCreate`, old pivot rows could survive through
`syncWithoutDetaching`, timestamps used unrelated `now()` calls, and CV review,
applications, information requests, tests, interviews, notifications, audit
logs, and persisted recommendations were not covered.

`SampleUserSeeder` is now only a deprecated wrapper around
`FullProjectSeeder`; there is no second demo dataset.

## Safety and Running

The default command rebuilds all application demo data:

```bash
php artisan db:seed
```

The same flow can be selected explicitly:

```bash
php artisan db:seed --class=Database\\Seeders\\FullProjectSeeder
```

These commands delete and recreate domain data, personal access tokens,
notifications, audit records, recommendation records, workflow histories, and
files under `storage/app/private/demo-seed`. They do not delete `migrations` or
cache tables and do not alter table structure.

Destructive demo seeding throws
`Demo database seeding is disabled in production.` before deletion. `--force`
does not bypass this guard. Demo files always use the local private disk; no
HTTP, email, queue, parser, LLM, S3, or ML service is invoked.

To rebuild schema and demo data locally or in testing:

```bash
php artisan migrate:fresh --seed
```

## Seeder Architecture

| Seeder/support class | Models and responsibility |
| --- | --- |
| `DemoDatabaseResetter` | Ordered cleanup for all domain tables, tokens, sessions, queued-job records, and demo files; resets identities and restores FK enforcement in `finally`. |
| `ReferenceDataSeeder` | `ApplicationStatus`, `Skill`; invokes standalone-compatible `ApplicationStatusSeeder`. |
| `DemoUsersSeeder` | `User`, `Company`, `EmployerProfile`; all roles, user states, and company approval states. |
| `DemoJobSeekerProfilesSeeder` | `JobSeekerProfile`, `Experience`, `Education`, `JobSeekerSkill`; seven personas and every source value used by the demo. |
| `DemoCVSeeder` | `CVFile`, `CVParsingResult`, `ProfileChangeSuggestion`; local files, parsing/review/archive lifecycle, reviewed JSON, and suggestion decisions. |
| `DemoJobPostingsSeeder` | `JobPosting`, `JobPostingSkill`, `JobScreeningQuestion`, `JobScreeningQuestionOption`; job states, modes, contracts, skills, and all screening types. |
| `DemoApplicationsSeeder` | `JobApplication`, `ApplicationStatusHistory`, and all `JobApplicationScreening*` models; one application per official status with immutable question/answer snapshots. |
| `DemoApplicationInformationSeeder` | All `ApplicationInformation*` models plus `ApplicationInternalNote` and `ApplicationInternalNoteRevision`; pending/responded/cancelled requests and private-note revision/soft-delete scenarios. |
| `DemoTestsSeeder` | `Test`, `TestQuestion`, `TestOption`, `ApplicationTestAssignment`, `ApplicationTestAssignmentDeadlineChange`, `TestAttempt`, `TestAnswer`, `TestAnswerGrading`, and `test_answer_options`; objective/manual grading, deadlines, pass/fail, and retakes. |
| `DemoInterviewsSeeder` | `Interview`, `InterviewStatusHistory`, `InterviewScheduleChange`, `InterviewEvaluation`, `InterviewEvaluationItem`; every type, mode, status, attendance value, and recommendation. |
| `DemoNotificationsSeeder` | `Notification`; read/unread payloads matching listener contracts for candidate, employer, and administrator actors. |
| `DemoAuditLogsSeeder` | `AuditLog`; safe before/after data for users, companies, jobs, applications, tests, interviews, CVs, and final decisions. |
| `DemoRecommendationsSeeder` | `RecommendationRun`, `RecommendationItem`; live, expired, ML, matching, and safe-fallback results with sequential ranks and bounded scores. |
| `FullProjectSeeder` | Production guard, one reference time, reset, ordered orchestration, record-count summary, and login summary. |

`EventSideEffectExecution` has no semantic demo row because seeders suppress
model events and must not pretend that a listener executed. Its table is still
cleaned. Infrastructure-only cache, password-reset, session, token, and queue
tables do not have domain scenarios.

## Expected Counts

Counts remain stable across repeated runs:

| Table | Count |
| --- | ---: |
| users | 15 |
| companies | 5 |
| job_seeker_profiles | 7 |
| cv_files | 6 |
| job_postings | 8 |
| job_applications | 14 |
| application_status_histories | 61 |
| application_information_requests | 3 |
| application_test_assignments | 10 |
| test_attempts | 7 |
| interviews | 9 |
| notifications | 14 |
| audit_logs | 15 |
| recommendation_runs | 3 |
| recommendation_items | 6 |

## Coverage Matrix

| DB-backed enum/state | Seeded values and counts |
| --- | --- |
| `UserRole` | `admin` 2, `employer` 6, `job_seeker` 7 |
| `UserStatus` | `active` 12, `suspended` 3 |
| `CompanyApprovalStatus` | `approved` 2, `pending` 1, `rejected` 1, `suspended` 1 |
| `EducationLevel` | `high_school` 1, `diploma` 1, `bachelor` 3, `master` 1, `doctorate` 1 |
| Job status | `draft` 1, `open` 6, `closed` 1 |
| `JobWorkMode` | `remote` 4, `on_site` 2, `hybrid` 2 |
| `JobSkillRequirementType` | `required` 17, `nice_to_have` 7, legacy `optional` 1 |
| `ScreeningQuestionType` | one each: `short_text`, `long_text`, `single_choice`, `multiple_choice`, `boolean`, `number` |
| Application status | one current application each: `submitted`, `under_review`, `shortlisted`, `test_pending`, `test_completed`, `interview_pending`, `interview_scheduled`, `interview_completed`, `final_review`, `accepted`, `rejected`, `withdrawn`, `on_hold`, `need_more_information` |
| `ApplicationInformationRequestStatus` | `pending` 1, `responded` 1, `cancelled` 1 |
| CV parsing status | `uploaded` 1, `processing` 1, `parsed` 3, `failed` 1; one parsed CV is archived |
| CV review state | `draft` 2, `comparison_pending` 1, `decisions_pending` 1, `applied` 2 |
| Profile suggestion type/status | `add`, `update`, `merge`, `ignore` once each; `pending`, `accepted`, `rejected`, `applied` once each |
| `TestQuestionType` | `single_choice` 3, `multiple_choice` 1, `true_false` 2, `short_text` 1, `long_text` 2, `file_upload` 1 |
| `TestAttemptGradingStatus` | `pending` 1, `auto_graded` 3, `manual_grading_required` 1, `fully_graded` 2 |
| `TestAnswerGradingType` | `automatic` 15, `manual` 4 |
| `InterviewType` | `hr` 4, `technical` 3, `final` 2 |
| `InterviewMode` | `online` 5, `on_site` 4 |
| `InterviewStatus` | `scheduled`, `confirmed`, `rescheduled`, `completed`, `cancelled`, `no_show` once each; `evaluated` 3 |
| `InterviewAttendanceStatus` | candidate: `pending` 3, `present` 4, `absent` 1, `excused` 1; interviewer values are limited to logically applicable pending/present states |
| Interview recommendation | `advance`, `hold`, `reject` once each |
| `RecommendationEngine` | `ml_xgbranker`, `matching_v2`, `matching_v2_fallback` once each |

All persisted enum cases are asserted dynamically with `EnumClass::cases()` in
`FullProjectSeederTest`. Behavioral enums are not forced into unrelated tables.

## Demo Scenarios

- Backend candidate: parsed/confirmed primary CV, high skill overlap, passed
  mixed assessment, completed interviews, final review, and acceptance.
- Frontend candidate: processing CV, under-review/on-hold/information-request
  branches, including a stored response attachment.
- Data candidate: parsed CV comparison, all suggestion decisions, ML role
  application, completed test, interview, and recommendation examples.
- Junior, senior, incomplete, and suspended candidates cover partial profiles,
  pending work, withdrawal, rejection, account blocking, and experience levels.
- Tests include an assignment without an attempt, expired assignment, extended
  deadline, in-progress attempt, automatic pass/fail, pending manual grading,
  fully graded pass/fail, and a two-attempt retake series.
- Interviews include online/on-site schedules, confirmation, rescheduling,
  completion, cancellation, no-show, evaluation, attendance, and private notes.
- Notifications and audits are inserted directly with real payload keys while
  model events are disabled, preventing duplicate side effects on reruns.
- Recommendations include a live ML run, expired matching run, and live
  safe-fallback run with contiguous ranks and scores in `[0, 100]`.

## Demo Accounts

All demo accounts use password `password`.

| Role | Email | Status | Company/description |
| --- | --- | --- | --- |
| Admin | `admin@workey.test` | Active | Platform administrator |
| Admin | `admin.suspended@workey.test` | Suspended | Account-state testing |
| Employer | `employer.approved@workey.test` | Active | Workey Labs, approved |
| Employer | `employer.recruiter@workey.test` | Active | Workey Labs teammate |
| Employer | `employer.pending@workey.test` | Active | Pending Ventures |
| Employer | `employer.rejected@workey.test` | Active | Rejected Systems |
| Employer | `employer.suspended@workey.test` | Suspended | Suspended Digital |
| Employer | `employer.second@workey.test` | Active | Damascus Data Co., approved |
| Job Seeker | `seeker.backend@workey.test` | Active | Backend accepted workflow |
| Job Seeker | `seeker.frontend@workey.test` | Active | Frontend/information workflows |
| Job Seeker | `seeker.data@workey.test` | Active | Data/AI and CV suggestions |
| Job Seeker | `seeker.junior@workey.test` | Active | Junior/pending workflow |
| Job Seeker | `seeker.senior@workey.test` | Active | Senior/interview workflow |
| Job Seeker | `seeker.incomplete@workey.test` | Active | Incomplete profile |
| Job Seeker | `seeker.suspended@workey.test` | Suspended | Account state/withdrawal |

## Schema-Limited Gaps

- Skills have no active/inactive, proficiency, or years-of-use columns.
- Profiles have no expected salary, availability, or relational certification
  tables. Certification data is represented in actual parsed CV JSON.
- CV parsing uses `uploaded`, `processing`, `parsed`, and `failed`; there is no
  separate `pending` value. Archiving is represented by `archived_at`.
- Jobs have no AI-enabled flag, and applications have no matching-score or
  breakdown columns. Matching data is stored in the recommendation tables.
- Tests use `is_active`; there is no draft status column. Assignment/attempt
  states such as expired, not-started, and submitted are derived from dates and
  grading fields.
- Internal notes have revisions and soft deletion but no visibility/team tags.
- An information request update is an event while status stays `pending`;
  `updated` is not a persisted request status.
