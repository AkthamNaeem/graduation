# Syrian Cities API and Integration Contract

Structured Syrian cities are additive to the existing free-text `location`
fields. Clients must keep treating `location` as optional address/details text
and use nullable `city_id` for stable city selection. No external geocoding
service is used.

## Localization

All endpoints use the existing `Accept-Language` negotiation (`en`, `ar`, and
regional tags such as `ar-SY`). `city.name` and location matching messages are
localized by the backend. `city.id`, `city.code`, JSON keys, reason codes, and
filter values are stable and are never translated.

English city object:

```json
{"id":1,"code":"damascus","name":"Damascus","country_code":"SY"}
```

Arabic city object:

```json
{"id":1,"code":"damascus","name":"دمشق","country_code":"SY"}
```

## Reference endpoint

| Method | URL | Authentication | Role | Side effects |
|---|---|---|---|---|
| `GET` | `/api/v1/reference/cities` | None | Public | None |

Query parameters:

| Parameter | Validation | Behavior |
|---|---|---|
| `search` | Optional string, max 150 | Partial match against `code`, Arabic name, or English name. |
| `active_only` | Optional boolean; default `true` | When false, includes inactive Syrian records. Non-Syrian records are never returned. |

```http
GET /api/v1/reference/cities?search=dam&active_only=true
Accept-Language: en
```

```json
{
  "success": true,
  "message": "Cities retrieved successfully.",
  "data": [
    {"id": 1, "code": "damascus", "name": "Damascus", "country_code": "SY"}
  ]
}
```

## Job seeker profile

`POST /api/v1/auth/register/job-seeker` also accepts optional `location` and
`city_id`, applying the same active-Syrian-city validation while creating the
initial profile. Existing registration requests remain valid without either
field.

| Method | URL | Authentication | Role |
|---|---|---|---|
| `GET` | `/api/v1/profile` | Sanctum | Job seeker |
| `PUT` | `/api/v1/profile` | Sanctum | Job seeker, own profile only |

```json
{
  "location": "المزة، قرب ساحة المحافظة",
  "city_id": 1
}
```

`city_id` is optional. Sending `null` removes the structured city; omitting it
preserves the current value. A legacy request containing only `location`
continues to work. Profile responses always add `city`, which is `null` for
legacy/unselected rows. Employers receive the same city object only through
application/profile resources they are already authorized to read; precise
free-text visibility is unchanged.

## Job posting

Existing job create, update, detail, public list, employer list, Home, and
recommendation responses add `city` without removing `location`.

```json
{
  "title": "Backend Developer",
  "description": "Build recruitment APIs",
  "requirements": "Laravel experience",
  "employment_type": "full_time",
  "experience_level": "mid_level",
  "work_mode": "on_site",
  "location": "دمشق، شارع الثورة",
  "city_id": 1
}
```

On-site and hybrid jobs retain the existing free-text `location` requirement.
The structured city remains optional for backward compatibility. Remote jobs
may use `city_id=null`; the backend does not assign a placeholder city.
Ownership and company-approval authorization are unchanged.

## Validation and errors

When present, `city_id` must be an integer referencing an active record with
`country_code=SY`. Validation uses the standard `422` API envelope and adds a
stable top-level code when the failure is city-specific:

| Code | Meaning |
|---|---|
| `INVALID_CITY_ID` | The value is not a valid integer. |
| `CITY_NOT_FOUND` | No city exists for the id. |
| `CITY_INACTIVE` | The city exists but cannot be used for a new write. |
| `CITY_NOT_SYRIAN` | The city exists but is outside the supported country scope. |

Messages and `errors.city_id` are localized. Inactive cities remain readable
on existing linked rows but cannot be selected by new create/update requests.

## Public job search

`GET /api/v1/jobs` keeps every existing filter and adds:

| Parameter | Behavior |
|---|---|
| `city_id` | Exact `job_postings.city_id` match. |
| `city_code` | Optional exact match through the city relationship. |
| `include_remote` | Boolean, default false. With a city filter, also includes `work_mode=remote`. |

The explicit city filter never compares `location` text. Text `search` also
matches Arabic/English city names and code. Public status/company visibility,
pagination, and sorting rules are unchanged. City is eager-loaded on job lists.

## Recommendation and candidate scoring

`config/matching.php` assigns 5 of 100 points to location and reduces text
similarity from 15 to 10. Required skills (45), nice-to-have skills (10),
experience (20), and education (10) remain dominant.

| Status | Location score | Behavior |
|---|---:|---|
| `same_city` | 5/5 | Positive compatibility. |
| `remote` | 5/5 | No city mismatch penalty. |
| `missing` | 2.5/5 | Neutral; no free-text guess. |
| `different_city` | 0/5 | Only the location component is reduced; the item is not excluded. |

Recommendation and ranked-candidate responses add `breakdown.location` and
`location_match` with `status`, `score`, `max_score`, `match_percentage`,
localized `message`, and stable `reason_code`. A stable reason is also included
in `reasons`. These values are decision support only and never accept or reject
an application. ML recommendations preserve the provider contract, blend the
configured 5-point location component into the display ranking only when city
compatibility is actionable, and expose the same breakdown.

## CV parsing and Smart Profile Sync

The immutable parser contract still extracts free-text `location`. A local
deterministic matcher checks active Syrian city `name_ar`, `name_en`, and
`code` using normalized token/component matching; it performs no fuzzy or
distance matching.

- Initial import: a confident match adds optional `profile.city_id` to the
  editable `reviewed_json` draft. It is saved only after candidate confirmation.
- Profile sync: a confident match creates an `ADD`, `UPDATE`, or `IGNORE`
  profile suggestion for `city_id`.
- Unknown or ambiguous text creates no `city_id`.
- An existing manual city is never overwritten before the candidate accepts
  and finally applies the suggestion.

## Frontend integration notes

1. Load the reference endpoint and store the selected `id`; render `name`.
2. Never translate city names locally or use `name` as a key.
3. Keep a separate address/details control for `location`.
4. Send `city_id:null` only when the user explicitly clears the selection.
5. Treat `city:null` as a supported legacy state.
6. Use reason/status codes for UI logic and the backend message for display.
7. Do not interpret location score as eligibility or an automated decision.
