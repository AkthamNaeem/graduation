# Optional Image Fields API

## Scope and compatibility

The API supports four nullable image paths without changing registration, login, existing JSON contracts, test scoring, or `companies.logo_path`:

| API field | Database field | Storage | Response field |
| --- | --- | --- | --- |
| User avatar | `users.avatar_path` | `public`, under `user-avatars/{user_id}` | `avatar_url` |
| Company cover | `companies.cover_image_path` | `public`, under `company-covers/{company_id}` | `cover_image_url` |
| Test question image | `test_questions.image_path` | configured private disk, under `tests/{test_id}/questions/{question_id}` | protected `image_url` |
| Job category icon | `skills.icon_path` | `public`, under `skill-icons/{skill_id}` | `icon_url` |

`Skill`/`skills` is the existing job-classification catalog in this project, so no parallel category module was introduced. Existing rows return the corresponding URL field as `null`.

All image fields are optional. Existing create and update requests continue to work without images. Image upload is isolated in multipart endpoints so existing JSON clients do not need to change content type.

## Validation

Uploads accept real `jpg`, `jpeg`, `png`, and `webp` images only. SVG is not accepted. Laravel validates `nullable|file|image|mimes:jpg,jpeg,png,webp` with these limits:

- Avatar: 2 MB.
- Company cover: 5 MB.
- Test question image: 5 MB.
- Skill/category icon: 2 MB.

Invalid MIME data, disguised extensions, corrupt/empty non-images, and oversized files return the existing `422` API validation envelope with errors under `image`.

Public avatar, company logo, company cover, and skill icon uploads are decoded and stored once as optimized WebP files at quality 82. They preserve aspect ratio and transparency, are never upscaled, and are limited respectively to `512x512`, `512x512`, `1600x1200`, and `256x256`. Each replacement uses a unique filename; image processing does not run while serving `/storage/*`. Test question images remain private and outside this public-image optimization path.

## Endpoints

All endpoints require `Authorization: Bearer <token>`. Upload requests use `multipart/form-data` and the field name `image`. Both `POST` and `PATCH` are supported for upload/replacement.

### User avatar

```http
POST|PATCH /api/v1/profile/avatar
DELETE     /api/v1/profile/avatar
```

The authenticated user can manage only the avatar on their own `users` row. This works for job seekers, employers, and administrators.

### Company cover

```http
POST|PATCH /api/v1/company/cover-image
DELETE     /api/v1/company/cover-image
```

The existing `UPDATE_COMPANY` permission is required. The endpoint always resolves the authenticated employer's active company membership, preventing cross-company selection. Uploading or deleting a cover never changes `logo_path`.

### Test question image

```http
GET        /api/v1/tests/{test}/questions/{question}/image
POST|PATCH /api/v1/tests/{test}/questions/{question}/image
DELETE     /api/v1/tests/{test}/questions/{question}/image
```

The existing `TestPolicy::manageQuestions` authorization and test/question hierarchy checks apply. The owning company must be in scope, and immutable assigned tests remain immutable.

Candidates receive an attempt-scoped URL only after they are authorized to read a started attempt:

```http
GET /api/v1/test-attempts/{testAttempt}/questions/{question}/image
```

The image is streamed from private storage with `Cache-Control: private, no-store`; the raw storage path is never returned. Another candidate, another company, or an unauthenticated caller cannot use the endpoint.

### Job category / Skill icon

```http
POST|PATCH /api/v1/admin/skills/{skill}/icon
DELETE     /api/v1/admin/skills/{skill}/icon
```

Only administrators can manage icons, through the existing Admin Skills API and admin middleware.

## Request and response examples

Upload or replace:

```bash
curl -X POST "$BASE_URL/api/v1/profile/avatar" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -F "image=@avatar.webp"
```

Successful resource responses preserve the existing envelope and expose URLs, not internal paths:

```json
{
  "success": true,
  "message": "Avatar updated successfully.",
  "data": {
    "id": 42,
    "avatar_url": "https://api.example.test/storage/user-avatars/42/uuid.webp"
  }
}
```

Remove an image:

```bash
curl -X DELETE "$BASE_URL/api/v1/profile/avatar" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

The response URL becomes `null`. Repeating DELETE when no image exists is idempotent and does not return a server error.

## Replacement, cleanup, and audit behavior

Replacement follows this order:

1. Store and verify the new file through Laravel storage.
2. Commit the new path in a database transaction.
3. Delete the previous file only after the database update succeeds.
4. If the database transaction fails, delete the newly stored file before rethrowing the error.

Deleting an image first commits the nullable field, then removes the old object. Deleting a question or an unused skill also cleans its owned image according to the existing hard-delete behavior.

Audit metadata records only whether an image existed, not file contents, URLs, or internal paths. Actions are `user.avatar.updated/removed`, `company.cover_image.updated/removed`, `test_question.image.updated/removed`, and `job_category.icon.updated/removed`.

## Flutter and Next.js integration notes

- No selection: omit `image`; do not send an empty string or Base64.
- Upload/replacement: send `multipart/form-data` with a binary `image` part.
- Removal: call the corresponding `DELETE` endpoint; `image: null` is not interpreted as deletion.
- Render `null` URL fields with the existing placeholder UI.
- Use the returned `image_url` for test questions. Do not construct a storage URL from any path.
- Protected question-image GET requests must include the Bearer token. Flutter clients should use authenticated byte loading; Next.js should proxy or fetch with the user's authorization context rather than exposing a server credential.
