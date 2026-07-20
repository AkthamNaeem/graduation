# Mobile CV Review Flow

The CV review API has two explicit modes. After upload, poll the CV resource or call `GET /api/v1/cv/{cvFile}/review` and route the UI using `review_mode`, `review_status`, and `next_action`.

## Shared state

`next_action` is one of `wait_for_parsing`, `retry_upload`, `review_draft`, `generate_suggestions`, `review_suggestions`, `apply_suggestions`, or `completed`.

`parsed_json` is the immutable extraction result. It can contain read-only identity, language, certification, nationality, marital-status, and birth-date data. Never send it back as an editable profile payload.

Archived CVs and reviews whose status is `applied` are read-only. Every review route is job-seeker-only and owner-scoped.

## Initial import

When `review_mode=initial_import`, the backend determined that the profile had no meaningful scalar, experience, education, or skill data. `primary_cv_file_id` alone does not make a profile non-empty.

1. `GET /api/v1/cv/{cvFile}/review` returns `parsed_json` plus editable `reviewed_json`.
2. Edit the full draft and replace it with `PUT /api/v1/cv/{cvFile}/review-draft`.
3. Confirm once with `POST /api/v1/cv/{cvFile}/confirm`.

The draft shape is:

```json
{
  "profile": {"phone": null, "summary": null, "location": null},
  "experience": [],
  "education": [],
  "skills": []
}
```

Removing an array item deletes it only from the draft. Confirm applies the complete reviewed draft atomically with CV source tracking. It does not apply unsupported read-only fields and does not create profile-change suggestions. If the profile gained meaningful data after draft creation, confirm returns `409 CV_REVIEW_MODE_STALE` and applies nothing.

## Profile synchronization

When `review_mode=profile_sync`:

1. Generate comparisons with `POST /api/v1/cv/{cvFile}/suggestions/generate` (legacy `POST .../confirm` delegates here).
2. Read them with `GET /api/v1/cv/{cvFile}/suggestions`.
3. Save each actionable decision with `POST /api/v1/profile/suggestions/{suggestion}/accept` or `/reject`. Accept may include an entity-specific `edited_value`.
4. When `review_status=ready_to_apply`, the Save Changes button calls `POST /api/v1/cv/{cvFile}/suggestions/apply`.

Accept and reject save decisions only; they never update the profile. Decisions may be changed until final apply. `IGNORE` items are shown under matched/no-change items, are not actionable, and never block readiness.

`ADD` introduces a missing value or entity. `UPDATE` represents conflicting non-empty values. `MERGE` fills only missing fields on one unambiguous match. `IGNORE` represents an equivalent or already-attached value. Missing data in the new CV never generates deletion.

Experience matches use normalized title and company plus the start year when available. Education matches use normalized institution and degree plus the start year, or the graduation year when no start year is available. Ambiguous matches are never merged into an arbitrary profile row.

Final apply is one transaction across the CV, profile, suggestions, and target entities. It applies accepted suggestions only, leaves rejected items unchanged, treats ignored items as no-ops, and is idempotent. A repeated successful call returns `already_applied=true`.

If any target differs from the stored `old_value`, the whole transaction rolls back with `409 SUGGESTION_STALE`. The error includes only the safe suggestion id and entity type; it never includes the changed value.

## Decision payload examples

```json
{"edited_value":{"phone":"+963..."}}
```

```json
{
  "edited_value": {
    "title": "Backend Engineer",
    "company_name": "Acme",
    "location": "Remote",
    "start_date": "2024-01-01",
    "end_date": null,
    "is_current": true,
    "description": "Built APIs"
  }
}
```

Unexpected fields, ids, ownership fields, email, role, and client-supplied skill slugs are rejected.
