# Application CV Summary API

Implements AI-06 / UC-EMP-08 as an employer-facing, job-specific summary of the selected CV and verified candidate profile.

The LLM is an assistant only. The response never changes application status, recommends acceptance or rejection, assigns a match score, or replaces employer review.

## Configuration

```env
OPENAI_API_KEY=
CV_SUMMARY_PROVIDER=openai
OPENAI_CV_SUMMARY_MODEL=gpt-5-mini
OPENAI_CV_SUMMARY_TIMEOUT=60
OPENAI_CV_SUMMARY_CONNECT_TIMEOUT=10
CV_SUMMARY_MAX_SOURCE_CHARACTERS=30000
```

The implementation reuses OpenAI's Responses API pattern already used by CV parsing. Requests set `store=false` and require a strict JSON schema.

## GET `/api/v1/applications/{application}/cv-summary`

Returns the stored summary for the request locale selected through `Accept-Language`.

- Authentication: Sanctum.
- Roles: Employer company members with `view_applications`; Administrator.
- Job Seeker access: forbidden.
- Response data: `null` when no summary has been generated for that locale.
- Side effects: none.

## POST `/api/v1/applications/{application}/cv-summary`

Generates or reuses the summary for the request locale.

- Authentication: Sanctum.
- Roles: Employer company members with `manage_applications`; Administrator.
- Body:

```json
{
  "force": false
}
```

`force` is optional. When false, an existing summary is reused only when its input hash still matches the current job, selected CV, verified profile, locale, model, and prompt version.

### Successful response data

```json
{
  "id": 12,
  "job_application_id": 45,
  "source_cv_file_id": 9,
  "locale": "en",
  "headline": "Backend candidate aligned with Laravel API work",
  "summary": "...",
  "strengths": ["..."],
  "gaps": ["Docker experience is not evidenced in the supplied data."],
  "evidence": [
    {
      "statement": "Laravel REST API experience",
      "source": "Selected CV and verified profile"
    }
  ],
  "ai_disclaimer": "...",
  "generation": {
    "provider": "openai",
    "model": "gpt-5-mini",
    "prompt_version": "1.0",
    "generated_at": "2026-07-31T09:00:00.000000Z"
  }
}
```

## Data and privacy rules

- Uses the job description, requirements, required/nice-to-have skills, verified profile, and the CV selected for this application.
- Removes direct identity and sensitive fields from structured CV input: name, email, phone, birth date, nationality, marital status, and personal location.
- Redacts email addresses and phone numbers when raw text fallback is required.
- Does not persist the outbound prompt payload or expose provider request details to clients.
- Stores provider/model/prompt metadata and an input hash for traceability.
- Audits generation without storing the generated summary inside the audit log.

## Stable errors

| Code | HTTP | Meaning |
| --- | ---: | --- |
| `CV_SUMMARY_SOURCE_UNAVAILABLE` | 422 | Candidate source data is insufficient |
| `CV_SUMMARY_NOT_CONFIGURED` | 503 | API key/configuration is missing |
| `CV_SUMMARY_TIMEOUT` | 503 | Provider connection timed out |
| `CV_SUMMARY_AUTHENTICATION_FAILED` | 503 | Provider credentials were rejected |
| `CV_SUMMARY_RATE_LIMITED` | 503 | Provider rate limit was reached |
| `CV_SUMMARY_PROVIDER_UNAVAILABLE` | 503 | Provider returned a server failure |
| `CV_SUMMARY_INVALID_RESPONSE` | 502 | Provider response failed the strict schema |
