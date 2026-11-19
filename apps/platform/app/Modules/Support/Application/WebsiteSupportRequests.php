<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Notifications\Application\SupportMailTransport;
use App\Modules\Notifications\Mail\SupportVerificationMail;
use App\Modules\Tenancy\Application\PublicSupportTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final readonly class WebsiteSupportRequests
{
    /** @param array<string,mixed> $data */
    public function start(array $data, ?string $previous): string
    {
        abort_unless(config('support.enabled'), 404);
        app(PublicSupportTenant::class)->id();
        $email = Str::lower(trim((string) $data['email']));
        $rate = 'support:email:'.hash_hmac('sha256', $email, (string) config('app.key'));
        abort_if(RateLimiter::tooManyAttempts($rate, 3), 429, 'Please wait before requesting another support code.');
        abort_unless(app(SupportMailTransport::class)->ready(), 503, 'Email support is temporarily unavailable. Please try again later.');
        RateLimiter::hit($rate, 3600);
        if ($previous !== null) {
            Cache::forget('support:challenge:'.$previous);
        }
        $challenge = Str::random(40);
        $code = (string) random_int(10000000, 99999999);
        $data['email'] = $email;
        Cache::put('support:challenge:'.$challenge, [
            'payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
            'tenant_id' => app(PublicSupportTenant::class)->id(),
            'digest' => $this->digest($challenge, $code), 'attempts' => 0, 'expires_at' => now('UTC')->addMinutes(15)->getTimestamp(),
        ], now('UTC')->addMinutes(15));
        try {
            Mail::to($email)->send(new SupportVerificationMail($code));
        } catch (\Throwable) {
            Cache::forget('support:challenge:'.$challenge);
            abort(503, 'Email support is temporarily unavailable. Please try again later.');
        }

        return $challenge;
    }

    /** @return array{id:string,ticket_number:string}|null */
    public function confirm(string $challenge, #[\SensitiveParameter] string $code, string $correlationId): ?array
    {
        abort_unless(config('support.enabled'), 404);

        return Cache::lock('support:confirm:'.$challenge, 15)->block(3, function () use ($challenge, $code, $correlationId): ?array {
            $key = 'support:challenge:'.$challenge;
            $record = Cache::get($key);
            if (! is_array($record) || ($record['tenant_id'] ?? null) !== app(PublicSupportTenant::class)->id()) {
                return null;
            }
            if (! hash_equals($record['digest'], $this->digest($challenge, $code))) {
                $record['attempts']++;
                if ($record['attempts'] >= 5) {
                    Cache::forget($key);
                } else {
                    // Never extend the original verification lifetime on failed attempts.
                    Cache::put($key, $record, now('UTC')->addSeconds(max(1, (int) ($record['expires_at'] ?? 0) - now('UTC')->getTimestamp())));
                }

                return null;
            }
            $data = json_decode(Crypt::decryptString($record['payload']), true, 512, JSON_THROW_ON_ERROR);
            $ticket = app(PublicSupportContext::class)->run($correlationId, fn () => app(SupportIntake::class)->verifiedWebsite($data, 'website:'.$challenge));

            return ['id' => (string) $ticket->getKey(), 'ticket_number' => (string) $ticket->ticket_number];
        });
    }

    private function digest(string $challenge, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $challenge.'|'.$code, (string) config('app.key'));
    }
}
