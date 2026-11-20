<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\SupportStationQuery;
use App\Modules\Charging\Application\SupportSessionQuery;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Notifications\Application\SupportEmailRequests;
use App\Modules\Support\Domain\Models\SupportIntakeRequest;
use App\Modules\Support\Domain\Models\SupportTicket;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SupportIntake
{
    public const CATEGORIES = ['charger' => 'Charger', 'session' => 'Charging session', 'payment' => 'Payment', 'receipt' => 'Receipt', 'general' => 'General enquiry', 'other' => 'Other'];

    /** @param array<string,mixed> $data */
    public function mobile(User $actor, array $data, string $key): SupportTicket
    {
        abort_unless(config('support.enabled'), 404);
        $context = app(CurrentTenant::class)->get();
        abort_unless($actor->isEnabled() && $actor->hasVerifiedEmail() && $context->actorType === ActorType::Human && $context->actorId === $actor->public_id, 403);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $target = app(SupportSessionQuery::class)->forDriver((string) $actor->public_id, (string) $data['session_id']);

        return $this->create($data, 'mobile:'.hash('sha256', $actor->public_id.':'.$key), 'mobile', (int) $actor->getKey(), $target['site_id'], $target['session_id']);
    }

    /**
     * Called only after browser-bound contact email verification.
     *
     * @param  array<string,mixed>  $data
     */
    public function verifiedWebsite(array $data, string $key): SupportTicket
    {
        abort_unless(config('support.enabled'), 404);
        $siteId = empty($data['station_id']) ? null : app(SupportStationQuery::class)->site((string) $data['station_id']);

        return $this->create($data, $key, 'website', null, $siteId, null);
    }

    /** @param array<string,mixed> $data */
    private function create(array $data, string $key, string $source, ?int $requester, ?string $site, ?string $session): SupportTicket
    {
        $images = app(SupportImages::class)->prepare($data['images'] ?? []);
        unset($data['images']);
        $data = Validator::make($data, ['category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'description' => ['required', 'string', 'min:10', 'max:5000'], 'subject' => ['nullable', 'string', 'max:160'],
            'email' => [$source === 'website' ? 'required' : 'nullable', 'email', 'max:254'],
        ])->validate();
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $fingerprintData = $data;
        if ($images !== []) {
            $fingerprintData['image_digests'] = array_column($images, 'digest');
        }
        $fingerprint = hash_hmac('sha256', json_encode([$source, $requester, $site, $session, $fingerprintData], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($data, $key, $source, $requester, $site, $session, $fingerprint, $images): SupportTicket {
            $existing = SupportIntakeRequest::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request reference was already used for a different issue.']);
                }

                return SupportTicket::query()->findOrFail($existing->ticket_id);
            }
            $ticket = SupportTicket::query()->firstOrCreate(['open_fingerprint' => $fingerprint], [
                'status' => 'open', 'priority' => 'normal', 'support_level' => 2,
                'ticket_number' => 'SUP-'.Str::ulid(), 'requester_user_id' => $requester,
                'site_id' => $site, 'session_id' => $session, 'source' => $source,
                'category' => $data['category'], 'subject' => $data['subject'] ?? self::CATEGORIES[$data['category']].' support request',
                'description' => $data['description'], 'contact_email' => $source === 'website' ? $data['email'] : null,
                'contact_verified_at' => $source === 'website' ? now('UTC') : null,
                'idempotency_key' => $key, 'request_fingerprint' => $fingerprint,
            ]);
            $intent = SupportIntakeRequest::query()->firstOrCreate(['idempotency_key' => $key], [
                'ticket_id' => $ticket->getKey(), 'request_fingerprint' => $fingerprint,
            ]);
            if (! hash_equals((string) $intent->request_fingerprint, $fingerprint) || $intent->ticket_id !== $ticket->getKey()) {
                throw ValidationException::withMessages(['idempotency_key' => 'This request reference was already used for a different issue.']);
            }
            if (! $ticket->wasRecentlyCreated) {
                return $ticket;
            }
            app(SupportImages::class)->save($ticket, $images);
            app(SupportEmailRequests::class)->enqueue((string) $ticket->getKey());
            app(AuditRecorder::class)->record(new AuditEntry('support.ticket.created', 'support_ticket', (string) $ticket->getKey(),
                AuditResult::Succeeded, metadata: ['source' => $source, 'site_id' => $site, 'session_id' => $session]));

            return $ticket;
        });
    }
}
