# Smart Recruitment Platform — Backend Implementation Report

## Company Management, Membership, Invitations, and Roles (2026-07-30)

### 1. Baseline

| Item | Value |
| --- | --- |
| Branch | `master` |
| Starting commit | `7811ce8d016444568b772cba1ff67f5e4d367838` |
| Starting working tree | Clean |
| Baseline tests | 802 passed, 2 skipped, 17,282 assertions |

### 2. Existing implementation reused

The implementation extends the existing `Company`, `EmployerProfile`, and `User`
models and reuses `AdminCompanyController`, `RegistrationService`,
`CompanyRecruitmentAccessService`, `EnsureCompanyApproved`, the current
recruitment policies, `AuditLogService`, `NotificationService`, `ApiResponse`,
Sanctum tokens, and the existing `/api/v1` route layout. No duplicate
`company_members` table or parallel recruitment module was created.

### 3. Architecture decisions

- `employer_profiles` remains the one-company-per-employer membership record.
  `user_id` stays unique, while company role, membership state, inviter, and
  lifecycle timestamps are stored on that record.
- `company_invitations` is separate because an invitation can exist before a
  user account. Only a SHA-256 token hash is persisted. The raw token is
  returned only by create/resend.
- Global `UserRole::EMPLOYER` identifies the account type. `CompanyRole`
  controls company permissions: `owner`, `company_admin`, `recruiter`,
  `interviewer`, and `reviewer`.
- `CompanyPermissionService` is the source for active membership, same-company
  scope, role permissions, and the Administrator bypass. Services still enforce
  data invariants after authorization succeeds.
- Admin-created companies set `owner_setup_required`. Recruitment remains
  blocked with `COMPANY_SETUP_OWNER_REQUIRED` until an Owner accepts.
- Last-Owner checks are never bypassed. Ownership transfer locks the company,
  current owner, and target membership in one retried transaction.

### 4. Changed files

| File or group | Change | Reason |
| --- | --- | --- |
| `database/migrations/2026_07_30_000003_*` | Membership columns, indexes, deterministic backfill | Extend the existing membership table safely |
| `database/migrations/2026_07_30_000004_*` | `company_invitations` table | Store invitation lifecycle independently |
| `database/migrations/2026_07_30_000005_*` | Owner setup marker | Block new Admin companies until Owner setup |
| `app/Enums/Company*` | Role, permission, membership, invitation enums | Eliminate repeated authorization strings |
| `app/Models/{Company,EmployerProfile,CompanyInvitation,User}.php` | Casts and relationships | Represent membership and invitation state |
| `app/Services/Company*Service.php` | Permissions, invitations, membership, ownership | Keep business rules out of controllers |
| `app/Services/AdminCompanyService.php` | Transactional Admin create/update | Support optional Owner invitation |
| `app/Http/Controllers/Api/V1/{Admin,Company}` | Admin/team/public invitation APIs | Expose the REST contract |
| `app/Http/Requests/Api/V1/{Admin,Company}` | Per-operation validation | Validate roles, filters, status, ownership input |
| `app/Http/Resources/Api/V1/Company*Resource.php` | Safe member/invitation/setup responses | Never expose token hashes |
| `app/Policies/*` and recruitment services | Role matrix, Admin bypass, active membership, company scope | Apply permissions across existing modules |
| `routes/api/v1.php` | Admin, team, invitation, ownership routes | Extend the existing API |
| `database/seeders/DemoUsersSeeder.php` | Role/status/invitation demo scenarios | Provide deterministic test data |
| `tests/Feature/Api/V1/Company*Test.php` | Invitation, membership, ownership, matrix coverage | Protect invariants |
| `postman/*` | Six management folders, mobile invitation flow, variables | Document and exercise the API |

### 5. Database changes

`employer_profiles` now includes `company_role`, `membership_status`,
`invited_by_user_id`, `joined_at`, `suspended_at`, and `removed_at`. Indexes
cover role, membership status, `(company_id, membership_status)`, and
`(company_id, company_role)`; the original company foreign-key index and unique
`user_id` remain.

The backfill marks every existing membership active, copies `created_at` to
`joined_at`, chooses the oldest `(created_at, id)` membership in each company
as Owner, and assigns the remaining memberships Company Admin.

`company_invitations` contains company/email/role, unique token hash, status,
inviter, expiry, acceptance actor/timestamps, rejection/revocation timestamps,
foreign keys, and lookup indexes. Rollback drops invitations and the added
membership/setup columns and indexes.

### 6. API contract

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST/GET | `/api/v1/admin/companies` | Create/list companies |
| GET/PUT/PATCH | `/api/v1/admin/companies/{company}` | Show/update company |
| GET | `/api/v1/admin/companies/{company}/members` | Filter/paginate members |
| POST/GET | `/api/v1/admin/companies/{company}/invitations` | Create/list invitations |
| PATCH | `/api/v1/admin/companies/{company}/members/{user}/role` | Change company role |
| PATCH | `/api/v1/admin/companies/{company}/members/{user}/status` | Suspend/reactivate |
| DELETE | `/api/v1/admin/companies/{company}/members/{user}` | Soft-remove membership |
| POST | `/api/v1/admin/companies/{company}/transfer-ownership` | Admin ownership transfer |
| GET | `/api/v1/company/members` | List current company members |
| POST/GET | `/api/v1/company/invitations` | Create/list invitations |
| POST | `/api/v1/company/invitations/{invitation}/resend` | Rotate token/expiry |
| POST | `/api/v1/company/invitations/{invitation}/revoke` | Revoke invitation |
| PATCH/DELETE | `/api/v1/company/members/{user}/*` | Role/status/remove |
| POST | `/api/v1/company/transfer-ownership` | Owner transfer |
| GET | `/api/v1/company-invitations/{token}` | Safe public inspection |
| POST | `/api/v1/company-invitations/{token}/accept` | Atomic join/reactivation |
| POST | `/api/v1/company-invitations/{token}/reject` | Reject invitation |

`POST /api/v1/auth/register/employer` remains routable for compatibility but
returns HTTP 403 with `EMPLOYER_SELF_REGISTRATION_DISABLED` and performs no
writes. Employer accounts now enter through invitation acceptance.

Member and invitation lists support validated filtering, sorting, date bounds,
and pagination capped at 100.

### 7. Permissions matrix

| Action | Admin | Owner | Company Admin | Recruiter | Interviewer | Reviewer |
| --- | --- | --- | --- | --- | --- | --- |
| Create/manage company | Any | Own | Own | No | No | No |
| View/manage team | Any | Yes | Except Owner | No | No | No |
| Invite Owner | Yes | Yes | No | No | No | No |
| Transfer ownership | Yes | Yes | No | No | No | No |
| Jobs | Any | Manage | Manage | Manage | Context only | No |
| Applications | Any | Manage | Manage | Manage | Read | Read |
| Tests | Any | Manage | Manage | Manage | No | Read/grade |
| Interviews | Any | Manage/evaluate | Manage/evaluate | Schedule/manage | Complete/evaluate | No |
| Internal notes | Any | Yes | Yes | Yes | No | Yes |

All non-Admin permissions require active membership in the resource company.
Suspended and removed memberships fail before role permissions are considered.

### 8. Business rules

- Emails are trimmed and lowercased. A company/email has at most one unexpired
  pending invitation.
- Accepted, rejected, revoked, expired, or rotated tokens cannot be reused.
- New acceptance creates an Employer and membership in the invited company only.
- Job Seeker/Admin emails and Employers in another company are rejected without
  changing global roles or moving memberships.
- Removed members may return only through a new invitation to the same company.
- Suspension/removal retains history and revokes every Sanctum token.
- Company Admin cannot grant/manage Owner. Ownership changes use the explicit
  transactional transfer endpoint.
- Audit/notification metadata never includes raw tokens, hashes, OTPs, or
  passwords.

### 9. Tests

Feature coverage includes Admin company creation with Owner invitation, token
hashing, acceptance without company creation, reuse/expiry/revocation, duplicate
invitations, role conflicts, cross-company denial, role restrictions, token
revocation, reactivation, last-Owner protection, ownership transfer, audit
events, and the permission matrix. Legacy employer-registration tests now
assert the disabled contract.

Final regression result: **817 passed, 2 skipped, 17,278 assertions**. The two
skipped tests are the repository's opt-in real S3 integration checks. Focused
company-role tests passed with 15 tests and 101 assertions; the requested
filters also passed: Company 44/323, Invitation 7/58, Member 5/27, and
Authorization 9/106.

### 10. Verification

The complete migration set ran on isolated SQLite. The three new migrations
also rolled back and reapplied successfully. Route inspection found 197
`/api/v1` routes, including every documented Admin, company-team, invitation,
and ownership endpoint. All three Postman files decode as valid JSON, and
`git diff --check` passes.

Pint passes for all 58 changed/new PHP files. Repository-wide
`vendor/bin/pint --test` still reports 51 pre-existing style violations in
unchanged files; those files were deliberately not reformatted as part of this
feature. `composer audit --locked` could not be completed in the sandbox:
Packagist access was blocked, and elevated access was denied because it would
disclose locked dependency metadata to an external service.

### 11. Remaining gaps

- No email provider was added. In-app notification is used when the invitee
  already has an account.
- Database locks serialize ownership and acceptance on MySQL/PostgreSQL. SQLite
  tests validate atomic outcomes but cannot reproduce server lock scheduling.
- Public registration OTP remains available for Job Seekers only.

## 1. Executive Summary

The backend implements a Laravel 12 REST API for a smart recruitment platform. The current scope covers account registration and authentication, job seeker and employer profiles, CV upload and structured parsing, employer job posting management, job applications with status history, test assignments and attempts, interview scheduling and evaluation, deterministic matching/ranking based on TF-IDF and cosine similarity, user-facing in-app notifications, and platform-level admin APIs.

The implementation follows a service-oriented Laravel structure using Form Requests for validation and authorization, API Resources for response shaping, Policies and gates for ownership and role checks, seeders for initial data, and feature/unit tests for the implemented modules. API routes are versioned under `/api/v1`. The backend is functionally broad for an MVP, but it does not yet include deep AI/LLM matching, email notification delivery, or a UI-facing dashboard.

### 1.1 Temporary Registration OTP Verification

Job Seeker registration requires email verification before login. Public
Employer registration is disabled and performs no writes; Employer users join
an existing company through a company invitation. Job Seeker registration
creates an active unverified user/profile in one transaction, returns no Sanctum
token, and creates one hashed OTP record in `email_verification_otps`.
`users.email_verified_at` is the only verification source of truth;
`UserStatus` remains reserved for administrative account state.

**Temporary OTP for development/demo: `000000`.**

No OTP is currently delivered. During this temporary phase the user must enter `000000` through `POST /api/v1/auth/email/verify-otp`. The API never returns the OTP, never stores it in plaintext, and sends no email, SMS, or WhatsApp request. `POST /api/v1/auth/email/resend-otp` preserves the future sender contract by refreshing expiry, resetting attempts, and enforcing a cooldown even though nothing is delivered.

This static OTP is intentionally insecure and must not be used as a real production authentication mechanism. A real email, SMS, or WhatsApp delivery driver must replace the static driver before production use; the registration, verification, reissue, and login API contracts are designed to remain unchanged.

Configuration:

```env
OTP_DRIVER=static
OTP_LENGTH=6
OTP_TTL_MINUTES=5
OTP_MAX_ATTEMPTS=5
OTP_RESEND_COOLDOWN_SECONDS=60
OTP_ALLOW_STATIC_IN_PRODUCTION=false
```

The static driver is allowed in `local` and `testing`. In `production` it fails safely with `OTP_DRIVER_NOT_AVAILABLE` unless the explicit temporary override is enabled. For the graduation-project Render demo only:

```env
OTP_DRIVER=static
OTP_LENGTH=6
OTP_TTL_MINUTES=5
OTP_MAX_ATTEMPTS=5
OTP_RESEND_COOLDOWN_SECONDS=60
OTP_ALLOW_STATIC_IN_PRODUCTION=true
```

`OTP_ALLOW_STATIC_IN_PRODUCTION=true` is a temporary demo exception. Remove it when a real delivery provider is implemented. No SMTP variables are required for this workflow.

Security controls include hashed OTP storage, one OTP row per user, expiry, database-level attempt limits, reissue cooldown, row locks and transactions during consumption, one-time deletion, token creation only after commit, email/IP route throttling, normalized email handling, production-driver protection, generic reissue responses, and safe audit metadata that excludes codes, hashes, passwords, tokens, and request payloads.

### 1.2 Temporary OTP Forgot-Password Workflow

The public forgot-password workflow is separate from registration email verification and authenticated password changes. It no longer uses Laravel Password Broker tokens, reset links, or email notifications.

Forgot password:

```text
email
→ OTP
→ new password
→ login again
```

`POST /api/v1/auth/forgot-password` creates or refreshes a hashed record in the separate `password_reset_otps` table for verified, unverified, active, or suspended users. Unknown emails receive the same generic success response. Requests during the service cooldown also receive the same response without changing the hash, expiry, attempts, or issue time.

`POST /api/v1/auth/reset-password` accepts `email`, `otp`, `password`, and `password_confirmation`. It locks and verifies the purpose-specific OTP, changes the password, rotates `remember_token`, revokes all Sanctum tokens, consumes the OTP, dispatches the internal `PasswordReset` event, and returns no replacement token. It does not change email verification, role, administrative status, profiles, companies, or company approval state.

Authenticated change password remains separate:

```text
authenticated user
→ current password
→ new password
→ confirmation
```

`POST /api/v1/auth/change-password` remains protected by Sanctum, requires the current password, uses no email or OTP, and does not create or consume a password-reset OTP.

Mobile/frontend integration:

1. Submit the email to `forgot-password`.
2. Navigate to a form containing OTP, new password, and confirmation.
3. For the temporary graduation demo, enter `000000`; no code is delivered.
4. Submit the form to `reset-password`.
5. On success, clear any locally stored access token, return to login, and authenticate with the new password.
6. For authenticated change-password, send the current password and new confirmed password with the Bearer token; no OTP screen is involved.

The registration verification and password-reset records are deliberately independent. Neither purpose can operate from only the other purpose's database record, and consuming one does not consume the other. Both currently share the insecure static configuration described above. The production override is for the graduation demo only and must be removed when a real sender replaces the static driver.

## 2. Technology Stack

| Area | Implementation |
| --- | --- |
| Framework | Laravel `^12.0` |
| PHP | `^8.2` |
| Database | MySQL, configured through Laravel database configuration |
| Authentication | Laravel Sanctum `^4.3`, bearer tokens via `personal_access_tokens` |
| API style | REST API with JSON responses and `/api/v1` versioning |
| Architecture | Controllers, Services, Form Requests, API Resources, Policies, Seeders, Jobs, Eloquent Models |
| File parsing libraries | `smalot/pdfparser` for PDF, `phpoffice/phpword` for DOCX |
| Testing | PHPUnit feature and unit tests through `php artisan test` |

## 3. Implemented Phases Overview

| Phase | Phase Name | Main Implemented Features | Status |
| --- | --- | --- | --- |
| 1 | Project Setup | Laravel 12 app, Sanctum installation, API route versioning, hardened auth endpoints, user roles/status enforcement, standard API envelope, current Postman collections | Implemented / Hardened |
| 2 | Core Profiles | Job seeker profile, employer profile, company profile, experiences, education, skills, role-scoped profile endpoints | Implemented |
| 3 | CV Upload & Parsing | CV upload for PDF/DOCX, queued parsing job, raw text extraction, parsed JSON storage, confirm flow to append profile data | Partially Implemented (MVP/basic parsing) |
| 4 | Job Posting | Employer job CRUD, public open-job listing, filters, skills attachment, publish and close workflows | Implemented |
| 5 | Job Applications Workflow | Job applications, duplicate prevention, application statuses, transition validation, terminal states, role-safe status-history resources | Implemented with candidate-safe response boundaries |
| 6 | Testing Module | Company-owned immutable tests, canonical question-point scoring, normalized answers, grading/results, deadlines, retake series, and attempt-scoped candidate content | Advanced / Implemented for current MVP scope |
| 7 | Interview Module | Interview scheduling, completion and evaluation with candidate-safe and employer-management response boundaries | Implemented with remaining mode/attendance/status refinements |
| 8 | AI Matching | Deterministic TF-IDF matching, cosine similarity, recommendations for job seekers, ranked candidates for employers, score breakdowns | Partially Implemented (IR-based matching, not deep AI) |
| 9 | Notifications + Admin APIs | In-app notification table, workflow event/listener dispatch, notification endpoints, admin APIs for users, companies, skills, and tests | Implemented |
| 10 | Admin & Reports Completion | Completed admin user/company/skill filters and detail actions, safe skill deletion behavior, and basic read-only JSON report endpoints for dashboard statistics | Implemented |

## 4. Database Structure

### `users`

Purpose: Stores application accounts for admins, job seekers, and employers.

Key columns: `id`, `name`, `email`, `role`, `status`, `email_verified_at`, `password`, `remember_token`, timestamps.

Main relationships: has one `job_seeker_profiles`, has one `employer_profiles`, has many `cv_files`, has many `notifications`, owns Sanctum tokens through `personal_access_tokens`.

### `job_seeker_profiles`

Purpose: Stores candidate profile details.

Key columns: `id`, `user_id`, `headline`, `summary`, `phone`, `location`, `portfolio_url`, `linkedin_url`, `github_url`, timestamps.

Main relationships: belongs to `users`; has many `experiences`, `education`, and `job_applications`; belongs to many `skills` through `job_seeker_skills`.

### `companies`

Purpose: Stores employer company information.

Key columns: `id`, `name`, `industry`, `website`, `location`, `description`, `approval_status`, timestamps.

Main relationships: has many `employer_profiles`; has many `job_postings`.

### `notifications`

Purpose: Stores in-app notifications generated by workflow events.

Key columns: `id`, `user_id`, `type`, `title`, `message`, nullable JSON `data`, nullable `read_at`, `created_at`.

Main relationships: belongs to `users`.

### `employer_profiles`

Purpose: Stores employer user profile details and links each employer to a company.

Key columns: `id`, `user_id`, `company_id`, `job_title`, `phone`, `bio`, timestamps.

Main relationships: belongs to `users`; belongs to `companies`.

### `experiences`

Purpose: Stores candidate work history.

Key columns: `id`, `job_seeker_profile_id`, `title`, `company_name`, `location`, `start_date`, `end_date`, `is_current`, `description`, timestamps.

Main relationships: belongs to `job_seeker_profiles`.

### `education`

Purpose: Stores candidate education records.

Key columns: `id`, `job_seeker_profile_id`, `institution`, `degree`, `field_of_study`, `start_date`, `end_date`, `description`, timestamps.

Main relationships: belongs to `job_seeker_profiles`.

### `skills`

Purpose: Stores reusable platform skills.

Key columns: `id`, `name`, `slug`, timestamps.

Main relationships: belongs to many `job_seeker_profiles` through `job_seeker_skills`; belongs to many `job_postings` through `job_posting_skills`.

### `job_seeker_skills`

Purpose: Pivot table between job seeker profiles and skills.

Key columns: `id`, `job_seeker_profile_id`, `skill_id`, timestamps; unique pair on `job_seeker_profile_id` and `skill_id`.

Main relationships: pivot for `job_seeker_profiles` and `skills`.

### `cv_files`

Purpose: Tracks uploaded CV files and parsing state.

Key columns: `id`, `user_id`, `original_name`, `stored_path`, `disk`, `mime_type`, `extension`, `size_bytes`, `status`, `error_message`, `confirmed_at`, timestamps.

Main relationships: belongs to `users`; has one `cv_parsing_results`.

### `cv_parsing_results`

Purpose: Stores extracted text and parsed structured data for a CV.

Key columns: `id`, `cv_file_id`, `raw_text`, `parsed_json`, timestamps.

Main relationships: belongs to `cv_files`; one parsing result per CV file.

### `job_postings`

Purpose: Stores employer-created job posts.

Key columns: `id`, `company_id`, `title`, `description`, `employment_type`, `experience_level`, `location`, `salary_min`, `salary_max`, `status`, `published_at`, timestamps.

Main relationships: belongs to `companies`; belongs to many `skills` through `job_posting_skills`; has many `job_applications`.

### `job_posting_skills`

Purpose: Pivot table between job postings and skills.

Key columns: `id`, `job_posting_id`, `skill_id`, timestamps; unique pair on `job_posting_id` and `skill_id`.

Main relationships: pivot for `job_postings` and `skills`.

### `application_statuses`

Purpose: Catalog of valid application status values.

Key columns: `id`, `name`, `slug`, timestamps.

Main relationships: has many `job_applications`; referenced by application status history as both source and target statuses.

### `job_applications`

Purpose: Stores a candidate's application to a job.

Key columns: `id`, `job_posting_id`, `job_seeker_profile_id`, `application_status_id`, timestamps; unique pair on `job_posting_id` and `job_seeker_profile_id`.

Main relationships: belongs to `job_postings`, `job_seeker_profiles`, and `application_statuses`; has many `application_status_histories`, `application_test_assignments`, and `interviews`.

### `application_status_histories`

Purpose: Audit trail for application status changes.

Key columns: `id`, `job_application_id`, `from_application_status_id`, `to_application_status_id`, `changed_by_user_id`, `note`, timestamps.

Main relationships: belongs to `job_applications`; belongs to source/target `application_statuses`; belongs to the changing `users` record.

### `tests`

Purpose: Stores test catalog entries assignable to applications.

Key columns: `id`, `title`, `description`, `instructions`, `duration_minutes`, `max_score`, `passing_score`, `is_active`, timestamps.

Main relationships: has many `application_test_assignments`.

### `application_test_assignments`

Purpose: Links an application to a test assigned by an employer.

Key columns: `id`, `job_application_id`, `test_id`, `assigned_by_user_id`, `note`, `assigned_at`, timestamps; unique pair on `job_application_id` and `test_id`.

Main relationships: belongs to `job_applications`, `tests`, and assigning `users`; has one `test_attempt`; also exposes a has-many relation to `test_attempts`, although the table enforces one attempt per assignment.

### `test_attempts`

Purpose: Stores candidate test attempts and employer evaluation results.

Key columns: `id`, `application_test_assignment_id`, legacy nullable `answers` JSON, `started_at`, `submitted_at`, `score`, `feedback`, `evaluated_by_user_id`, `evaluated_at`, timestamps. New answer writes use `test_answers` and `test_answer_options`, not this legacy JSON column.

Main relationships: belongs to `application_test_assignments`; belongs to evaluating `users`; unique `application_test_assignment_id` enforces one attempt per assignment.

### `interviews`

Purpose: Stores scheduled interviews for applications.

Key columns: `id`, `job_application_id`, `scheduled_by_user_id`, `interview_type`, `scheduled_at`, `duration_minutes`, `interview_mode`, `location`, `meeting_link`, `note`, `completion_note`, `completed_at`, `completed_by_user_id`, timestamps.

Main relationships: belongs to `job_applications`; belongs to scheduling and completing `users`; has one `interview_evaluations`.

### `interview_evaluations`

Purpose: Stores one employer evaluation for a completed interview.

Key columns: `id`, `interview_id`, `evaluated_by_user_id`, `recommendation`, `overall_comment`, `evaluated_at`, timestamps.

Main relationships: belongs to `interviews`; belongs to evaluating `users`; has many `interview_evaluation_items`.

### `interview_evaluation_items`

Purpose: Stores scored criteria for an interview evaluation.

Key columns: `id`, `interview_evaluation_id`, `criterion`, `score`, `comment`, `sort_order`, timestamps.

Main relationships: belongs to `interview_evaluations`.

## 5. Models and Relationships

### `User`

- hasOne `JobSeekerProfile`
- hasOne `EmployerProfile`
- hasMany `CVFile`
- hasMany `Notification`
- uses Sanctum tokens through `HasApiTokens`
- uses Laravel `Notifiable`; in-app MVP notifications are stored in the custom `notifications` table
- casts `role` to `UserRole`

### `JobSeekerProfile`

- belongsTo `User`
- hasMany `Experience`
- hasMany `Education`
- belongsToMany `Skill` through `job_seeker_skills`
- hasMany `JobApplication`

### `Company`

- hasMany `EmployerProfile`
- hasMany `JobPosting`

### `EmployerProfile`

- belongsTo `User`
- belongsTo `Company`

### `Experience`

- belongsTo `JobSeekerProfile`

### `Education`

- belongsTo `JobSeekerProfile`
- uses explicit table name `education`

### `Skill`

- belongsToMany `JobSeekerProfile` through `job_seeker_skills`
- belongsToMany `JobPosting` through `job_posting_skills`

### `JobSeekerSkill`

- pivot model for `job_seeker_skills`

### `CVFile`

- belongsTo `User`
- hasOne `CVParsingResult`

### `CVParsingResult`

- belongsTo `CVFile`
- casts `parsed_json` to array

### `JobPosting`

- belongsTo `Company`
- belongsToMany `Skill` through `job_posting_skills`
- hasMany `JobApplication`

### `JobPostingSkill`

- pivot model for `job_posting_skills`

### `ApplicationStatus`

- hasMany `JobApplication`
- hasMany `ApplicationStatusHistory` as `statusChangesFrom`
- hasMany `ApplicationStatusHistory` as `statusChangesTo`

### `JobApplication`

- belongsTo `JobPosting`
- belongsTo `JobSeekerProfile`
- belongsTo `ApplicationStatus`
- hasMany `ApplicationStatusHistory` as `statusHistory`
- hasMany `ApplicationTestAssignment`
- hasMany `Interview`

### `ApplicationStatusHistory`

- belongsTo `JobApplication`
- belongsTo `ApplicationStatus` as `fromStatus`
- belongsTo `ApplicationStatus` as `toStatus`
- belongsTo `User` as `changedBy`

### `Test`

- hasMany `ApplicationTestAssignment`

### `ApplicationTestAssignment`

- belongsTo `JobApplication`
- belongsTo `Test`
- belongsTo `User` as `assignedBy`
- hasOne `TestAttempt`
- hasMany `TestAttempt`, but database uniqueness currently allows only one attempt

### `TestAttempt`

- belongsTo `ApplicationTestAssignment`
- belongsTo `User` as `evaluatedBy`

### `Interview`

- belongsTo `JobApplication`
- belongsTo `User` as `scheduledBy`
- belongsTo `User` as `completedBy`
- hasOne `InterviewEvaluation`

### `InterviewEvaluation`

- belongsTo `Interview`
- belongsTo `User` as `evaluatedBy`
- hasMany `InterviewEvaluationItem`

### `InterviewEvaluationItem`

- belongsTo `InterviewEvaluation`

## 6. Implemented API Endpoints

All responses use the `ApiResponse` envelope:

- Success: `{ "success": true, "message": "...", "data": ... }`
- Error: `{ "success": false, "message": "...", "errors": ... }`

### Authentication

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| POST | `/api/v1/auth/register/job-seeker` | Public | Public | Register a job seeker and create an empty job seeker profile | `name`, `email`, `phone`, `terms_accepted`, `password`, `password_confirmation` | `UserResource` with nested job seeker profile |
| POST | `/api/v1/auth/register/employer` | Public | Public | Register an employer, company, and employer profile | `name`, `email`, `company_name`, `company_website`, `phone`, `terms_accepted`, `password`, `password_confirmation` | `UserResource` with nested employer profile and company |
| POST | `/api/v1/auth/login` | Public | Public | Authenticate active users and issue Sanctum token | `email`, `password` | `token`, `token_type`, `user` |
| POST | `/api/v1/auth/forgot-password` | Public | Public | Create or refresh a hashed password-reset OTP with generic anti-enumeration response | `email` | Generic static-channel metadata |
| POST | `/api/v1/auth/reset-password` | Public | Public | Verify password-reset OTP, replace password, and revoke all existing tokens | `email`, `otp`, `password`, `password_confirmation` | Success or enumeration-resistant OTP error |
| GET | `/api/v1/auth/me` | Required | Any authenticated | Return current authenticated user | None | `UserResource` with loaded profile relations |
| POST | `/api/v1/auth/change-password` | Required | Any authenticated | Change password after verifying current password | `current_password`, `password`, `password_confirmation` | Success or current password error |
| POST | `/api/v1/auth/logout` | Required | Any authenticated | Revoke current access token | None | Success message |
| POST | `/api/v1/auth/logout-all` | Required | Any authenticated | Revoke all current user's Sanctum tokens | None | Success message |

Auth hardening notes:

- Registration now requires `terms_accepted` for job seekers and employers.
- Registration uses `Password::defaults()` and confirmed passwords.
- Job seeker registration can persist `phone` to `job_seeker_profiles.phone`.
- Employer registration can persist `phone` to `employer_profiles.phone` and `company_website` to `companies.website`.
- `company_size` is not accepted or persisted because no existing company/profile column supports it.
- Users have `active` and `suspended` status values. Only `active` users can login; non-active users receive HTTP 403 and no Sanctum token is created.
- Forgot password always returns the same success message and metadata for existing and non-existing email addresses.
- Password reset uses `otp`, not `token`; it revokes all existing Sanctum tokens and issues no replacement token.
- Password reset leaves email verification, role, status, profiles, and companies unchanged.
- Change password verifies the current password and revokes other tokens while keeping the current token.

### Profiles

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/profile` | Required | Job seeker | View job seeker profile | None | `JobSeekerProfileResource` with user, experiences, education, skills |
| PUT | `/api/v1/profile` | Required | Job seeker | Update job seeker profile | `headline`, `summary`, `phone`, `location`, `portfolio_url`, `linkedin_url`, `github_url` | Updated `JobSeekerProfileResource` |
| GET | `/api/v1/profile/experiences` | Required | Job seeker | List own experiences | None | Collection of `ExperienceResource` |
| POST | `/api/v1/profile/experiences` | Required | Job seeker | Create experience | `title`, `company_name`, `location`, `start_date`, `end_date`, `is_current`, `description` | Created `ExperienceResource` |
| GET | `/api/v1/profile/experiences/{experience}` | Required | Job seeker owner | View own experience | None | `ExperienceResource` |
| PUT/PATCH | `/api/v1/profile/experiences/{experience}` | Required | Job seeker owner | Update own experience | Same fields as create, optional | Updated `ExperienceResource` |
| DELETE | `/api/v1/profile/experiences/{experience}` | Required | Job seeker owner | Delete own experience | None | Success message |
| GET | `/api/v1/profile/education` | Required | Job seeker | List own education | None | Collection of `EducationResource` |
| POST | `/api/v1/profile/education` | Required | Job seeker | Create education record | `institution`, `degree`, `field_of_study`, `start_date`, `end_date`, `description` | Created `EducationResource` |
| GET | `/api/v1/profile/education/{education}` | Required | Job seeker owner | View own education record | None | `EducationResource` |
| PUT/PATCH | `/api/v1/profile/education/{education}` | Required | Job seeker owner | Update own education record | Same fields as create, optional | Updated `EducationResource` |
| DELETE | `/api/v1/profile/education/{education}` | Required | Job seeker owner | Delete own education record | None | Success message |
| POST | `/api/v1/profile/skills` | Required | Job seeker | Attach skill to profile | `skill_id` | Updated `JobSeekerProfileResource` |
| DELETE | `/api/v1/profile/skills/{skill}` | Required | Job seeker | Detach skill from profile | None | Updated `JobSeekerProfileResource` |
| GET | `/api/v1/company` | Required | Employer | View employer company | None | `CompanyResource` |
| PUT | `/api/v1/company` | Required | Employer | Update employer company | `name`, `industry`, `website`, `location`, `description` | Updated `CompanyResource` |
| GET | `/api/v1/employer/profile` | Required | Employer | View employer profile | None | `EmployerProfileResource` |
| PUT | `/api/v1/employer/profile` | Required | Employer | Update employer profile | `job_title`, `phone`, `bio` | Updated `EmployerProfileResource` |

### Skills Catalog

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/skills` | Public | Public | List/search platform skills | Optional `search`, optional `limit` from 1 to 100 | Collection of `SkillResource` ordered by name |

### CV Management

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/cv` | Required | Job seeker | List own uploaded CVs | Optional `per_page` from 1 to 100 | Paginated `CVFileResource` collection |
| POST | `/api/v1/cv/upload` | Required | Job seeker | Upload CV and dispatch parsing job | Multipart `file`; PDF/DOCX, max 5120 KB | Created `CVFileResource`, initial status `uploaded` |
| GET | `/api/v1/cv/{cvFile}` | Required | Job seeker owner | View own CV file metadata | None | `CVFileResource` with parsing result when loaded |
| GET | `/api/v1/cv/{cvFile}/parsed` | Required | Job seeker owner | View parsed CV result | None | `CVParsingResultResource` with `raw_text` and `parsed_json` |
| POST | `/api/v1/cv/{cvFile}/confirm` | Required | Job seeker owner | Confirm parsed data and append it to profile | None | Updated `JobSeekerProfileResource` |

### Job Posting

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/jobs` | Public | Public | List open jobs | Filters: `search`, `location`, `skill`, `experience_level`, `employment_type`, `salary_min`, `salary_max`, `sort_by`, `sort_direction`, optional `per_page` | Paginated collection of open `JobPostingResource` |
| GET | `/api/v1/jobs/{jobPosting}` | Public for open jobs; protected for non-open jobs | Public or owning employer | View job details | None | `JobPostingResource` |
| POST | `/api/v1/jobs` | Required | Employer | Create draft job | `title`, `description`, `employment_type`, `experience_level`, `location`, `salary_min`, `salary_max` | Created draft `JobPostingResource` |
| GET | `/api/v1/jobs/my` | Required | Employer | List own company's jobs | Filters: `search`, `location`, `skill`, `experience_level`, optional `per_page` | Paginated `JobPostingResource` collection |
| PUT | `/api/v1/jobs/{jobPosting}` | Required | Owning employer | Update own job | Same fields as create, optional | Updated `JobPostingResource` |
| DELETE | `/api/v1/jobs/{jobPosting}` | Required | Owning employer | Delete own job | None | Success message |
| POST | `/api/v1/jobs/{jobPosting}/skills` | Required | Owning employer | Attach job skills | `skill_ids[]` | Updated `JobPostingResource` |
| DELETE | `/api/v1/jobs/{jobPosting}/skills/{skill}` | Required | Owning employer | Detach job skill | None | Updated `JobPostingResource` |
| POST | `/api/v1/jobs/{jobPosting}/publish` | Required | Owning employer | Publish job as open | None; job must have at least one skill | Updated `JobPostingResource` with status `open` and `published_at` |
| POST | `/api/v1/jobs/{jobPosting}/close` | Required | Owning employer | Close job | None | Updated `JobPostingResource` with status `closed` |

Frontend contract for public job listing:

`GET /api/v1/jobs?search=backend&location=Damascus&skill=laravel&experience_level=junior&employment_type=full-time&salary_min=500&salary_max=1500&sort_by=published_at&sort_direction=desc&per_page=15`

Supported public query parameters:

| Query parameter | Validation | Behavior |
| --- | --- | --- |
| `search` | Optional string, max 255 | Searches job title and description. |
| `location` | Optional string, max 255 | Partial match against job location. |
| `skill` | Optional string, max 255 | Matches skill id when numeric, otherwise skill slug. |
| `experience_level` | Optional string, max 255 | Exact match against job experience level. |
| `employment_type` | Optional string, max 255 | Exact match against the existing job `employment_type` value. Current creation validation stores this as a string, for example `full-time` or `contract`. |
| `salary_min` | Optional numeric, minimum 0 | Returns jobs with no `salary_max` or with `salary_max >= salary_min`. |
| `salary_max` | Optional numeric, minimum 0; must be greater than or equal to `salary_min` when both are sent | Returns jobs with no `salary_min` or with `salary_min <= salary_max`. |
| `sort_by` | Optional; one of `published_at`, `created_at`, `salary_min`, `salary_max`, `title` | Sort field. Defaults to `published_at`. |
| `sort_direction` | Optional; `asc` or `desc` | Sort direction. Defaults to `desc`. |
| `per_page` | Optional integer, 1 to 100 | Pagination size. Defaults to 15. |

Public listing always returns only jobs with `status = open`; draft and closed jobs are not exposed. The optional `work_mode` filter accepts only `remote`, `on_site`, or `hybrid`, composes with all existing filters, and is also available on the authenticated employer listing.

### Applications

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| POST | `/api/v1/jobs/{jobPosting}/applications` | Required | Job seeker | Apply to an open job using clearer nested route | None | Created `JobApplicationResource` with status `submitted` |
| POST | `/api/v1/applications/{jobPosting}` | Required | Job seeker | Apply to an open job | None | Created `JobApplicationResource` with status `submitted` |
| GET | `/api/v1/applications/my` | Required | Job seeker | List own applications | Optional `per_page` from 1 to 100 | Paginated `JobApplicationResource` collection |
| GET | `/api/v1/applications/{jobApplication}` | Required | Applicant or owning employer | View application | None | `JobApplicationResource` with status and history |
| POST | `/api/v1/applications/{jobApplication}/withdraw` | Required | Applicant | Withdraw own application | Optional `note` | Updated `JobApplicationResource` with status `withdrawn` |
| GET | `/api/v1/jobs/{jobPosting}/applications` | Required | Owning employer | List applications for own job | Optional `per_page` from 1 to 100 | Paginated `JobApplicationResource` collection |
| POST | `/api/v1/applications/{jobApplication}/status` | Required | Owning employer | Change application status | `status`, optional `note` | Updated `JobApplicationResource` and appended history |

### Tests

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/tests` | Required | Employer, admin, job seeker | List test catalog entries; job seekers only see active tests | Optional `per_page` from 1 to 100 | Paginated `TestResource` collection |
| POST | `/api/v1/tests` | Required | Employer or admin | Create test catalog draft | `title`, `duration_minutes`, optional `description`, `instructions`, `passing_score`, `is_active`; `max_score` is system-managed | Created `TestResource` |
| GET | `/api/v1/tests/{test}` | Required | Employer, admin | View an authorized test catalog entry; candidates use attempt-scoped content APIs | None | `TestResource` |
| PUT/PATCH | `/api/v1/tests/{test}` | Required | Employer or admin | Update test catalog entry | Same fields as create, optional | Updated `TestResource` |
| DELETE | `/api/v1/tests/{test}` | Required | Employer or admin | Delete test catalog entry | None | Success message |
| POST | `/api/v1/applications/{jobApplication}/assign-test` | Required | Owning employer | Assign active test to application | `test_id`, optional `note` | `ApplicationTestAssignmentResource`; application moves to `test_pending` |
| GET | `/api/v1/applications/{jobApplication}/tests` | Required | Owning employer | List test assignments for application | None | Collection of `ApplicationTestAssignmentResource` |
| GET | `/api/v1/my/tests` | Required | Job seeker | List own assigned tests | Optional `per_page` from 1 to 100 | Paginated `ApplicationTestAssignmentResource` collection with state |
| POST | `/api/v1/tests/{applicationTestAssignment}/start` | Required | Assigned job seeker | Start test attempt | None | Created `TestAttemptResource` |
| POST | `/api/v1/tests/{applicationTestAssignment}/submit` | Required | Assigned job seeker | Submit the current normalized attempt | `confirm: true`; transitional structured `answers` array is deprecated | Updated `TestAttemptResource` with normalized answers and `submitted_at` |
| POST | `/api/v1/tests/{testAttempt}/evaluate` | Required | Owning employer | Evaluate submitted test attempt | `score`, optional `feedback` | Updated `TestAttemptResource`; application moves to `test_completed` |

### Interviews

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| POST | `/api/v1/applications/{jobApplication}/interviews` | Required | Owning employer | Schedule interview | `interview_type`, `scheduled_at`, `duration_minutes`, `interview_mode`, `location`, `meeting_link`, `note` | Created `InterviewResource`; application moves to `interview_scheduled` |
| GET | `/api/v1/applications/{jobApplication}/interviews` | Required | Owning employer | List interviews for an application | None | Collection of `InterviewResource` |
| PUT | `/api/v1/interviews/{interview}` | Required | Owning employer | Update scheduled, unfinished interview | Same fields as create | Updated `InterviewResource` |
| DELETE | `/api/v1/interviews/{interview}` | Required | Owning employer | Delete unfinished, unevaluated interview | None | Success message; status recalculated |
| POST | `/api/v1/interviews/{interview}/complete` | Required | Owning employer | Mark interview completed | Optional `completion_note` | `InterviewResource`; application moves to `interview_completed` |
| POST | `/api/v1/interviews/{interview}/evaluate` | Required | Owning employer | Evaluate completed interview | `recommendation`, `overall_comment`, `items[].criterion`, `items[].score`, `items[].comment` | `InterviewResource` with evaluation; application moves to `final_review` |
| GET | `/api/v1/my/interviews` | Required | Job seeker | List own interviews | Optional `per_page` from 1 to 100 | Paginated `InterviewResource` collection |
| GET | `/api/v1/interviews/{interview}` | Required | Applicant or owning employer | View interview | None | `InterviewResource` |

### Matching

| Method | URL | Auth | Role | Purpose | Main request fields | Main response summary |
| --- | --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/jobs/recommended` | Required | Job seeker | Recommend open jobs not already applied to | Optional `limit` from 1 to 50 | Collection of job resources with `score`, `breakdown`, `matched_skills` |
| GET | `/api/v1/jobs/{jobPosting}/candidates/ranked` | Required | Owning employer | Rank candidates who applied to a job | Optional `limit` from 1 to 50 | Collection with `job_application_id`, `application_status`, `score`, `breakdown`, `matched_skills`, `job_seeker_profile` |

## 7. Services Layer

### `App\Services\Auth\AuthService`

Purpose: Handles login, authenticated user loading, and logout.

Main methods: `login`, `loadAuthenticatedUser`, `logout`.

Important logic: validates credentials using `Hash::check`, creates Sanctum token named `api-token`, eager-loads relevant profile relations, deletes the current access token on logout.

### `App\Services\Auth\RegistrationService`

Purpose: Handles role-specific registration.

Main methods: `registerJobSeeker`, `registerEmployer`.

Important logic: creates user and empty job seeker profile in a transaction; creates user, company, and employer profile in a transaction; assigns `UserRole` values.

### `App\Services\ProfileService`

Purpose: Encapsulates job seeker and employer profile operations.

Main methods: `getJobSeekerProfile`, `updateJobSeekerProfile`, `getExperiences`, `createExperience`, `updateExperience`, `deleteExperience`, `getEducation`, `createEducation`, `updateEducation`, `deleteEducation`, `attachSkill`, `detachSkill`, `getCompany`, `updateCompany`, `getEmployerProfile`, `updateEmployerProfile`.

Important logic: enforces ownership for experiences and education, manages idempotent skill attachment with `syncWithoutDetaching`, and scopes employer company/profile access to the authenticated employer profile.

### `App\Services\CVService`

Purpose: Handles CV upload, retrieval, parsed-result retrieval, and confirmation.

Main methods: `upload`, `list`, `get`, `getParsedResult`, `confirm`.

Important logic: stores uploaded files under `cv-files/{user_id}` on the local disk, dispatches `ParseCVFileJob`, paginates CV file lists, enforces CV ownership, prevents repeat confirmation, appends parsed experiences/education, and attaches already-known skills by slug without creating new skills.

### `App\Services\CVParsingService`

Purpose: Extracts text from uploaded CVs and parses basic structured data.

Main methods: `extractText`, `parseText`.

Important logic: supports PDF and DOCX extraction, normalizes text, extracts email and phone with regular expressions, detects skills by matching existing `skills` records, and parses simple experience/education section lines.

### `App\Services\JobPostingService`

Purpose: Handles public job listing and employer job management.

Main methods: `getPublicJobs`, `getEmployerJobs`, `getVisibleJobPosting`, `createJob`, `updateJob`, `deleteJob`, `publishJob`, `closeJob`, `attachSkills`, `detachSkills`.

Important logic: filters jobs by search, location, skill, and experience level; paginates public and employer job lists; creates jobs as `draft`; requires at least one skill before publishing; sets `published_at` when published; closes jobs by setting status to `closed`.

### `App\Services\ApplicationWorkflowService`

Purpose: Handles job application creation and status workflow.

Main methods: `applyToJob`, `changeStatus`, `withdrawApplication`, `validateTransition`, `recordHistory`, `checkDuplicateApplication`, `getMyApplications`, `getJobApplications`, `getApplication`.

Important logic: restricts applications to job seekers, only allows applying to open jobs, prevents duplicate applications, paginates main application lists, validates transitions against a static map, blocks changes from terminal states, blocks employers from forcing `withdrawn`, and writes an `ApplicationStatusHistory` row for initial submission and every status change.

### `App\Services\TestService`

Purpose: Handles test catalog management, test assignment, test attempts, submissions, and evaluations.

Main methods: `getCatalogTests`, `createCatalogTest`, `getCatalogTest`, `updateCatalogTest`, `deleteCatalogTest`, `assignTest`, `getApplicationAssignments`, `getMyAssignments`, `startAttempt`, `submitAttempt`, `evaluateAttempt`.

Important logic: employers and admins manage global test catalog rows; job seekers can list/view only active catalog tests; only active tests can be assigned, duplicate assignments are blocked, assignment moves application to `test_pending`, each assignment can have only one attempt, attempts must be started before submitted, submitted attempts can be evaluated once, score cannot exceed test maximum, and evaluation moves application to `test_completed`.

### `App\Services\InterviewService`

Purpose: Handles interview scheduling, listing, update/delete, completion, evaluation, and application status synchronization.

Main methods: `createInterview`, `getApplicationInterviews`, `getMyInterviews`, `getInterview`, `updateInterview`, `deleteInterview`, `completeInterview`, `evaluateInterview`.

Important logic: prevents interviews for terminal applications, allows only one unfinished interview per application, paginates job seeker interview lists, moves applications to `interview_scheduled`, blocks modification/deletion of completed or evaluated interviews, completion moves applications to `interview_completed`, evaluation creates scored items and moves applications to `final_review`, and deletion recalculates interview-related status.

### `App\Services\MatchingService`

Purpose: Provides deterministic profile/job matching and ranking.

Main methods: `buildTextFromProfile`, `buildTextFromJob`, `computeTFIDF`, `cosineSimilarity`, `recommendJobsForUser`, `rankCandidatesForJob`.

Important logic: builds sectioned text for `skills`, `experience`, `core`, and `education`; computes TF-IDF vectors; calculates cosine similarity; applies section weights; returns rounded scores, breakdowns, matched skills, deterministic tie ordering, job recommendations, and candidate rankings.

## 8. Business Rules Implemented

- Duplicate job application prevention is implemented by both a database unique constraint and `ApplicationWorkflowService::checkDuplicateApplication`.
- Applications are only allowed for jobs with status `open`.
- Application status transitions are validated against an explicit transition map.
- Terminal statuses are `accepted`, `rejected`, and `withdrawn`; once reached, status changes are blocked.
- Employers can change application statuses only for applications belonging to jobs owned by their company.
- Employers cannot move an application to `withdrawn`; withdrawal is reserved for the job seeker applicant.
- Job seekers can withdraw only their own applications.
- Job publish rules require at least one attached skill before publishing.
- Jobs are created as `draft`; publishing sets `status = open` and `published_at = now()`.
- CV parsing does not overwrite profile headline, summary, phone, or links automatically.
- CV confirmation appends parsed experience and education records and attaches matching existing skills.
- CV confirmation is one-time per uploaded file.
- Test assignment requires an active test and prevents duplicate assignment of the same test to the same application.
- Test attempts are limited to one per assignment.
- Tests must be started before submission and submitted before evaluation.
- Test attempts cannot be re-submitted or re-evaluated.
- A completed attempt score cannot exceed its snapshotted `test_attempts.max_score`, which must match the canonical question-points total at submission.
- Interview creation is blocked for terminal applications.
- Only one unfinished interview is allowed per application.
- Completed or evaluated interviews cannot be updated or deleted.
- Interview evaluation requires completion first and can be performed once.
- Matching is explainable and deterministic: it returns total score, section breakdown, matched skills, and stable tie ordering.

## 9. Application Workflow

### Status List

Seeded statuses:

| Slug | Name |
| --- | --- |
| `submitted` | Submitted |
| `under_review` | Under Review |
| `shortlisted` | Shortlisted |
| `test_pending` | Test Pending |
| `test_completed` | Test Completed |
| `interview_pending` | Interview Pending |
| `interview_scheduled` | Interview Scheduled |
| `interview_completed` | Interview Completed |
| `final_review` | Final Review |
| `accepted` | Accepted |
| `rejected` | Rejected |
| `withdrawn` | Withdrawn |
| `on_hold` | On Hold |
| `need_more_information` | Need More Information |

### Transition Map

| Current status | Allowed target statuses |
| --- | --- |
| `submitted` | `under_review`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `under_review` | `shortlisted`, `test_pending`, `interview_pending`, `interview_scheduled`, `final_review`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `shortlisted` | `test_pending`, `interview_pending`, `interview_scheduled`, `final_review`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `test_pending` | `test_completed`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `test_completed` | `interview_pending`, `interview_scheduled`, `final_review`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `interview_pending` | `interview_scheduled`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `interview_scheduled` | `interview_pending`, `interview_completed`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `interview_completed` | `interview_scheduled`, `final_review`, `accepted`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `final_review` | `accepted`, `rejected`, `on_hold`, `need_more_information`, `withdrawn` |
| `need_more_information` | `under_review`, `shortlisted`, `test_pending`, `interview_pending`, `interview_scheduled`, `final_review`, `rejected`, `on_hold`, `withdrawn` |
| `on_hold` | `under_review`, `shortlisted`, `test_pending`, `interview_pending`, `interview_scheduled`, `final_review`, `rejected`, `withdrawn` |
| `accepted` | No transitions |
| `rejected` | No transitions |
| `withdrawn` | No transitions |

### Status History Recording

`ApplicationWorkflowService::recordHistory` creates an `application_status_histories` row with:

- `job_application_id`
- `from_application_status_id`, nullable for initial submission
- `to_application_status_id`
- `changed_by_user_id`
- optional `note`

History is recorded when an application is submitted, when an employer changes status, when a job seeker withdraws, and when test/interview services trigger workflow status changes.

### Test and Interview Status Updates

- Assigning a test moves the application to `test_pending`.
- Evaluating a submitted test attempt moves the application to `test_completed`.
- Scheduling an interview moves the application to `interview_scheduled`.
- Completing an interview moves the application to `interview_completed`.
- Evaluating an interview moves the application to `final_review`.
- Deleting an unfinished interview recalculates the application status to `interview_scheduled`, `interview_completed`, or `interview_pending` depending on remaining interviews.

## 10. CV Upload and Parsing Flow

Supported file types:

- PDF: `application/pdf`
- DOCX: `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- The validation also permits `application/zip` for DOCX compatibility.
- Maximum upload size: 5120 KB.

Upload process:

1. Job seeker submits multipart `file` to `POST /api/v1/cv/upload`.
2. `CVService::upload` stores the file on the local disk under `cv-files/{user_id}`.
3. A `cv_files` row is created with status `uploaded`.
4. `ParseCVFileJob` is dispatched.
5. The job marks the file `processing`, extracts text, parses structured data, stores a `cv_parsing_results` row, then marks the file `parsed`.
6. If parsing fails, the file is marked `failed` and `error_message` is saved.

Text extraction:

- PDF text is extracted through `Smalot\PdfParser\Parser`.
- DOCX text is extracted through `PhpOffice\PhpWord\IOFactory` by traversing sections, text runs, containers, and table cells.

Parsed JSON structure:

```json
{
  "email": "candidate@example.com",
  "phone": "+1 555 0100",
  "skills": ["PHP", "Laravel"],
  "experience": [
    {
      "title": "Backend Developer",
      "company_name": "Example Co",
      "description": "Backend Developer at Example Co"
    }
  ],
  "education": [
    {
      "institution": "State University",
      "degree": "Bachelor of Science",
      "field_of_study": "Computer Science",
      "description": "Bachelor of Science in Computer Science, State University"
    }
  ]
}
```

Confirm flow:

- `POST /api/v1/cv/{cvFile}/confirm` requires the CV to belong to the authenticated job seeker.
- A parsing result must exist.
- The CV must not already be confirmed.
- Parsed experiences and education are appended to the profile.
- Parsed skills are slug-matched against existing `skills`; matching skills are attached with `syncWithoutDetaching`.
- The CV's `confirmed_at` timestamp is set.

The confirm flow does not automatically overwrite profile fields such as headline, summary, phone, portfolio URL, LinkedIn URL, or GitHub URL.

## 11. AI Matching Implementation

The matching implementation is deterministic information retrieval, not deep AI or LLM-based matching.

Profile text is built in sections by `MatchingService::buildTextFromProfile`:

- `core`: profile headline, summary, location
- `skills`: skill names attached to the profile
- `experience`: experience title, company name, location, description
- `education`: institution, degree, field of study, description

Job text is built in sections by `MatchingService::buildTextFromJob`:

- `core`: title, description, employment type, experience level, location
- `skills`: required skill names attached to the job
- `experience`: experience level, title, description
- `education`: currently empty

TF-IDF implementation:

- Text is lowercased.
- Non-letter/non-number characters are normalized to spaces.
- Tokens are split on whitespace.
- Term frequency is calculated per document.
- Inverse document frequency uses `log((documentCount + 1) / (documentFrequency + 1)) + 1`.

Cosine similarity:

- Vectors are compared using dot product divided by vector magnitudes.
- Empty vectors return `0.0`.

Section weights:

| Section | Weight |
| --- | --- |
| `skills` | `0.50` |
| `experience` | `0.25` |
| `core` | `0.15` |
| `education` | `0.10` |

Job recommendation endpoint:

- `GET /api/v1/jobs/recommended`
- Job seeker only.
- Requires a job seeker profile.
- Considers open jobs that the job seeker has not already applied to.
- Returns `score`, `breakdown`, and `matched_skills`.
- Sorts by score descending, then published date descending, then job ID ascending.

Candidate ranking endpoint:

- `GET /api/v1/jobs/{jobPosting}/candidates/ranked`
- Owning employer only.
- Considers candidates who have applied to the selected job.
- Returns `job_application_id`, application status, profile, `score`, `breakdown`, and `matched_skills`.
- Sorts by score descending, then application ID ascending.

Score format:

- Scores are floating point values rounded to six decimal places.
- Breakdown includes `skills`, `experience`, `core`, and `education`.

Explainability fields:

- `breakdown`: section-level similarity scores.
- `matched_skills`: exact case-insensitive skill-name overlap.

## 12. Authorization and Policies

Role-based access:

- `job_seeker`: profile management, CV management, applications, own tests, own interviews, recommendations.
- `employer`: company/employer profile management, job posting management, application review, status changes, tests, interviews, ranked candidates.
- `admin`: enum and seeded admin user exist; admins can manage test catalog entries, but no broader admin API module is implemented.

Policies registered in `AppServiceProvider`:

- `JobPostingPolicy`
- `JobApplicationPolicy`
- `ApplicationTestAssignmentPolicy`
- `InterviewPolicy`
- `TestAttemptPolicy`

Ownership checks:

- Employers can manage only jobs belonging to their company.
- Employers can view applications, assign tests, evaluate attempts, and manage interviews only for applications tied to their company jobs.
- Job seekers can view/withdraw only their own applications.
- Job seekers can start/submit only their own assigned tests.
- Job seekers can list/view only their own interviews.
- CV files are scoped manually in `CVService` by `user_id`.
- Experience and education ownership is enforced manually in `ProfileService`.

Public vs protected endpoints:

- Public: registration, login, skill listing/search, open job listing, open job detail.
- Protected: all profile, CV, employer job mutation, application, test catalog management, test assignment/attempt, interview, matching, logout, and current-user endpoints.
- Non-open job detail is visible only to the owning employer.

## 13. Validation

Main Form Request classes:

- `JobSeekerRegisterRequest`: validates `name`, unique `email`, required accepted `terms_accepted`, optional `phone`, confirmed password using `Password::defaults()`.
- `EmployerRegisterRequest`: validates `name`, unique `email`, required `company_name`, required accepted `terms_accepted`, optional `company_website`, optional `phone`, confirmed password using `Password::defaults()`.
- `LoginRequest`: validates `email` and `password`.
- `ForgotPasswordRequest`: validates reset email.
- `ResetPasswordRequest`: validates email, token, and confirmed password using `Password::defaults()`.
- `ChangePasswordRequest`: validates current password and confirmed new password using `Password::defaults()`.
- `UpdateJobSeekerProfileRequest`: validates optional profile text fields and URL fields.
- `StoreExperienceRequest` / `UpdateExperienceRequest`: validates title, company, dates, current flag, description.
- `StoreEducationRequest` / `UpdateEducationRequest`: validates institution, degree, field, dates, description.
- `AttachSkillRequest`: validates existing `skill_id`.
- `UpdateCompanyRequest`: validates company details and website URL.
- `UpdateEmployerProfileRequest`: validates employer profile fields.
- `UploadCVRequest`: validates required PDF/DOCX file, MIME type, max size.
- `IndexSkillRequest`: validates skill `search` and `limit`.
- `CVIndexRequest`: validates CV list pagination.
- `IndexJobPostingRequest`: validates public job filters and pagination.
- `StoreJobPostingRequest` / `UpdateJobPostingRequest`: validates job fields and salary min/max consistency.
- `AttachJobPostingSkillsRequest`: validates `skill_ids` array and existing skill IDs.
- `RecommendedJobsRequest` / `RankedCandidatesRequest`: validates `limit` from 1 to 50.
- `MyJobApplicationIndexRequest` / `IndexJobApplicationsForJobRequest`: validate application list pagination.
- `ChangeApplicationStatusRequest`: validates target status slug exists and optional note.
- `WithdrawJobApplicationRequest`: validates optional note.
- `IndexTestCatalogRequest` / `ShowTestCatalogRequest` / `StoreTestCatalogRequest` / `UpdateTestCatalogRequest` / `DeleteTestCatalogRequest`: validate test catalog access and fields, prohibit client writes to system-managed `max_score`, and validate the nullable absolute-points `passing_score` against the canonical question total in the service transaction.
- `ListMyTestsRequest`: validates assigned test list pagination.
- `AssignTestRequest`: validates active `test_id` and optional note.
- `SubmitTestAttemptRequest`: prefers `confirm: true`; temporarily accepts a structured `answers` array that is normalized through `TestAnswerService` and never written to legacy JSON.
- `EvaluateTestAttemptRequest`: validates numeric score within the test's max score and optional feedback.
- `ListMyInterviewsRequest`: validates job seeker interview list pagination.
- `CreateInterviewRequest`: validates future `scheduled_at`, interview mode, meeting link, duration, notes.
- `UpdateInterviewRequest`: validates interview update fields.
- `CompleteInterviewRequest`: validates optional completion note.
- `EvaluateInterviewRequest`: validates recommendation in `advance`, `hold`, `reject`; requires at least one scored item with score between 1 and 5.

Authorization is also embedded in Form Requests through role helpers and policy checks.

## 14. Seeders and Test Data

Seeders:

- `DatabaseSeeder` calls `ApplicationStatusSeeder` and `SampleUserSeeder`.
- `ApplicationStatusSeeder` seeds all 14 application statuses used by the workflow.
- `SampleUserSeeder` seeds sample accounts, profiles, skills, and jobs.

Seeded users:

| Email | Role | Password |
| --- | --- | --- |
| `admin@smartrecruitment.test` | `admin` | `password` |
| `jobseeker@smartrecruitment.test` | `job_seeker` | `password` |
| `employer@smartrecruitment.test` | `employer` | `password` |

Seeded company:

- `Acme Hiring Co.`

Seeded job seeker profile data:

- Headline: Laravel Backend Developer
- Summary focused on REST APIs, Laravel, MySQL, and service-oriented architecture
- Example experience and education records
- Skills attached: PHP, Laravel, MySQL, REST APIs, Git

Seeded skills:

- PHP
- Laravel
- MySQL
- REST APIs
- JavaScript
- Vue.js
- React
- Git
- Docker
- AWS
- Communication
- Problem Solving
- Testing
- Agile
- API Design

Seeded jobs:

- `Senior Laravel Backend Engineer`, open, with backend skills
- `Frontend Product Engineer`, draft, with frontend/collaboration skills
- `Technical Recruiter`, closed, with communication/problem-solving skills

Sample applications/tests/interviews:

- Seeders do not create sample applications, test assignments, test attempts, interviews, or interview evaluations.
- Feature tests create those records during test execution.
- The `tests` table exists, but `SampleUserSeeder` does not seed test catalog rows.

## 15. Postman Collection

Postman collections are located in the `postman/` directory:

- `Smart Recruitment Platform - Mobile App.postman_collection.json`
- `Smart Recruitment Platform - Web App.postman_collection.json`
- `Smart Recruitment Platform - Environment.postman_environment.json`

Modules covered:

- Mobile App collection: job seeker auth, public jobs, public skills/reference data, job seeker profile, CV upload/parsing, profile suggestions, applications, tests, interviews, notifications.
- Web App collection: public website calls, web-role auth, employer company/profile/job/application/test/interview workflows, notifications, admin users/companies/skills/tests, and admin audit log filters.
- Environment: `base_url`, role token variables, company approval scenario token variables, auth email variables, reset token variable, and reusable resource ID variables.

Common collection variables:

- `base_url`, typically pointing to the local API version root such as `http://localhost:8000/api/v1`
- `job_seeker_token`, `employer_token`, and `admin_token`
- `pending_employer_token` and `suspended_employer_token` for company approval enforcement examples
- `reset_token`
- `job_seeker_email`, `employer_email`, and `admin_email`
- Resource IDs such as `job_id`, `application_id`, `skill_id`, `test_id`, `assignment_id`, `attempt_id`, `interview_id`, and `cv_id` depending on the phase
- The complete collection environment also includes `job_seeker_email`, `job_seeker_password`, `employer_email`, `employer_password`, `admin_email`, `admin_password`, `notification_id`, `user_id`, `company_id`, `experience_id`, and `education_id`
- Phase 9.5 adds `audit_log_id`, `approved_company_id`, and `rejected_company_id`

Suggested testing order:

1. Run migrations and seeders.
2. Use `01 Auth - Job Seeker` in the Mobile App collection for job seeker registration, login, me, forgot/reset password, change password, logout, and logout-all.
3. Use `02 Auth - Web Roles` in the Web App collection for employer registration, employer/admin login, me, forgot/reset password, change password, logout, and logout-all.
4. Continue through the remaining Mobile App or Web App folders based on the target role and workflow.

## 16. Phase 9 Notifications and Admin APIs

Notification workflows are implemented as loosely coupled events and listeners. Application submission, application status changes, test assignment, test submission, test evaluation, interview scheduling, interview rescheduling, interview cancellation, and interview evaluation dispatch events after successful database commits. Notifications are in-app only for the MVP; no email, push, digest, or external delivery is included.

Job seeker notification triggers covered:

- `application.submitted`
- `application.status_changed`
- `application.need_more_information`
- `test.assigned`
- `test.evaluated`
- `interview.scheduled`
- `interview.rescheduled`
- `interview.cancelled`
- `interview.evaluated`
- `final.accepted`
- `final.rejected`

Employer notification triggers covered:

- `application.received`
- `test.submitted`

Privacy and authorization:

- Notifications are user-facing messages; `AuditLog` remains internal/admin-facing.
- Users can list, read, mark, and delete only their own notifications.
- Job seeker application and interview payloads now enforce that boundary in API Resources: internal status notes and actors, interview management notes, actor IDs, recommendations, evaluation comments/items, and evaluator identity are absent from candidate JSON rather than returned as null.
- Employer notifications are generated only for users attached to the job posting company through employer profiles.

Notification endpoints:

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/notifications` | Required | Paginated current-user notifications, latest first; supports `is_read`, `type`, `date_from`, `date_to`, and `per_page` filters |
| GET | `/api/v1/notifications/unread-count` | Required | Current user's unread notification count |
| PATCH | `/api/v1/notifications/{id}/read` | Required | Mark an owned notification as read |
| PATCH | `/api/v1/notifications/read-all` | Required | Mark all current-user notifications as read |
| DELETE | `/api/v1/notifications/{id}` | Required | Delete an owned notification |

Postman updates:

- Mobile App collection includes `10 Notifications` with list, unread list, unread count, mark read, mark all read, and delete requests using `{{job_seeker_token}}`.
- Web App collection includes `Employer - Notifications` with list, unread count, mark read, and mark all read requests using `{{employer_token}}`.
- The shared environment includes `notification_id`, `job_seeker_token`, `employer_token`, and `admin_token`.

Admin APIs are protected by Sanctum plus the `admin` middleware and expose platform-level controls without a UI. This phase adds `users.status` with `active` and `suspended`, and `companies.approval_status` with `pending`, `approved`, and `rejected`; non-active user status now blocks login and prevents Sanctum token issuance.

Admin endpoints:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/users` | Paginated user listing |
| GET | `/api/v1/admin/users/{user}` | User details |
| PATCH | `/api/v1/admin/users/{user}/role` | Update user role |
| PATCH | `/api/v1/admin/users/{user}/status` | Update user active/suspended status |
| GET | `/api/v1/admin/companies` | Paginated company listing |
| PATCH | `/api/v1/admin/companies/{company}/approve` | Mark company approved |
| PATCH | `/api/v1/admin/companies/{company}/reject` | Mark company rejected |
| GET | `/api/v1/admin/skills` | Paginated skill listing |
| POST | `/api/v1/admin/skills` | Create skill |
| PUT | `/api/v1/admin/skills/{skill}` | Update skill |
| DELETE | `/api/v1/admin/skills/{skill}` | Delete skill |
| GET | `/api/v1/admin/tests` | Paginated test catalog listing |
| POST | `/api/v1/admin/tests` | Create test catalog entry |
| PUT | `/api/v1/admin/tests/{test}` | Update test catalog entry |
| DELETE | `/api/v1/admin/tests/{test}` | Delete test catalog entry |

## 16.5 Phase A/B Contract Verification Notes

Application submission requires `selected_cv_file_id` and accepted `consent_to_share_profile`; the selected CV is validated against the authenticated job seeker in both the request and application workflow service. `JobApplicationResource` exposes the applicant's cover letter, consent flag, screening answers, and safe selected-CV metadata to the authorized applicant or owning employer. It never exposes the CV disk, stored path, or parser error details.

The current MVP duplicate rule remains strict: a job seeker can have only one application per job posting, including after terminal statuses such as `withdrawn` or `rejected`.

Profile source tracking is non-AI source metadata only. Manual profile data is stored as `manual`; accepted CV-created records are stored as `cv_confirmed`; accepted CV merge updates only fill empty fields and are stored as `cv_merged`. Existing manual values remain the source of truth during merges.

## 17. Phase 9.5 Audit Logs and Company Approval Enforcement

Phase 9.5 adds a general `AuditLog` trail for sensitive platform actions and stricter employer workflow access based on company approval status.

Audit logging:

- `ApplicationStatusHistory` remains the source of truth for job application status transitions.
- `AuditLog` is a separate general platform audit trail for sensitive actions such as company approval, user suspension, job publishing, final decisions, test/interview actions, and CV/profile suggestion decisions.
- Audit records store actor user, action, entity type/id, before/after JSON values, metadata, IP address, user agent, and creation timestamp.
- Audit logging is best-effort. If non-critical audit writing fails, the main business flow is not intentionally broken.

New audit endpoint:

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/audit-logs` | Admin only | Paginated audit logs with filters for `action`, `actor_user_id`, `entity_type`, `entity_id`, `date_from`, and `date_to` |

Company approval enforcement:

- Employer workflow routes now use `company.approved` middleware.
- Approved companies can create/manage/publish/close jobs, manage application pipelines, assign/evaluate tests, schedule/cancel/evaluate interviews, and make final decisions.
- Pending companies may view/update their own company profile and employer profile, but are blocked from employer workflow routes.
- Rejected and suspended companies are blocked from employer workflow routes.
- Admin endpoints are not blocked by company approval status.
- Job seeker APIs and public job browsing are not affected by company approval status.

Admin company controls:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/companies?approval_status=pending` | Filter companies by approval status |
| PATCH | `/api/v1/admin/companies/{company}/approve` | Mark company approved and write `company.approved` audit log |
| PATCH | `/api/v1/admin/companies/{company}/reject` | Mark company rejected and write `company.rejected` audit log |
| PATCH | `/api/v1/admin/companies/{company}/suspend` | Mark company suspended and write `company.suspended` audit log |

Audit actions currently emitted:

- `company.approved`, `company.rejected`, `company.suspended`, `company.updated`
- `user.activated`, `user.suspended`
- `job.created`, `job.updated`, `job.published`, `job.closed`
- `application.accepted`, `application.rejected`
- `test.assigned`, `test.evaluated`
- `interview.scheduled`, `interview.cancelled`, `interview.evaluated`
- `cv.suggestions.generated`, `cv.suggestions.applied`, `cv.suggestion.accepted`, `cv.suggestion.rejected`

Postman:

- `Smart Recruitment Platform - Web App.postman_collection.json` includes Admin - Companies approval/suspension/status filter requests, Admin - Audit / Reports audit log filters, and employer examples for pending-company publish blocking and approved-company publishing.
- `Smart Recruitment Platform - Environment.postman_environment.json` includes Phase 9.5 variables for audit and company approval scenarios.
- The Mobile App collection was not modified for this backend-only employer/admin phase; Phase 9.6 later adds the Mobile App notification folder.

Limitations:

- Audit metadata is intentionally practical for the graduation project and does not attempt a full compliance-grade immutable ledger.
- Update/delete actions that are less sensitive, such as job skill attach/detach and routine profile edits, are not exhaustively audited.
- Company suspension is represented by `companies.approval_status = suspended`; there is no separate suspension reason table.

## 18. Phase 10 Admin & Reports Completion

Phase 10 completes the backend surface needed for a practical Admin Dashboard MVP. These endpoints are backend-only and return JSON data for frontend/admin dashboard screens; no frontend UI or chart rendering is implemented in this repository.

Admin user capabilities:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/users` | Paginated user listing with `search`, `role`, `status`, `created_from`, `created_to`, `sort_by`, and `sort_direction` filters |
| GET | `/api/v1/admin/users/{user}` | User detail with role/status and loaded profile summary where available |
| PATCH | `/api/v1/admin/users/{user}/activate` | Mark a user active and write `user.activated` audit log |
| PATCH | `/api/v1/admin/users/{user}/suspend` | Mark a user suspended, revoke Sanctum tokens, and write `user.suspended` audit log |
| PATCH | `/api/v1/admin/users/{user}/role` | Existing administrative role update endpoint |
| PATCH | `/api/v1/admin/users/{user}/status` | Existing generic status update endpoint retained for compatibility |

Admin company capabilities:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/companies` | Paginated company listing with `search`, `approval_status`, `industry`, `created_from`, `created_to`, `sort_by`, and `sort_direction` filters |
| GET | `/api/v1/admin/companies/{company}` | Company detail with employer user, job, and application counts where practical |
| PATCH | `/api/v1/admin/companies/{company}/approve` | Mark company approved and write `company.approved` audit log |
| PATCH | `/api/v1/admin/companies/{company}/reject` | Mark company rejected and write `company.rejected` audit log |
| PATCH | `/api/v1/admin/companies/{company}/suspend` | Mark company suspended and write `company.suspended` audit log |

Admin skills/reference data capabilities:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/skills` | Paginated skill listing with `search`, `sort_by`, and `sort_direction` filters |
| POST | `/api/v1/admin/skills` | Create a reusable skill, generate slug when omitted, reject duplicate names case-insensitively, and write `skill.created` audit log |
| PATCH/PUT | `/api/v1/admin/skills/{skill}` | Update a skill name/slug, prevent duplicate names, and write `skill.updated` audit log |
| DELETE | `/api/v1/admin/skills/{skill}` | Delete unused skills only; used skills return HTTP 409 instead of breaking profile/job relationships; successful deletes write `skill.deleted` audit log |

Admin reports:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/reports/overview` | Read-only summary counts for users, companies, jobs, applications, tests, interviews, notifications, CV files, CV parsing results, and audit logs |
| GET | `/api/v1/admin/reports/applications` | Read-only application statistics with `date_from`, `date_to`, `company_id`, and `job_id` filters |
| GET | `/api/v1/admin/reports/jobs` | Read-only job statistics with `date_from`, `date_to`, `company_id`, and `status` filters |
| GET | `/api/v1/admin/reports/cv-parsing` | Read-only CV upload/parsing and profile suggestion statistics with date filters |

Report responses are basic JSON statistics for dashboard cards/tables, not frontend charts. Queries use database aggregation and avoid loading all records into memory.

Postman updates:

- `Smart Recruitment Platform - Web App.postman_collection.json` now includes updated admin folders for users, companies, skills, and audit/reports.
- `Smart Recruitment Platform - Environment.postman_environment.json` includes reusable admin dashboard variables such as `admin_token`, `user_id`, `company_id`, `skill_id`, `job_id`, `application_id`, and `audit_log_id`.
- `Smart Recruitment Platform - Mobile App.postman_collection.json` was not modified for this backend-only admin phase.

Limitations:

- Skills do not have `status` or `category` columns in the current schema, so the admin skill API supports only real fields: `name` and `slug`.
- Used skills cannot be deactivated because there is no existing status/soft-delete column. The safe MVP behavior is HTTP 409 conflict for used skills and hard delete only for unused skills.
- Reports are aggregate counts only; there are no exports, charts, drill-down datasets, or advanced analytics dimensions.

## 19. Known Limitations

- Partially Implemented: CV parsing is MVP/basic. It uses regex and simple section-line parsing, so complex CV formats may parse poorly.
- Partially Implemented: CV parsing only supports PDF and DOCX.
- Partially Implemented: Parsed profile confirmation appends experience and education, which can create duplicates if the CV contains data already present in the profile.
- Partially Implemented: Parsed skills are only attached if they already exist in the `skills` table; unknown skills are ignored.
- Partially Implemented: Matching is deterministic IR-based matching, not deep AI, semantic embeddings, or LLM reasoning.
- Not Implemented: Matching has no configurable weights through admin settings; section weights are hardcoded.
- Partially Implemented: Admin APIs and basic admin report endpoints exist, but no admin dashboard UI, exports, charts, or advanced analytics are implemented.
- Partially Implemented: In-app notifications cover core candidate and employer workflow events, but no email, push, digest, or external delivery is implemented.
- Not Implemented: No advanced employer analytics or dashboard endpoints are implemented.
- Not Implemented: Seeders do not create sample test catalog entries, applications, test assignments, interviews, or evaluations.
- Partially Implemented: `POST /api/v1/jobs/{jobPosting}/applications` is the clearer application creation route; the older `POST /api/v1/applications/{jobPosting}` route remains temporarily for backward compatibility.
- CV parsing is queued; local development needs a queue worker or synchronous queue configuration for immediate parsing.
- Partially Implemented: File storage is local by default; no cloud storage integration is implemented.
- Not Implemented: There is no email verification enforcement despite `email_verified_at` existing on `users`.
- Partially Implemented: Main high-volume list endpoints for jobs, applications, assigned tests, interviews, and CV files are paginated; smaller profile subresource lists such as experiences, education, application test assignments, and application interviews still return full collections.
- Not Implemented: There is no rate limiting customization documented for login, upload, or matching endpoints.
- Not Implemented: No OpenAPI/Swagger documentation is present.
- Automated tests are implemented and broad for current modules, but there are no browser/end-to-end tests because this repository is backend-only.

## 20. Improvement Plan Preparation

### High Priority

- Improve CV confirmation to detect duplicates before appending experiences and education.
- Add OpenAPI documentation for all `/api/v1` endpoints.

### Medium Priority

- Improve CV parsing with more robust section detection, date extraction, and profile field suggestions.
- Add unknown-skill suggestion or creation workflow during CV confirmation.
- Add configurable matching weights and optional threshold filters.
- Add embedding-based semantic matching as an optional enhancement while keeping deterministic explanations.
- Add pagination, sorting, and status filters to application, test, and interview endpoints.
- Add email verification API flows.
- Add cloud/object storage configuration for CV files.
- Add audit metadata for job changes and test/interview updates.
- Add database seed data for tests, applications, assignments, and interviews to support demos.

### Low Priority

- Add soft deletes for jobs, profiles, CV files, and workflow records where business retention is needed.
- Add export endpoints for candidate pipelines and ranked candidate lists.
- Add richer interview score aggregation and recommendation rules.
- Add support for more CV file types if required, such as TXT or RTF.
- Add localized validation messages and API documentation examples.
- Add configurable application status workflows per company or job.
- Add analytics endpoints for employer hiring funnels and candidate conversion.

## 21. Company-Owned Test Questions and Options

Implemented on 2026-07-17 as an extension of the existing test catalog and assignment flow.

### Database and Ownership

- `tests.company_id` associates each newly created test with a company. The migration keeps this column nullable only so legacy test rows are not assigned to a guessed company; application writes require a company.
- `test_questions` stores ordered, scored, required/optional questions with the supported types `single_choice`, `multiple_choice`, `true_false`, `short_text`, `long_text`, and `file_upload`.
- `test_options` stores ordered options and their correct-answer flag.
- Employer test creation always derives the company from the authenticated employer profile. Employer-supplied `company_id` is prohibited.
- Admin creation requires an explicit valid `company_id`; administrators can manage tests across companies.
- Test catalog reads and all structure mutations are policy-scoped. Nested test/question/option identifiers are checked to prevent cross-company and cross-parent IDOR access.

### Question and Option APIs

- Added list, create, show, update, delete, and full-set reorder endpoints under `/api/v1/tests/{test}/questions`.
- Added create, update, delete, and full-set reorder endpoints under `/api/v1/tests/{test}/questions/{question}/options`.
- Question creation/update with an `options` payload is transactional.
- Reordering requires the complete current ID set and uses a collision-safe two-pass update.
- Validation enforces compatible option use, case-insensitive unique option text, unique ordering, minimum option counts, and correct-answer cardinality for each choice type.
- Correct-answer flags remain available to authorized employer/admin managers but are omitted from job seeker test catalog resources.

### Assignment Immutability

- `TestService::ensureTestIsMutable()` is the single guard used by test, question, and option mutation paths.
- Once any `application_test_assignment` references a test, updates, deletes, question/option changes, and reordering return HTTP 409. This prevents an assigned assessment from changing underneath an existing assignment.
- Assignment additionally validates that the selected test and application belong to the same company.

### Verification

- `TestQuestionModuleTest` covers company ownership, admin access, IDOR rejection, supported question types, answer validation, duplicate option validation, CRUD, complete-set reordering, assignment immutability, cross-company assignment rejection, and job seeker access/correct-answer hiding.
- The Web Postman collection now contains employer test/question/option management requests and the environment includes `question_id` and `option_id` variables.

### Deliberately Remaining Outside This Increment

- Per-question candidate answer persistence was implemented in the subsequent increment documented in section 22.
- Objective auto-grading and answer-level manual grading.
- Assignment deadlines and candidate attempt deadline enforcement.
- Versioning or assignment snapshots; the current MVP safety strategy is strict immutability after first assignment.
- Test catalog seed/demo data.

## 22. Normalized TestAnswer Persistence

Implemented on 2026-07-17 on top of the company-owned immutable test structure.

### Database and Relationships

- `test_answers` is the canonical answer store. Each row belongs to one `TestAttempt` and one `TestQuestion`, with a unique constraint on that pair.
- `test_answer_options` is the normalized many-to-many selection store, allowing multiple-choice answers without arrays or a single `selected_option_id` column.
- File answers store only private storage metadata: disk, internal path, original name, detected MIME type, and size. Scores and correct answers are not stored in answer rows.
- `TestAttempt::testAnswers()`, `TestQuestion::testAnswers()`, and the `TestAnswer`/`TestOption` many-to-many relationships expose the normalized model graph.

### Draft APIs and Validation

- Added candidate draft list, single-answer upsert, delete, atomic bulk upsert, private file upload, and authorized download endpoints under `/api/v1/test-attempts/{testAttempt}/answers`.
- `single_choice` and `true_false` require exactly one option; `multiple_choice` requires at least one. Every selected option is verified against its question.
- `short_text` and `long_text` are trimmed, reject whitespace-only values, and use 1,000/10,000-character limits. Text questions reject options and files.
- `file_upload` accepts PDF, DOC, DOCX, TXT, ZIP, PNG, JPG, and JPEG up to 10 MB. Both MIME and extension are validated.
- Bulk saving excludes files, validates every answer first, and commits all upserts in one transaction.

### Ownership, Privacy, and File Lifecycle

- Only the candidate who owns the assignment can create, replace, or delete draft answers.
- The owning employer and administrators can read normalized answers and download files but cannot mutate candidate answers.
- Full attempt/question/option ownership checks prevent cross-candidate, cross-company, and cross-test IDOR.
- Files always use the private `local` disk. Resources never expose the disk or internal path and never expose `is_correct` to candidates.
- Replacing a file stores the new file before the database update and removes it if persistence fails; the previous file is removed only after a successful update. Deleting a draft file answer removes its private file.

### Submit and Legacy Compatibility

- Final submit uses the existing endpoint and validates every required question against normalized answers before setting `submitted_at`.
- Final submit defensively revalidates stored answer shape, choice cardinality, and option-to-question ownership so malformed pivot data cannot bypass the draft API rules.
- Missing required questions return HTTP 422 with `unanswered_question_ids`.
- Successful submit, objective auto-grading, and the `test_completed` workflow transition/history record run in the same transaction. No score is stored on `JobApplication`.
- Submitted attempts reject further answer mutation and repeat submit with HTTP 409.
- The legacy nullable `test_attempts.answers` JSON column remains for database compatibility, but new writes no longer use it and API resources return only normalized answers.
- A transitional structured `answers` array on submit is normalized through `TestAnswerService`. Unstructured legacy maps are accepted only for legacy tests with no question definitions because they cannot be backfilled safely.

### Remaining Testing Module Work

- Answer-level manual grading.
- Final result completion after subjective grading and reviewer notes.
- Snapshot/versioning only if strict test immutability is relaxed later.

## 23. Objective Auto-Grading and Explainable TestAttempt Results

Implemented as a submit-time extension of normalized `TestAnswer` persistence. The grading system is advisory and never accepts or rejects an application.

### Schema and Status

- A new migration adds `objective_score`, `objective_max_score`, `manual_score`, `manual_max_score`, `total_score`, `max_score`, `percentage`, `grading_status`, `auto_graded_at`, and `manually_graded_at` to `test_attempts`.
- `TestAttemptGradingStatus` defines `pending`, `auto_graded`, `manual_grading_required`, and `fully_graded`. The subsequent manual-grading increment documented in section 24 owns the transition to `fully_graded` for mixed tests.
- `test_answer_gradings` stores one grading row per actual answer, constrained by `unique(test_answer_id)`. Automatic rows have no grader identity and contain correctness, awarded/max points, a concise explanation, and grading time.
- Optional unanswered objective questions do not create fake answer or grading rows. Result breakdowns synthesize their zero-point outcome from the immutable question definition.
- The legacy `test_attempts.score`, `feedback`, `evaluated_by_user_id`, and `evaluated_at` fields remain the backward-compatible attempt-level employer evaluation. They do not overwrite automatic totals and are not interpreted as answer-level manual grading.

### Calculation Policy

- `single_choice` and `true_false` award all question points only when the one selected option matches the correct option; otherwise they award zero.
- `multiple_choice` uses order-independent exact-set matching. Missing a correct option, adding an incorrect option, or selecting only a subset awards zero; partial credit is not implemented.
- Optional objective questions always contribute their points to `objective_max_score` and receive zero if unanswered.
- Subjective question points contribute to `manual_max_score`; `manual_score`, `total_score`, and `percentage` remain null until all existing subjective answers are manually graded.
- Objective-only attempts set `total_score = objective_score`, `max_score = objective_max_score`, and `grading_status = auto_graded`. Percentage is calculated only when the objective maximum is greater than zero.
- Mixed attempts use `manual_grading_required`, expose only the objective subtotal, and do not claim a final percentage.
- `tests.passing_score` is an absolute-points threshold because catalog validation constrains it to `tests.max_score`. `is_passing_score_met` is returned only for an `auto_graded` or `fully_graded` result with a non-zero derived maximum. It is informational and never changes workflow state.

### Atomic Submit and Audit

- The existing submit endpoint validates ownership, required answers, stored answer shape, and option hierarchy before setting `submitted_at` and invoking `TestGradingService`.
- Grading rows, attempt totals, application transition/history, and submit state share the outer database transaction. A grading or workflow failure rolls all of them back.
- `TestSubmitted` remains registered with `DB::afterCommit`, so a rolled-back submit produces no submit notification.
- Repeat submit returns HTTP 409 before grading and cannot duplicate grading rows, history, or notifications.
- One safe `test_attempt.auto_graded` audit record contains totals/status/timestamp only; answer text, correct option sets, files, and private paths are excluded.

### Result API and Privacy

- `GET /api/v1/test-attempts/{testAttempt}/result` returns a candidate-safe summary to the attempt owner and a detailed question breakdown to the owning employer or an administrator.
- Candidate results omit breakdown correctness, correct options, explanations, reviewer identity, and private file information.
- Employer/admin breakdowns include question text/type, answered state, awarded/max points, selected options, correct options, automatic explanations, and subjective manual grading details. Candidate responses remain summary-only.
- Cross-candidate and cross-company access is denied through `TestAttemptPolicy`; an unsubmitted attempt returns HTTP 409.

### Remaining Work

- Any future explicit partial-credit policy.

## 24. Answer-Level Manual Grading and Final Mixed Results

Implemented on 2026-07-17 on top of normalized answers and objective auto-grading. Manual grading remains advisory and does not accept or reject an application.

### APIs and Authorization

- Added single-answer `PUT`/`PATCH`/`DELETE` grading endpoints and an atomic bulk grading endpoint under `/api/v1/test-attempts/{testAttempt}`.
- Only an administrator or an employer whose company owns the related application can grade. Candidates cannot grade, and cross-company, cross-attempt, and cross-test identifiers are rejected.
- Attempts must already be submitted. Automatic grading rows cannot be overridden or deleted through manual grading APIs.

### Manual Rules and Progress

- Manual grading applies only to `short_text`, `long_text`, and `file_upload` answers. Awarded points must be between zero and the immutable question maximum.
- Reviewer notes are optional, trimmed, limited to 5,000 characters, and exposed only to authorized employer/admin result views.
- Bulk grading validates the entire payload before writing. Duplicate questions, objective questions, foreign questions, missing answers, or invalid point values roll back the whole request.
- Employer/admin results expose manual progress counts and per-answer reviewer details. Candidate results omit notes, grader identity, correctness, explanations, and answer-level breakdowns.

### Finalization and Optional Questions

- While an existing subjective answer remains ungraded, `manual_score`, `total_score`, `percentage`, and `manually_graded_at` stay null and the attempt remains `manual_grading_required`.
- After every existing subjective answer is graded, the attempt becomes `fully_graded`; manual, objective, total, maximum, percentage, passing-threshold outcome, and completion timestamp are recalculated from grading rows and immutable question points.
- Optional unanswered subjective questions require no grading, create no fake answer/grading rows, contribute zero awarded points, and still contribute their configured points to the maximum score.
- Deleting a manual grade reopens the attempt as `manual_grading_required` and clears final totals until grading is complete again. Zero-maximum tests keep percentage and passing outcome null.

### Transactions, Audit, Notifications, and Legacy Evaluation

- Grading writes lock the attempt and affected rows inside database transactions, preventing concurrent partial totals. Bulk updates and recalculation commit or roll back together.
- Safe audit events record create/update/delete actions and the `fully_graded` transition with identifiers, status changes, and numeric before/after values. They exclude answer content, reviewer-note text, correct-option sets, and private file paths.
- No notification is sent for each grading mutation or for finalization in this increment, avoiding grading noise until a dedicated result-notification product policy is defined.
- The legacy attempt-level evaluation endpoint remains available and does not overwrite objective/manual totals or per-answer grading rows.

### Deliberately Remaining Outside This Increment

- An explicit result-ready notification policy.
- Result decision automation; no automatic application acceptance/rejection is performed.
- Partial-credit policy if later required.
- Snapshot/versioning only if strict test-definition immutability is relaxed.

## 25. Test Assignment Deadlines, Expiration, and Extensions

Implemented on 2026-07-17 without changing application decision workflow, submitted attempts, or automatic/manual grading.

### Schema, UTC, and Boundary Semantics

- `application_test_assignments.deadline_at` is a nullable indexed UTC timestamp. Null means that the assignment has no deadline.
- `application_test_assignment_deadline_changes` records every later deadline assignment/extension with the previous and new UTC values, actor, optional internal reason, and timestamps. The initial deadline is stored on the assignment and audited but does not create an extension-history row.
- API timestamps use ISO 8601 UTC. Laravel's application timezone remains UTC.
- Start, draft mutation, and submit are allowed while `current_time <= deadline_at` and rejected only when `current_time > deadline_at`.
- An unsubmitted assignment is computed as expired after its deadline. A submitted attempt remains submitted and is never reopened or functionally marked expired.

### Central Enforcement and Resources

- `TestAssignmentDeadlineService` owns deadline normalization, expiry guards, extension validation, history creation, audit metadata, and the single clock policy.
- The existing start and submit transactions lock the assignment and check the deadline before creating an attempt, setting `submitted_at`, grading, workflow history, or notifications.
- Every answer upsert, bulk update, delete, and file replacement checks expiry before mutation and rechecks inside its transaction. A file stored before a race failure is removed by the existing cleanup path.
- Assignment, attempt, and result resources expose the current deadline and safe computed availability flags. Employer/admin assignment views additionally expose extension count/latest timestamp. Candidate resources never expose internal reasons or extension actors.

### Extension APIs, Ownership, and Privacy

- `PATCH /api/v1/test-assignments/{applicationTestAssignment}/deadline` lets the owning employer or an administrator set a previously null deadline or move the current deadline later.
- `GET /api/v1/test-assignments/{applicationTestAssignment}/deadline-history` is restricted to the owning employer and administrators.
- A new deadline must be future, non-null, and later than the current value. Shortening/removal, cross-company access, candidate access, submitted attempts, and accepted/rejected/withdrawn applications are rejected.
- An expired but unsubmitted assignment can be extended and becomes usable again for start, answer mutation, and submit before the new deadline.

### Transactions, Audit, and Notification

- Extensions lock the assignment, persist its new deadline and domain history, and write safe audit metadata in one transaction. Consecutive extensions therefore preserve the actual previous value.
- Audit actions are `test_assignment.deadline_set` and `test_assignment.deadline_extended`. Audit metadata excludes answer content, results, reviewer notes, files, and the internal reason text.
- After a successful extension commit, one `test.deadline_extended` notification is sent to the candidate with only `assignment_id` and `new_deadline_at`. The existing assignment notification now includes `deadline_at` when present.
- Expiration alone does not change the application from `test_pending`, reject/withdraw the candidate, delete the assignment, or schedule reminder jobs.

### Deliberately Remaining Outside This Increment

- Scheduled deadline reminders and advanced expiration reports.
- Result-ready notification policy.
- An explicit objective partial-credit policy if later required.
- Snapshot/versioning only if strict test-definition immutability is relaxed.

## 26. Controlled Test Retake Policy and Assignment Series

Implemented on 2026-07-17 as an explicit employer/admin action. Passing score never grants a retake and no result is selected or combined automatically.

### Assignment-Per-Attempt Model and Migration

- Every attempt continues to belong to exactly one `ApplicationTestAssignment`; a retake creates a new assignment and does not reopen or reuse the prior attempt.
- The old `unique(job_application_id, test_id)` constraint is replaced by `unique(job_application_id, test_id, attempt_number)`. A unique previous-assignment link provides an additional defense against two concurrent next assignments.
- Assignments now store `series_root_assignment_id`, `previous_assignment_id`, `attempt_number`, `max_attempts`, `retake_granted_by_user_id`, and an internal `retake_reason`.
- Existing rows are safely backfilled by database defaults as attempt 1 with maximum 1 and null root/previous/retake metadata. Self-referencing foreign keys restrict deletion of assignments used by later series entries.
- The root assignment is the official policy source. Policy increases are synchronized to existing series rows for consistent resource output; the value cannot be reduced and is bounded from 1 to 5.

### Eligibility, Workflow, and Isolation

- `POST /api/v1/test-assignments/{assignment}/retake` requires the latest assignment, a submitted attempt, `test_completed`, attempts remaining, and no interview/final/terminal state.
- An expired unsubmitted assignment must use deadline extension instead. Active `test_pending` assignments cannot receive a parallel retake.
- A successful grant creates the next assignment with the same application/test, an incremented attempt number, root/previous links, a fresh optional UTC deadline, and either new instructions or the previous assignment note. Attempts, answers, files, grading, results, and deadline-change history are never copied.
- A dedicated `ApplicationWorkflowService::grantTestRetake()` transition records `test_completed → test_pending` without opening that transition to general status APIs. Submitting the new attempt uses the existing `test_pending → test_completed` path.
- Only the latest pending assignment can start. Older assignments and all submitted attempts remain immutable and readable for history.

### APIs, Series Visibility, and Privacy

- Initial assignment accepts optional `max_attempts`; `PATCH /api/v1/test-assignments/{assignment}/retake-policy` explicitly raises the root limit without creating a retake.
- `GET /api/v1/test-assignments/{assignment}/attempt-series` returns attempts used/remaining, the operationally latest assignment, and each preserved result summary. It never computes best, average, or combined scores.
- Candidates can view only their own series and candidate-safe result summaries. Internal retake reason, grant actor, reviewer notes, correct answers, and audit metadata are omitted.
- The owning employer and administrators can view internal retake metadata and use existing per-attempt result endpoints for authorized detailed breakdowns. Cross-company and cross-candidate access is rejected by policy.

### Transactions, Audit, and Notification

- Retake grants lock the application, series root, and series rows. Unique attempt-number and previous-assignment constraints defend against concurrent duplicate grants and lost updates.
- Assignment creation, the workflow history transition, and safe audit metadata commit or roll back together. The audit actions are `test_assignment.retake_policy_updated` and `test_assignment.retake_granted`; internal reason text and test content are excluded from audit metadata.
- One `test.retake_granted` notification is dispatched after commit with assignment ID, attempt number, maximum attempts, and the new deadline only. Policy changes do not notify the candidate.
- Retakes are prohibited after interview pending/scheduled/completed, final review, accepted, rejected, or withdrawn. No application score or automatic acceptance/rejection is introduced.

### Deliberately Remaining Outside This Increment

- Scheduled deadline reminders and result-ready notifications.
- Advanced test/expiration/series reporting.
- A product policy for selecting or comparing retake results.
- Objective partial credit if later required.
- Snapshot/versioning only if strict test-definition immutability is relaxed.

## 27. Candidate-Safe Application and Interview Resource Boundaries

Application and interview records continue to store their complete internal history and evaluation data. This increment changes serialization and role-specific eager loading only; it does not alter the database schema, workflow transitions, stored notes, evaluations, notifications, or interview states.

### Role Visibility

- Job seekers receive application IDs, safe job/current-status data, applicant-provided cover letter and screening answers, safe selected-CV metadata, timestamps, and a simplified status timeline. Timeline entries omit internal notes, status actor IDs, actor objects, and administrative timestamps.
- Job seeker interview responses retain the interview type/mode, scheduled time, computed end time when duration exists, public state, and the mode-appropriate meeting link or location. They omit scheduler/completer IDs, internal scheduling and completion notes, the evaluation object, recommendation, scored criteria, reviewer comments, and evaluator identity.
- Owning employers retain the existing management fields, status-history notes, interview evaluation and items. Actor relations are serialized as limited `id`, `name`, and `role` summaries rather than complete user resources.
- Administrators receive the management-shaped fields whenever an authorized admin endpoint serializes these resources. Existing application/interview policies do not add new admin access in this increment.

### Nested Safety and Query Scope

- The same conditional resources protect application timelines nested inside interviews and test-assignment responses.
- Candidate application queries omit status actors and candidate-profile management relations.
- Candidate interview queries omit scheduler/completer users, interview evaluations, evaluator users, and evaluation items.
- Candidate test-assignment/start/submit loading omits application history actors and unused attempt evaluators/gradings while retaining the data required by the existing safe resources.

### API and Compatibility Impact

The affected candidate endpoints are `GET /api/v1/applications/my`, `GET /api/v1/applications/{application}`, `GET /api/v1/my/interviews`, and `GET /api/v1/interviews/{interview}`, including nested application data returned by candidate test-assignment flows. Removing internal candidate fields is an intentional security-related breaking response change. Employer list/detail and mutation responses retain their management contract.

`ApplicationPrivacyTest` and `InterviewPrivacyTest` verify candidate ownership, company ownership, safe fields, absence of the complete private-field inventory, nested-resource safety, employer compatibility, and that candidate interview reads do not query evaluation tables. The Mobile Postman collection includes candidate-side privacy assertions; the Web collection documents the retained employer contract.

### Remaining Outside This Increment

- Test catalog secrecy and test duration enforcement.
- Job work mode, application deadline, and required/optional skill fields.
- Request-more-information messaging.
- Interview lifecycle, conditional mode validation, attendance, and status policy are implemented in section 35.

## 28. Global User and Company Recruitment-State Enforcement

This security increment centralizes account and company-state enforcement without changing schema, deleting historical recruitment data, mutating job/application statuses, or revoking employer tokens merely because a company is non-approved.

### Active Users and Token Revocation

- Every `auth:sanctum` API route now runs through `EnsureUserIsActive` after authentication. A technically valid token belonging to a suspended user receives `403 USER_SUSPENDED`, including candidate, employer, notification, profile, and admin routes.
- Login continues to reject suspended users and now exposes the same stable error code. The middleware remains an independent defense when status is changed directly in the database.
- Specialized activate/suspend endpoints and the generic user-status endpoint all use `AdminUserStatusService`. Every transition to suspended deletes all Sanctum tokens transactionally and audits the previous/new status, actor, and revoked-token count. Reactivation creates no token; a fresh login is required.
- Existing self-suspension behavior for administrators was preserved to avoid introducing a new admin-governance policy in this increment. A suspended admin is nevertheless blocked globally like every other user.

### Company Access Matrix and Route Coverage

- `CompanyApprovalStatus` defines the existing `pending`, `approved`, `rejected`, and `suspended` values. `CompanyRecruitmentAccessService` resolves companies consistently from employers, jobs, applications, assignments, attempts, and interviews.
- Active employers may use authentication, notifications, their employer/company profile reads, and current profile-update endpoints regardless of company approval. Profile edits never approve or resubmit the company automatically.
- Employer recruitment management requires an approved company across jobs, applications, test catalog/questions, assignments, grading, deadlines, retakes, results management, and interviews. Admin users bypass the company check only where their existing role/policy already authorizes the endpoint and never require an employer profile.
- State errors are `COMPANY_PENDING`, `COMPANY_REJECTED`, `COMPANY_SUSPENDED`, and `COMPANY_PROFILE_MISSING`. Candidate mutations tied to a company use `COMPANY_RECRUITMENT_UNAVAILABLE`. All use HTTP 403 and the standard API error envelope.
- Company approval transitions use `AdminCompanyStatusService` and preserve audit history. They do not delete jobs, applications, tests, attempts, answers, interviews, or notifications; change recruitment/workflow status; or revoke employer tokens.

### Public Jobs and Candidate Historical Access

- Public lists, filtered searches, details, and candidate recommendations include only open jobs whose company is approved. A non-approved company's open job is hidden without changing its database status; reapproval restores visibility automatically.
- Application creation rechecks company approval in the workflow service, preventing stale-page races and avoiding application/history/notification side effects on rejection.
- Candidate start, answer upsert/bulk/file replacement/delete, and submit operations recheck company approval inside the domain services. Suspension therefore cannot be bypassed through candidate routes.
- Candidates retain read access to existing applications, safe history, interviews, assignments, saved answers, private answer downloads, and submitted results. Existing workflow-permitted withdrawal remains available. No test attempt, answer, deadline, or interview is deleted or extended automatically.

### Verification and Breaking Behavior

`AccountStateTest` verifies login, old-token rejection, both admin suspension paths, all-token revocation, reactivation, suspended-admin denial, audit metadata, and active-user middleware coverage for every Sanctum route. `CompanyStateTest` verifies all company states, stable codes, profile access, employer-token retention, public visibility/reapproval, candidate mutation freezing, no apply side effects, admin bypass, missing profiles, and representative route coverage.

The intentional breaking behavior is that previously usable tokens for suspended accounts now receive 403, employer recruitment endpoints consistently reject non-approved companies, and open jobs belonging to non-approved companies disappear from public/recommended responses.

### Remaining Outside This Increment

- Test duration enforcement and a formal test-score invariant.
- Duplicate event-listener registration and event-driven notification idempotency are implemented in section 34.
- Application information-request workflow.
- Conditional interview validation, attendance, and explicit cancellation/status policy.

## 29. Job Posting MVP Fields and Application Deadline Enforcement

This increment completes the MVP job contract while preserving company-state guards, application workflow states, public pagination, and candidate-safe resource boundaries.

### Schema and Legacy Strategy

- `job_postings.work_mode` stores `on_site`, `remote`, or `hybrid` and is indexed. Existing rows receive the explicit safe default `on_site`; the value is not inferred from location.
- `job_postings.application_deadline` is a nullable indexed UTC timestamp. Null means no deadline.
- `job_posting_skills.requirement_type` stores `required` or `optional`, is indexed, and defaults existing rows to `required`. The existing unique job/skill constraint remains unchanged, so changing classification updates one pivot row instead of creating duplicates.
- PHP-backed enums and model casts are used; no database-specific enum was introduced, preserving SQLite/MySQL portability.

### Validation, Publishing, and Application Boundary

- New jobs require a valid work mode. `on_site` and `hybrid` require a non-empty location; `remote` permits null location. Updates validate the effective stored/input combination so location cannot be cleared while a location-dependent mode remains active.
- Deadlines supplied on create/update must be future timestamps, may be shortened to another future time, extended, or removed with null. Jobs do not close automatically when time passes.
- Publishing requires core job fields, a valid mode/location combination, at least one required skill, and a deadline that has not passed. Optional-only skill sets return `422 JOB_REQUIRED_SKILL_MISSING`; expired deadlines return `422 JOB_APPLICATION_DEADLINE_PASSED` without publication audit/state changes.
- Application creation permits `now <= application_deadline` and rejects `now > application_deadline` with `409 JOB_APPLICATION_DEADLINE_PASSED`. Rejection occurs before application, status-history, or notification creation and never mutates the open job status.

### Resources, Filters, and Skills Contract

- Job resources now expose `work_mode`, ISO-8601 `application_deadline`, `has_application_deadline`, `is_application_deadline_passed`, and `can_apply`. The latter requires open status, approved company, and an unexpired/null deadline but is not used as a substitute for service enforcement.
- Skills serialize as safe skill fields plus `requirement_type`; raw pivot IDs and timestamps are not exposed.
- Public/employer filters add `work_mode`, boolean `accepting_applications`, and `skill_requirement` when paired with the existing `skill` filter. Search, location, salary, experience, employment, sorting, pagination, and company-approval filtering remain compatible.
- Create/update accept structured `skills` items. The existing `skill_ids` attach contract remains supported and treats legacy IDs as required. Structured attach calls update the existing pivot classification idempotently.

### Matching, Authorization, and Audit

- Matching remains advisory and does not change application status or make acceptance decisions. Required matches receive full skill weight and optional matches receive half weight. Recommendation/ranking responses separate `required_skills_matched`, `required_skills_missing`, and `optional_skills_matched`; optional skills are never reported as missing required skills.
- Existing owner/cross-company policies and approved-company middleware remain in force. Public jobs still require approved companies; owners retain expired jobs in `/jobs/my`.
- Existing `job.created`, `job.updated`, `job.published`, and `job.closed` actions remain. Skill changes add `job.skills_updated`; deadline changes add `job.application_deadline_changed`. Metadata contains safe IDs, old/new field values, actor ID, and required/optional counts rather than full descriptions or candidate data.

### Breaking Contract Changes and Verification

- `work_mode` is now required for API-created jobs.
- Publishing now requires at least one skill classified as required.
- Job skill responses include `requirement_type`, and structured skill payloads are preferred while `skill_ids` remains transitional-compatible.
- Job and application deadline tests use frozen UTC time to verify equality/past boundaries, visibility, extension, removal, and absence of partial side effects. Work-mode, skill-classification, filters, matching explanations, company-state, application, privacy, and notification regression suites remain covered.

### Remaining Outside This Increment

- Test catalog secrecy, test duration enforcement, and a formal test-score invariant.
- Duplicate event-listener registration and event-driven notification idempotency are implemented in section 34.
- Application request-more-information entities/messages.
- Conditional interview validation, attendance, and explicit cancellation/status policy.

## 30. Application Request-More-Information Workflow

This increment replaces the former status-only `need_more_information` behavior with a normalized, transactional employer/candidate workflow. It does not add chat, drafts, profile/CV mutation, automated decisions, or AI.

### Schema and Domain Model

- `application_information_requests` stores one historical request round, its requester, message, optional UTC due date, `pending|responded|cancelled` status, the previous application status, and response/cancellation timestamps. Expiry is computed from a pending request whose `due_at` is earlier than the current time; it is not persisted as a fourth status.
- `application_information_request_items` stores the ordered requested checklist. Every request requires at least one item, ordering follows the submitted array, and labels are unique case-insensitively within the request.
- `application_information_responses` stores exactly one final candidate response per request. `application_information_response_attachments` stores private attachment metadata without exposing disk or storage path.
- Applications have many request rounds and expose only a safe latest-request summary in application resources. A transaction plus an application row lock enforces one pending request at a time across SQLite/MySQL-compatible deployments.

### API and Workflow

- Employer management: `POST|GET /api/v1/applications/{jobApplication}/information-requests`, `GET|PATCH /api/v1/information-requests/{informationRequest}`, and `POST /api/v1/information-requests/{informationRequest}/cancel`.
- Candidate submission: `POST /api/v1/information-requests/{informationRequest}/respond`, accepting a trimmed optional message and up to five optional multipart files, with at least one of those forms of content required.
- Authorized parties download private files through `GET /api/v1/information-response-attachments/{attachment}/download`; no public URL is generated.
- Creation atomically locks the application, creates the request/items, records the previous state, transitions to `need_more_information`, appends one status history entry, and records a safe audit event. A successful candidate response atomically creates the response/attachments, marks the request responded, and moves the application to `under_review`. Cancellation preserves the request/items and restores the recorded previous status, falling back to `under_review` only if that status no longer exists.
- The general status endpoint now rejects direct entry into `need_more_information` with `INFORMATION_REQUEST_ENDPOINT_REQUIRED` and rejects direct exits from that state with `INFORMATION_RESPONSE_REQUIRED`. Candidate withdrawal closes a pending request as cancelled in the same workflow transaction so no open request is orphaned.

### Validation, Deadlines, Authorization, and Privacy

- Request messages and item labels are trimmed and cannot be whitespace-only. Descriptions are limited to 2,000 characters; item labels to 255; duplicate labels are rejected case-insensitively. Due dates are nullable, must be future values when supplied, and may be extended or removed while pending.
- Candidate responses are allowed at exact equality with `due_at` and rejected only when `now > due_at` with `409 APPLICATION_INFORMATION_REQUEST_EXPIRED`. Expiry does not reject the candidate, change application state, or close the request; employers may extend or cancel it.
- Existing application/company ownership is rechecked inside the service, not just middleware/policies. Candidate A, cross-company employers, suspended users, and public callers are denied. Non-approved company state blocks create/update/cancel/respond/upload with `APPLICATION_INFORMATION_REQUEST_COMPANY_UNAVAILABLE`, while historical reads remain available under existing read policies. Existing conventions do not grant admins application-management access, so no new admin override was introduced.
- Candidate resources omit requester/canceller IDs and summaries, employer email, internal notes, audit metadata, disks, and paths. Employer actor data is limited to `id` and `name`. Resource viewer resolution uses the current bearer token, preventing stale guard state from shaping a candidate response as an employer response.

### Files, Events, Audit, and Concurrency

- Attachments use the private `local` disk, UUID storage names, sanitized original basenames, and allow PDF, DOC, DOCX, TXT, ZIP, PNG, JPG/JPEG up to 10 MB each and five files total. Failed storage/database work deletes all newly stored files; download verifies physical existence and returns `X-Content-Type-Options: nosniff`.
- Dedicated after-commit events cover request creation, material update, response, and cancellation. Candidate notifications contain safe request IDs, due dates, summaries/counts; the requester receives safe response/attachment counts. No-op PATCH requests do not notify. Section 34 documents the single discovery-based registration source and database idempotency ledger now applied to these listeners.
- Audit actions are `application.information_request_created`, `application.information_request_updated`, `application.information_request_cancelled`, and `application.information_response_submitted`. Metadata contains IDs, statuses, due date, and counts—never full messages/descriptions, candidate response text, filenames, contents, or paths.
- Application then request row locks serialize create/create, update/respond, and cancel/respond races. Database uniqueness guarantees one response; domain conflicts are returned as stable 409 responses rather than raw SQL errors.

### Postman and Verification Coverage

The Web collection adds employer request creation, listing, viewing, update/deadline management, cancellation, attachment download, duplicate-open, and cross-company examples. The Mobile collection adds candidate view, message/file/mixed response, responded view, download, and expired-deadline examples. Shared environment variables cover request, response, attachment, and due-date IDs; all three JSON files parse successfully.

`ApplicationInformationRequestTest` covers atomic creation, ordered items, history, audit, notification, duplicate-open protection, direct-status bypass, IDOR-safe views, response workflow, due boundaries/extensions, no-op updates, cancellation/restoration, multiple rounds, company state, and validation. `ApplicationInformationResponseTest` covers empty response, MIME/count rejection, orphan-free failures, private downloads, cross-candidate/company denial, and suspended-company historical reads.

### Remaining Outside This Increment

- Test catalog secrecy, test duration enforcement, and a formal test-score invariant.
- Duplicate event-listener registration and event-driven notification idempotency are implemented in section 34.
- Conditional interview-mode validation, attendance, and explicit interview cancellation/status redesign.
- Primary-CV selection and any general application internal-notes feature.

## 31. Secure Test Catalog and Attempt-Scoped Candidate Content

This increment closes the previously documented test-catalog secrecy gap without changing test definitions, assignment deadlines, retakes, grading, scoring, workflow transitions, or database schema.

### Access Matrix and Candidate Contract

- `GET /api/v1/tests` and `GET /api/v1/tests/{test}` are management catalog APIs for employers and administrators only. A job seeker receives `403 TEST_CATALOG_FORBIDDEN`, including after assignment or attempt start.
- Employers retain full catalog/question/option management for tests owned by their company. Administrators retain the existing global catalog behavior. Existing cross-company policy checks remain authoritative.
- `GET /api/v1/my/tests` now uses candidate-only resources. Each invitation exposes assignment/application/test IDs, attempt policy and deadline flags, a safe test summary (`id`, title, description, instructions, duration, and `question_count`), and minimal attempt state. It does not embed the application, questions, options, maximum/passing scores, answer keys, grading details, internal assignment actors, or retake reasons.
- The candidate contract is intentionally breaking for clients that previously fetched `/tests/{test_id}`. Mobile clients must start the assignment, retain the returned `test_attempt_id`, then fetch `/test-attempts/{testAttempt}/questions`.

### Attempt Questions, Privacy, and Lifecycle

- `GET /api/v1/test-attempts/{testAttempt}/questions` is available only to the job seeker who owns the attempt. It requires an actually started attempt, rejects another candidate/employer and an active superseded retake, and preserves safe historical reads for a submitted attempt.
- Questions are ordered and expose only ID, text, type, order, required flag, and safe ordered options. Options expose only ID, text, and order. The query explicitly selects this allowlist, so `points`, `is_correct`, timestamps, test ownership columns beyond the relation key, passing score, correct-option summaries, reviewer notes, and grading details are never loaded into this candidate path.
- The endpoint reads the immutable assigned test definition; it does not snapshot or copy questions into an attempt. Answer save, submit, result, deadline, retake, and company-state rules continue through their existing services and policies.

### Query Shape, Postman, and Verification Coverage

- Candidate invitations use `withCount('questions')`, constrained eager loading, and one grouped attempt-series lookup. Resources do not issue queries during serialization and options are not loaded for invitation summaries.
- Attempt questions use one constrained eager load for all options rather than per-question/per-option lookups. Employer catalog pagination and full management resources are unchanged.
- Mobile Postman now uses the attempt-scoped questions endpoint, checks for the absence of `is_correct`, `correct_options`, `passing_score`, `reviewer_note`, and `points`, and includes a candidate-catalog-forbidden example. Web Postman retains company catalog/structure examples and adds cross-company structure denial.
- Feature coverage verifies guest/candidate catalog denial, employer company scope, admin compatibility, summary-only invitations, query-level secret exclusion, pre-start/ownership/IDOR behavior, safe post-submit reads, and a complete start → fetch IDs → answer → submit → result flow.

### Remaining Outside This Increment

- Test attempt duration enforcement is implemented in section 32 below.
- The formal test-score invariant is implemented in section 33 below.
- Duplicate event-listener registration and event-driven notification idempotency are implemented in section 34.
- Interview lifecycle, conditional mode validation, attendance, and status policy are implemented in section 35.
- Primary-CV selection and any general application internal-notes feature.

## 33. Canonical Test Score Invariant

`SUM(test_questions.points)` is now the canonical maximum score. `tests.max_score` remains a stored derived value for display and querying, but Create/Update reject client writes with `422 TEST_MAX_SCORE_IS_SYSTEM_MANAGED`. Tests begin as drafts with `max_score=0`; question create/update/delete locks the Test, mutates atomically, synchronizes the derived maximum using fixed two-decimal minor units, and rolls back with `TEST_PASSING_SCORE_EXCEEDS_MAX_SCORE` if the existing absolute-points threshold would become invalid.

`passing_score` is nullable and always means absolute points. It may be changed only before the first assignment, must be between zero and the canonical maximum, and exposes a calculated display-only `passing_score_percentage`. A zero-score or questionless draft cannot be assigned. Assignment repairs stored max-score drift transactionally, while invalid thresholds fail without assignment, workflow history, status transition, or notification. Submit repeats the score-configuration check before `submitted_at` or grading and rolls back corrupt configurations with `TEST_SCORE_CONFIGURATION_INVALID`.

Automatic and manual grading calculate objective/manual maxima from every question, including optional unanswered questions, using fixed decimal minor units. Their sum must equal both `test_attempts.max_score` and the canonical Test maximum. Passing indicators are returned only for complete, non-zero results and compare `total_score >= passing_score`; they never drive application status, acceptance, rejection, or retake behavior.

The data migration normalizes every stored Test maximum from its questions, clears an invalid legacy passing threshold instead of guessing a replacement, and leaves all historical attempts, percentages, and grading rows unchanged. Employer/admin resources expose the canonical maximum, absolute threshold, display percentage, question count, and configuration-valid flag. Candidate invitation secrecy remains unchanged.

Question point changes and passing-threshold changes write safe audit metadata without question text, correct options, or candidate data. Web Postman documents the intentional breaking max-score contract and the draft → questions → passing-score flow; Mobile result examples continue to expose result totals and the passing indicator without revealing the threshold in invitations.

## 32. Effective Test Attempt Deadline

Test attempts now snapshot `effective_deadline_at` when Start succeeds. `TestAttemptTimingService` calculates `started_at + tests.duration_minutes` and chooses the earlier of that value and the optional assignment deadline. Equality is permitted; answer mutations and Submit fail only when `now > effective_deadline_at`, returning `409 TEST_ATTEMPT_TIME_EXPIRED`. There is no pause, scheduler, reminder, automatic submission, automatic retake, or application-status transition caused by time expiry.

`test_attempts.effective_deadline_at` is nullable for legacy compatibility. An unsubmitted legacy attempt with a start time calculates and persists its snapshot on its next mutation; submitted historical attempts remain readable. A missing start time or invalid legacy duration fails closed with `TEST_ATTEMPT_START_TIME_MISSING` or `INVALID_TEST_DURATION`. New catalog durations are integers from 1 through 1440 minutes, and assigned tests retain the existing strict definition immutability.

Start locks the assignment, creates one attempt, records the snapshot and a safe `test_attempt.started` audit event. Repeated Start returns the same attempt without changing its timestamps or creating a second audit event. Answer saves, bulk saves, deletes, file uploads/replacements, and Submit recheck timing inside their transactions. A file stored immediately before the transactional recheck is deleted if the deadline has passed, preventing orphan files.

Assignment deadline extension locks the assignment and recalculates an active attempt to `min(original duration deadline, new assignment deadline)`. It can therefore restore time cut short by the old assignment deadline, but never extend the attempt beyond its original duration or reset `started_at`; extending after duration expiry does not reopen the attempt. Retakes create independent attempts with fresh start and deadline snapshots. Company suspension does not pause time.

Attempt and assignment resources expose `effective_deadline_at`, non-negative `remaining_seconds`, `is_time_expired`, and availability flags. Submitted results and safe questions/answers remain readable after expiry. Mobile Postman captures the effective deadline and demonstrates the stable late-save/late-submit error contract.

Feature coverage verifies duration/assignment precedence, exact-boundary save and Submit, rejection one second later, idempotent Start, extension capped by duration, rollback/no-orphan behavior, resources, audit, and duration validation. The existing grading, workflow, authorization, deadline, retake, company-state, privacy, and notification behavior remains in place.

## 34. Single Event Registration and Idempotent Persisted Side Effects

Laravel event discovery is the sole application registration source for notification listeners. Nine manual `Event::listen` registrations were removed from `AppServiceProvider`; `event:list` now shows exactly one listener for every one of the 15 application events. A regression test reads the active dispatcher and enforces one registered listener per critical event, while also preventing manual registration from returning to the provider.

The `event_side_effect_executions` ledger provides database-scoped exactly-once persisted side effects. Its deterministic `effect_key` is uniquely indexed and identifies the event namespace, aggregate, optional domain occurrence, recipient, effect type, and explicit version. The ledger stores identifiers and execution metadata only; it never stores notification payloads, candidate answers, file paths, tokens, review notes, or correct options, and it has no API route or public resource.

Every event-driven notification listener extends the shared idempotent listener base. For each recipient, `EventSideEffectService::executeOnce` atomically inserts the unique marker, creates the database notification, and records `executed_at` inside one transaction. A duplicate insert returns a normal no-op. If the callback fails, both notification and marker roll back, the exception is rethrown, and a later retry can succeed. Multi-recipient events use one key and transaction per recipient, permitting independent retries without duplicating successful recipients.

Keys use stable aggregate records. Test assignment, submission, evaluation, retake, application submission, interview schedule/cancel/evaluation, information request creation/cancellation, and information response use their immutable domain IDs. Repeated deadline extensions use the deadline-change row ID. Application status notifications use the status-history row ID. Material information-request and interview updates use their audit occurrence ID, with a deterministic state fingerprint fallback if audit recording is unavailable. A later real occurrence therefore receives a new key, while redispatching the same event cannot rewrite or replace its historical notification.

All covered domain services continue dispatching only through `DB::afterCommit`. Application state, status history, assignments, submission timestamps, grading, deadline changes, retakes, responses, interview evaluation, and required audit writes remain in their original locked domain transactions; listeners create only the existing follow-up database notifications. A rolled-back domain transaction emits neither a notification nor a ledger record. No queue, outbox endpoint, cache, new API contract, or historical-notification cleanup was added.

Covered events are `ApplicationSubmitted`, `ApplicationStatusChanged`, all four application-information events, `InterviewScheduled`, `InterviewUpdated`, `InterviewCancelled`, `InterviewEvaluated`, `TestAssigned`, `TestSubmitted`, `TestEvaluated`, `TestAssignmentDeadlineExtended`, and `TestRetakeGranted`. Tests verify single registration, repeated dispatch, one row per recipient, stable historical payloads, callback rollback, successful retry, independent recipients/occurrences, API duplicate regressions, and the existing deadline, retake, information-request, interview, grading, and application workflows.

The exactly-once guarantee is limited to persisted effects inside this database. No external email, SMS, or push provider is currently integrated; a future provider must use an outbox/delivery record and the provider's supported idempotency contract before any external exactly-once claim can be made. Postman descriptions for assignment and evaluation now note single-notification behavior; no ledger endpoint or effect key is exposed.

## 35. Complete Interview Lifecycle

The existing interview service, controller, policy, evaluation entities, application workflow, audit service, and exact-once event infrastructure now form one canonical lifecycle. Interview types are restricted to `hr`, `technical`, and `final`; statuses are `scheduled`, `confirmed`, `rescheduled`, `completed`, `cancelled`, `no_show`, and `evaluated`. Active uniqueness means one active (`scheduled`, `confirmed`, or `rescheduled`) interview per application and type. The check runs while the application row is locked and does not rely on a database-specific partial index.

Schedule and reschedule accept explicit UTC-compatible `scheduled_start_at` and `scheduled_end_at`. Start must be in the future, end must be after start, and duration cannot exceed eight hours. `online` requires a valid `meeting_link` and clears location; `on_site` requires `location_text` up to 1000 characters and clears meeting link. Rescheduling records old and new times, mode, relevant location/link, actor, and reason in `interview_schedule_changes`, resets confirmation and attendance, and emits a new occurrence. Metadata-only edits are limited to unconfirmed scheduled interviews and do not emit audit/events on a no-op.

Only the owning candidate can confirm. Employers in the owning approved company can reschedule, cancel, record attendance, mark no-show, complete, evaluate, and view paginated histories. Cancellation preserves the interview row, internal reason, safe candidate message, actor, and timestamp. Attendance uses separate candidate and interviewer states (`pending`, `present`, `absent`, or `excused`) because the current domain has one scheduling interviewer rather than a multi-interviewer panel relation. Attendance cannot be recorded before start or after the interview reaches a final state, and a no-op creates no audit or notification.

No-show is available at or after start for candidate, interviewer, or both and closes the interview without automatically accepting or rejecting the application. Completion requires `confirmed`, `now >= scheduled_start_at`, and present attendance for both parties. Evaluation requires `completed` and creates the existing evaluation/items once. HR and technical evaluations leave the application at `interview_completed`; only an evaluated final interview advances it to `final_review`.

Every status change creates exactly one ordered `interview_status_histories` row. Application changes continue through `ApplicationWorkflowService`, so application history is not duplicated. Domain mutations use transactions, application/interview row locks in a consistent order where both are needed, state revalidation, and post-commit dispatch. Interview notification events now cover schedule, confirmation, reschedule, cancellation, attendance update, no-show, completion, and evaluation. Each discovered listener is registered once and uses the persisted exact-once ledger with a status-history, schedule-change, or audit occurrence ID.

Candidate resources expose the actionable schedule, relevant online/on-site field, safe message, confirmation state, candidate attendance state, and lifecycle status. They omit internal notes, cancellation reasons, attendance notes, evaluation data, actor IDs, and both management histories entirely rather than returning those fields as null. Candidate list/detail queries do not load evaluation or history tables. Employer resources retain management details and eager-loaded evaluation/history data; standalone history endpoints are paginated and eager-load actors without resource queries.

The migration normalizes legacy type/mode/status values, derives explicit end times where legacy duration exists, preserves legacy notes as internal notes, adds lifecycle/attendance/cancellation/confirmation fields and indexes, and creates the two history tables. Web Postman includes online/on-site scheduling, reschedule, cancel, attendance, no-show, complete, evaluate, both histories, and representative errors. Mobile Postman includes candidate-safe list/detail, confirmation, rescheduled view, and cancelled view. Environment variables include interview type, mode, start, and end.

This closes the previously listed interview attendance/status-validation gap. Primary CV selection and multi-CV lifecycle management are implemented in section 36, and collaborative application internal notes are implemented in section 37. Remaining gaps include external calendar/email/SMS/push delivery. Video/audio recording, face/voice analysis, AI interview bots, automatic meeting-link creation, and frontend work remain intentionally out of scope.

## 36. Primary CV Selection and Multiple-CV Lifecycle

Job seeker profiles now hold the single canonical nullable `primary_cv_file_id` pointer. The first successful upload becomes primary while later uploads remain secondary unless `make_primary=true`; switching locks the profile and target CV and updates one pointer. Legacy rows are normalized deterministically from active non-failed CV records using confirmation, parsed status, creation time, and ID ordering. No primary operation parses a CV, applies suggestions, edits profile source tracking, or rewrites an existing application.

CV versions have independent nullable `version_label` and `archived_at` lifecycle fields. Archive preserves the file, parsing result, suggestions, confirmed profile data, and every historical application reference. Archiving a primary when another usable active version exists requires an explicit owned replacement and performs replacement plus archive atomically; archiving the only usable version clears the pointer. Restore never reparses or reapplies data and becomes primary only when the pointer is empty. Archived versions remain readable and downloadable to their owner and through an authorized historical application, but confirmation, suggestion generation/accept/reject/bulk apply, and new application use are blocked.

Applications accept the existing `selected_cv_file_id` contract and the `cv_file_id` alias. When omitted, the service resolves and locks the current primary CV. Ownership, archive state, physical file availability, and usability are rechecked before application/history/notification creation. `selected_cv_file_id` remains immutable when the primary changes. Employer application resources expose only selected-CV metadata and the application-scoped download URL; candidate CV resources no longer expose disk, stored path, parser errors, raw text, or storage identifiers.

New candidate routes cover metadata update, make-primary, archive, restore, and owner download. Lifecycle failures use stable `CV_*` codes. Upload, label changes, primary changes, archive/restore, and parsing transitions write safe audit metadata containing identifiers and state only. Profile locks and CV row locks serialize first-upload, primary switch, archive/replacement, and apply/archive races. The mobile Postman collection documents lifecycle and primary-fallback requests, the web collection documents employer application-scoped download, and the shared environment contains primary, secondary, archived, and selected-application CV IDs.

Feature coverage includes first/subsequent upload behavior, explicit switching and no-op audit idempotency, archive replacement, historical application immutability, restore behavior, primary application fallback, archived read-only behavior, private download headers, archived-list filtering, and JSON storage-metadata safety. Basic parsing remains the existing PDF/DOCX implementation; advanced parsing accuracy, external object storage, CV building, and frontend work remain outside this increment. Collaborative application internal notes are documented in section 37.

## 37. Collaborative Application Internal Notes

Applications now own a paginated internal-note stream that is accessible only to active employer users belonging to the job's company. `application_internal_notes` stores the author, plain-text body, optimistic version, edit timestamp, and soft-deletion metadata. `application_internal_note_revisions` preserves the complete previous body and version before every material update. Authors remain required historical references; deletion is logical and never removes note or revision content.

Any same-company employer may read active notes, safe deleted tombstones, and revision history, including while the company is pending, rejected, or suspended. Only the author may update or delete, and only while the company is approved, the application is non-final, the note is active, and `now <= created_at + 15 minutes`. The window is server-configured in `config/application.php`. Reapproval never resets it. Accepted, rejected, and withdrawn applications keep historical reads but reject all note mutations with `APPLICATION_INTERNAL_NOTES_READ_ONLY`.

Update and delete contracts require the current integer version. The service locks the application first and note second, rechecks company ownership/state and final status, rejects stale versions, writes one unique revision for the prior version, and increments the note version. A normalized no-op update returns the current resource without a revision, version increase, or audit entry. Soft deletion records actor and time, retains the database body, and returns a body-free tombstone.

Six employer-only endpoints cover list, create, show, update, delete, and paginated revision history. Application lists do not eager-load notes, and `JobApplicationResource` does not expose notes or summaries. Candidate application list/detail and nested application privacy assertions explicitly require the absence of internal-note fields. There is no candidate, public, notification, attachment, mention, rich-text, AI-summary, or restore endpoint.

Create, material update, and delete write `application.internal_note_created`, `application.internal_note_updated`, and `application.internal_note_deleted` audit records. Metadata contains only application/note/actor IDs, versions, body length, and deletion state; note and revision bodies are never audited. No event or notification is emitted, and note operations never modify application status, status history, matching, tests, interviews, or information-request workflows.

Feature coverage verifies body validation and trimming, ownership and candidate denial, company-state behavior, pagination/filtering/order, tombstones, author-only mutations, exact edit boundary, no-op behavior, revisions, stale versions, soft-delete history, all three final states, audit privacy, no notifications/workflow side effects, and candidate resource privacy. Web Postman documents the full management lifecycle and representative conflicts; Mobile assertions enforce absence from candidate responses. Advanced parsing accuracy, external object storage, external calendar/email/SMS/push delivery, attachments, mentions, AI summaries, and frontend work remain outside scope.

## 38. Production-Safe Private Object Storage

Durable private storage is implemented for CV files, test-answer files, and application-information response attachments. New writes use `filesystems.private_disk`, selected by `PRIVATE_FILESYSTEM_DISK` (`local` for development/testing and `s3` for production). Reads always use the disk and relative path stored on each record, preserving access to legacy local objects during a gradual migration.

`PrivateFileStorageService` centralizes UUID object keys, streaming writes/reads/downloads, size verification, checksums, idempotent deletion, cross-disk copy, safe provider exceptions, and structured failure logging. Object keys contain stable domain prefixes and no original filenames or user identifiers. API resources do not expose disk, object path, provider URL, bucket, or credentials. Authorized download endpoints retain their routes and response contract while adding `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.

Uploads are written and verified before database metadata is committed. Database failure triggers best-effort new-object compensation without hiding the original exception. Test-answer replacement commits new metadata before old-object cleanup; cleanup failure is logged as an orphan warning. Multi-attachment information responses remove all newly uploaded objects if any upload or database transaction fails.

`ParseCVFileJob` reads the record's disk through a stream, copies to a unique temporary local file only for parsers that require a filesystem path, and removes the temporary file in `finally`. Parsing retries preserve the first immutable `parsed_json` result.

Operator commands:

- `storage:verify-private`: write/read/content/delete verification with non-zero failure exit.
- `storage:inventory-private-files`: read-only totals and safe hashed details, with JSON/CSV/table output and strict mode.
- `storage:migrate-private-files`: dry-run by default, stream/checksum verification, locked row recheck, resumable deterministic UUID targets, optional post-commit source deletion, reverse source/target support, and safe reports.

Setup, cutover, rollback, restart verification, migration, and recovery procedures are documented in `docs/RENDER_OBJECT_STORAGE_SETUP.md`, `docs/PRIVATE_STORAGE_MIGRATION_RUNBOOK.md`, and `docs/PRIVATE_STORAGE_RECOVERY_RUNBOOK.md`.

Real S3 integration and Render restart durability were not executed in this implementation environment. The storage blocker is closed in code only; operational closure requires production environment configuration, existing-file inventory/preservation, successful dedicated-bucket integration tests, staging restart durability, and a restore drill. Queue worker deployment remains a separate finding.
# AI-Assisted Structured CV Parsing

The CV pipeline now separates local PDF/DOCX text extraction from structured text parsing through `App\Contracts\CV\CVTextParser`. The application container independently selects `RuleBasedCVTextParser`, `OpenAICVTextParser`, or `GroqCVTextParser` using `CV_PARSER_DRIVER`; controllers and jobs contain no driver switch.

The OpenAI driver performs a synchronous `POST /v1/responses` using Laravel's HTTP client. It sends only extracted text, sets `store=false`, and requests strict Structured Outputs named `cv_parsing_result`. Every object disallows additional properties, all strict-schema fields are required, unavailable scalar values are nullable, and confidence scores are bounded from zero to one. Response handling reads only `output[*].content[*]` items of type `output_text`, rejects refusals/missing text/invalid JSON/invalid structures, and never stores the provider response.

The Groq driver performs a synchronous `POST /openai/v1/chat/completions` using Laravel's HTTP client and extracts JSON only from `choices[0].message.content`. It shares the same strict prompt, schema definition, contract validation, and post-parse normalizer as OpenAI. Successful metadata identifies `groq`, the configured model, no fallback, and schema version `1.0`.

Extracted PDF/DOCX text is canonicalized before storage and provider use by decoding HTML entities and normalizing Unicode whitespace, dashes, quotes, and bullets. When a PDF exposes an empty email label but its object structure contains a valid `mailto:` URI, the email is recovered deterministically without OCR or inference.

`CVParsedDataNormalizer` applies deterministic safety checks after either parser: recursive trimming, grouped-skill splitting outside parentheses, case-insensitive deduplication, date-order validation, responsibility preservation, and evidence-bounded confidence. Experience requires title and company anchors plus date/evidence support; education requires an institution anchor plus degree, field, year, or evidence support. Count-only normalization diagnostics record input/output and safe drop reasons without retaining rejected values or PII.

The provider and stored `parsed_json` contract now add nullable `nationality`, nullable `marital_status`, and a `certifications` array. Every certification includes `name`, nullable `issuer`, nullable `issue_year`, nullable `expiration_year`, nullable `description`, `evidence`, and a zero-to-one `confidence_score`; unknown properties and reversed issue/expiration years are rejected. Both strict JSON Schema and Groq JSON Object fallback prompts require explicit certification extraction, keep degrees and ordinary skills out of certifications, and prohibit inferring nationality or marital status.

Normalization retains nationality and marital status only when the same value appears under an explicit source label. Certification normalization requires a source-backed name and evidence, normalizes HTML entities and Unicode, bounds confidence by matched evidence, rejects reversed years, and deduplicates by normalized name, issuer, and issue year. Certification input/output counts and the safe reasons `certification_missing_name`, `certification_invalid_evidence`, `certification_reversed_years`, and `certification_duplicate` are included in diagnostics; values and evidence are never copied into diagnostics or audit metadata.

These fields remain review-only inside `parsed_json`. No profile column, certification model/table, profile suggestion type, automatic application, or ranking response was added. `ProfileSyncService` considers only phone, summary, location, experience, education, and skills, and matching/scoring/recommendation services do not consume nationality, marital status, or birth date. Synthetic DOCX and fake-provider tests cover extraction, schema validation, normalization, storage, and confirm-flow non-persistence without real candidate data or provider calls.

OpenAI failures use safe codes (`OPENAI_UNAVAILABLE`, `OPENAI_TIMEOUT`, `OPENAI_RATE_LIMITED`, `OPENAI_INVALID_RESPONSE`, or `OPENAI_AUTHENTICATION_FAILED`). If `CV_PARSER_FALLBACK_TO_RULES=true`, the legacy parser may supply the draft only for timeout, rate-limit, availability, or invalid-response failures, and `_meta` records the requested driver, actual driver, fallback flag, schema version, and safe reason. Missing credentials and HTTP 401/403 never fall back, ensuring deployment authentication errors remain visible. If fallback is disabled or ineligible, the existing job failure lifecycle marks the CV failed. Under `QUEUE_CONNECTION=sync`, upload storage compensation is limited to pre-commit transaction failures; a parsing exception after commit preserves the CV row, stored file, and primary pointer while recording the safe failed state. Audit events remain `cv.parsing_started`, `cv.parsing_completed`, and `cv.parsing_failed`; successful audit metadata contains only safe parser/model/fallback/schema values.

Groq uses safe diagnostic codes including `GROQ_AUTHENTICATION_FAILED`, `GROQ_RATE_LIMITED`, `GROQ_TIMEOUT`, `GROQ_UNAVAILABLE`, `GROQ_BAD_REQUEST`, `GROQ_REFUSAL`, `GROQ_EMPTY_CONTENT`, `GROQ_INVALID_JSON`, and `GROQ_CONTRACT_MISMATCH`. HTTP 400 diagnostics retain only the status and sanitized provider error type/code. Rule fallback remains unavailable for authentication, bad-request, and refusal failures. Provider keys, raw CV text, request bodies, responses, and parsed personal data are not logged.

When Groq strict JSON Schema mode specifically returns HTTP 400 with `error.code=json_validate_failed`, the driver performs one provider-level retry using `response_format.type=json_object` and a compact contract prompt. A successful provider fallback remains `parser_driver=groq` and `fallback_used=false`, with `structured_output_mode=json_object_fallback`; it is distinct from the optional legacy rules fallback. No third structured-output attempt is made, and other HTTP 400 codes remain terminal `GROQ_BAD_REQUEST` failures.

Both Groq structured-output modes use a validated completion budget (default `4096`, allowed `1024..16384`), validated `low|medium|high` reasoning effort, hidden reasoning output, bounded temperature, and non-streaming responses. Invalid configuration falls back to safe defaults. A repeated `json_validate_failed` in JSON Object Mode becomes `GROQ_JSON_GENERATION_FAILED`; it reaches rules only when that code is explicitly allowlisted and rules fallback is enabled. HTTP 413 becomes `GROQ_REQUEST_TOO_LARGE` without retrying the same oversized request and may reach rules only when rules fallback is enabled.

Birth dates are distinct from experience dates in the shared prompt and schema description. Provider validation accepts a nullable birth-date string structurally; deterministic normalization accepts only `YYYY-MM-DD`, `D Month YYYY`, or `Month D, YYYY`, converts complete dates to `YYYY-MM-DD`, and maps partial or invalid dates to `null` without rejecting the remaining CV payload.

No parsed value is applied automatically. Initial imports require final draft confirmation; profile synchronization requires explicit decisions followed by the final atomic apply call. Existing phone, experience, education, and string-skill contracts remain supported, including mapping structured education years to profile date fields.

For Render Free synchronous parsing, configure `QUEUE_CONNECTION=sync` plus the selected CV provider variables documented in `.env.example`. Keep timeouts bounded and do not add a worker, background response, polling, or provider file upload for this flow. Automated tests use `Http::fake()` and require no provider credentials.

## 39. Two-Mode CV Review and Import

Baseline for this change was branch `master`, HEAD `5955d855d7683ae381cfa54c9a5f8e3eb2efe83c`, a clean worktree, and 391 passing tests (3123 assertions) with the optional real-S3 test skipped.

The review architecture now selects one immutable mode after successful parsing. `ProfileDataStateService` treats headline, summary, phone, location, portfolio/LinkedIn/GitHub URLs, experience, education, or skills as meaningful; a profile row or `primary_cv_file_id` alone is not meaningful. Empty profiles become `initial_import/draft` and receive a supported-field `reviewed_json`; non-empty profiles become `profile_sync/comparison_pending` and retain `reviewed_json=null`. A retry never overwrites the original `parsed_json` or silently reselects the review mode.

The additive migrations add nullable `review_mode` and `review_status` strings to `cv_files`, plus nullable `reviewed_json` and `reviewed_at` to `cv_parsing_results`. Legacy CV rows are classified as `profile_sync`; confirmed rows become `applied`, while unconfirmed rows become `comparison_pending`. Model constants define all modes and states instead of database enums.

Initial-import drafts contain only `profile.phone`, `profile.summary`, `profile.location`, experience, education, and string skills. Identity, birth date, languages, certifications, nationality, and marital status remain visible only in immutable parsed output. `GET /cv/{id}/review` exposes capabilities and `next_action`; `PUT /review-draft` validates and replaces the complete draft. `POST /confirm` locks the CV and profile, rechecks that the profile is still empty, validates the persisted draft, applies it atomically with `cv_confirmed` source metadata, and creates no suggestions. A changed profile produces `CV_REVIEW_MODE_STALE` with no writes.

Profile synchronization compares phone, summary, location, experience, education, and skills. ADD represents a missing value/entity, UPDATE a conflicting non-empty value, MERGE an unambiguous match with missing fields only, and IGNORE an equivalent/already-present item. Experience matching uses normalized title/company plus start period when available; education uses normalized institution/degree plus the start period or, when absent, the graduation period. Ambiguous matches are conservatively treated as additions, and absent CV items never generate deletion.

Accept/reject endpoints now persist reversible decisions only. Edited values are allowlisted per entity and client ids, owners, roles, email, and skill slugs are rejected. IGNORE is non-actionable and does not block `ready_to_apply`. `POST /cv/{id}/suggestions/apply` and the legacy bulk endpoint share the same transactional service. Final apply locks the CV, profile, suggestions, and target rows; applies accepted entries only; leaves rejected entries alone; converts ignored entries to applied no-ops; records source tracking; and marks the CV applied only after success. Repeated apply is idempotent.

Every scalar and matched entity is compared with its stored `old_value` immediately before writing. Any manual change raises `SUGGESTION_STALE`, returns only a safe suggestion id/entity type, and rolls back the entire batch. Audits contain ids, states, decisions, and counts only—never parsed/reviewed JSON, old/new/edited values, raw CV data, email, phone, or other PII.

The mobile contract and examples are documented in `docs/MOBILE_CV_REVIEW_FLOW.md`, and the mobile Postman collection contains review, draft replacement, and final-apply requests. Targeted feature coverage includes mode detection, primary-CV exclusion, immutable parsed data, editable initial drafts, atomic initial confirm, unsupported-field exclusion, source tracking, duplicate prevention, stale initial imports, all four comparison types, reversible decisions, entity-specific validation, final atomic apply, stale rollback, and idempotency. No real Groq or S3 request is used by the standard tests.

Final independent verification after the CV review audit and formatting: the focused CV review suites passed 34 tests with 378 assertions; the complete project suite passed 425 tests with 3504 assertions and skipped only the opt-in real-S3 lifecycle test. Pint passed on every modified PHP file, Postman JSON parsed successfully, route discovery showed the review routes, and `git diff --check` passed. No commit or push was performed.

## 40. Work Mode Completion Audit

### Summary and Baseline

This audit completed and verified the existing Work Mode implementation without rewriting the job-posting module. The baseline was branch `master`, HEAD `ef751f2e5e6da72b9d30c020b5a38eb2e8753a70`, and a clean working tree. Before this increment, the full suite passed 425 tests with 3504 assertions and skipped only the opt-in real-S3 integration test.

The repository already contained the Work Mode schema, enum, model cast/fillable entry, create/update effective-state validation, service persistence, resource serialization, public/employer filters, publication guard, initial feature coverage, and Postman examples. Therefore no duplicate migration, endpoint, response envelope, or field contract was introduced.

### Changed Files

| File | Change |
| --- | --- |
| `app/Services/JobPostingService.php` | Publication now validates the raw persisted `work_mode` through `JobWorkMode::tryFrom`, so malformed legacy values return the existing 422 domain error instead of failing during enum casting. |
| `app/Http/Requests/Api/V1/JobPosting/StoreJobPostingRequest.php` | Applied the repository import order to the existing Work Mode request validation. |
| `app/Http/Resources/Api/V1/JobPostingResource.php` | Kept the existing `work_mode` response contract and made the model mixin import Pint-compliant. |
| `tests/Feature/Api/V1/JobWorkModeTest.php` | Replaced four grouped tests with twenty explicit create, update, authorization, filtering, resource, and publication cases. |
| `postman/Smart Recruitment Platform - Web App.postman_collection.json` | Added combined public filtering, employer filtering, on-site-without-location validation, and job-detail response examples. |
| `postman/Smart Recruitment Platform - Mobile App.postman_collection.json` | Added a canonical Work Mode job-detail response example while preserving the existing public filter. |
| `BACKEND_IMPLEMENTATION_REPORT.md` | Removed the obsolete statement that Work Mode was unsupported and recorded this audit. |

### Database and API Contract

`database/migrations/2026_07_17_000011_add_mvp_fields_to_job_postings_table.php` already adds indexed string column `job_postings.work_mode` with safe legacy default `on_site` and drops its index/column in `down()`. The migration was already tracked and applied, so creating another migration for the same column would be unsafe and was intentionally avoided.

The canonical values remain `remote`, `on_site`, and `hybrid`, centralized in `App\Enums\JobWorkMode`. Create requires `work_mode`. Remote jobs permit a missing or null `location`; on-site and hybrid jobs require a non-empty location. Update validation uses the effective final combination of persisted and submitted values. Public and employer listings accept the optional `work_mode` filter, invalid values return 422, and public results remain limited to open jobs belonging to approved companies. Every `JobPostingResource` response exposes the enum backing string.

Publishing still requires at least one required skill. It also requires a valid persisted Work Mode and a location for on-site/hybrid jobs; remote jobs can be published without a location. The publication guard now safely rejects malformed legacy Work Mode values before the model enum cast can throw.

### Tests Added

The twenty Work Mode feature tests cover all three persisted/resource values; remote, on-site, and hybrid creation; missing/invalid mode; conditional create location validation; on-site-to-remote and remote-to-hybrid transitions using effective state; cross-company update denial; public remote/on-site filtering; invalid filter validation; composition with search, employment type, and sorting; draft/closed exclusion; employer-company filtering; on-site/hybrid publication rejection without location; valid remote publication; and invalid legacy data publication rejection.

### Verification Results

- `php artisan migrate`: passed. The Work Mode migration was already applied; Laravel applied two previously pending CV-review migrations (`2026_07_19_000001` and `2026_07_19_000002`).
- `php artisan test --filter=JobWorkModeTest`: 20 passed, 71 assertions.
- `php artisan test --filter=JobPostingTest`: 14 passed, 111 assertions.
- `php artisan test`: 441 passed, 3542 assertions, 1 optional real-S3 test skipped.
- Pint on every modified PHP file: passed.
- Repository-wide `vendor/bin/pint --test`: still fails on 55 pre-existing unrelated PHP files; zero Work Mode/task files are in the failure set. The baseline had the same repository-wide formatting debt, and unrelated formatting was not changed.
- `php artisan route:list`: passed and discovered the existing API routes without adding a new endpoint.
- Web, Mobile, and shared Environment Postman JSON: parsed successfully.
- `git diff --check`: passed.

### Remaining Gaps and Git Status

The only known verification gap is the pre-existing repository-wide Pint debt outside this task. No Work Mode-specific implementation or test gap remains. No commit or push was performed.

## 41. Core Job Contract and Application Deadline Completion

### Baseline and Existing Implementation

This increment started on branch `master` at HEAD `b569320a9fbe3d087a1ad41ace32aa5fdd0600e9` with a clean working tree, 441 passing tests, 3542 assertions, one opt-in real-S3 test skipped, and no pending migrations.

The repository already had the nullable indexed `job_postings.application_deadline` column, datetime model cast, future create/update validation, publication and application deadline guards, exact-boundary behavior (`now <= application_deadline`), `JOB_APPLICATION_DEADLINE_PASSED`, deadline audit metadata, public/employer `accepting_applications` validation and filtering, resource deadline flags, initial deadline tests, and Postman examples. Those pieces were retained instead of adding a duplicate migration, endpoint, or error envelope.

### Schema and Legacy Compatibility

Migration `2026_07_20_000001_add_job_contract_fields_to_job_postings_table.php` adds nullable `department` string plus nullable `responsibilities`, `requirements`, and `benefits` long-text columns. Nullable database columns preserve reads for legacy rows and do not invent or backfill values. `down()` drops only these four columns. No text indexes were added; the existing application-deadline index remains unchanged.

`requirements` is required, trimmed by the standard request middleware, and limited to 20000 characters for every new API-created job. Legacy rows may remain null, but publication rejects them with 422 `JOB_REQUIREMENTS_MISSING` without backfilling or changing status. `department` is nullable with a 255-character maximum; `responsibilities` and `benefits` are nullable strings with 20000-character limits. All four fields are mass assignable and exposed by `JobPostingResource`.

### Deadline and Availability Rules

Create and update accept a nullable ISO-compatible datetime and normalize valid offset input to UTC before validation and persistence. Non-null submitted deadlines must be strictly after `now`; null removes an existing deadline. An expired open job remains open and editable, and its owner may extend or remove the deadline. Expiry never mutates persisted job status.

Publication preserves the required-skill and Work Mode/location guards, additionally requires non-blank requirements, and rejects a passed deadline with 422 `JOB_APPLICATION_DEADLINE_PASSED`. Null deadlines remain publishable. Application creation allows null deadlines and the exact deadline instant, rejects only when `now > application_deadline` with 409 `JOB_APPLICATION_DEADLINE_PASSED`, and creates no application, history, notification, or audit side effect on rejection.

Application availability is checked before expensive work and rechecked on a freshly loaded job row under `lockForUpdate` immediately before duplicate checking and writes. This prevents a concurrent deadline update from allowing a stale application decision.

`is_accepting_applications` is true exactly when the stored job status is `open` and its deadline is null or not passed. Existing `can_apply` remains for backward compatibility and continues to include company approval. `has_application_deadline` and `is_application_deadline_passed` also remain additive-compatible.

### Filtering, Sorting, and API Contract

Public and employer endpoints accept `accepting_applications=true|false|1|0`. Public `true` returns only open approved-company jobs with null/current/future deadlines; public `false` returns only open approved-company jobs with passed deadlines. Draft and closed jobs never enter the public result. Employer `true` returns only open jobs currently accepting applications within the existing company scope, while employer `false` includes that company's draft, closed, and expired-open jobs.

The filter composes with search, Work Mode, existing filters, sorting, and pagination. `application_deadline` is now an allowed public sort field. A portable `CASE` expression keeps null deadlines last on both MySQL and SQLite, then sorts non-null deadlines in the requested direction.

Create/update requests and every `JobPostingResource` response now support `department`, `responsibilities`, `requirements`, `benefits`, and `application_deadline`. Responses serialize deadlines in UTC ISO-8601 and add `is_accepting_applications` without changing the existing API envelope. No new endpoint was added.

### Changed Files

| File | Change |
| --- | --- |
| `database/migrations/2026_07_20_000001_add_job_contract_fields_to_job_postings_table.php` | Adds and rolls back the four nullable legacy-compatible contract columns. |
| `app/Models/JobPosting.php` | Adds fillable fields and the status/deadline availability helper. |
| `app/Http/Requests/Api/V1/JobPosting/StoreJobPostingRequest.php` | Adds field validation and UTC deadline normalization. |
| `app/Http/Requests/Api/V1/JobPosting/UpdateJobPostingRequest.php` | Adds optional update validation while preventing requirements from being cleared. |
| `app/Http/Requests/Api/V1/JobPosting/IndexJobPostingRequest.php` | Allows safe sorting by application deadline. |
| `app/Http/Resources/Api/V1/JobPostingResource.php` | Exposes the four fields and `is_accepting_applications`. |
| `app/Services/JobPostingService.php` | Enforces publication requirements, effective availability filters, and portable deadline sorting. |
| `app/Services/ApplicationWorkflowService.php` | Centralizes availability enforcement and rechecks the locked job before writes. |
| `database/seeders/SampleUserSeeder.php` | Gives sample jobs realistic contract fields. |
| `tests/Feature/Api/V1/JobPostingContractTest.php` | Adds explicit create/update/validation/authorization/publication contract coverage. |
| `tests/Feature/Api/V1/JobDeadlineTest.php` | Expands boundary, side-effect, resource, filter, sort, pagination, and UTC coverage. |
| `tests/Feature/Api/V1/JobPostingTest.php` | Updates valid job fixtures for the required requirements contract. |
| `tests/Feature/Api/V1/JobSkillRequirementTest.php` | Preserves required-skill tests under the new job contract. |
| `tests/Feature/Api/V1/JobWorkModeTest.php` | Preserves Work Mode tests and isolates legacy invalid-mode publication. |
| `tests/Feature/Api/V1/CompanyStateTest.php` | Preserves approved-company creation coverage. |
| Web/Mobile Postman collections and shared environment | Add contract payloads, validation failures, accepting/expired IDs, filters, details, and before/after-deadline application examples. |

### Tests and Verification

Seventeen feature tests were added: eleven in `JobPostingContractTest` and six in `JobDeadlineTest`. They cover all new columns, nullable compatibility, missing/blank requirements, length limits, invalid/past deadlines, updates/removal/extension, UTC offsets, owner/cross-company/candidate authorization, legacy publication, future/null publication, exact-boundary availability, status behavior, public and employer true/false filters, invalid booleans, filter composition, pagination, and portable deadline sorting. Existing application tests continue covering duplicate prevention.

- `php artisan migrate`: passed; the new migration ran in batch 4.
- `php artisan migrate:status`: all migrations are `Ran`; none are pending.
- `php artisan test --filter=JobPosting`: 25 passed, 170 assertions.
- `php artisan test --filter=Application`: 52 passed, 529 assertions.
- `php artisan test --filter=Deadline`: 30 passed, 269 assertions.
- Full `php artisan test`: 458 passed, 3638 assertions, one opt-in real-S3 test skipped.
- Pint on every modified PHP file: passed.
- Repository-wide `vendor/bin/pint --test`: still fails on 54 pre-existing unrelated files; zero task files are in that failure set.
- `php artisan route:list`: passed; no route was added or broken.
- PHP syntax checks for all modified production, migration, seeder, and primary test files: passed.
- Web, Mobile, and Environment Postman files parse as valid JSON.
- `git diff --check`: passed.

### Remaining Gaps and Git Status

The only remaining verification gap for that increment was repository-wide formatting debt outside its scope. Candidate Matching v2 and nice-to-have skills are now documented in section 43. External notifications and unrelated refactors remain excluded. Screening questions, cover letters, and explicit consent are implemented in section 42. No commit or push was performed.

## 42. Job Screening Questions and Immutable Application Answers

### Baseline and Existing Implementation

This increment started on branch `master` at HEAD `951e17ec38d809cac07133c92c4f4afcab3a14c9` with a clean working tree, 458 passing tests, 3638 assertions, one opt-in real-S3 test skipped, and no pending migrations. The repository already had the application submission endpoint and aliases, selected/primary CV resolution, nullable `cover_letter`, explicit consent request validation, a legacy JSON `screening_answers` column, application deadline and duplicate guards, status history, after-commit submission notifications, application authorization, and candidate/employer resources.

The applied 2026-07-02 migration was not modified. Its legacy JSON column remains readable for historical records, but new submissions write normalized relational answers and leave that column null. Existing CV aliases and selected-CV immutability remain unchanged.

### Architecture and Database Schema

Migration `2026_07_20_000002_create_job_screening_question_tables.php` creates six independent tables:

- `job_screening_questions` stores the current job-owned definition, type, required flag, stable order, active state, and safe creator reference.
- `job_screening_question_options` stores ordered options for current choice questions.
- `job_application_screening_questions` stores immutable question snapshots owned by one submitted application.
- `job_application_screening_question_options` stores immutable option text and order for each snapshot question.
- `job_application_screening_answers` stores exactly one typed scalar answer per application snapshot question using mutually exclusive text, decimal, or boolean columns.
- `job_application_screening_answer_options` stores selected snapshot-option relationships for choice answers.

Foreign keys cascade only through the owning current definition or application aggregate. Snapshot source references are nullable and use `nullOnDelete`, so deleting a current question or option cannot delete historical text. Composite indexes support active ordered job reads and ordered application reads. Unique constraints prevent duplicate source snapshots, duplicate answers, and duplicate selected options. `down()` drops the six tables in dependency-safe reverse order. The migration ran successfully against the configured MySQL database in batch 5.

### Question Types and Management Rules

`App\Enums\ScreeningQuestionType` is the single source for `short_text`, `long_text`, `single_choice`, `multiple_choice`, `boolean`, and `number`. Question text is trimmed, required, and limited to 2000 characters. Choice questions require 2-50 non-empty options of at most 1000 characters. Option duplicates are rejected after whitespace normalization and case-insensitive comparison. Non-choice questions reject options, and boolean questions do not create artificial true/false rows.

The owner employer can create, update, change type, and deactivate questions while the job is not closed and the company remains approved. Changing choice to a scalar type removes only the current options; changing a scalar type to choice requires valid replacement options. Existing application snapshots are never updated. Deletion is a safe `is_active=false` operation. At most 50 active questions are allowed per job; creation locks the job row before counting and writing. Default ordering uses the current maximum plus one, and all reads use `sort_order ASC, id ASC`.

Question audit records contain only IDs, type, flags, order, state, and option counts. They never contain question or option text.

### Extended Application Contract and Typed Validation

The existing application endpoint now accepts `selected_cv_file_id` or its existing alias, nullable trimmed `cover_letter` up to 10000 characters, explicit JSON boolean `consent_to_share_profile=true`, and a list of `screening_answers`. Missing, false, null, or truthy string consent is rejected before any application write. New writes always persist consent as true.

Each submitted answer must identify one active question belonging to the target job. Duplicate questions, inactive or cross-job questions, cross-question options, duplicate option IDs, extra value fields for choice questions, and option fields for scalar questions are rejected rather than ignored. Required questions must be answered; optional questions may be omitted but cannot be submitted with an invalid empty value.

- `short_text`: trimmed non-empty string, maximum 1000 characters.
- `long_text`: trimmed non-empty string, maximum 10000 characters.
- `number`: native JSON integer or decimal from -1000000000 through 1000000000; zero and negative values are valid.
- `boolean`: native JSON boolean; false is a valid required answer.
- `single_choice`: exactly one option owned by the question and no scalar value.
- `multiple_choice`: one or more distinct options owned by the question and no scalar value.

### Snapshot, Atomicity, and Side Effects

Application submission locks the job row, rechecks open status, company availability, deadline, duplicate application, profile, and selected CV, then loads the active ordered questions and options under the same transaction. It builds a fully validated answer plan before creating the application. The application, all question/option snapshots, typed answers, selected snapshot options, and submitted status history are then written in one transaction.

Any validation, snapshot, answer, status-history, CV, duplicate, company, or deadline failure rolls back the complete aggregate. A synthetic snapshot failure test confirms that no application, history, snapshot, answer, selected option, or notification remains. The existing `ApplicationSubmitted` event remains scheduled with `DB::afterCommit`; screening answers and cover letters are not added to notification payloads or audit metadata.

Submitted answers, cover letters, and consent have no update endpoint. Editing or deactivating current questions and options affects only future submissions. Candidate CV selection remains immutable through `selected_cv_file_id`, and status changes or withdrawal do not delete historical answers.

### Authorization, Privacy, Resources, and Queries

Public visitors and job seekers can read active questions for an approved open job. Only the owning approved employer can manage them; other companies, job seekers, visitors, pending companies, and closed jobs are rejected according to existing authorization and company-state contracts.

Application details expose cover letter, consent, immutable question text/type/required/order, typed value, and selected option text only to the candidate owner or owning employer. Source question/option IDs, creator IDs, audit data, and internal timestamps are omitted. Other candidates, other employers, and visitors cannot read the application. Application list endpoints remain summary-only and do not eager-load normalized answers; detail and mutation responses eager-load the entire snapshot graph without resource-side queries. Legacy JSON answers remain readable when a historical application has no normalized snapshots.

Job detail responses add safe ordered `screening_questions`. A dedicated public list endpoint exposes the same safe resource without administrative fields.

### API Endpoints and Error Contracts

- `GET /api/v1/jobs/{jobPosting}/screening-questions`
- `POST /api/v1/jobs/{jobPosting}/screening-questions`
- `PUT /api/v1/jobs/{jobPosting}/screening-questions/{question}`
- `DELETE /api/v1/jobs/{jobPosting}/screening-questions/{question}`
- Existing `POST /api/v1/jobs/{jobPosting}/applications` and `POST /api/v1/applications/{jobPosting}` aliases were extended; no replacement submission endpoint was added.

Domain failures use the existing API envelope and stable codes including `JOB_SCREENING_QUESTION_LIMIT_REACHED`, `JOB_SCREENING_QUESTION_OPTIONS_REQUIRED`, `JOB_SCREENING_QUESTION_OPTIONS_NOT_ALLOWED`, `JOB_SCREENING_QUESTION_DUPLICATE_OPTION`, `JOB_SCREENING_QUESTION_NOT_FOUND`, `JOB_SCREENING_QUESTION_FORBIDDEN`, `JOB_SCREENING_QUESTION_JOB_CLOSED`, `APPLICATION_SCREENING_REQUIRED_ANSWER_MISSING`, `APPLICATION_SCREENING_QUESTION_INVALID`, `APPLICATION_SCREENING_OPTION_INVALID`, `APPLICATION_SCREENING_DUPLICATE_ANSWER`, and `APPLICATION_SCREENING_ANSWER_TYPE_INVALID`. Structural request failures remain standard Laravel 422 field errors.

### Tests, Postman, and Verification

Thirty-five explicit feature tests were added across `JobScreeningQuestionTest`, `ApplicationScreeningAnswerTest`, and `ApplicationScreeningPrivacyTest`. They cover all six types, option rules, maximum count, ordering, safe resources, ownership and company state, type changes, closed jobs, deactivation, required/optional behavior, cover-letter limits, strict consent, scalar boundaries, choice cardinality and hierarchy, duplicate/inactive/cross-job answers, immutable question and option text, rollback, legacy reads, summary/detail loading, privacy, and PII-free audit/notification data.

Web Postman now contains a `Job Screening Questions` folder with management, type-change, denial, invalid-option, and required/optional examples. Mobile Postman contains job details, scalar and choice submissions, missing-answer, invalid-option, consent failure, and historical detail examples. The shared environment contains response-populated screening IDs and no hard-coded application-specific IDs.

- `php artisan migrate`: passed; the new migration ran in batch 5.
- `php artisan migrate:status`: every migration is `Ran`; none are pending.
- `php artisan test --filter=ScreeningQuestion`: 15 passed, 68 assertions.
- `php artisan test --filter=JobApplication`: 6 passed, 27 assertions.
- `php artisan test --filter=ApplicationPrivacy`: 4 passed, 65 assertions.
- `ApplicationScreeningAnswerTest`: 17 passed, 121 assertions.
- `ApplicationScreeningPrivacyTest`: 3 passed, 30 assertions.
- Full `php artisan test --compact`: 493 passed, 3860 assertions, one opt-in real-S3 test skipped.
- Pint on all 35 task PHP files and PHP syntax checks: passed.
- Repository-wide `vendor/bin/pint --test`: still fails on 54 pre-existing unrelated files; no task file is in that failure set.
- `php artisan route:list`: passed and reports 176 routes, including the four screening-question routes.
- Web, Mobile, and Environment Postman JSON parse successfully.

### Remaining Scope and Git Status

Candidate Matching v2 and nice-to-have skills are now implemented in section 43. LLM matching, embeddings, vector databases, CV summary, email/push notifications, a generic form builder, post-submission answer editing, reapplication, and AI-generated questions remain outside scope. No commit or push was performed.

## 43. Candidate Matching v2: Weighted Skills and 0-100 Score

### Baseline and Existing Implementation

This increment started on branch `master` at HEAD `d19a8ee128910f007acb7bedecedc7447822d09f` with a clean working tree, 493 passing tests, 3860 assertions, one opt-in real-S3 test skipped, and no pending migrations. The preceding screening-question work was already isolated in commit `d19a8ee`; no work from that phase was mixed into this increment.

The repository already contained the recommendation and ranked-candidate endpoints, TF-IDF and cosine-similarity primitives, deterministic tie ordering, ownership policies, eager-loaded matching relations, a unique `(job_posting_id, skill_id)` pivot, and a partial `requirement_type` contract with `required` and legacy `optional`. Those pieces were extended rather than duplicated. In particular, no new matching endpoint, score column, cache table, or duplicate `skill_type` column was added.

### Architecture and Database Changes

Migration `2026_07_20_000003_add_candidate_matching_fields.php` adds pivot `weight` as an unsigned tiny integer with default 1, adds the composite `(job_posting_id, requirement_type)` index, canonicalizes existing explicit `optional` rows to `nice_to_have`, and adds nullable `job_postings.education_level`. The existing unique job/skill constraint remains authoritative. Rollback restores the legacy optional spelling before removing only the new index, weight, and education field.

The existing `JobSkillRequirementType` enum is the single type source. It now exposes canonical `required` and `nice_to_have` values while accepting `optional` only as a backward-compatible input/database alias. `EducationLevel` defines the deterministic five-level education order. `JobPostingSkill` casts weight to integer, and the existing job/skills relationship exposes both type and weight without adding duplicate relations or resource-side queries.

### Job Skill API Contract

The existing create, update, and `POST /api/v1/jobs/{jobPosting}/skills` flows accept the separated contract:

```json
{
  "required_skills": [{"skill_id": 1, "weight": 5}],
  "nice_to_have_skills": [{"skill_id": 2, "weight": 2}]
}
```

Required weights are integers from 1 through 5. Nice-to-have weights use the same range and default to 1. Arrays are capped at 100, duplicate IDs are rejected within a list, and a cross-list duplicate is rejected before writes. The separated contract, current structured `skills` contract, and historical `skill_ids` contract cannot be mixed. Historical IDs remain required with weight 1; structured `optional` input is normalized to `nice_to_have`.

Writes run transactionally and reuse `sync` or `syncWithoutDetaching`, allowing weight/type changes without duplicate pivots. Drafts may have empty lists. Publication still requires at least one required skill; nice-to-have-only jobs fail with `JOB_REQUIRED_SKILL_MISSING`. Raw invalid legacy types and weights fail safely with `JOB_SKILL_TYPE_INVALID` and `JOB_SKILL_WEIGHT_INVALID`. Existing work-mode, location, requirements, deadline, company, and ownership rules remain unchanged. Audit metadata contains safe IDs and aggregate required/nice-to-have counts, not candidate or skill narrative data.

### Matching Formula and Explainability

`config/matching.php` versions the contract as `2.0` and centrally defines components totaling 100:

- Required skills: `45 × matched required weight / total required weight`.
- Nice-to-have: `10 × matched nice-to-have weight / total nice-to-have weight`; if none are configured, 10 points with `not_applicable=true`.
- Experience: 20 points at or above the configured requirement, otherwise `20 × candidate years / required years`; zero required years gives 20.
- Education: 10 at or above the requested level, 5 one level below, and 0 otherwise; no job requirement gives 10 with `not_applicable=true`.
- Text similarity: the existing TF-IDF/cosine result multiplied by 15.

Required matches use canonical skill IDs and never names. Candidate experience is calculated from the union of valid date intervals so concurrent jobs are not double-counted; current roles use the current date. The actual `entry`, `entry-level`, `junior`, `mid`, `mid-level`, and `senior` job values map centrally to years. Education normalization is case-insensitive, deterministic, selects the highest explicit known degree, and never infers a requirement from free text.

The final score is clamped to 0-100 and rounded to two decimals. Every recommendation and ranked candidate retains `score`, `breakdown`, and `matched_skills`, and adds `matching_score_version`, five detailed breakdown objects, weighted matched/missing required skills, matched nice-to-have skills, and stable reason codes/messages. Legacy string skill breakdowns and ratio fields remain additive compatibility aids. Sorting remains score descending, then the pre-existing deterministic endpoint-specific tie breaker.

### Recalculation, Authorization, and Privacy

Scores are computed on demand from eagerly loaded current job/profile relations. Changing job weights, skills, experience level, education requirement, professional text, or the candidate's skills, experience, education, or professional text therefore changes the next response without invalidation jobs or stale storage. Matching performs no writes: it does not update application status, create status history, shortlist, accept/reject, notify, or create decision audit records.

The candidate text builder uses an explicit professional allowlist: headline, summary, skill names, experience titles/descriptions, and education fields. Job text uses title, department, description, responsibilities, requirements, experience/education levels, and skill names. Names, email, phone, candidate location, birth data, nationality, screening answers, cover letters, private notes, test answers, and interview evaluations are excluded. Existing job-seeker and owning-employer authorization remains in force, including cross-company ranked-candidate denial.

### API, Seeder, Postman, and Tests

Job resources retain `skills` and add `required_skills` and `nice_to_have_skills`, each with canonical type and weight, plus nullable `education_level`. Recommendation and ranking resources expose the same Candidate Score v2 explainability contract without changing the API envelope.

`SampleUserSeeder` now includes varied required weights, optional nice-to-have skills, a partially matching candidate, overlapping-capable experience data, and an explicit education requirement. Web Postman covers weighted attachment, nice-to-have attachment, weight/type changes, invalid duplicates/weights, publication failure, job details, and ranked breakdown. Mobile Postman documents separated job skills and recommendation explainability. The shared environment adds blank response-populated matching IDs; all three JSON files parse successfully.

Dedicated tests cover weighted and unweighted required/nice-to-have math, missing lists, not-applicable behavior, experience interval union/current/invalid intervals, education normalization and scoring, TF-IDF/cosine boundaries, configuration validation, stable errors, total limits and rounding, API compatibility, authorization, deterministic ranking, publication guards, pivot updates, validation contracts, and an isolated down/up migration cycle that preserves IDs and the historical unique constraint. Final command results are recorded after the complete verification run below.

### Changed Files

| Files | Candidate Matching v2 change |
| --- | --- |
| `database/migrations/2026_07_20_000003_add_candidate_matching_fields.php` | Adds weight, type index, optional-to-nice canonicalization, education requirement, and safe rollback. |
| `app/Enums/EducationLevel.php`, `app/Enums/JobSkillRequirementType.php`, `config/matching.php` | Central education/type domains, version, component weights, and experience mapping. |
| `app/Services/CandidateExperienceCalculator.php`, `app/Services/EducationLevelNormalizer.php`, `app/Services/MatchingService.php` | Interval union, deterministic degree normalization, 0-100 scoring, explainability, privacy allowlist, and on-demand ranking. |
| `app/Models/JobPosting.php`, `app/Models/JobPostingSkill.php`, `app/Services/JobPostingService.php` | Education persistence, weighted pivot access, canonical transactional writes, filters, publication validation, and safe audit counts. |
| Job posting request concern and Store/Update/Attach/Index requests | Separated/structured/legacy contracts, validation, alias normalization, duplicate protection, and filter compatibility. |
| Job, Skill, Recommended Job, and Ranked Candidate API resources | Canonical weighted skill arrays and Candidate Score v2 fields. |
| `database/factories/JobPostingFactory.php`, `database/seeders/SampleUserSeeder.php` | Education-capable jobs and meaningful weighted required/nice-to-have samples. |
| Matching, experience, education, job-skill, job-posting, and API tests | Explicit formula, boundary, compatibility, authorization, publication, recalculation, privacy, and no-side-effect coverage. |
| Web/Mobile Postman collections and shared environment | Weighted management, invalid cases, job details, ranking/recommendations, and matching variables. |
| `BACKEND_IMPLEMENTATION_REPORT.md` | Candidate Matching v2 architecture, contracts, privacy, tests, and actual verification results. |

### Verification Results

- `php artisan migrate --force`: passed; Candidate Matching v2 migration ran in batch 6.
- `php artisan migrate:status`: every migration is `Ran`; none are pending.
- `php artisan test --filter=Matching`: 31 passed, 163 assertions.
- `php artisan test --filter=Experience`: 17 passed, 76 assertions.
- `php artisan test --filter=Education`: 18 passed, 56 assertions.
- The literal `Recommendation`, `RankedCandidate`, and `JobPostingSkill` filters match no current PHPUnit method/class names; the corresponding `MatchingTest`, `JobSkillRequirementTest`, and `JobSkillWeightTest` files were run directly and passed.
- Focused job-skill/job-posting verification: 26 passed, 194 assertions.
- Full `php artisan test --compact`: 534 passed, 3994 assertions; one opt-in real-S3 integration test skipped.
- PHP syntax checks for every modified or new PHP file: passed.
- Pint on every modified or new PHP file: passed.
- Repository-wide `php vendor/bin/pint --test`: 52 pre-existing unrelated files still require formatting; zero task files overlap that set.
- `php artisan route:list --json`: passed and reports 176 routes; no endpoint was added.
- Web, Mobile, and Environment Postman files parse as valid JSON.
- `git diff --check`: passed; no debug calls were introduced.

### Remaining Scope and Git Status

LLM matching, embeddings, vector databases, stored scores, automatic decisions, automatic shortlist, interview/test score inclusion, notifications, and unrelated refactors remain intentionally out of scope. No commit or push was performed.

## 44. Phase 13: Laravel ML Client

### Architecture and Scope

Phase 13 adds an internal typed Laravel client for the frozen Phase 12 FastAPI inference contract. `RecommendationMlClientContract` exposes only `live`, `ready`, `metadata`, and `rank`; `RecommendationMlClient` implements those operations with Laravel's existing HTTP client. The binding is registered in `AppServiceProvider`. No recommendation orchestrator, fallback, public endpoint, controller, route, model, migration, repository, queue, cache, persistence, or matching-workflow change was introduced.

The isolated `app/Data/RecommendationMl` namespace contains readonly configuration, request, professional-fact, prediction, explanation, health, metadata, and response values. Array construction rejects unknown fields, validates OpenAPI bounds and finite numbers, emits deterministic payloads, and applies the Phase 12 skill normalization policy: NFKC/case folding, deterministic hyphens and whitespace, independent maximum candidate proficiency/experience values, maximum required weight, deduplication, sorting, and required-skill precedence over nice-to-have skills.

### Configuration, Transport, and Privacy

`config/recommendation_ml.php` reads the ML base URL, service token, timeouts, request limits, and six frozen contract versions. `MlClientConfiguration` rejects missing or unsafe URLs, URL credentials/query/fragment/path, tokens shorter than 32 characters, unreasonable timeout relationships, invalid limits, and missing version identities without including configuration values in exceptions.

Every operation uses JSON request/response headers, explicit connect and total timeouts, and disabled redirects. Health operations are unauthenticated. Metadata and rank send only `X-ML-Service-Token`. There is no automatic retry, cookie jar, Laravel/Sanctum authorization header, request/response logging, or fallback.

`MlOutboundPayloadGuard` scans the final rank payload recursively and case-insensitively against the complete Phase 13 denylist. `name` is allowed only at candidate-skill and required-job-skill paths. Exceptions contain only an internal code, optional request ID and HTTP status, operation, retryability, and a validated provider error code; they never retain the token, request, response, professional facts, or rejected value.

### Response Contract and Failure Mapping

Successful HTTP status is not sufficient. Health and metadata DTOs reject unknown or missing fields and validate service, Bundle, Model, Feature Schema, score-transform, explanation, reason-code, checksum, and readiness identities. Rank validation checks the echoed request ID and limit, all six configured versions, exact prediction count, complete Job-ID reconciliation, unique IDs and ranks, ranks `1..N`, finite raw/display/contribution/latency values, 0-100 display bounds, raw-score descending then Job-ID ascending order, up to three factors per direction, the twenty reason codes, ten feature groups, exact direction, 0-1 strength, and the non-probability/non-hiring-decision explanation note. Every supplied Job prediction is retained even when the requested limit is smaller.

Connection and timeout failures map to `MlRecommendationTransportException`; 401 to authentication; 422 to validation with only a safe provider code; 429, 503, and other 5xx responses to unavailable; malformed successful responses, unexpected status, version mismatch, and reconciliation failures to contract exceptions. No fallback is applied.

### Contract Compatibility and Verification

Compatibility tests read the frozen artifacts only from their Phase 12 repository location. They verify all four published SHA-256 values, validate the request example against the actual OpenAPI schema without adding a dependency, round-trip the request through Laravel DTOs, parse the response example, and prove that runtime code has no path dependency on the contract files.

- Focused client tests: 138 passed, 438 assertions.
- Matching regression: 32 passed, 170 assertions.
- Full Laravel regression: 672 passed, 4432 assertions; one opt-in real-S3 test skipped.
- Pint on all Phase 13 PHP files: passed.
- `git diff --check`: passed.
- Static transport/security scans: passed; no retry, cookies, bearer authorization, or logging exists in the ML client.
- Real local integration: live, ready, metadata, and rank passed through the Laravel client; request ID and versions matched, all three Job IDs were returned, and the measured database query/write count was zero.
- The integration FastAPI process was stopped and port 8100 was no longer listening.

The frozen Bundle Model, Feature Schema, Bundle manifest, OpenAPI contract, and contract manifest retained their SHA-256 values. No file under the Python service, Model, or Bundle was modified by Phase 13. No commit or push was performed.

## 45. Phase 14: Recommendation Orchestrator and Fallback

### Architecture and Public Cutover

The existing `GET /api/v1/jobs/recommended` endpoint now delegates job recommendations to `RecommendationOrchestratorContract::recommend(User $user, int $limit): RecommendationResult`. The controller continues to use the same `RecommendedJobsRequest`, route, middleware, HTTP status, `Recommended jobs retrieved successfully.` message, `ApiResponse::success` envelope, and `RecommendedJobResource` boundary. The employer candidate-ranking action still calls `MatchingService::rankCandidatesForJob()` directly and remains outside ML.

`RecommendationResult` is readonly and carries items, the selected engine, requested limit, candidate and returned counts, fallback state, an internal safe fallback code, frozen version metadata, and request ID. The closed engine values are `ml_xgbranker`, `matching_v2`, and `matching_v2_fallback`. Operational fallback codes are retained in the result/logging boundary and are not exposed by the public resource.

The orchestrator follows one direction only: controller to orchestrator to either the Phase 13 client or the unchanged Matching 2.0 service. It performs no readiness, liveness, or metadata preflight, no retry, no batching, no recursion, and no partial ML acceptance. `MatchingService` and its formula, weights, rounding, recommendation output, and candidate-ranking method were not modified.

### Configuration and Lazy Client Resolution

`ML_RECOMMENDATION_ENABLED=false` is the safe default and is parsed as a boolean in `config/recommendation_ml.php`. A typed `RecommendationMlClientFactoryContract` defers both configuration validation and client construction until the orchestrator has confirmed that ML is enabled and eligible jobs exist. The disabled path therefore works with a missing token or invalid URL and performs no network request. The direct Phase 13 `RecommendationMlClientContract` binding remains available and unchanged for direct client consumers and tests.

### Laravel Eligibility and Candidate-Pool Policy

`RecommendationEligibilityProvider` is the only candidate-set authority. It captures one clock boundary and returns only jobs that are open, owned by an approved company, have no deadline or a deadline at/after that boundary, and have no prior application by the current profile in any status. It validates an active Job Seeker and an existing profile, deliberately does not require `published_at`, and eagerly loads profile skills/experience/education and job company/skills. It performs no writes and has no per-job query loop.

Zero eligible jobs return an empty compatible result without ML or Matching work. A pool from one through the configured maximum produces one rank attempt containing every eligible job. A pool above the maximum is not truncated or split; it moves directly to full-list Matching 2.0 fallback with `ML_CANDIDATE_LIMIT_EXCEEDED`.

### Request Mapping, Privacy, and Reconciliation

`RecommendationMlRequestMapper` constructs only the Phase 13 DTOs. Candidate facts are limited to domain placeholders, headline, calculated non-overlapping experience years, normalized highest education, normalized/deduplicated skills, and empty preference lists where no local source exists. Job facts come from the actual title, department, description, responsibility lines, weighted required and nice-to-have skills, configured experience level, education level, work mode, and employment type. Missing facts become contract-safe nulls or empty lists. `profile_ref` is null.

The mapper does not send name, email, phone, profile/user IDs, raw CV data, experience employer/description data, education institution/description data, applications, screening answers, tests, interviews, internal notes, auth/session data, feature vectors, labels, or unnecessary timestamps. It issues zero queries once eligibility relations are loaded. Unsafe or unrepresentable local values produce a typed mapping failure and Matching fallback.

Each ML attempt receives a new UUID, the frozen Feature Schema, every eligible ID, and the contract-safe minimum of requested limit, configured maximum results, and eligible count. After the one `rank()` call, the orchestrator independently checks request/version identities, exact prediction count, missing/extra/duplicate IDs, local job existence in one bulk query, membership in the original eligibility snapshot, finite raw/display scores, and display bounds. Any failure rejects the complete response.

### Final Ranking, Resource, and Fallback

Laravel performs the final deterministic ML sort by raw score descending, then `published_at` descending with nulls last, then job ID ascending. It applies the public limit only after reconciliation and sorting, assigns complete ranks `1..N`, and removes the internal raw score.

The public ML score is the model display score in the 0-100 range and is explicitly described as a ranking score, not a probability. Existing resource fields remain present. ML items add rank, engine, model, Feature Schema, explanation-contract, and fallback metadata. Reasons are produced only from the twenty Phase 13 allowlisted reason codes and safe local messages; raw feature names, values, and contributions are not exposed.

Fallback calls the unchanged `MatchingService::recommendJobsForUser()` once, filters its complete output to the authoritative deadline-correct eligibility snapshot, and preserves its score, version, breakdown, matched/missing skills, and reasons without recalculation. Typed configuration, transport/timeout, authentication, provider validation, rate limit, unavailable/5xx, contract, mapping, reconciliation, and unexpected ML-client failures map to stable safe codes. A Matching/domain/authorization/public-validation failure is not swallowed or recursively retried.

### Files and Bindings

Phase 14 adds four contracts under `app/Contracts/Recommendation`, four readonly result/eligibility/client-handle values under `app/Data/Recommendation`, two safe exceptions, the eligibility provider, lazy factory, request mapper, orchestrator, one resource adapter, `RecommendationOrchestratorTest`, and `RecommendedJobsMlTest`. It modifies only `.env.example`, `config/recommendation_ml.php`, `AppServiceProvider`, the existing recommendation controller action, `RecommendedJobResource`, and this report. No route, model, migration, repository, Python, Bundle, Model, Postman, Docker, cache, queue, notification, audit, or persistence file was added or changed.

`AppServiceProvider` binds the orchestrator, eligibility provider, mapper, and lazy client factory contracts. Container resolution and controller construction pass without circular dependencies or request-state singletons.

### Verification and Manual Integration

- `php artisan test --compact --do-not-cache-result --filter=RecommendationOrchestrator`: 17 passed, 167 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendedJobsMl`: 19 passed, 289 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendationMlClient`: 138 passed, 438 assertions.
- `php artisan test --compact --do-not-cache-result --filter=Matching`: 50 passed, 392 assertions.
- Full `php artisan test --compact --do-not-cache-result`: 708 passed, 4888 assertions; one opt-in real-S3 integration test skipped.
- Pint on Phase 14 PHP files, PHP syntax checks, static recursion/preflight/retry/privacy scans, and `git diff --check`: passed.
- The five frozen artifact hashes match their locked values. No file under `services/ml-recommendation` was modified by Phase 14.

Manual integration used a temporary process-only service token and a fresh in-memory SQLite database. The real public endpoint returned HTTP 200 through `ml_xgbranker`, returned all three eligible fixtures, excluded a closed job, a pending-company job, an expired job, and an already-applied job, kept scores in 0-100, exposed safe reasons, issued one FastAPI rank request, performed zero recommendation/domain writes, and exposed no token or candidate PII in the response or test log. The only endpoint write observed in automated query capture is Sanctum's pre-existing `personal_access_tokens.last_used_at` authentication update; recommendation domain tables remain read-only.

FastAPI was then stopped, port 8100 was verified not listening, and the same public flow with ML enabled returned HTTP 200 through `matching_v2_fallback` with all current Matching 2.0 fields and no exception details. All temporary fixtures, logs, process tokens, and integration scripts were removed. No commit or push was performed.

## 46. Phase 15: Recommendation Persistence, Cache, and Invalidation

### Architecture and Lookup Lifecycle

`RecommendationOrchestrator` remains the only entry point for the recommendation flow. Each request captures eligibility once, builds a versioned content fingerprint, checks a deterministic cache pointer, checks the durable database store on a cache miss, and computes through the unchanged Phase 14 ML-or-Matching path only on a complete miss. Cache and persistence hits hydrate from the already eager-loaded current eligible Job models; no Job snapshot or professional fact is stored.

The lookup key is `recommendations:v1:profile:{profile_id}:limit:{limit}:context:{context_hash}`. Its value contains exactly the cache schema version, run ID, context hash, requested limit, and expiry. It uses Laravel's generic cache repository with no tags or Redis-only calls. A database hit warms the cache. A corrupt pointer, missing/expired run, invalid item, stale Job, unsupported version, count mismatch, duplicate rank, or non-eligible Job rejects the complete stored result and follows one normal computation path.

### Persistence Schema and Transaction

Migration `2026_07_25_000001_create_recommendation_tables.php` creates exactly two tables. `recommendation_runs` stores the profile reference, optional request UUID, SHA-256 context identity and version, request/result counts, engine and fallback metadata, frozen ML versions, generation time, expiry, and timestamps. It has the profile foreign key, context and expiry indexes, and the composite profile/context/limit/expiry lookup index.

`recommendation_items` stores only the run and Job references, rank, display score, optional internal raw score, score-contract version, safe numeric breakdown, safe reasons, and creation time. It has cascading run and Job foreign keys plus unique run/Job and run/rank constraints. JSON casts preserve breakdown and reasons. Candidate facts, Job text, skill names, feature vectors, request/response payloads, tokens, base URLs, and PII are not persisted.

Run and item creation is one database transaction. Counts, ranks, unique eligible Job IDs, score bounds, finite raw scores, engine/fallback/version consistency, reason shape, and item score versions are validated before publication. An item-write exception rolls back the run and every item. Empty eligibility is persisted as a zero-item run; a non-empty candidate set cannot publish an empty result.

### Context Fingerprint and Invalidation

`recommendation-context-v1` is a canonical SHA-256 fingerprint over only state that affects eligibility, mapping, Matching 2.0 scoring, Laravel ranking, or frozen contracts. It covers candidate headline/summary, calculated experience and contributing experience fields, education, skills, all eligible Job scoring/mapping fields, required/nice-to-have pivots, current company approval, status, deadline, `published_at`, ML enabled state, Matching configuration/version, Model/Feature Schema/Explanation/score-transform versions, limits, and final-ranking policy.

Associative keys are recursively sorted, unordered model collections receive deterministic ordering, meaningful lists retain order, floats use deterministic representation, enums use their values, and dates use normalized ISO-8601 values. Only the resulting hash and version leave the fingerprint service. User name/email/phone/password/auth state, unrelated timestamps, request UUID, availability, exceptions, and application facts are excluded. Prior applications still invalidate by changing the authoritative eligible Job list.

### TTL, Failure Safety, and Pruning

Successful `ml_xgbranker` and ML-disabled `matching_v2` results use 900 seconds. `matching_v2_fallback` uses 60 seconds. Zero-eligible results use 60 seconds. All values and the 30-day retention window are environment-configurable through validated config; success TTL is constrained to 1-86400 seconds, fallback/empty TTL cannot exceed it, and retention is constrained to 1-365 days.

Cache reads/writes and database reads/writes are isolated from recommendation computation. Failures use safe codes and counts in logs, never payloads or facts, and do not fail the public result, retry ML, retry Matching, recurse, or delete a successfully written database run.

`recommendations:prune` deletes expired or retention-old runs in ID chunks; items cascade. `--dry-run` performs no writes and reports only `deleted_runs`. No schedule was added because this repository has no existing scheduler convention.

### Public Compatibility, Tests, and Verification

The route, middleware, request validation, controller envelope, success message, HTTP status, and `RecommendedJobResource` remain the Phase 14 contract. Run IDs, hashes, cache keys, expiry, safe fallback codes, and persistence errors are never exposed. Cache/persistence metadata remains internal to the result and safe logs. `MatchingService`, the eligibility provider, request mapper, Phase 13 client, public routes, controller, and resource received no Phase 15 change.

Dedicated `RecommendationPersistenceTest`, `RecommendationCacheTest`, and `RecommendationInvalidationTest` coverage verifies schema, foreign keys, uniqueness, casts, cascades, deterministic hashing and mutation matrix, all three engines and empty round trips, raw-score and version retention, atomic rollback, privacy, cache/DB hits, pointer validation, expiry, separate keys, failure isolation, bounded hydration queries, pruning, and public-equivalent hydrated results.

The isolated SQLite lifecycle verification performed first computation, cache reuse with zero recommendation writes, cache flush and database reuse, scoring-field invalidation and recomputation, prior-application exclusion, dry-run counting, deletion, and item cascade. Final command results:

- `php artisan migrate:fresh --env=testing` with process-scoped SQLite overrides: passed; all migrations including both recommendation tables ran.
- `php artisan test --compact --do-not-cache-result --filter=RecommendationPersistence`: 10 passed, 82 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendationCache`: 11 passed, 78 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendationInvalidation`: 5 passed, 45 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendationOrchestrator`: 17 passed, 167 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendedJobsMl`: 19 passed, 291 assertions.
- `php artisan test --compact --do-not-cache-result --filter=RecommendationMlClient`: 138 passed, 438 assertions.
- `php artisan test --compact --do-not-cache-result --filter=Matching`: 52 passed, 414 assertions.
- Full `php artisan test --compact --do-not-cache-result`: 734 passed, 5095 assertions; one opt-in real-S3 integration test skipped.
- Pint on every Phase 15 PHP file, PHP syntax checks, privacy/Redis scans, and `git diff --check`: passed.

The frozen Model, Feature Schema, Bundle manifest, OpenAPI contract, and contract manifest retain their locked SHA-256 hashes. `MatchingService`, public routes, the recommendation controller/resource, and every Python file retain their Phase 15 baseline content. No Redis dependency, Postman, Docker, Queue, notification, audit persistence, commit, or push was introduced.

## 47. Phase 16: Docker and Deployment Packaging

### Package and Runtime Contract

Phase 16 adds a provider-neutral, production-oriented container package for
the existing FastAPI `0.2.0` inference service. The Laravel Dockerfile and
deployment conventions remain unchanged. The ML image uses a multi-stage
build and the multi-architecture digest-pinned Python `3.12.10-slim` base.
The builder creates wheels; the runtime installs only the exact closure from
`requirements-container.lock`, including `xgboost-cpu==3.3.0` without
GPU/NCCL packages.

The runtime runs as fixed UID/GID `10001:10001`, exposes port `8100`, starts
one Uvicorn worker without reload, and uses a standard-library readiness
healthcheck. `/app` is immutable and the verified launch supplies only a
bounded `/tmp` tmpfs. All capabilities are dropped and
`no-new-privileges` is enabled. The token is a required runtime secret; it is
absent from the Dockerfile environment, build arguments, image labels,
history, Compose values, documentation values, and deterministic manifest.

Only the installed inference package, its exact runtime dependencies, two
container scripts, and the eight frozen Bundle files are present. Training,
tuning, evaluation, split, synthetic-source, test, Laravel, Git, cache,
virtualenv, local environment, and explanation artifacts are excluded. The
installed package retains only the professional-domain catalog and schema
types required by the frozen inference pipeline.

### Compose, Manifest, and Runbook

`compose.ml.yml` builds only the ML service, requires token interpolation,
binds `127.0.0.1:8100`, enables read-only mode, tmpfs, capability dropping,
`no-new-privileges`, init, readiness healthcheck, and a 30-second stop grace
period. Compose configuration validation passed with a process-only token.

`deployment/container/v1/container_manifest.json` is sorted,
machine-independent, timestamp-free, secret-free, and records the base
digest, source revision, runtime identity, exact dependency versions,
packaging-file hashes, and all eight Bundle hashes. `DEPLOYMENT.md` documents
build/tag policy, runtime environment, internal networking, Laravel
configuration, rollout, rollback, coordinated token rotation, smoke checks,
monitoring, limitations, and horizontal scaling. No provider manifest,
production deployment, Kubernetes resource, proxy, Redis, database, or
CI/CD pipeline was added.

### Image and Runtime Verification

The final local image is
`workeyx/ml-recommendation:0.2.0-phase16`. Docker reports compressed image
size `196154361` bytes, 11 rootfs layers, `linux/amd64`, user
`10001:10001`, workdir `/app`, the expected entrypoint and healthcheck, and
complete OCI revision/date/application labels. Docker's human-readable
uncompressed listing is approximately `688MB`.

Actual hardened runtime verification passed. The service became healthy,
returned live/ready and metadata identities, and ranked the frozen three-Job
request with matching request/version identities. Final-package startup
observations were approximately 6.1-8.2 seconds; a prior measured warm
five-request sample was `13.22`, `16.61`, `11.71`, `11.10`, and `11.59ms`.
Observed memory was `70.47MiB` idle and `71.46MiB` after rank, CPU `0.17%`,
and graceful stop `513.88ms`. These are local observations, not an SLA.

Root writes and Bundle/model writes failed, `/tmp` writes succeeded, the
process identity was `10001:10001`, and only one Uvicorn worker was present.
The image also became ready and completed ranking under `--network none`.
Missing token produced live `200`, ready `503` with
`SERVICE_TOKEN_NOT_CONFIGURED`, and protected endpoint `401`. A missing
Bundle produced ready/rank `503`; a wrong caller token produced `401`.
No tested secret appeared in logs.

The clean no-cache rebuild and final image have different BuildKit
manifest-list IDs because provenance attestations differ, but they have the
same size, layer count, OCI/runtime configuration, dependency versions,
startup/healthcheck hashes, all eight Bundle hashes, ready state, and
three-prediction rank response.

### Laravel-to-Container Integration

The opt-in `RecommendationContainerIntegrationTest` uses the real public
Laravel endpoint and real container transport. It passed 29 assertions:
the first request used `ml_xgbranker`, persisted one run/item, the second
request reused the cache without another ML request or run, and no token or
candidate email appeared publicly. The coordinator then stopped the
container, changed the scoring context, and the same endpoint returned
`matching_v2_fallback`, persisted the fallback result, and preserved the
public contract.

### Verification Results

- Container packaging tests: 6 passed; one upstream
  `StarletteDeprecationWarning`.
- Safe Python suite: 362 passed; whole-package line coverage `91.11%`
  with no omitted source module. The Locked Test contract unit used generated
  temporary fixtures only; the repository Locked Test was not opened or
  evaluated.
- Additional branch-inclusive measurement with the real Locked Test unit
  excluded: 355 passed and `86.07%`; recorded separately because project
  branch coverage and the requested line-coverage gate are distinct.
- Ruff lint: passed. Ruff formatting over `src`, `tests`, and `container`:
  108 files formatted. The broad service-path invocation hit a Ruff
  `Expected a ruff source file` panic, so the equivalent explicit Python
  directories were verified.
- Mypy strict: 106 source files passed. Compileall and pip check passed.
- Recommendation and Matching filters passed; Matching reported 52 tests and
  414 assertions.
- Full Laravel: 734 passed, 5095 assertions, 2 opt-in integrations skipped.
- The real container integration was separately enabled and passed.
- Pint for the new PHP integration test and Compose config validation passed.
- `composer audit --locked`: no vulnerability advisories.
- Trivy, Syft, and Grype were unavailable. Docker Scout `1.20.4` was
  available, but image-metadata egress was rejected by the execution safety
  policy, so no Scout CVE result is claimed.
- Image environment, history, and Phase 16 static secret scans found zero
  token/private-key matches.

Early image-stripping iterations exposed required imports from
`data.catalog` and `schemas.synthetic`; the final Dockerfile retains only
those runtime requirements and all final builds/runtime checks pass. Initial
coverage runs also exposed a local ACL restriction for SQLite coverage files;
the final measurement ran safely from the writable repository root.

### Integrity and Repository State

The Architecture, Model, Feature Schema, Bundle manifest, OpenAPI, and
contract-manifest hashes remain respectively:
`60eb219152ce26b525735ed65564f667d403bf438f29000b4ece90d65950553f`,
`3abd74137bc8881667643f31a658c790ef6712359d7802ea7fcffa0c4cf9e26e`,
`aeb260b25f34b55b7164b215e613a0b4327df33ee65af95abc904045849ce4a0`,
`1d566e4516724fae0c08cd6131214c0722dffcd589a370cf2405b8b0450dfb00`,
`b73b11b5fa67c40927e5a05ab72e2d2f7b292fa3149f0d945ae74be08f7ca96d`,
and
`a51e8f4e74189ccb086bdb7fe32816c6e56953533f3c77243e50650be0bf9cb2`.

Branch `master` and HEAD
`6cd51f733d5197e0c3f6b7dfb3711c2860ffef71` remain unchanged with zero
staged files. Phase 16 adds 11 untracked files and modifies the already
untracked ML README plus this already tracked report; total untracked files
are 251. No inference, Feature Pipeline, recommendation business logic,
Matching formula, Model, Bundle, migration, database, production environment,
commit, or push changed. Phase gate: **READY FOR PHASE 17**.

## Phase 17 — End-to-End Integration and Failure Testing

Phase 17 exercised the unchanged public
`GET /api/v1/jobs/recommended` flow through Laravel, the existing
`workeyx/ml-recommendation:0.2.0-phase16` container, database persistence,
cache, and MatchingService 2.0 fallback. The repeatable loopback harness
passed cold ML, cache hit, persistence hit, content-addressed invalidation,
eligibility, stopped-container, wrong-token, invalid-Bundle, safe
cache/persistence corruption, 18 provider/network failure modes, bounded warm
and cold concurrency, privacy scanning, and final cleanup. It rebuilt no
image and used no production data or service.

The deterministic Phase 17 matrix contains 35 passed scenarios and zero
failed scenarios. Ten warm concurrent requests created no new run. Five cold
requests produced one complete equivalent run, zero duplicate-equivalent
runs, zero partial runs, and no HTTP failure. Runtime secret and fixture PII
marker hits were zero across public bodies, Laravel/container/fault logs, and
recommendation storage.

Laravel verification finished with 761 passed tests, 8537 assertions, and the
two expected opt-in integrations skipped. Python verification finished with
362 passed tests, one upstream Starlette deprecation warning, and
91.17823564712943% whole-package line coverage. Ruff lint/format, the
runtime-compatible Mypy check over 106 files, compileall, pip check, and Pint
passed. The literal protected Mypy 3.11 configuration conflicts with installed
NumPy stubs that use Python 3.12 syntax, so the successful compatibility
check explicitly targeted the deployed Python 3.12 runtime without changing
configuration or dependencies. Composer advisory lookup was attempted but
could not reach Packagist locally, and external retry was denied by the
private-lockfile metadata policy; no advisory result is claimed.

The pre-change protected baseline verified all 864 entries with zero live
mismatches. Final comparison found no unexpected protected mismatch; this
report is the only approved existing-file modification. Architecture, Model,
Feature Schema, Bundle manifest, OpenAPI, contract manifest, container
manifest, all eight Bundle files, and all stored container-input hashes remain
exact. No application/ML behavior, model, Bundle, contract, Docker packaging,
migration, schema, training, tuning, Locked Test, deployment, commit, or push
changed. The detailed evidence is in
`docs/ml-job-recommendation/phase17/PHASE_17_E2E_REPORT.md` and
`docs/ml-job-recommendation/phase17/E2E_TEST_MATRIX.json`.

## Phase 18 — Final Documentation, Demo, and Handover

Phase 18 closes the ML Job Recommendation academic implementation plan with a
new protected cryptographic baseline, a final handover, final verification
report, repeatable demo runbook, machine-readable requirements traceability,
completion checklist, Arabic graduation-defense guide, deterministic handover
manifest, and a documentation contract test. Root and ML service READMEs now
link to those sources of truth.

The handover preserves the existing system boundary: Laravel owns
authentication, eligibility, job discovery, final sorting, persistence,
cache, fallback, and the public resource; FastAPI owns strict validation,
frozen Feature construction, XGBRanker inference, display-score conversion,
and attribution. AI remains decision-support only. `display_score` is not a
probability, SHAP attribution is not causality, and the model remains trained
on synthetic data.

No production PHP source, route, controller, resource, service, model,
migration, schema, Bundle, contract, Docker packaging, image, training, tuning,
or Locked Test evaluation changed. A post-handover commit-safety correction in
the Python baseline evaluator replaces only the machine-local repository label
written to its Markdown report; it changes no ranking, metric, feature, split,
inference, Model, or Bundle behavior. Production deployment and production load
testing were not performed. Detailed evidence is indexed by
`docs/ml-job-recommendation/phase18/FINAL_HANDOVER.md`.

Final verification passed the Phase 18 documentation contract, 358 safe Python
tests at 93% whole-package coverage, Ruff, explicit Python
3.12 Mypy, compileall, pip check, Pint, the 35-scenario E2E matrix, frozen
artifact checks, privacy checks, cleanup, and the 873-entry protected
comparison with zero unexpected mismatches. Composer advisory lookup remained
indeterminate because Packagist was unavailable.

The previous sole failure was a stale Phase 17 assertion that fixed the root
`README.md` byte size before Phase 18 added its explicitly required handover
links. Final-gate test maintenance replaced the brittle size/hash check with
semantic link, deployment-disclaimer, path, and secret-safety assertions.
`RecommendationEndToEndTest.php` is named in exactly one explicit Phase 18
maintenance allowlist; no wildcard or broader test exemption was introduced.

The final portability audit approved the repository label in
`services/ml-recommendation/data/baselines/v1/BASELINE_REPORT.md` and its
report-template literal in
`services/ml-recommendation/src/smart_recruitment_ml/baselines/evaluator.py`.
The Phase 7 baseline manifest received one integrity-metadata update: only the
report output's byte size and SHA-256 now identify the approved portable bytes.
The unchanged Python baseline integrity test verifies that record and all other
outputs. No metric, ranking result, Dataset row, split, model output, version,
or numerical evidence changed.
The service README uses a portable `python` command and repository-relative
virtual environment path. The historical Phase 18 baseline remains immutable;
the current comparison therefore reports nine total approved differences, zero
unexpected protected mismatches, and zero missing protected paths.

The trainer's current Phase 7 provenance now equals the portable manifest
SHA-256
`C591708A58AE66941BB004CE08522EAADC90F476105F7BED08B5E2DB477046BF`.
Historical reproduction remains byte-faithful by reading the old manifest hash
and recorded Locked Test hash from the frozen initial-model manifest, supplying
those historical values only inside the test, and guarding the trainer's actual
`Path.open` path against Locked Test access. Real synthetic Train/Validation
training executed in an external pytest temporary directory; all eight
historical initial-model outputs were byte-identical. No frozen Model or Bundle
changed, and no production retraining occurred.

The Phase 17 and Phase 18 integrity tests now recognize the trainer-provenance
and historical-reproduction test paths through exact two-path maintenance
entries. No wildcard, Python directory exemption, or broad test exemption was
added; every other protected path retains size and SHA-256 enforcement.

The final complete Laravel run reported 762 passed, two expected opt-in skips,
zero failures, and 16,547 assertions. No production behavior changed.
The strict Phase 18 gate is **PROJECT HANDOVER READY**.

---

## Unified Mobile Home API — 2026-07-30

### Summary

Implemented `GET /api/v1/home` as the single mobile Home endpoint for both
anonymous visitors and authenticated job seekers. The endpoint uses the
project's `ApiResponse` envelope, existing public-job rules, the existing
recommendation orchestrator, and the existing account-state protection.
Employer and administrator tokens are rejected with `403`; an invalid,
expired, or malformed supplied token is rejected with `401` rather than being
downgraded to a guest request.

### Existing components reused

- `JobPostingService::getPublicJobs()` already owned public visibility rules:
  open jobs belonging to approved companies, including its existing
  `accepting_applications` deadline filter. Home calls it with that filter for
  latest jobs and does not alter the existing public-list behavior.
- `RecommendationOrchestratorContract::recommend(User $user, int $limit)` was
  already backed by the ML provider plus the existing Matching V2 fallback.
  Home calls this contract directly with limit `6`; it never calls the
  `/jobs/recommended` endpoint over HTTP and does not duplicate matching.
- Recommendation eligibility already excludes non-open jobs, jobs from
  unapproved companies, passed deadlines, and jobs previously applied to.
- Existing profile, experience, education, skill, primary-CV, CV review,
  profile suggestion, test assignment/attempt, interview, application
  information request, company, and job relations are queried directly.
- `EnsureUserIsActive` and `ApiResponse` remain the common account-state and
  response-envelope mechanisms.

### Optional Sanctum authentication decision

The route is intentionally outside the mandatory `auth:sanctum` group. It uses
`auth.sanctum.optional`, which:

1. Continues as a guest only when the `Authorization` header is absent.
2. Resolves a supplied Bearer token through the Sanctum guard.
3. Returns `401 INVALID_AUTHORIZATION_TOKEN` when a supplied token is invalid,
   expired, or malformed.

After a token is authenticated, the optional middleware delegates to the
unchanged `EnsureUserIsActive` middleware, preserving its `USER_SUSPENDED`
response without changing the protected middleware file. Home service accepts
only `job_seeker`; authenticated employer/admin users receive `403` with
`Mobile Home is available to job seekers only.`

### Final guest contract

`data` contains only:

- `viewer`: `{ "type": "guest", "is_authenticated": false }`
- `hero`: title, description, register semantic action, login semantic action
- `latest_jobs`: at most five lightweight visible job cards
- `featured_companies`: at most six lightweight company cards
- `app_features`: three static product feature descriptors

Personal profile, recommendations, applications, tests, interviews,
notifications, and any other user's data are omitted, not returned as `null`.

### Final authenticated job-seeker contract

`data` contains:

- `viewer`: type, authentication state, user id, name, and nullable
  `avatar_url`
- `profile_completeness`
- `required_action`: the highest-priority computed action or `null`
- `recommended_jobs`: at most six lightweight cards with `match`
- `featured_companies`
- `latest_jobs`
- `meta.recommendations_available`
- `meta.recommendations`: availability, source, and fallback-used flag

Home job cards contain only id, title, company card, location, work mode,
employment type, and publication time. Recommendation cards additionally
contain score, matched skill names, missing required skill names, and safe
reason code/message pairs. CV raw text and parsed JSON are neither selected by
Home queries nor serialized.

### Required-action order

`HomeActionResolver` computes a view model without adding a database table:

1. Available pending or started test (`100`); submitted and expired tests are
   excluded.
2. Future scheduled/confirmed/rescheduled interview (`90`); cancelled and
   completed interviews are excluded. Unconfirmed interviews use the
   `interview_confirmation` action.
3. Pending, unanswered, non-expired information request (`80`).
4. Parsed, unconfirmed CV requiring review (`70`).
5. Pending profile-sync suggestion (`60`).
6. Incomplete profile (`50`).
7. No action: `required_action` is `null`.

Dates are emitted with Laravel/Carbon ISO-8601 serialization and retain the
application timezone offset where applicable. Navigation is semantic
(`profile_section`, `test_assignment`, `interview`, `information_request`,
`cv_review`, `profile_suggestions`) and contains no mobile route paths.

### Profile-completeness calculation

`ProfileCompletenessService` uses the requested 100-point allocation:

- Basic information (name, email, phone): 15
- Professional headline and summary: 15
- Location: 10
- At least one experience: 20
- At least one education record: 15
- At least three skills: 15
- A primary, confirmed, non-archived CV: 10

The response includes integer percentage, `is_complete`, ordered semantic
missing items, missing count, and the highest-priority `next_item`.

### Public jobs and featured companies

Latest jobs call the existing `JobPostingService::getPublicJobs()` with
`accepting_applications=true`, `published_at desc`, and `per_page=5`, reusing
the public endpoint's visibility and sorting implementation without changing
its contract.

Featured companies use one aggregate query: approved companies only, at least
one open non-expired job, open job count descending, latest open-job
publication descending, then id; limit six. `withCount` and `withMax` avoid
per-company queries.

### Recommendation and failure behavior

Home injects and calls `RecommendationOrchestratorContract` directly with
limit six. The existing orchestrator remains responsible for ML ranking,
eligibility, persistence/cache, explainability, and its Matching V2 fallback.
The orchestrator's eligibility provider remains the authority that excludes
non-public jobs and previous applications before Home serialization. No AI
request contract was changed.

Normal provider failures continue through the orchestrator's existing local
fallback and report its engine in metadata. If an unexpected exception escapes
the orchestrator, Home logs only user id and exception class, returns an empty
`recommended_jobs`, and sets recommendations to
`{available: false, source: "unavailable", fallback_used: false}`; the Home
response remains `200`.

### Files added

- `app/Http/Controllers/Api/V1/Home/HomeController.php`
- `app/Http/Middleware/AuthenticateSanctumOptionally.php`
- `app/Http/Resources/Api/V1/Home/HomeActionResource.php`
- `app/Http/Resources/Api/V1/Home/HomeCompanyResource.php`
- `app/Http/Resources/Api/V1/Home/HomeJobResource.php`
- `app/Services/Home/HomeActionResolver.php`
- `app/Services/Home/HomeService.php`
- `app/Services/Home/ProfileCompletenessService.php`
- `tests/Feature/Api/V1/HomeApiTest.php`
- `tests/Unit/Home/HomeActionResolverTest.php`
- `tests/Unit/Home/ProfileCompletenessServiceTest.php`

### Files modified

- `bootstrap/app.php`
- `routes/api/v1.php`
- `postman/Smart Recruitment Platform - Mobile App.postman_collection.json`
- `BACKEND_IMPLEMENTATION_REPORT.md`

The Mobile Postman collection now contains `Home - Guest` and
`Home - Authenticated Job Seeker`, including authentication, successful
contracts, `401`/`403` behavior, action ordering, company ordering, and
recommendation fallback notes.

### Verification

- Focused Home tests: 26 passed, 109 assertions.
- PHP syntax checks: all added and modified PHP files passed.
- Route verification: one `GET|HEAD api/v1/home` route named `v1.home`.
- Full Laravel suite: 843 passed, 2 expected opt-in skips, 0 failures, and
  17,387 assertions.
- Laravel Pint: all scoped added/modified PHP files pass.
- Mobile Postman collection: valid JSON after adding both Home requests.

No migration, commit, or push was created for this implementation.

## Localization audit and bilingual API contract (2026-07-30)

### Baseline and findings

The baseline had `APP_LOCALE` and `APP_FALLBACK_LOCALE` configuration but no
application `lang/` directory, no request-locale middleware, and no unified
language negotiation. An initial static inventory found 43 PHP files with 210
`ApiResponse` calls, 65 files with human-display fields, and 60 files with
custom throw/abort sites. Home copy was Arabic-only while controllers,
validation, domain exceptions, recommendation reasons, and notifications were
predominantly English-only.

### Implemented behavior

- `SetRequestLocale` is prepended to Laravel 12's API middleware group only.
- Supported languages are configured by `APP_SUPPORTED_LOCALES=en,ar`.
- Missing headers use `config('app.locale')`; unsupported or malformed headers
  use `config('app.fallback_locale')`.
- Regional tags are reduced to their base language and valid `q` weights are
  sorted by quality then original order.
- Every API response includes `Content-Language` and appends
  `Accept-Language` to `Vary`.
- Translation files are split by API, auth, profile, CV, jobs, applications,
  tests, interviews, companies, notifications, admin, Home, enums, AI, and
  shared errors.
- Laravel validation has complete English and Arabic rule messages plus
  translated attribute names; error object keys are unchanged.
- Controller messages use translation keys. The exception renderer localizes
  common/domain exceptions, never exposes internal exception text, and handles
  404, 405, 413, 415, 429, validation, authentication, authorization,
  conflicts, and generic 500 responses.
- Enum values remain unchanged. `EnumLabel` is the centralized opt-in helper;
  no label fields were added to frozen contracts that did not already require
  them.
- Home system copy and profile-completeness/action copy are bilingual.
- Structured matching/ML reasons keep their stable `code` and are translated
  in Laravel at resource serialization time. Scoring and ranking are unchanged.
  The recommendation cache stores structured identifiers/results rather than a
  translated HTTP response, so resource-time translation prevents
  cross-language leakage without fragmenting model cache entries.
- In-app notification templates use translation keys and placeholders.
  User-authored messages remain untouched. The User schema has no locale
  preference and no migration was added; notifications created outside a
  request therefore use the configured default locale.
- Both Postman collections inject `Accept-Language: {{locale}}`; the environment
  defaults `locale` to `en`, and collection tests verify `Content-Language` and
  `Vary`.

### Examples

English:

```json
{
  "success": false,
  "message": "Invalid credentials.",
  "errors": []
}
```

Arabic:

```json
{
  "success": false,
  "message": "بيانات تسجيل الدخول غير صحيحة.",
  "errors": []
}
```

Stable structured reason:

```json
{
  "code": "REQUIRED_SKILLS_MATCH",
  "message": "تطابقت 2 مهارة من أصل 3 مهارات مطلوبة.",
  "value": 66.67
}
```

### Verification notes

The dedicated Localization suite covers default/simple/regional/weighted/
unsupported/malformed negotiation, response headers, English and Arabic
validation attributes, authentication, sequential locale isolation, and
structured AI reason translation. Full-suite and formatting results are
recorded in the task handoff. No migration, commit, or push was created.

Final verification now includes the negotiation suite, exact bilingual domain
error contract tests, complete translation-catalog parity, non-empty values,
and placeholder parity. The final command results are recorded in the
localization closure report below.

The final broad literal scan still identifies reviewed legacy/internal
candidates. Every user-facing candidate is connected to an exact catalog key;
known domain codes never use the generic fallback. The complete 215-row
classification is in `reports/LOCALIZATION_HARDCODED_STRING_INVENTORY.md`.

## Localization final closure — 2026-07-30

### Baseline and discovered issues

- The pre-existing backend had no application `lang/` tree and only isolated
  translation-helper use. API success/error messages, validation attributes,
  middleware/exception messages, Home copy, notification text, workflow
  reasons, enum display labels, and recommendation explanations contained
  display literals.
- The first implementation pass left 215 review candidates and used a generic
  Arabic fallback for unmatched legacy domain messages.
- Phase 17/18 integrity tests failed because protected presentation files had
  changed. The failures were
  `FinalHandoverDocumentationTest::test_final_handover_documentation_contract`
  and
  `RecommendationEndToEndTest::test_phase17_protected_baseline_entries_and_aggregate_are_valid`.

### Request locale and fallback

- `SetRequestLocale` is prepended only to Laravel's API middleware group.
- Supported locales are `en` and `ar`. It parses regional tags, weighted
  language lists, invalid entries, and original-order ties.
- Missing header uses `config('app.locale')`; a header without a supported
  candidate uses `config('app.fallback_locale')`, then the configured default.
- Every API response receives `Content-Language` and merges
  `Accept-Language` into `Vary`.

### Catalogs and response behavior

- There are matching English and Arabic domain catalogs for API, auth,
  applications, companies, CV, Home, interviews, jobs, notifications, profile,
  tests, validation, enum labels, AI reasons, exact domain error codes,
  persisted system text, and domain validation.
- `ApiResponse` resolves a known error with
  `code → domain_errors.CODE`. It no longer replaces an unmatched known domain
  message with a generic Arabic sentence.
- Validation field keys remain unchanged. Only messages and human-readable
  attribute names are localized, including nested/custom validation.
- Enum/status/code values remain stable. Resources expose localized labels only
  where the existing presentation contract needs them.
- Legacy persisted system reasons are translated at serialization through a
  narrow exact mapping; arbitrary user notes are returned unchanged.

### Notifications, AI, and cache

- Notification titles and bodies use bilingual keys with placeholders. The
  current User schema has no locale preference; no migration was added.
  Notifications outside an HTTP request therefore use the configured default
  locale. Request-scoped notifications use the negotiated locale.
- Matching/ML reason codes stay stable and are translated in Resources.
  `MatchingService` is byte-identical to its protected baseline; scoring,
  ranking, weights, thresholds, fallback behavior, and ML contracts are
  unchanged.
- No public/Home response cache storing translated payloads exists. The
  recommendation cache stores structured codes/data and translation occurs
  after hydration, preventing Arabic/English response leakage.

### Protected baselines

- The protected JSON baselines and aggregate hashes were not regenerated.
- Eighty-seven reviewed localization-only protected paths were added to the
  tests' existing explicit post-handover allowlists. Both tests continue to
  verify every non-approved file and all baseline self-integrity constraints.
- Per-file previous/current hashes and diff summaries are recorded in
  `reports/LOCALIZATION_PHASE17_18_FINGERPRINT_AUDIT.md`.

### Inventory and compatibility

- All 215 final-pass candidates are classified by file, line, original text,
  exposure, translation key, and decision.
- User-facing candidates without a key: zero.
- Residual literals are limited to renderer/Resource-translated legacy values,
  CLI output, internal logs, provider diagnostics, AI schema guidance, SQL, or
  technical invariants.
- Field names, pagination, error codes, enums, statuses, HTTP statuses,
  business rules, and user-generated content remain stable.

### Verification

- Dedicated Localization suite: **25 passed, 6193 assertions, 0 failed**.
- Complete Laravel suite: **868 passed, 2 expected opt-in skips, 23232
  assertions, 0 failed**.
- Phase 17/18 protected tests pass without skips or baseline regeneration.
- Laravel Pint `--dirty --test`: passed. Composer strict validation: passed.
  Postman JSON parsing: passed. `git diff --check`: passed.
- Composer defines no PHPStan/Psalm or other static-analysis command.
- Postman Web/Mobile collections use `Accept-Language: {{locale}}`; the
  environment defaults to `en` and documents `ar`.
- No migration was added. No commit or push was performed.

## Localized key/value response contract — 2026-07-30

### Contract

All audited system-controlled values exposed for presentation now use:

```json
{
  "key": "under_review",
  "value": "Under review"
}
```

The same Arabic request keeps `key` unchanged and returns `قيد المراجعة` in
`value`. Request bodies and query filters continue to accept the stable key
only. User-authored text is not translated.

`LocalizedValue` is the single strict serializer. It accepts backed enums or
string keys and reads `options.<group>.<key>` in the negotiated locale. A
missing catalog entry throws a configuration error; there is no generated
headline or raw-key fallback. English and Arabic catalogs have matching keys,
non-empty values, and placeholder parity tests.

### Coverage

- Users: role and account status.
- Companies: approval, role, membership, invitation, and permission catalogs.
- Jobs: work mode, employment type, experience level, education level, job
  state, and skill requirement/source.
- Applications: workflow status and information-request state.
- CV/profile: parsing/review/next-action state, persisted source, suggestion
  entity/action/status/source/display group.
- Tests: question type, grading type/status, and assignment state.
- Interviews: type, mode, lifecycle, confirmation/attendance, evaluator role,
  and recommendation.
- Admin: report distributions are arrays of `{key,value,count}`; audit actions
  are localized objects and the raw technical `entity_type` is retained beside
  a localized `entity`.

Application status resources now expose `id`, `key`, and `value`; the previous
human `name`/duplicate `slug` presentation shape is replaced by the unified
contract. Other converted fields retain their existing field names and change
only from a raw system string to the object.

### Canonical job values and compatibility

Employment types are canonicalized to `full_time`, `part_time`, `contract`,
and `internship`. Experience levels are canonicalized to `entry_level`,
`junior`, `mid_level`, and `senior`. The API continues accepting the known
hyphenated/short aliases and normalizes new writes. Filtering queries include
canonical and known legacy database representations, so no data migration was
required.

### Deliberate raw identifiers

Notification `type`, recommendation engine/model/version, AI reason `code`,
audit `entity_type`, routing discriminators, MIME types, extensions, URLs, and
provider diagnostics remain technical identifiers. Job titles/descriptions,
company descriptions, locations, messages, notes, screening/test content,
answers, and other user-generated content remain verbatim.

### Verification

The focused contract suite validates strict catalog behavior, every catalog
leaf in both locales, every backed enum case, legacy alias normalization,
bilingual API serialization, stable keys, canonical persistence, and unchanged
free text. Both Postman collections additionally inspect returned presentation
fields for the exact `{key,value}` shape. Final full-suite, Pint, Composer, JSON,
and diff-check counts are recorded in the task handoff and the dedicated audit:
`reports/LOCALIZED_KEY_VALUE_CONTRACT_AUDIT.md`.
## 42. Structured Syrian Cities (2026-08-01)

### Baseline and design decision

Before this change, `job_seeker_profiles.location` and
`job_postings.location` were nullable free-text columns. Profile and job
Requests/Resources/Services already owned those contracts; public search used
the existing `JobPostingService`; recommendation and employer candidate ranking
shared `MatchingService` with centralized 100-point weights; localization used
`SetRequestLocale` plus the existing `lang/en` and `lang/ar` catalogs; CV
review already separated immutable parsed data, editable initial drafts, and
candidate-approved profile-sync suggestions. No city/reference model existed.

The implementation adds a `cities` lookup table rather than an enum. Stable
`id`/`code` values drive relationships and matching; `name_ar`/`name_en` are
presentation data. Nullable `city_id` foreign keys use `nullOnDelete`, retain
the original `location` columns unchanged, and preserve all legacy records and
requests. The seed inventory contains 14 Syrian cities and can be extended
without schema or business-logic changes. Seeder backfill is deliberately
limited to exact English/Arabic city names and exact `City, Syria` forms.

### Schema, API, and validation

- Migration `2026_08_01_000001_create_cities_and_add_city_references.php`
  creates `cities(id, code unique, country_code indexed default SY, name_ar,
  name_en, is_active indexed, timestamps)` and indexed nullable foreign keys
  on both profile and job tables.
- `City`, its inverse relationships, the profile/job `belongsTo` relationships,
  and `city_id` fillable entries were added.
- Public `GET /api/v1/reference/cities` accepts `search` and `active_only`,
  scopes to Syria, defaults to active rows, and returns localized `name`.
- Profile/job create-update responses add nullable `city`; Home, employer,
  application, authentication, and public job presentations reuse the same
  resource. Existing ownership and company policies remain authoritative.
- Job-seeker registration accepts optional `location` and `city_id` and stores
  both on the automatically created profile; legacy registration remains
  unchanged when they are omitted.
- Writes accept nullable `city_id` and reject non-integer, missing, inactive,
  or non-Syrian references with localized field errors and stable codes
  `INVALID_CITY_ID`, `CITY_NOT_FOUND`, `CITY_INACTIVE`, or `CITY_NOT_SYRIAN`.
- Remote jobs require no city and receive no placeholder. Existing work-mode
  rules for the free-text location remain unchanged.

### Search, matching, and CV synchronization

Public/employer job queries eager-load cities. `city_id` and optional
`city_code` are exact relationship filters; they never inspect legacy
`location`. `include_remote=true` explicitly includes remote jobs alongside a
city filter. Existing filters, visibility, pagination, and sorting compose as
before. General text search additionally matches city code and both names.

Matching version 2 keeps a 100-point total: required skills 45,
nice-to-have skills 10, experience 20, education 10, text similarity 10, and
location 5. Same-city and remote cases receive 5/5; missing city data is neutral
at 2.5/5; a different on-site/hybrid city receives 0/5 without exclusion.
Recommendation and ranked-candidate contracts expose translated reasons,
`breakdown.location`, and `location_match`. Candidate wording is viewer-aware,
and no location result performs an application state transition. ML display
ranking incorporates the same configured component only when actionable while
preserving the provider payload schema.

CV city recognition is local and deterministic over active Syrian `name_ar`,
`name_en`, and `code`; there is no fuzzy matching or external API. Initial
imports add optional `profile.city_id` only to the review draft. Existing
profiles receive a separate ADD/UPDATE/IGNORE suggestion. Manual data remains
unchanged until candidate confirmation/final apply, and unknown/ambiguous text
produces no city id.

### Documentation and verification inventory

Added/updated documentation: `docs/SYRIAN_CITIES_API.md`, README integration
link, mobile CV review notes, both Postman collections, shared Postman
environment, and this report. Feature/unit coverage is in
`CitySupportTest`, `LocationCompatibilityServiceTest`, and the extended
`MatchingServiceTest`, covering localization, active filtering, profile and job
writes/removal/backward compatibility, exact search, remote inclusion,
explainable location states/ranking, and conservative CV suggestions.

Frontend implementation is intentionally out of scope. The complete endpoint,
request/response, validation, localization, side-effect, and client integration
contract is documented in `docs/SYRIAN_CITIES_API.md`.

### Final verification

- Complete Laravel suite: **884 passed, 2 expected opt-in skips, 26845
  assertions, 0 failed**.
- Focused city, matching, recommendation, CV, and protected-contract suites:
  passed.
- Laravel Pint `--test --dirty`: passed after formatting changed PHP files.
- PHP syntax: passed for all 63 changed or newly added PHP files.
- Composer strict validation: passed. The project defines no PHPStan, Psalm,
  or other static-analysis command.
- Postman Web, Mobile, and Environment JSON parsing: passed.
- Route registration and `git diff --check`: passed.
- No frontend change, commit, or push was performed.

## Applications Page Backend Expansion (2026-08-01)

### Repository state and reused implementation

Work started from a clean `master` worktree tracking `origin/master`. The
existing `GET /api/v1/applications/my`, application details, withdrawal,
tests, interviews, information requests, `ApplicationWorkflowService`,
candidate-safe resources, policies, localization, and `ApiResponse` pagination
shape were retained. No duplicate applications-list endpoint was added.

### List contract

`GET /api/v1/applications/my` now accepts:

- `search`: job title, company name, location text, or Arabic/English city.
- `group`: `all` (default), `active`, `requires_action`, or `completed`.
- `status[]`: one or more official application-status slugs.
- `sort_by`: `priority` (default), `updated_at`, `created_at`,
  `last_status_changed_at`, or `deadline`.
- `sort_direction`: `desc` (default) or `asc`.
- `per_page`: 1–100, default 15.

The established response envelope remains unchanged: list items are under
`data.data`, Laravel pagination is under `data.meta`, and the stable tab
counts are under `data.meta.counts`. Counts are calculated for all applications
owned by the authenticated candidate and are independent of pagination.

Groups are defined as follows: `all` includes every owned application;
`active` excludes `accepted`, `rejected`, and `withdrawn`; `completed` includes
exactly those three terminal states; and `requires_action` is relation-aware.
It includes an unsubmitted latest test assignment while the application is
`test_pending`, a pending/respondable latest information request while the
application is `need_more_information`, or an upcoming scheduled interview
that still requires candidate confirmation while the application is
`interview_scheduled`.

Priority ordering is performed before pagination in SQL. It places overdue
required actions first, then other required actions, upcoming interviews,
upcoming tests, recently changed applications, other active applications, and
terminal applications. Ties use the latest status-history timestamp descending.

### Candidate presentation and safety

Candidate application list/detail resources now expose `requires_action`,
localized `next_action`, `allowed_actions`, `last_status_changed_at`,
`upcoming_event`, a minimal `current_test`, and a minimal
`relevant_interview`. The presentation decisions live in
`ApplicationPageService`, not in the controller or resource. The timeline
continues to omit internal notes and actor ids, and interview summaries omit
evaluation and internal company fields. List relations are eager-loaded with
a fixed query shape; complete test answers, private interview evaluation data,
and internal notes are not loaded.

### Reapplication policy and migrations

The former absolute `(job_posting_id, job_seeker_profile_id)` unique key was
removed by `2026_08_01_000002_allow_terminal_job_reapplications.php` and
replaced with a lookup index. `checkDuplicateApplication()` now rejects only
an existing non-terminal application. The existing transaction and locked job
posting row serialize competing submissions for the same job, so two active
applications cannot pass the check concurrently through the application API.
Reapplication is allowed after `accepted`, `rejected`, or `withdrawn` without
deleting historical data.

### Company logo decision

No prior logo field was present. A focused implementation was added through
`2026_08_01_000003_add_logo_path_to_companies.php`. The existing company
profile update API accepts JPEG, PNG, or WebP images up to 2 MiB, supports
replacement and `remove_logo`, deletes replaced files, and returns `logo_url`
from `CompanyResource`. It reuses Laravel's configured public disk rather than
introducing a media library.

### Tests and client documentation

`ApplicationsPageTest` covers authorization, candidate scoping, search,
groups, status filtering, counts, pagination independence, required-action
types, overdue priority, safe details, validation, and reapplication after
rejected/withdrawn states. `CompanyLogoTest` covers upload, replacement,
deletion, and invalid/empty files. The mobile Postman collection contains All,
Active, Requires Action, Completed, Search, Status Filter, Priority Sorting,
Details, and Withdraw requests. No frontend, commit, or push is included.

### Final verification results

- Complete Laravel suite: **895 passed, 2 expected opt-in S3 skips, 27,203
  assertions, 0 failed**.
- Focused applications, logo, protected-baseline, and recommendation E2E set:
  **15 passed, 10,151 assertions**.
- `php artisan migrate:fresh --seed --force`: passed against an isolated
  temporary SQLite database; all migrations and the full project seeder ran.
- `php artisan route:list --path=api/v1/applications`: passed; the existing
  applications routes remain registered with no new duplicate list endpoint.
- `composer audit`: passed with **no security vulnerability advisories**.
- Laravel Pint on changed PHP files: passed.
- Both modified Postman collection files parse as valid JSON.
- `git diff --check`: passed. No commit or push was created.

## CV Secure Preview and Profile Availability

### Repository state and preserved work

Task 5 started on `master` with a clean working tree at commit `1e4baec`
(`feat: complete CV review confirmation workflow`). The implemented Profile
Page aggregation, completeness/attention contract, single-current-CV contract,
and CV upload/review/comparison/confirmation/cancellation workflow were kept
intact and extended without frontend, application snapshot, avatar, expected
salary, or unrelated application-workflow changes.

### Existing download and secure preview design

CV downloads already used `PrivateFileStorageService::downloadResponse()` to
stream private Local or S3 objects through Laravel. It forces
`Content-Disposition: attachment`; no public or signed storage URL is returned.
Task 5 keeps that behavior and adds:

```http
GET /api/v1/cv/{cvFile}/preview
```

The endpoint accepts only an authenticated job seeker who owns the CV. The CV
must be the confirmed current profile CV or an active pending workflow; an
archived, cancelled, unrelated confirmed legacy, or other user's CV is not
previewable. Other-user IDs remain hidden behind `404`; invalid owner workflow
states return `CV_PREVIEW_FORBIDDEN`.

PDF is streamed unchanged with `Content-Type: application/pdf`, an RFC-safe
`Content-Disposition: inline` filename, `X-Content-Type-Options: nosniff`,
`Cache-Control: private, no-store`, `Pragma: no-cache`, and
`Accept-Ranges: none`. Header filenames remove CR/LF, NUL, control characters,
and path components. Storage disk/path, signed URLs, file bytes, and raw CV text
are never logged or serialized.

DOCX is not converted. A readable DOCX preview returns `415` with
`CV_PREVIEW_NOT_SUPPORTED`, a localized file hint, and `download` as its
allowed action; its download endpoint remains an attachment. A missing object
returns `404 CV_FILE_NOT_FOUND`. An empty, path-invalid, or MIME/extension-
incompatible object returns `422 CV_FILE_UNAVAILABLE`. Backend streaming was
chosen instead of signed URLs to keep authorization and privacy centralized.

Range requests are deliberately not implemented. The private storage layer
supports both Local and S3 Flysystem streams, whose seek/range behavior is not
uniformly guaranteed. Returning a correct full `200` stream with
`Accept-Ranges: none` is safer than advertising partial support on only one
driver. The Range regression test proves that a Range header receives the
complete file, not a false `206` response.

One `cv.previewed` or `cv.downloaded` audit event is recorded per successful
full request with only actor/user/CV IDs. There are no per-byte-range audit
events because byte ranges are not supported.

### CV allowed actions

Actions are calculated once per exposed CV from its trusted MIME/extension,
private-object existence and non-zero size, pending-workflow state, and CV
stage:

- Current readable PDF without a pending update: `preview`, `download`,
  `update`.
- Current readable DOCX: `download`, `update`.
- Current CV while an update is pending omits `update`.
- Processing: `preview`, `download`, `view_status`, `cancel`.
- First/differences review: `preview`, `download`, `review`, `cancel`.
- Final confirmation: `preview`, `download`, `review`, `confirm`, `cancel`.
- Failed: `download`, `cancel`.
- Cancelled pending CVs are not exposed.

The new Profile Page contract replaces ambiguous `view` with executable
`preview`; legacy `CVFileResource` remains unchanged.

### Availability enum, storage, and validation

`JobSeekerAvailabilityStatus` defines `available_now`,
`available_from_date`, and `not_available`. Migration
`2026_08_02_000001_add_availability_to_job_seeker_profiles.php` adds nullable
string `availability_status` and nullable date `available_from` without an
index or backfill. Existing profiles remain unset; the migration supports
`up()` and `down()` on the supported test schema.

`PUT /api/v1/profile` validates the final merged state, not just the submitted
partial payload:

| Status | `available_from` | Result |
| --- | --- | --- |
| `null` | `null` | Valid clear |
| `available_now` | `null` | Valid |
| `available_now` | date | `PROFILE_AVAILABILITY_DATE_NOT_ALLOWED` |
| `available_from_date` | today/future ISO date | Valid |
| `available_from_date` | missing/null | `PROFILE_AVAILABILITY_DATE_REQUIRED` |
| `available_from_date` | past date | `PROFILE_AVAILABILITY_DATE_IN_PAST` |
| `not_available` | `null` | Valid |
| `not_available` | date | `PROFILE_AVAILABILITY_DATE_NOT_ALLOWED` |
| unknown status | any | `PROFILE_AVAILABILITY_STATUS_INVALID` |

Changing only one field is checked against the other stored field. Clearing
both fields together is valid. `available_from` is stored and returned as
`YYYY-MM-DD`; it is a calendar date and is not converted to UTC.

### Profile contract, completeness, and CV interaction

The editable scalar compatibility fields include `availability_status` and
`available_from`. The typed display contract is:

```json
{
  "career_summary": {
    "availability": {
      "status": {"key": "available_from_date", "label": "Available from a date"},
      "available_from": "2026-09-01",
      "display_label": "Available for work from 1 September 2026"
    }
  }
}
```

Arabic and English localize status and display labels; the machine date stays
ISO. An unset value returns null status/date/display label and adds an optional
`availability` item to `profile_completeness.recommended_items`. Availability
does not change weights, percentage, `is_complete`, required missing items, or
attention cards.

The current CV parser/review draft does not produce trustworthy availability
data, so Task 5 does not invent extraction, comparison suggestions, or CV-source
tracking for it. Manual profile values remain outside the reviewed CV draft and
are preserved by initial confirmation, update confirmation, conflict handling,
and cancellation. Employer candidate/application resources were not expanded;
availability is exposed only in the authenticated job-seeker Profile Page.

### API and client examples

```http
PUT /api/v1/profile
Authorization: Bearer {job_seeker_token}
Content-Type: application/json

{"availability_status":"available_from_date","available_from":"2026-09-01"}
```

```http
GET /api/v1/cv/{current_or_pending_cv_id}/preview
Authorization: Bearer {job_seeker_token}
```

Both mobile and web Postman collections add the eight requested preview/error
scenarios and nine availability/localization scenarios using environment
variables. The shared environment adds DOCX, missing, other-user, cancelled,
and future-availability variables. Existing requests were retained.

### Performance, privacy, and verification

Preview authorization loads only the primary pointer and target CV; it does
not load the full profile. Profile Page relations remain eagerly loaded with a
constant query count. Availability labels are pure resource formatting with no
query. File capabilities perform one existence/size inspection per exposed CV
and are reused for all of that CV's actions.

Verification results:

- Complete Laravel suite: **991 passed, 2 expected opt-in integration skips,
  28,746 assertions, 0 failed**. The skips require dedicated ML-container and
  S3 integration environments.
- Focused Task 5, single-current-CV, CV workflow, private storage, and protected
  baseline set: **53 passed, 10,435 assertions** (including S3 fake preview).
- `migrate:fresh --seed --force`: passed on an isolated temporary SQLite file,
  including the Task 5 migration and full demo seed. The temporary file and
  directory were removed. An initial denied `C:\tmp` isolation attempt fell
  back to a generated workspace SQLite file; MySQL was never selected, and the
  generated fallback file was verified and removed before final verification.
- Route checks passed: **17 profile routes** and **20 CV routes**, including
  `GET|HEAD api/v1/cv/{cvFile}/preview`.
- Pint passed for every PHP file changed or added by Task 5. Repository-wide
  `vendor/bin/pint --test` still reports only pre-existing style issues in
  unrelated files; no unrelated reformat was performed.
- `php -l` passed for all **34** changed/new PHP files.
- Both Postman collections and the environment parse as valid JSON. Each
  collection contains all **17** requested Task 5 requests.
- `git diff --check` passed; its only output is Git's existing CRLF-to-LF
  warning for the three Postman JSON files.
- `composer audit --locked --no-interaction` did **not** complete: the Composer
  cache directory is not writable and the sandbox could not connect to
  `repo.packagist.org:443` (`curl error 7`). No dependency metadata was sent via
  an escalated or unapproved network path, so audit success is not claimed.

Manual verification is represented by the executable feature scenarios for
current/pending PDF streaming, DOCX rejection/download, owner/other-user/role
authorization, missing/empty objects, all availability states, merged partial
validation, localization, and CV confirmation/cancellation preservation. No
commit or push is created by this task.

## CV Update Review and Confirmation Workflow

### Repository baseline and preserved contracts

Task 4 started on `master` with a clean working tree and four local commits ahead
of `github/master`. The existing Profile Page aggregation, Profile Completeness
and Attention Items, Single Current CV contract, and MySQL reapplication-migration
fix were treated as the baseline. The implementation preserves the rule that
`current_cv` is exactly the confirmed CV referenced by
`job_seeker_profiles.primary_cv_file_id`, while `pending_cv_update` is a separate,
single, unconfirmed workflow.

The previous review implementation already provided editable initial drafts,
profile-difference suggestions, reversible decisions, source columns, audit
logging, and confirmed-CV application validation. Task 4 completes and tightens
that lifecycle instead of introducing a parallel CV subsystem.

### Corrected operation detection and pending uniqueness

`CandidateCVOperationResolver` classifies an upload as `initial_upload` only when
the candidate has neither a valid current CV nor meaningful professional profile
data. Name and email alone do not make an update. Headline, summary, phone,
location/city, professional links, experience, education, skills, or a valid
current CV classify the workflow as `update`. The operation is persisted in the
existing `review_mode` field (`initial_import` or `profile_sync`) so later API
responses do not recalculate a different operation.

Upload locks the job-seeker profile row and checks for an owned, unconfirmed,
unarchived, non-cancelled CV before creating another. A duplicate attempt returns
`409 CV_PENDING_UPDATE_EXISTS` with the pending CV ID and stage. The supporting
`cv_active_workflow_idx` index keeps that lookup bounded; the transactional lock
is the cross-database uniqueness authority. Upload and parsing never modify the
profile or `primary_cv_file_id`, including when legacy clients send
`make_primary=true`.

### Parsed data, drafts, and comparison base

`raw_text` and `parsed_json` remain private parsing artifacts. They are not
returned by the review/final-preview resources. `reviewed_json` is the mutable,
normalized draft containing only supported profile fields, experiences,
education, and skills. `system_generated_review_json` is a private reference copy
used to distinguish accepted CV data from user-authored final edits.
`comparison_base_json`, `comparison_profile_hash`, and
`comparison_profile_updated_at` capture the exact profile state used for update
comparison. `final_approved_json` records the validated draft actually committed.

For an initial upload, parsing creates the first-review draft. The owner can edit
profile fields, add/update/remove experience and education, and add/remove skills
through `PATCH /api/v1/cv/{cvFile}/review`. Validation covers allowed fields,
types, URLs, dates, current-job end dates, duplicate IDs/items, skill duplicates,
and relationship ownership. `POST .../ready-for-confirmation` validates the
draft and advances initial review to final confirmation. No profile record is
written before confirm.

### Update comparison and decision contract

An update compares parsed data with the captured profile snapshot and generates
`ADD`, `UPDATE`, `MERGE`, and `IGNORE` suggestions. Manual values are the default:
rejecting a proposal means keep current, while accepting chooses the proposal;
an accepted proposal can carry a validated `edited_value`. Suggestion responses
expose current/proposed/selected values, allowed decisions, and presentation
state, but omit owner IDs, internal confidence, reasons, raw text, and storage
metadata.

Suggestion decisions only update review state. They never mutate the profile.
All non-IGNORE suggestions must be resolved before the workflow becomes ready.
When decisions are complete, the backend materializes the true final draft from
the comparison base and accepted values. If the profile hash differs at confirm,
the API returns `409 CV_PROFILE_CHANGED_SINCE_COMPARISON`; regenerating suggestions
rebuilds the comparison safely from the current profile.

### Final preview, edit, and atomic confirmation

`GET /api/v1/cv/{cvFile}/final-preview` reuses the sanitized review contract and
returns `final_profile`, validation state, allowed actions, and counts for actual
`added`, `updated`, `merged`, `removed`, and `unchanged` values. Counts compare the
final draft with the captured base, so a last-minute explicit removal is visible
even when it was not a parser suggestion. A ready initial or update draft remains
editable with `PATCH .../review`; invalid or corrupt stored drafts cannot confirm.

Final confirmation locks the profile and CV rows in one short database
transaction. It rechecks ownership, pending/archived/cancelled state, parsing and
review readiness, unresolved decisions, profile snapshot hash, draft shape, and
relationship IDs. It then updates supported profile fields, synchronizes
experiences, education, and skills, stores the final approved draft, marks the CV
confirmed/applied, and switches `primary_cv_file_id`. Any late failure rolls back
all profile, relationship, suggestion, CV, and current-pointer writes.

For update drafts, omission from the final full list is the user's explicit
deletion decision. Existing relationship IDs must belong to the locked profile;
new records are inserted once; unchanged records and skill pivots retain their
metadata. Skills use one bulk insert-ignore plus a bounded fetch and sync, rather
than one query per skill. Initial upload does not delete pre-existing professional
data because such data would have classified the operation as an update.

Source policy is explicit. An unchanged retained manual record/pivot stays
`manual` with its existing metadata. A relationship or skill accepted unchanged
from the system-generated CV draft becomes `cv_confirmed` with the confirmed CV
ID and verification time. A value added or changed manually in the editable
review draft is stored as `manual`, with no source CV ID, and with confirmation as
its verification time. The schema has no per-field source columns for scalar
profile fields, so source attribution applies to the existing experience,
education, and skill-pivot source contract.

After confirmation, the old current CV row and private storage object remain
intact for legacy application references; only the current pointer changes. The
new CV becomes usable for applications only after confirmation. Pending,
cancelled, archived, failed, unavailable, foreign, and otherwise unconfirmed CVs
remain blocked by both make-primary and application validation.

Confirmation is idempotent for the new current CV: a retry returns success with
`already_confirmed=true` and does not duplicate relationships, source metadata,
or audit events. A confirmed CV that is no longer current is not silently
re-promoted by the confirmation endpoint. Row locks and idempotent relationship
sync also protect concurrent retries.

### Cancellation, errors, audit, and notifications

`POST /api/v1/cv/{cvFile}/cancel` is owner/job-seeker only. It can close processing,
first-review, differences-review, or final-confirmation workflows. It rejects a
current/confirmed CV, records `cancelled_at`, changes the review status to
`cancelled`, rejects unapplied suggestions, clears transient comparison and draft
artifacts, leaves profile/current CV unchanged, and returns
`pending_cv_update=null`. Retry is safe and reports `already_cancelled=true`.
Private files are retained under the repository's audit/retention policy and are
hidden from user history/current/pending contracts. The parsing job checks active
pending state both before expensive parsing and again before saving, so a job that
finishes after cancellation produces no draft, suggestions, success event, or
misleading failure.

Stable localized lifecycle errors include:

- `CV_PENDING_UPDATE_EXISTS`, `CV_NOT_PENDING`, `CV_REVIEW_NOT_READY`,
  `CV_REVIEW_HAS_UNRESOLVED_CHANGES`, and `CV_FINAL_DRAFT_INVALID`.
- `CV_PROFILE_CHANGED_SINCE_COMPARISON`, `CV_ALREADY_CONFIRMED`,
  `CV_CANNOT_CANCEL_CURRENT`, `CV_ALREADY_CANCELLED`, and
  `CV_CONFIRMATION_CONFLICT`.

Existing ownership, archive, storage, and application error codes remain in use
where they are more specific. English and Arabic translations cover operations,
stages, decisions, actions, success messages, statuses, validation, and errors.

Audited events are `cv.uploaded`, `cv.parsing_completed`,
`cv.review_draft_updated`, `cv.suggestion_decision_updated`,
`cv.ready_for_confirmation`, `cv.confirmed`, `cv.cancelled`,
`profile.updated_from_cv`, and `cv.current_changed`. Audit metadata contains IDs,
operation, counts, and pointer changes—not raw CV text, parsed JSON, full drafts,
AI prompts, or reasoning. No CV lifecycle notifications existed in the repository,
so this task deliberately did not introduce a new notification subsystem.

### API and client contracts

The final lifecycle endpoints are:

- `POST /api/v1/cv/upload`
- `GET /api/v1/cv/{cvFile}/review`
- `PATCH /api/v1/cv/{cvFile}/review`
- `POST /api/v1/cv/{cvFile}/ready-for-confirmation`
- `GET /api/v1/cv/{cvFile}/suggestions`
- the existing accept/reject suggestion endpoints
- `GET /api/v1/cv/{cvFile}/final-preview`
- `POST /api/v1/cv/{cvFile}/confirm`
- `POST /api/v1/cv/{cvFile}/cancel`

Legacy `PUT .../review-draft` remains as an alias. Legacy
`POST .../suggestions/apply` delegates to the same atomic finalization service and
cannot bypass final-draft validation or current-CV promotion rules.

Both mobile and web Postman collections now contain ordered Initial Upload,
Update, and Cancel/Error scenario folders, including all requested `CV Flow - ...`
requests. The shared environment adds pending and per-decision suggestion IDs.

All endpoints require an active Sanctum user and authorize job seekers only.
Guest requests return 401, employer/admin requests return 403, owners succeed,
and another job seeker receives the existing ownership denial. Public resources
do not expose raw text, storage path/disk, comparison snapshots, system-generated
reference drafts, internal confidence/reasons, or another user's suggestions.

### Migration, files, performance, and tests

Migration `2026_08_01_000005_add_cv_workflow_state.php` adds cancellation and
comparison state, the active-workflow lookup index, the private comparison/system
drafts, and the final-approved draft. New services isolate operation detection,
snapshot hashing, final-draft building and summarization, validation, relationship
application, and atomic finalization. Existing CV models, resources, controller,
job, services, routes, translations, profile aggregation/attention code, Postman
collections, and focused tests were updated. No frontend, PDF preview, application
snapshot, commit, or push was created.

Profile aggregation loads one current CV, one pending CV, and only that pending
workflow's suggestions. Existing query-count regression coverage proves old CVs
and old suggestions do not grow the profile query count. Final-preview comparison
is in-memory over the bounded draft, and confirmation bulk-resolves skills.

Focused Task 4 and CV regression verification passes **64 tests with 697
assertions**. It covers corrected operation detection, pending uniqueness,
initial review editing/validation, all suggestion types and decisions, stale
profile conflict/rebuild, actual final-preview counts, explicit relationship
removal, source provenance, atomic rollback, current promotion/retention,
idempotency, cancellation, cancelled parsing, application safety, authorization,
privacy, localization contracts, and prior CV/Profile contracts.

Final verification results for the repository state after Task 4 are:

- Complete Laravel suite: **973 passed, 2 expected opt-in S3 skips, 28,532
  assertions, 0 failed**.
- Protected Phase 17 recommendation baseline and final handover documentation
  checks pass after explicitly allowlisting only the intentional Task 4 files.
- `php artisan migrate:fresh --seed --force` passes on a unique temporary SQLite
  database inside `storage/framework/testing`; the database is removed afterward.
- CV and Profile route inspection passes with **19 CV routes** and **17 Profile
  routes**, including final preview, ready, confirm, cancel, and suggestion routes.
- `php -l` passes for every changed/new PHP file, and Pint passes for every PHP
  file changed by Task 4. Repository-wide Pint still reports pre-existing style
  findings in unrelated files; Task 4 does not reformat them.
- Both Postman collections and the shared environment parse as valid JSON. Each
  collection contains all 18 requested named scenarios plus 3 supporting
  ready/edit requests in the new workflow folder.
- `git diff --check` passes (Git emits only the existing CRLF/LF normalization
  notices for the three Postman JSON files).
- `composer audit --no-interaction` could not complete: the Composer cache path
  was not writable and the environment could not connect to
  `repo.packagist.org:443`. An elevated retry was not authorized because it would
  disclose dependency metadata (potentially including private package names) to
  Packagist without explicit approval. No claim is made that dependencies are
  advisory-free.
- No commit or push was created.

Manual verification order is: initial upload → first review/edit → ready → final
preview → confirm/retry; profile-data update without current CV → resolve
differences → preview → confirm; current-CV update → verify old current remains
active until confirm; modify profile after comparison → verify conflict/rebuild;
cancel at each pending stage; and attempt an application with a pending/cancelled
CV to verify rejection.

## Single Current CV Contract

### Starting state and preserved work

Task 3 started on a clean `master` working tree, two local commits ahead of
`github/master`. The Profile Page aggregation from Task 1 and the centralized
completeness/attention implementation from Task 2 were retained and extended.
No frontend, migration, PDF preview, cancel-update flow, application snapshot,
or suggestion comparison/decision redesign was introduced.

The pre-existing CV API manages multiple records through list, metadata,
make-primary, archive, restore, download, parsed result, review draft,
confirmation, and suggestion endpoints. It stores parsing state as
`uploaded`, `processing`, `parsed`, or `failed`; review mode as
`initial_import` or `profile_sync`; and review status as `draft`,
`comparison_pending`, `decisions_pending`, `ready_to_apply`, or `applied`.
`primary_cv_file_id` is the existing authoritative pointer.

### Current and pending selection

`CurrentCVResolver` returns only `primaryCVFile`, and only when it belongs to
the authenticated user, is confirmed, parsed, unarchived, has usable storage
metadata, and passes `CVFile::isUsableForApplication()`. It intentionally does
not fall back to another confirmed row when the pointer is corrupt or invalid;
the API returns `current_cv: null` and completeness reports the confirmed-CV
requirement as missing.

The pending state is the newest owned CV whose `confirmed_at` and
`archived_at` are null. A constrained `ofMany` relation orders by
`created_at DESC`, then `id DESC`, so at most one pending object is exposed.
Older legacy pending files remain stored and are neither returned nor deleted.
Current and pending resolution are independent: a processing/reviewing upload
does not replace the existing current CV. Confirmation promotes the newly
confirmed CV to `primary_cv_file_id` inside the existing confirmation
transaction; the comparison, draft, decisions, and confirmation response
flows themselves are unchanged.

### Stage, operation, progress, and actions

`CVStageResolver` is the single mapper used by both the pending contract and
Task 2 attention cards. Its deterministic mapping is:

- valid confirmed CV → `confirmed`;
- `failed` parsing status → `failed`;
- `uploaded` or `processing` → `processing`;
- parsed `initial_import` + `draft` → `first_review`;
- parsed `profile_sync` + comparison/decision state, or pending suggestion
  count → `differences_review`;
- parsed `ready_to_apply` (or inconsistent applied-without-confirmation legacy
  state) → `final_confirmation`.

Confirmed state wins over stale review fields. Final confirmation is evaluated
before pending differences, so a stale suggestion count cannot downgrade it.
Unknown transitional values map conservatively to processing without exposing
an actionable review button.

Operation is `initial_upload` when no valid current CV exists and `update` when
one does. Progress is derived, not stored: upload is complete for a persisted
CV, text extraction is true only when the minimal parsing-result relation
exists, parsing is complete only for `parsed`, and review is complete when the
workflow has reached final confirmation.

Next actions are typed as `wait_for_processing` (non-actionable),
`review_extracted_cv`, `review_cv_changes`, `confirm_cv_review`, or `upload_cv`.
Current allowed actions are `view`, `download`, and `update`; view means the
existing CV metadata endpoint, not inline PDF preview. Pending allowed actions
are `view_status`, `review`, or `confirm` according to stage. Cancel is not
exposed.

### Profile contract and consistency

`GET /api/v1/profile` now adds `current_cv` and `pending_cv_update`; both are
null when absent. The current resource contains safe file metadata, localized
confirmed stage, timestamps, confirmed usability, and allowed actions. It does
not expose `version_label`, primary/archive capabilities, archived state,
storage path, disk, or parsing payloads. The pending resource contains safe
metadata, localized operation/stage, derived progress, localized typed next
action, `can_use_for_application: false`, allowed actions, and timestamps.

Completeness now evaluates the same strict primary CV resolved as current, so
`confirmed_cv` is complete if and only if `current_cv` is valid. Attention uses
the same stage mapper as `pending_cv_update`, preventing failed, processing,
first-review, differences, or final-confirmation contradictions. A valid
current CV stays usable and keeps completeness unchanged while a pending update
is reviewed.

The application workflow had allowed real unconfirmed workflow CVs because the
legacy usability helper validates only storage availability. A narrow guard
now rejects uploaded/processing/reviewing pending CVs with
`CV_NOT_USABLE_FOR_APPLICATION`. Parsed legacy records with no review metadata
retain backward compatibility. This is the only application change and is
required to enforce the pending contract.

### Legacy compatibility, privacy, localization, and performance

The existing `GET /cv`, metadata, make-primary, archive, restore, download,
parsed, review, confirm, and suggestion routes and their response resources are
unchanged. Multiple-version and primary/archive endpoints are retained as
legacy APIs, but are deprecated for the new mobile/web Profile UI. No HTTP
deprecation headers were added.

English and Arabic translations cover all candidate stages, initial/update
operations, next actions, and allowed-action concepts. The Profile endpoint
remains job-seeker-only: guests receive 401 and employer/admin users receive
403. Current/pending queries are user-scoped and never serialize raw text,
parsed/reviewed JSON, internal parsing errors, confidence, suggestion content,
storage paths, disks, archived files, old files, or another user's IDs.

Only the primary CV, newest pending CV, a minimal parsing-result projection,
and a pending non-ignore suggestion count are eager-loaded. No CV history,
parsed JSON, raw text, or suggestion collection is loaded. Query-count tests
enforce a maximum of 16 Profile queries and no growth beyond a one-query
tolerance when expanding from one state to 20 old CVs and 20 suggestions.

### Files, Postman, and verification

New runtime components are `CandidateCVStage`, `CandidateCVOperation`,
`CurrentCVResolver`, `CVStageResolver`, `CandidateCVStateResolver`,
`CurrentCVResource`, and `PendingCVUpdateResource`. Profile data/service/resource,
the CV and profile models, completeness, attention, Home loading, application
CV validation, and the two existing confirmation services were updated. New
coverage is provided by `CVStageResolverTest` and
`SingleCurrentCVContractTest`; Task 2 fixtures were aligned with the now
authoritative primary pointer.

Both Postman Profile folders retain all Task 1 and Task 2 requests and add the
nine requested Task 3 examples: no CV, current only, current plus processing
update, first review, differences, final confirmation, failed, Arabic, and
English contracts. No specialized `/cv/current-state` endpoint was added
because Profile already loads the complete page state and no standalone reload
consumer currently requires it.

### Verification results

- Complete Laravel suite: **963 passed, 2 expected opt-in S3 skips, 28,233
  assertions, 0 failed**.
- New Task 3 stage/contract suite: **18 passed, 160 assertions**.
- Focused Profile, Home, CV, Applications, and Activity regressions: **205
  passed, 1,746 assertions**.
- Protected Phase 17/18 baseline checks: **7 passed, 10,073 assertions** after
  approving only the intentional `CVFile` invariant change in their existing
  allowlists.
- `php artisan route:list --path=api/v1/profile`: passed and shows the existing
  17 Profile routes; no new page endpoint was introduced.
- `php artisan route:list --path=api/v1/cv`: passed and shows all 15 existing CV
  routes, including legacy primary/archive/restore endpoints.
- `php artisan migrate:fresh --seed --force`: passed on an isolated temporary
  SQLite database, which was removed afterward. No migration was added.
- Laravel Pint on every changed/new PHP file: passed. Repository-wide
  `vendor/bin/pint --test` still reports pre-existing style violations in
  unrelated files; they were not reformatted.
- `php -l`: passed for every changed/new PHP file.
- Both Postman collections parse as valid JSON and contain exactly nine Task 3
  Profile requests each.
- `git diff --check`: passed; Git emitted only the existing Postman CRLF-to-LF
  working-copy warnings.
- `composer audit`: unavailable. Composer could not write its external cache
  and the connection to `repo.packagist.org:443` was blocked. No dependency
  vulnerability-status claim is made.

No commit or push was created for Task 3.

## Profile Completeness and Attention Items

### Scope and preserved Profile Page work

The repository started this task on `master`, one local commit ahead of
`github/master`, with a clean working tree. The existing Task 1 Profile Page
aggregation was preserved: `ProfilePageService`, `ProfilePageResource`, the
experience calculator, the expanded `GET /api/v1/profile` response, and the
transactional name support in `PUT /api/v1/profile` remain the foundation of
the implementation. No frontend, migration, CV lifecycle endpoint, Current CV
contract, archive/restore/primary behavior, or upload/confirmation flow was
changed.

The existing `ProfileCompletenessService`, already used by Home, defined seven
required groups totaling 100%. It was extended instead of creating a second
calculator. Its compact Home response remains backward compatible, including
the legacy `confirmed_primary_cv` Home key, while the Profile Page uses the
neutral `confirmed_cv` key and a richer contract. A regression test verifies
that Home and Profile return the same percentage.

### Completeness definition and contract

The retained weights are basic information 15, professional profile 15,
location 10, experience 20, education 15, skills 15, and confirmed CV 10.
Basic information requires user name, email, and profile phone; professional
profile requires both headline and summary; location accepts `city_id` or the
legacy text value; experience and education require at least one record; and
skills require at least three records.

A confirmed CV must belong to the user, be confirmed, unarchived, parsed, and
pass `CVFile::isUsableForApplication()`. The newest usable confirmed CV is
resolved independently from the newest unconfirmed CV. Consequently, uploading
a newer CV for processing or review does not remove the 10% already earned by
an older valid confirmed CV. Failed or still-processing CVs are never counted
as complete.

GitHub, LinkedIn, and portfolio links are optional. Missing links appear under
`recommended_items` with `required: false`; they do not change the percentage
or `is_complete`. The response now includes integer `percentage`,
`is_complete`, completed and missing counts, typed completed and missing item
lists, optional recommendations, and the first required `next_item`.

Missing items use the deterministic order: basic information, professional
headline, professional summary, location, experience, education, skills, then
confirmed CV. Headline and summary are returned separately when either part of
the 15% professional group is absent.

### Attention resolution

`ProfileAttentionResolver` produces at most one most-specific CV item for the
latest owned, unconfirmed, unarchived CV, plus at most one profile-incomplete
item. Items are deduplicated by `attention_key` and sorted by numeric priority,
then the CV update timestamp and ID for stable ties.

Supported types and priorities are:

- `cv_processing_failed` (110): latest CV status is `failed`; exposes only a
  safe `upload_cv` action.
- `cv_differences_review_required` (100): parsed `profile_sync` review is in
  comparison/decision state or has pending non-ignore suggestions; exposes
  `review_cv_changes` and only the aggregate `changes_count`.
- `cv_first_review_required` (95): parsed `initial_import` review is still a
  draft; exposes `review_extracted_cv`.
- `cv_final_confirmation_required` (92): parsed `profile_sync` review is
  `ready_to_apply` but unconfirmed; exposes `confirm_cv_review`.
- `cv_processing` (90): latest CV status is `uploaded` or `processing`; no
  premature review action is returned.
- `profile_incomplete` (40): one card based on completeness, whose
  `complete_profile` action reuses `next_item.target`.

The serialized action targets use only `cv_upload`, `cv`, `cv_review`, and
`profile_section`; no frontend routes are embedded. `cv_missing` was
intentionally not added because the single `profile_incomplete` card already
covers a missing confirmed CV without duplicate content.

### Data loading, privacy, localization, and API

`ProfilePageService` passes its already-loaded profile to both services. The
user, city, experiences, education, skills, newest confirmed CV, and newest
unconfirmed CV are eager-loaded in bounded queries. Both latest-CV relations
use constrained `ofMany` subqueries so a newer ineligible row cannot hide an
older eligible one. CV eager loads select only state and identity columns;
pending suggestions are counted with one scoped `withCount` subquery and their
payloads are not loaded. Existing query-count coverage confirms that the
number of Profile queries remains constant as nested rows grow. Task 2 also
asserts a maximum of 15 queries and no growth beyond a one-query tolerance when
pending suggestions increase from one to twenty.

`GET /api/v1/profile` remains the only page-loading endpoint and is still
restricted to job seekers (guest 401; employer/admin 403). Its existing data is
unchanged and now also returns `profile_completeness` and `attention_items`.
Resources serialize localized typed values only. Parsed JSON, full CV text,
parsing exceptions, suggestion values/reasons, confidence scores, and data or
IDs belonging to another user are not returned.

English and Arabic translations cover completeness items and recommendations,
all six attention types, titles, descriptions, info/warning/error severities,
and all five actions. Feature coverage exercises both `Accept-Language: en`
and `Accept-Language: ar`.

### Files and client examples

New runtime files are the three Profile Attention enums,
`ProfileAttentionResolver`, and `ProfileAttentionItemResource`. New automated
coverage is in `ProfilePageCompletenessServiceTest`,
`ProfileAttentionResolverTest`, and `ProfileCompletenessAttentionTest`.
Modified runtime files are `ProfileCompletenessService`, `HomeService`,
`JobSeekerProfile`, `ProfilePageService`, `ProfilePageData`, and
`ProfilePageResource`, plus the English and Arabic profile translations.

Both mobile and web Postman collections remain valid JSON and retain all Task
1 requests. Each Profile folder now also contains the nine requested Task 2
requests for complete/incomplete profiles, all five CV attention stages, and
Arabic/English completeness.

### Verification

- Complete Laravel suite: **945 passed, 2 expected opt-in S3 skips, 27,957
  assertions, 0 failed**.
- New Task 2 unit/feature set: **17 passed, 141 assertions**.
- Task 2 plus existing Profile Page and Home focused regression set: **27 passed, 209
  assertions** after the final eager-load optimization.
- `php artisan route:list --path=api/v1/profile`: passed; the existing Profile
  routes remain registered and no completeness/attention endpoint was added.
- `php artisan migrate:fresh --seed --force`: passed against an isolated
  temporary SQLite database, which was removed afterward.
- Laravel Pint on every PHP file changed or added for Task 2: passed. The
  repository-wide `vendor/bin/pint --test` still reports pre-existing style
  violations in unrelated files; they were intentionally not reformatted.
- `php -l`: passed for every changed/new PHP file.
- Both Postman collections parse successfully and contain exactly nine new
  Task 2 Profile requests each.
- `git diff --check`: passed (Git emitted only its existing CRLF-to-LF warning
  for the two Postman working-copy files).
- `composer audit`: unavailable. Composer could not write its external cache
  and network access to `repo.packagist.org:443` was blocked, so no claim about
  dependency vulnerability status is made.

Manual verification can use a job-seeker token with `GET /api/v1/profile` and
switch `Accept-Language` between `ar` and `en`. Seed or select each documented
CV review state to inspect the corresponding single CV attention card, and
compare `data.profile_completeness.percentage` with `GET /api/v1/home`. No
optional `cv_missing` card was implemented for the deduplication reason above;
all required scope is implemented. No commit or push was created.

## Profile Page Backend Aggregation

### Goal and existing implementation

`GET /api/v1/profile` and `PUT /api/v1/profile` remain the only page-level
profile endpoints. Previously they returned the shared
`JobSeekerProfileResource`, which exposes the profile model with loaded user,
city, experiences, education, and skills. That resource is also used by CV
review, applications, matching, ranked candidates, authentication, and profile
skill operations, so changing its contract would create unrelated regressions.

The page endpoints now use the dedicated `ProfilePageResource` backed by
`ProfilePageService` and `ProfilePageData`. The shared resource and all CV
versioning, confirmation, preview, completeness, attention-item, application
snapshot, and frontend behavior remain unchanged. Existing editable scalar
profile keys and the nested user remain at the top level for backward
compatibility while the
canonical page sections are `identity`, `career_summary`,
`professional_profile`, `experiences`, `education`, `skills`,
`professional_links`, and `allowed_actions`.

### Identity and initials

Identity reads `id`, `name`, and `email` from the authenticated `User`; all
other identity fields come from its `JobSeekerProfile`. The city continues to
use the localized `CityResource`. `NameInitials` derives at most two Unicode
initials at response time, collapses repeated whitespace, supports Arabic and
Latin names, and returns `?` for a missing name. It does not persist initials
or add an avatar column.

### Career summary and experience overlap

`ProfileExperienceCalculator` follows the established candidate-duration
approach while preserving the matching calculator unchanged. It turns valid
experience start/end dates into sorted intervals, substitutes today for a
current role, merges overlapping and one-day-connected periods, ignores
missing starts and reversed intervals, sums the merged duration, and rounds
the result to one decimal.
Counts come from the already eager-loaded collections, including the filtered
professional-link list, and therefore do not issue per-section count queries.

Experiences are ordered with current roles first, then end date, start date,
and ID descending. Education is ordered by end date, start date, and ID
descending because the current schema has no `is_current` or expected
graduation field. Skills are ordered by name and ID ascending; the pivot's
unique key prevents duplicates. Page-specific nested resources expose the
user-facing source and verification fields but omit internal CV file IDs.

### Professional links, actions, and localization

Professional links are derived from existing `github_url`, `linkedin_url`, and
`portfolio_url` fields in that fixed order. Empty values are omitted, stored
URLs are returned unchanged, and labels come from Arabic/English translation
files. `ProfileAction` contains only operations backed by current APIs: edit
profile, manage experiences, education, skills, and professional links. The
job-seeker authorization already enforced by the form requests is retained;
guest requests receive 401 and employer/admin requests receive 403.

### Transactional update and audit

`UpdateJobSeekerProfileRequest` now accepts an optional, filled string `name`
up to 255 characters alongside the existing nullable profile fields and active
Syrian-city validation. `ProfileService` separates `name` from profile data and
updates `users` plus `job_seeker_profiles` within one database transaction.
Email, role, and status are never accepted. A single `profile.updated` audit
record captures changed submitted fields, and the response is reloaded through
the same full aggregation path used by GET.

### Performance and API contract

`ProfilePageService` performs one profile query plus bounded eager-load queries
for user, city, experiences, education, and skills/pivot. No query is issued
per experience, education record, skill, or professional link; the query-count
feature test verifies that growing the experience collection does not grow the
request query count.

Both successful endpoints retain the project's `success`, `message`, and
`data` envelope. The canonical `data` contract is:

```text
identity
career_summary
professional_profile
experiences
education
skills
professional_links
allowed_actions
created_at
updated_at
```

The mobile and web Postman collections include aggregated GET, Arabic GET,
English GET, update-name, update-professional-information, clear-nullable-link,
and validation-error requests. No old request was removed.

### Tests and verification

Focused unit coverage includes Unicode initials and nine duration scenarios:
empty input, completed/current roles, overlapping/non-overlapping/connected
periods, missing starts, reversed dates, and one-decimal profile rounding.
Feature coverage includes the complete aggregated contract, ordering, counts,
localized links/messages, hidden internal fields, full update response,
transaction-safe validation, nullable clearing, audit logging, guest/employer/
admin authorization, and bounded query count. Final command results are
as follows:

- Focused Profile unit/feature coverage: **23 passed, 84 assertions**.
- Complete Laravel suite: **928 passed, 2 expected opt-in S3 skips, 27,588
  assertions, 0 failed**.
- Protected final-handover and recommendation E2E checks: **7 passed, 10,077
  assertions** without changing protected tests or matching contracts.
- `php artisan migrate:fresh --seed --force`: passed against an isolated
  temporary SQLite database; the configured remote Aiven database was not
  modified and the temporary file was removed.
- `php artisan route:list --path=api/v1/profile`: passed and confirms the
  existing GET/PUT page endpoints without a duplicate page/dashboard route.
- Manual temporary-server checks passed for English GET, Arabic GET, and an
  English PUT that changed both name and headline and returned the full page.
- Laravel Pint passed for every PHP file changed or added by this task. The
  repository-wide `vendor/bin/pint --test` still reports pre-existing style
  violations in unrelated files, which were not reformatted.
- `php -l` passed for every changed/new PHP file.
- Both Postman collections parse as valid JSON and each contains the seven
  requested Profile Page requests; no old request was removed.
- `git diff --check` passed.
- `composer audit` could not reach Packagist inside the restricted environment.
  An external-network escalation was rejected because dependency metadata
  would be sent to Packagist, so no advisory result is claimed.

## Activity Page Backend Aggregation (2026-08-01)

### Goal and baseline

The mobile and web Activity page now use one job-seeker endpoint,
`GET /api/v1/activity`, instead of merging applications, tests, interviews,
information requests, and notifications in the client. Work started on branch
`master` with a clean working tree. The existing Applications page expansion
was already present in the repository and was preserved; no frontend files,
commit, or push are part of this implementation.

### Existing implementation reused

The aggregation reuses Sanctum, `user.active`, `ApiResponse`, the current
notification read/count endpoints, `ApplicationPageService`,
`HomeActionResolver`, `TestAttemptTimingService`, interview lifecycle rules,
`ApplicationInformationRequest::canBeRespondedTo()`, and the existing
idempotent event listeners. `CandidateActionResolver` is the small shared
classification layer used by Applications, Home, and Activity, avoiding a
second copy of the candidate action rules. No `activity_events` table or
parallel read-state API was added.

### Endpoint and contract

`GET /api/v1/activity` is inside `auth:sanctum` and `user.active`; its Form
Request authorizes `job_seeker` only. It accepts `search`, `group`, `type[]`,
`sort_by`, `sort_direction`, `date_from`, `date_to`, `timezone`, `per_page`,
`page`, and `schedule_limit`. `per_page` is 1–100, `schedule_limit` is 1–20,
and the default schedule limit is 5. The stable response sections are:
`summary`, `upcoming_schedule`, `requires_action`, and paginated `feed`.

Activity types are the public enum values `test`, `interview`,
`information_request`, `application_status`, `application_reminder`, and
`final_decision`. Actions use typed targets and the values `start_test`,
`continue_test`, `submit_information`, `confirm_interview`, `view_interview`,
`view_application`, and `view_test_result`; backend or frontend route strings
are not returned.

### Groups, dates, and timezone

- `all` returns current candidate attention items, the upcoming schedule, the
  historical notification feed, and summary counts.
- `requires_action` returns direct current actions and their related schedule;
  its historical `feed.data` is intentionally empty.
- `today` matches an item's occurrence, start, or due time against the local
  calendar day.
- `this_week` uses Monday 00:00 through Sunday 23:59:59 in the requested IANA
  timezone.

The timezone defaults to `config('app.timezone')` and affects calendar-window
calculation only. Response timestamps remain ISO 8601. Date-range boundaries
are interpreted in that timezone and converted to UTC for database queries.

### Current actions and schedule

Current action queries always start from the authenticated user's
`JobSeekerProfile`. The latest non-superseded, unsubmitted test assignment is
classified as Start or Continue from its latest attempt and effective
deadline. Scheduled/rescheduled future interviews require confirmation only
while `confirmed_at` is null. Pending information requests use their response,
status, due date, and `canBeRespondedTo()` rule. Overdue items never receive a
forbidden mutation action.

The schedule combines test/effective deadlines, interview starts, and pending
information-request due dates. Submitted tests, superseded retakes, completed
or cancelled interviews, and responded/cancelled information requests are
excluded. Items are deduplicated by `activity_key`, sorted by the nearest time,
and limited after the three bounded SQL queries are merged.

Priority is deterministic: overdue direct attention, due within 24 hours,
unconfirmed interviews, in-progress tests, new tests, information requests,
and then historical updates. Paginated feed priority/occurrence/due ordering is
applied in SQL before pagination, with driver-specific JSON expressions for
SQLite, PostgreSQL, and MySQL-compatible connections.

### Feed, summary, deduplication, and read state

Notifications remain the historical feed. New notifications are augmented in
`NotificationService` with `activity_version`, stable `activity_key`, safe
application/job/company identifiers and labels, `resource_type`,
`resource_id`, `activity_type`, `action_type`, and `occurred_at`, while all old
payload keys remain intact. Legacy notifications without this contract use
safe title/message and known ID-key fallbacks; IDs are never parsed from prose.

`requires_action` and `upcoming_schedule` come only from current domain
entities, while `feed` comes only from notifications. This prevents a
notification from becoming a duplicate current action. Deduplication within a
section uses `activity_key`. Notification `is_read`/`read_at` are independent
from `requires_action`; clients continue to use the existing notification read
endpoints.

Summary counts are candidate-scoped and independent of feed pagination. In
this contract, `all` is the filtered historical feed plus filtered current
attention items. `today` and `this_week` use the same definition with their
calendar windows. Type counts use the same two sources. `unread_notifications`
is the user's authoritative unread notification count.

### Search, privacy, and performance

Search is pushed into SQL for notification title/message/structured job and
company fields and for domain job title, company, location, and Arabic/English
city names; information-request message is included where applicable. All
domain relations are eager-loaded in bounded batches. Feed application context
is hydrated in one candidate-scoped query, avoiding per-item queries. Internal
application notes, actor IDs, interview evaluation/private notes, test reviewer
notes, and internal rejection reasons are neither queried for presentation nor
serialized.

Existing indexes already cover notification user/date and read state,
application status history, assignment deadlines and retake lookup, attempt
lookup/effective deadline, interview application/schedule, and information
request application/status and due date. Migration
`2026_08_01_000004_add_activity_feed_notification_index.php` adds only the
missing `(user_id, type, created_at)` feed-filter index.

### Localization, clients, and tests

English and Arabic translations cover the API message, validation, Activity
types, actions, and generated current-item titles. Both mobile and web Postman
collections contain All, Requires Action, Today, This Week, Tests, Interviews,
Information Requests, Search, Date Range, Arabic, and English requests plus a
saved response example.

`ActivityPageTest` covers guest/employer authorization, job-seeker access,
validation, candidate scoping, unified actions/schedule/feed/summary,
Start/Continue Test, interview confirmation, information requests,
deduplication, read state, groups, search, type filters, timezone localization,
legacy/structured notifications, and private-field absence. Existing
Applications, Home, Notification, Test Deadline, Interview Lifecycle, and
Information Request suites are also run as regression coverage.

Scheduled reminder delivery was not added. The repository has no established
recruitment reminder scheduler/deduplication store, and introducing one would
expand this aggregation task into a new delivery subsystem. Upcoming schedule
is complete without reminder notifications; the versioned notification
payload and `activity_key` provide the idempotency marker for a future focused
reminder implementation.

### Verification results

- Complete Laravel suite: **905 passed, 2 expected opt-in S3 skips, 27,450
  assertions, 0 failed**. One preceding run exposed a one-second timing flake in
  the unrelated `EmailVerificationServiceTest`; that test passed immediately in
  isolation and the subsequent complete suite passed.
- Focused Activity feature/unit suite: **10 passed, 87 assertions**.
- Existing Notification, Applications, Home, Test Deadline, Interview
  Lifecycle, and Information Request regression set: **49 passed, 446
  assertions**.
- Protected handover and recommendation E2E checks: **7 passed, 10,077
  assertions** after explicitly approving the three intentional shared-service
  changes in their baseline allowlists.
- `php artisan migrate:fresh --seed --force`: passed on an isolated temporary
  SQLite database; the temporary file was removed after verification.
- `php artisan route:list --path=api/v1/activity`: passed and shows exactly one
  `GET|HEAD api/v1/activity` route.
- `composer audit`: passed with **no security vulnerability advisories**.
- Laravel Pint on every PHP file changed by this implementation: passed. The
  repository-wide `vendor/bin/pint --test` still reports pre-existing style
  violations in unrelated files; they were not reformatted because this task
  explicitly forbids unrelated refactors.
- `php -l`: passed for every changed/new PHP file.
- Both Postman collections parse as valid JSON and each contains 11 Activity
  requests.
- `git diff --check`: passed. No commit or push was created.
