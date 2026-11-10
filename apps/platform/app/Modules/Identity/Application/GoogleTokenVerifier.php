<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditResult;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GoogleTokenVerifier
{
    public function verify(#[\SensitiveParameter] string $token, string $tenantId): GoogleIdentity
    {
        $server = config('google_sign_in.server_client_id');
        $android = config('google_sign_in.android_client_id');
        if (config('google_sign_in.enabled') !== true || config('google_sign_in.tenant_id') !== $tenantId
            || ! is_string($server) || $server === '' || ! is_string($android) || $android === '') {
            throw new GoogleSignInException('google_sign_in_unavailable', 'Google sign-in is currently unavailable.', 404);
        }

        $jwks = $this->keys();

        try {
            $headers = new \stdClass;
            // Only the key identifier is inspected before verification, to handle Google key rotation.
            $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
                throw new \UnexpectedValueException;
            }
            if (! in_array($header['kid'], array_column($jwks['keys'], 'kid'), true)
                && Cache::add('identity.google.jwks_refresh', true, 60)) {
                $jwks = $this->keys(true);
            }
            $claims = JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'), $headers);
            if (($headers->alg ?? null) !== 'RS256'
                || ! in_array($claims->iss ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
                || ($claims->aud ?? null) !== $server
                || (isset($claims->azp) && ! in_array($claims->azp, [$server, $android], true))
                || ! is_int($claims->exp ?? null) || $claims->exp <= now('UTC')->getTimestamp()
                || ! is_int($claims->iat ?? null) || $claims->iat > now('UTC')->getTimestamp() + 30
                || ! is_string($claims->sub ?? null) || $claims->sub === '' || strlen($claims->sub) > 255
                || ! is_string($claims->email ?? null) || strlen($claims->email) > 254
                || ! filter_var($claims->email, FILTER_VALIDATE_EMAIL)
                || ($claims->email_verified ?? null) !== true) {
                throw new \UnexpectedValueException;
            }
        } catch (GoogleSignInException $exception) {
            throw $exception;
        } catch (Throwable) {
            app(AuthenticationAudit::class)->record(null, $tenantId, 'google', AuditResult::Failed);
            throw new GoogleSignInException('google_token_invalid', 'Google sign-in expired or could not be verified. Choose your Google account again.');
        }

        $email = mb_strtolower($claims->email);

        return new GoogleIdentity(
            $claims->sub,
            $email,
            is_string($claims->given_name ?? null) ? mb_substr($claims->given_name, 0, 80) : '',
            is_string($claims->family_name ?? null) ? mb_substr($claims->family_name, 0, 80) : '',
            str_ends_with($email, '@gmail.com') || (is_string($claims->hd ?? null) && $claims->hd !== ''),
        );
    }

    /** @return array{keys: array<mixed>} */
    private function keys(bool $refresh = false): array
    {
        $cached = Cache::get('identity.google.jwks');
        if (! $refresh && is_array($cached) && is_array($cached['keys'] ?? null)) {
            return ['keys' => $cached['keys']];
        }
        try {
            $response = Http::connectTimeout(5)->timeout(10)->get('https://www.googleapis.com/oauth2/v3/certs')->throw();
            $keys = $response->json();
            if (! is_array($keys) || ! is_array($keys['keys'] ?? null) || $keys['keys'] === []) {
                throw new \UnexpectedValueException;
            }
            preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $matches);
            Cache::put('identity.google.jwks', $keys, max(60, min(86400, (int) ($matches[1] ?? 3600))));

            return ['keys' => $keys['keys']];
        } catch (Throwable) {
            throw new GoogleSignInException('google_sign_in_unavailable', 'Google sign-in is temporarily unavailable. Try again.', 503);
        }
    }
}
