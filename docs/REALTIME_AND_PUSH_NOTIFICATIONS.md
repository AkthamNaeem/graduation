# Realtime and Push Notifications — Backend Contract

## Scope

This backend task completes the server-side requirements for:

- authenticated realtime in-app notification delivery for Flutter and Next.js;
- Firebase Cloud Messaging push notifications for Android, iOS, and Web;
- device-token registration, refresh, logout cleanup, and invalid-token handling;
- queued delivery with retries, delivery history, and idempotency;
- unread-count refresh and reconnect-safe notification cursors.

The existing `notifications` table and `NotificationService` remain the single source of truth. Every existing recruitment event that creates an in-app notification automatically becomes realtime and push-enabled through `NotificationObserver`. This includes application submission/status changes, information requests, tests, interviews, and final decisions.

Realtime is not added to unrelated data. Job listings, profile edits, reports, and matching results remain normal request/response APIs. Their user-visible completion should create a notification only when immediate user attention is required.

## Architecture

### Foreground realtime

`GET /api/v1/notifications/stream` uses Server-Sent Events (SSE).

Reasons:

- one-way server-to-client delivery is exactly what notification updates need;
- both Flutter and Next.js can consume an authenticated streaming HTTP response;
- no additional WebSocket package or separate Reverb process is required;
- it works with the existing Laravel, Sanctum, database queue, and Render-style deployment.

The stream is intentionally bounded (25 seconds by default), sends heartbeat comments, then emits `stream.closed`. The client reconnects after one second and passes the last event ID as `cursor` or `Last-Event-ID`. This avoids indefinitely occupying PHP workers and survives normal proxy/request limits.

On a first connection without a cursor, the stream starts after the user's newest existing notification. Existing history is loaded through `GET /api/v1/notifications`; only newly created notifications are streamed.

### Background and terminated apps

FCM HTTP v1 delivers push notifications. The backend creates a signed service-account OAuth assertion directly with OpenSSL, obtains a short-lived Google access token, caches it, and calls the official FCM HTTP v1 endpoint. No Firebase Admin SDK or additional Composer package is required.

### Delivery lifecycle

1. A domain event creates a row in `notifications`.
2. `NotificationObserver` dispatches `SendNotificationPushJob` after the current database transaction commits.
3. The job runs on the `notifications` queue.
4. One `push_deliveries` row is created per notification/device pair.
5. Successful sends store the FCM message name and `sent_at`.
6. Transient failures retry with backoff: 10 seconds, 60 seconds, then 300 seconds.
7. FCM `UNREGISTERED`/invalid registration tokens are disabled automatically.
8. Previously successful device deliveries are skipped on job retry.

## API contract

All endpoints require:

```http
Authorization: Bearer <sanctum-token>
Accept: application/json
```

### Register or refresh a device token

```http
POST /api/v1/device-tokens
Content-Type: application/json
```

```json
{
  "token": "FCM_REGISTRATION_TOKEN",
  "platform": "android",
  "device_id": "stable-installation-id",
  "app_version": "1.0.0",
  "locale": "ar"
}
```

Validation:

- `token`: required string, max 512;
- `platform`: `android`, `ios`, or `web`;
- `device_id`: optional, max 191;
- `app_version`: optional, max 50;
- `locale`: optional, max 10.

The same FCM token is updated rather than duplicated. Register it after login, whenever FCM rotates it, and when the user changes account on the same installation.

### Disable token on logout

```http
DELETE /api/v1/device-tokens/{deviceTokenId}
```

The record is retained for delivery history and marked inactive. A user cannot disable another user's device.

### Realtime stream

```http
GET /api/v1/notifications/stream?cursor=123
Authorization: Bearer <sanctum-token>
Accept: text/event-stream
```

Events:

```text
event: connected
data: {"cursor":123,"unread_count":2,"server_time":"..."}

id: 124
event: notification.created
data: {"id":124,"type":"interview.scheduled",...}

event: unread-count.updated
data: {"unread_count":3}

event: stream.closed
data: {"cursor":124,"reconnect_after_ms":1000}
```

Client rules:

- store the greatest notification event ID received;
- reconnect after `stream.closed`, network failure, or timeout;
- pass the stored ID as `cursor`;
- on a long disconnection, call `GET /api/v1/notifications` first to reconcile history;
- never treat a push notification as the source of truth; open/fetch the API resource referenced in `data`.

## Flutter integration

1. Configure `firebase_core` and `firebase_messaging` in the mobile project.
2. Request notification permission where required.
3. Obtain the FCM token and call `POST /device-tokens` after authentication.
4. Subscribe to token refresh and register the replacement token.
5. Open the SSE endpoint with an HTTP client that supports streamed bearer-authenticated requests.
6. While foregrounded, update the notification list and unread badge from SSE.
7. Handle FCM foreground/background/tap callbacks and navigate using `resource_type`, `resource_id`, `application_id`, and `action_type` from the push `data` payload.
8. On logout, disable the backend token before clearing the local Sanctum token.

Do not use the browser-style `EventSource` API because it cannot reliably attach a bearer token. Use a streamed HTTP request.

## Next.js web/dashboard integration

1. Configure Firebase Web Messaging and a service worker in each Next.js application that needs Web Push.
2. Obtain the browser FCM token using the Web Push VAPID key, then register it with `platform=web`.
3. Use `fetch()` with `Authorization` and read `response.body` as an SSE stream; do not use native `EventSource` with bearer-token authentication.
4. Keep the cursor in memory or session storage.
5. Invalidate/refetch notification queries and relevant application/test/interview queries when `notification.created` arrives.
6. Disable the device token on logout or when browser notification permission is revoked.

## Environment and deployment

Required for realtime only:

```env
REALTIME_STREAM_DURATION_SECONDS=25
REALTIME_STREAM_POLL_INTERVAL_MS=1000
REALTIME_STREAM_BATCH_SIZE=50
```

Required for FCM:

```env
FCM_ENABLED=true
FCM_PROJECT_ID=your-firebase-project-id
FCM_SERVICE_ACCOUNT_JSON=<base64-service-account-json>
FCM_TIMEOUT_SECONDS=10
FCM_CONNECT_TIMEOUT_SECONDS=5
QUEUE_CONNECTION=database
```

Use the base64 JSON value as a secret environment variable. Never commit a service-account file or JSON key.

A continuously running queue worker is mandatory:

```bash
php artisan queue:work --queue=notifications,default --sleep=1 --tries=4 --timeout=60
```

Deployment steps:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

The web service and queue worker must use the same database, application key, and FCM configuration. If `FCM_ENABLED=false`, in-app storage and SSE continue to work and push jobs exit without sending.

## Database tables

### `device_tokens`

Stores user ownership, FCM token, platform, optional installation metadata, active state, last use, and disable time. The raw token is hidden from model serialization.

### `push_deliveries`

Stores provider, status, attempts, provider message ID, errors, and send time. A unique constraint on `(notification_id, device_token_id)` guarantees idempotency.

## Security and privacy

- SSE and device APIs require Sanctum and active-user middleware.
- Each stream query is scoped to the authenticated user's `user_id`.
- Device deletion verifies ownership and returns 404 for cross-user access.
- Service-account secrets never enter API responses, notifications, audit metadata, or logs.
- Push payloads contain the existing privacy-safe notification payload; internal notes, private scores, and protected evaluation details must continue to be excluded by the domain notification listeners.
- FCM tokens are credentials for delivery and must not be exposed through list endpoints.

## Verification

Run:

```bash
php artisan migrate:fresh --seed
php artisan test --filter=RealtimeNotificationTest
php artisan test --filter=NotificationTest
php artisan test
php artisan route:list --path=api/v1/notifications
php artisan route:list --path=api/v1/device-tokens
vendor/bin/pint --test app/Http/Controllers/Api/V1/Notification app/Http/Requests/Api/V1/Notification app/Jobs app/Models/DeviceToken.php app/Models/PushDelivery.php app/Observers app/Providers/RealtimeNotificationServiceProvider.php app/Services/Push tests/Feature/Api/V1/RealtimeNotificationTest.php
```

Manual FCM verification requires real Firebase credentials and an Android/iOS/Web registration token. Confirm that:

- a new notification appears through SSE while the app is foregrounded;
- the same notification arrives through FCM while the app is backgrounded;
- only one delivery row exists per notification/device;
- an invalid token becomes inactive;
- reconnecting with the latest cursor does not duplicate events;
- another authenticated user cannot receive or modify the first user's stream/device token.
