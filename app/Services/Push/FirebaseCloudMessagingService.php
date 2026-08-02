<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebaseCloudMessagingService
{
    /** @return array{message_id:?string,token_invalid:bool} */
    public function send(DeviceToken $deviceToken, Notification $notification): array
    {
        if (! config('realtime_notifications.push.enabled')) {
            throw new RuntimeException('FCM push delivery is disabled.');
        }

        $projectId = (string) config('realtime_notifications.push.project_id');
        if ($projectId === '') {
            throw new RuntimeException('FCM_PROJECT_ID is not configured.');
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withToken($this->accessToken())
                ->connectTimeout((int) config('realtime_notifications.push.connect_timeout_seconds', 5))
                ->timeout((int) config('realtime_notifications.push.timeout_seconds', 10))
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $deviceToken->token,
                        'notification' => [
                            'title' => $notification->title,
                            'body' => $notification->message,
                        ],
                        'data' => $this->stringifyData(array_merge($notification->data ?? [], [
                            'notification_id' => $notification->id,
                            'notification_type' => $notification->type,
                        ])),
                        'android' => [
                            'priority' => 'high',
                            'notification' => ['sound' => 'default'],
                        ],
                        'apns' => [
                            'headers' => ['apns-priority' => '10'],
                            'payload' => ['aps' => ['sound' => 'default']],
                        ],
                        'webpush' => [
                            'headers' => ['Urgency' => 'high'],
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to connect to FCM.', previous: $exception);
        }

        if ($response->successful()) {
            return [
                'message_id' => $response->json('name'),
                'token_invalid' => false,
            ];
        }

        $status = (string) $response->json('error.status');
        $body = $response->body();
        $tokenInvalid = in_array($status, ['NOT_FOUND', 'INVALID_ARGUMENT'], true)
            && (str_contains($body, 'UNREGISTERED') || str_contains($body, 'registration token'));

        if ($tokenInvalid) {
            return ['message_id' => null, 'token_invalid' => true];
        }

        throw new RuntimeException('FCM request failed: '.$status.' '.$response->body());
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.oauth_access_token', now()->addMinutes(50), function (): string {
            $credentials = $this->credentials();
            $now = time();
            $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claims;

            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign the FCM service-account assertion.');
            }

            $assertion = $unsigned.'.'.$this->base64UrlEncode($signature);
            $response = Http::asForm()
                ->connectTimeout((int) config('realtime_notifications.push.connect_timeout_seconds', 5))
                ->timeout((int) config('realtime_notifications.push.timeout_seconds', 10))
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

            $response->throw();

            return (string) $response->json('access_token');
        });
    }

    /** @return array{client_email:string,private_key:string} */
    private function credentials(): array
    {
        $json = config('realtime_notifications.push.service_account_json');
        $path = config('realtime_notifications.push.service_account_path');

        if (is_string($json) && $json !== '') {
            $decoded = base64_decode($json, true);
            $json = $decoded !== false ? $decoded : $json;
        } elseif (is_string($path) && $path !== '' && is_file($path)) {
            $json = file_get_contents($path);
        }

        $credentials = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('FCM service-account credentials are not configured correctly.');
        }

        return [
            'client_email' => (string) $credentials['client_email'],
            'private_key' => (string) $credentials['private_key'],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function stringifyData(array $data): array
    {
        return collect($data)
            ->reject(fn (mixed $value): bool => $value === null)
            ->map(fn (mixed $value): string => is_scalar($value)
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->all();
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
