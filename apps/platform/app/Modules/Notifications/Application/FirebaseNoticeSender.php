<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FirebaseNoticeSender
{
    private ?string $accessToken = null;

    private int $expiresAt = 0;

    public function check(): void
    {
        $this->authenticate();
        try {
            $response = Http::withToken((string) $this->accessToken)->connectTimeout(5)->timeout(15)
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) config('notice_push.project_id')).'/messages:send', [
                    'validate_only' => true,
                    'message' => ['topic' => 'configuration-validation',
                        'notification' => ['title' => 'Configuration check', 'body' => 'Validation only; not delivered.']],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('firebase_api_unreachable');
        }
        if (! $response->successful()) {
            throw new RuntimeException('firebase_api_rejected_http_'.$response->status());
        }
    }

    public function authenticate(): void
    {
        if ($this->accessToken !== null && $this->expiresAt > time()) {
            return;
        }
        $path = (string) config('notice_push.credentials');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('firebase_credentials_unreadable');
        }
        $key = json_decode((string) file_get_contents($path), true);
        if (! is_array($key) || ($key['type'] ?? '') !== 'service_account'
            || ($key['project_id'] ?? '') !== config('notice_push.project_id')
            || ! is_string($key['private_key'] ?? null) || ! is_string($key['client_email'] ?? null)) {
            throw new RuntimeException('firebase_credentials_invalid');
        }
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode('{"alg":"RS256","typ":"JWT"}');
        $claims = $encode(json_encode(['iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600], JSON_THROW_ON_ERROR));
        $private = openssl_pkey_get_private($key['private_key']);
        if ($private === false || ! openssl_sign($header.'.'.$claims, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('firebase_signing_failed');
        }
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $header.'.'.$claims.'.'.$encode($signature),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('firebase_auth_unreachable');
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('firebase_auth_rejected');
        }
        $this->accessToken = $response->json('access_token');
        $this->expiresAt = time() + 3000;
    }

    /** @param array{title: string, message: string, category: string, ttl: int} $notice
     * @return array{status: string, error: ?string, delay: int}
     */
    public function send(string $installationId, string $noticeId, array $notice, string $platform = 'android'): array
    {
        $this->authenticate();
        try {
            $response = Http::withToken((string) $this->accessToken)->connectTimeout(5)->timeout(15)
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) config('notice_push.project_id')).'/messages:send', [
                    'message' => [
                        'fid' => $installationId,
                        'notification' => ['title' => $notice['title'], 'body' => $notice['message']],
                        'data' => ['notice_id' => $noticeId, 'category' => $notice['category'], 'type' => 'driver_notice'],
                        ...($platform === 'web' ? ['webpush' => [
                            'headers' => ['TTL' => (string) $notice['ttl'], 'Urgency' => 'high'],
                            'notification' => ['tag' => 'notice-'.$noticeId,
                                'icon' => rtrim((string) config('app.url'), '/').'/mobile/icons/Icon-192.png'],
                            'fcm_options' => ['link' => rtrim((string) config('app.url'), '/').'/mobile/#/updates'],
                        ]] : ['android' => ['priority' => 'high', 'ttl' => $notice['ttl'].'s',
                            'restricted_package_name' => config('notice_push.android_package'),
                            'notification' => ['tag' => 'notice-'.$noticeId, 'channel_id' => 'driver_notices']]]),
                    ],
                ]);
        } catch (ConnectionException) {
            // A timeout can happen after FCM accepted the message. Do not blindly resend.
            return ['status' => 'unknown', 'error' => 'transport_uncertain', 'delay' => 0];
        }
        if ($response->successful()) {
            return ['status' => 'accepted', 'error' => null, 'delay' => 0];
        }
        $error = 'http_'.$response->status();
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && ($detail['errorCode'] ?? '') === 'UNREGISTERED') {
                return ['status' => 'invalid', 'error' => 'unregistered', 'delay' => 0];
            }
        }
        if ($response->status() === 401) {
            $this->accessToken = null;
        }
        $retry = in_array($response->status(), [401, 429, 500, 503], true);

        return ['status' => $retry ? 'pending' : 'failed', 'error' => $error,
            'delay' => max(60, min(86400, (int) $response->header('Retry-After')))];
    }
}
