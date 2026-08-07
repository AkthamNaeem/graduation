# Current logical CV API

The job seeker has one user-facing CV. Uploaded PDF/DOCX files are private parsing and audit artifacts; they are not the current logical CV and are not offered as a version library in the mobile experience.

The three data layers are:

1. `CVFile`: upload/parsing/review input and internal lifecycle metadata.
2. Confirmed structured profile: the current logical CV (`JobSeekerProfile`, user identity, experiences, education, and skills).
3. `ApplicationSnapshot.profile_snapshot`: the immutable CV submitted with one application.

## Profile CV state

`GET /api/v1/profile` retains `current_cv` and `pending_cv_update` for compatibility and adds `cv` as the mobile-facing state contract:

```json
{
  "cv": {
    "status": {"key": "confirmed", "label": "Your CV is ready"},
    "is_ready": true,
    "pending_update": null,
    "allowed_actions": ["preview_cv", "download_cv", "update_cv"],
    "preview_url": ".../api/v1/profile/cv/preview",
    "download_url": ".../api/v1/profile/cv/download"
  }
}
```

Status keys are `no_cv`, `processing`, `review_required`, `suggestions_review_required`, `ready_for_confirmation`, `confirmed`, and `failed`. A confirmed CV can remain ready while a separate update workflow is pending.

Mobile-facing actions are `upload_cv`, `continue_cv_review`, `preview_cv`, `download_cv`, and `update_cv`. Version management, primary selection, archive, and restore are not mobile-facing actions.

## Upload and confirmation

Upload only requires:

```http
POST /api/v1/cv/upload
Content-Type: multipart/form-data

file=<PDF or DOCX>
```

`version_label` and `make_primary` remain optional compatibility inputs. The existing parsing, review, suggestion, ready-for-confirmation, confirmation, and cancellation routes continue to use a `cvFile` ID as the pending workflow identifier.

## Generated current CV

### Preview

`GET /api/v1/profile/cv/preview`

- Role: authenticated Job Seeker.
- Input: none; no user/profile/CV ID is accepted.
- Output: generated PDF with `Content-Disposition: inline`.
- Source: current structured database profile, gated by the existing confirmed-current-CV rule.
- Error: `PRIMARY_CV_REQUIRED` (422) when no confirmed usable current CV exists; the legacy code means “confirmed current CV required.”

### Download

`GET /api/v1/profile/cv/download`

Same source and renderer as preview, with `Content-Disposition: attachment`.

The renderer is deterministic and on-demand. It does not use remote assets, persist generated documents, expose storage paths, or fall back to the uploaded source file.

## Apply without choosing a CV

New clients submit only application-specific data:

```http
POST /api/v1/jobs/{jobPosting}/applications
Content-Type: application/json

{
  "cover_letter": "Optional",
  "consent_to_share_profile": true,
  "screening_answers": []
}
```

The backend resolves `primary_cv_file_id` internally and requires it to be confirmed and usable. `cv_file_id` and `selected_cv_file_id` remain accepted only as deprecated compatibility inputs for existing clients; new clients must omit them. Modern explicit selections cannot choose a confirmed non-current CV.

Application creation, initial status history, screening snapshots, the immutable profile/application snapshot, events, and audit records remain in the existing transaction/after-commit flow.

## Submitted application CV

```http
GET /api/v1/applications/{jobApplication}/cv/preview
GET /api/v1/applications/{jobApplication}/cv/download
```

- Role: the owning candidate or a company member authorized to view that company’s application.
- Output: generated PDF, inline or attachment respectively.
- Source: only `ApplicationSnapshot.profile_snapshot`.
- Error: `APPLICATION_SNAPSHOT_NOT_AVAILABLE` (404) for legacy applications without a snapshot.

The source-file copy in `ApplicationSnapshot` remains for audit, traceability, checksum verification, and backfill compatibility. It is not returned by these generated-document endpoints. Missing snapshot fields remain missing; current profile data is never used to fill them.

Application CV summarization also uses `profile_snapshot` when a snapshot exists. The prior live-profile/parsing-result path remains only as a best-available compatibility fallback for legacy applications without snapshots.

## Legacy/internal artifact APIs

The existing `/api/v1/cv`, `/api/v1/cv/{cvFile}`, metadata, make-primary, archive/restore, and source-file preview/download routes remain operational for compatibility and internal lifecycle support. They expose uploaded artifacts and must not be used to implement the new mobile CV-management experience.
