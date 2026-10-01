<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Kyc;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Identity\Infrastructure\Kyc\KycClient;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class KycService
{
    public function __construct(private CurrentTenant $tenant, private KycClient $client, private AuditRecorder $audit) {}

    public function latest(User $user): ?KycVerification
    {
        $row = KycVerification::query()->where('subject_id', $user->public_id)->latest('id')->first();
        if ($row?->status === KycStatus::Approved && $row->expires_at?->isPast()) {
            $this->transition($row, KycStatus::Expired, 'VERIFICATION_EXPIRED');
        }

        return $row?->refresh();
    }

    public function owned(User $user, string $id): KycVerification
    {
        return KycVerification::query()->where('subject_id', $user->public_id)->findOrFail($id);
    }

    /** @param array<string, mixed> $data */
    public function start(User $user, array $data, bool $resubmit = false): KycVerification
    {
        if (! in_array(config('kyc.assurance_profile'), ['issuer_v1', 'optical_v1'], true)) {
            throw new KycException('KYC_NOT_CONFIGURED', 503);
        }
        $fingerprint = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $row = DB::transaction(function () use ($user, $data, $fingerprint, $resubmit): KycVerification {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = KycVerification::query()->where('subject_id', $user->public_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new KycException('IDEMPOTENCY_CONFLICT', 409);
                }

                return $existing;
            }
            $latest = $this->latest($user);
            if ($latest !== null && (! $resubmit || ! $latest->status->canResubmit())) {
                throw new KycException('VERIFICATION_ALREADY_EXISTS', 409);
            }
            $row = KycVerification::query()->create([
                'subject_id' => $user->public_id, 'idempotency_key' => $data['idempotency_key'],
                'request_fingerprint' => $fingerprint, 'status' => KycStatus::InProgress,
                'document_type' => $data['document_type'], 'consent_version' => $data['consent_version'],
                'consented_at' => now('UTC'),
                'review_mode' => app(KycSettings::class)->mode(),
                'assurance_profile' => config('kyc.assurance_profile'),
            ]);
            $this->record($row, 'CONSENT_RECORDED');

            return $row;
        });
        if ($row->status !== KycStatus::InProgress) {
            return $row;
        }
        $document = config('kyc.documents.'.$row->document_type);
        unset($document['label']);
        $response = $this->client->request('PUT', '/api/v1/verifications/'.$row->id, [
            ...$this->scope($row), 'document' => ['key' => $row->document_type, ...$document],
            'personal' => $data['personal'], 'consent_version' => $data['consent_version'],
            ...($row->assurance_profile === 'optical_v1' ? ['assurance_profile' => 'optical_v1'] : []),
        ]);

        return $this->apply($row, $response);
    }

    public function upload(KycVerification $row, string $kind, UploadedFile $file): KycVerification
    {
        if ($kind === 'selfie' && $row->assurance_profile === 'optical_v1') {
            throw new KycException('LIVE_CAPTURE_REQUIRED', 409);
        }
        if ($row->status !== KycStatus::PendingUpload) {
            throw new KycException('INVALID_STATE_TRANSITION', 409);
        }
        $response = $this->client->request('POST', '/api/v1/verifications/'.$row->id.'/evidence', [
            ...$this->scope($row), 'kind' => $kind, 'mime' => $file->getMimeType(),
            'content' => base64_encode($file->getContent()),
        ]);

        return $this->apply($row, $response);
    }

    /** @return array<string, mixed> */
    public function live(KycVerification $row, ?UploadedFile $file = null, ?string $token = null): array
    {
        if ($row->status !== KycStatus::PendingUpload || $row->assurance_profile !== 'optical_v1') {
            throw new KycException('INVALID_STATE_TRANSITION', 409);
        }
        $payload = $this->scope($row);
        if ($file !== null) {
            $payload = [...$payload, 'token' => $token, 'mime' => $file->getMimeType(), 'content' => base64_encode($file->getContent())];
        }
        $data = $this->client->challenge($this->client->request('POST', '/api/v1/verifications/'.$row->id.'/live/'.($file === null ? 'start' : 'frame'), $payload));
        if ($file === null || $data['complete']) {
            $this->reconcile($row);
        }

        return $data;
    }

    public function submit(KycVerification $row): KycVerification
    {
        if (! in_array($row->status, [KycStatus::PendingUpload, KycStatus::Submitted, KycStatus::Processing], true)) {
            return $row;
        }

        return $this->apply($row, $this->client->request('POST', '/api/v1/verifications/'.$row->id.'/submit', $this->scope($row)));
    }

    public function reconcile(KycVerification $row): KycVerification
    {
        return $this->apply($row, $this->client->request('POST', '/api/v1/verifications/'.$row->id.'/snapshot', $this->scope($row)));
    }

    /** @param array<string, mixed> $response */
    public function apply(KycVerification $row, array $response, ?string $eventId = null): KycVerification
    {
        $data = $this->client->snapshot($response);
        if (! app()->environment(['local', 'testing']) && (($data['result']['provider'] ?? '') === 'mock' || in_array('mock', $data['result']['checks'] ?? [], true))) {
            throw new KycException('MOCK_PROVIDER_FORBIDDEN', 503);
        }
        if ($data['id'] !== $row->id || $data['tenant_id'] !== $row->tenant_id || $data['subject_id'] !== $row->subject_id) {
            throw new KycException('INVALID_SERVICE_RESPONSE', 503);
        }

        return DB::transaction(function () use ($row, $data, $eventId): KycVerification {
            $row = KycVerification::query()->lockForUpdate()->findOrFail($row->id);
            if ($eventId !== null && DB::table('kyc_events')->where('tenant_id', $row->tenant_id)->where('event_id', $eventId)->exists()) {
                return $row;
            }
            if ((int) $data['version'] <= $row->service_version && $eventId === null) {
                return $row;
            }
            if ((int) $data['version'] > $row->service_version) {
                $next = KycStatus::from($data['status']);
                $reason = $data['result']['reason_code'] ?? null;
                if ($next === KycStatus::Approved || $next === KycStatus::Rejected) {
                    if ($row->review_mode === 'manual') {
                        $next = KycStatus::NeedsReview;
                        $reason = 'MANUAL_REVIEW_REQUIRED';
                    } elseif ($next === KycStatus::Approved && ! $this->assurancePassed($row, $data)) {
                        $next = KycStatus::NeedsReview;
                        $reason = 'ASSURANCE_INCOMPLETE';
                    }
                }
                $row->service_version = (int) $data['version'];
                $row->evidence = $data['evidence'];
                $row->evidence_deleted = $data['evidence_deleted'];
                $row->processing_result = $data['result'] ?? null;
                // Human decisions and cancellation are authoritative over delayed provider results.
                if ($row->reviewed_at === null && $row->status->canTransitionTo($next)) {
                    $this->setState($row, $next, $reason);
                }
                $row->save();
            }
            $this->record($row, $row->reason_code, $eventId);

            return $row;
        });
    }

    public function review(User $actor, KycVerification $row, KycStatus $next, string $reason): KycVerification
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::KycReview), 403);
        if (! in_array($next, [KycStatus::Approved, KycStatus::Rejected, KycStatus::ActionRequired], true) || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 500) {
            throw new KycException('REVIEW_REASON_REQUIRED');
        }
        // Ensure a reviewer does not approve evidence already removed by retention.
        $this->reconcile($row);

        return DB::transaction(function () use ($row, $actor, $next, $reason): KycVerification {
            $row = KycVerification::query()->lockForUpdate()->findOrFail($row->id);
            if ($row->status !== KycStatus::NeedsReview || $row->evidence_deleted) {
                throw new KycException('INVALID_STATE_TRANSITION', 409);
            }
            if ($row->subject_id === $actor->public_id) {
                throw new KycException('SELF_REVIEW_FORBIDDEN', 403);
            }
            $this->setState($row, $next, 'MANUAL_'.$next->value);
            $row->forceFill(['reviewed_at' => now('UTC'), 'reviewed_by' => $actor->public_id, 'review_reason' => $reason])->save();
            $this->record($row, $row->reason_code);

            return $row;
        });
    }

    public function cancel(KycVerification $row): KycVerification
    {
        try {
            $this->client->request('POST', '/api/v1/verifications/'.$row->id.'/erase', $this->scope($row));
        } catch (KycException $exception) {
            if ($row->status !== KycStatus::InProgress || $exception->errorCode !== 'VERIFICATION_NOT_FOUND') {
                throw $exception;
            }
        }
        $this->transition($row, KycStatus::Cancelled, 'CONSENT_WITHDRAWN');

        return $row->refresh();
    }

    /** @return array<string, mixed> */
    public function details(User $actor, KycVerification $row): array
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::KycSensitiveView), 403);
        $this->audit->record(new AuditEntry('identity.kyc.evidence_viewed', 'kyc_verification', $row->id, AuditResult::Succeeded));
        $details = $this->client->request('POST', '/api/v1/verifications/'.$row->id.'/details', $this->scope($row));
        $snapshot = $this->client->snapshot($details['snapshot'] ?? []);
        if ($snapshot['id'] !== $row->id || $snapshot['tenant_id'] !== $row->tenant_id || $snapshot['subject_id'] !== $row->subject_id) {
            throw new KycException('INVALID_SERVICE_RESPONSE', 503);
        }
        $allowed = ['full_name', 'first_name', 'middle_name', 'last_name', 'birth_date', 'document_number', 'expiration_date', 'nationality', 'issuing_country'];
        $result = [];
        foreach (['personal', 'extracted'] as $section) {
            foreach ($allowed as $field) {
                $value = $details[$section][$field] ?? null;
                if ($value !== null && (! is_string($value) || mb_strlen($value) > 180)) {
                    throw new KycException('INVALID_SERVICE_RESPONSE', 503);
                }
                $result[$section][$field] = $field === 'document_number' && $value !== null
                    ? '****'.mb_substr($value, -4) : $value;
            }
        }

        return $result;
    }

    public function image(User $actor, KycVerification $row, string $kind): string
    {
        abort_unless(app(AuthorizationService::class)->allows($actor, PermissionKey::KycSensitiveView), 403);
        abort_unless(in_array($kind, ['front', 'back', 'selfie'], true), 422);
        $this->audit->record(new AuditEntry('identity.kyc.image_viewed', 'kyc_verification', $row->id, AuditResult::Succeeded, metadata: ['kind' => $kind]));
        $data = $this->client->request('POST', '/api/v1/verifications/'.$row->id.'/image', [...$this->scope($row), 'kind' => $kind]);
        $content = $data['content'] ?? null;
        if (($data['mime'] ?? null) !== 'image/jpeg' || ! is_string($content) || strlen($content) > 12 * 1024 * 1024 || base64_decode($content, true) === false) {
            throw new KycException('INVALID_SERVICE_RESPONSE', 503);
        }

        return 'data:image/jpeg;base64,'.$content;
    }

    private function transition(KycVerification $row, KycStatus $next, string $code): void
    {
        DB::transaction(function () use ($row, $next, $code): void {
            $row = KycVerification::query()->lockForUpdate()->findOrFail($row->id);
            if ($row->status === $next) {
                return;
            }
            if (! $row->status->canTransitionTo($next)) {
                throw new KycException('INVALID_STATE_TRANSITION', 409);
            }
            $this->setState($row, $next, $code);
            $row->save();
            $this->record($row, $code);
        });
    }

    /** @param array<string, mixed> $data */
    private function assurancePassed(KycVerification $row, array $data): bool
    {
        if (! config('kyc.automatic_verification_enabled') || ($data['evidence_deleted'] ?? true)) {
            return false;
        }
        $required = match ($row->assurance_profile) {
            'optical_v1' => ['ocr', 'optical_document', 'document_data', 'face_match', 'liveness'],
            'issuer_v1' => ['ocr', 'document', 'face_match', 'liveness'],
            default => [],
        };
        if ($required === [] || in_array('failed', $data['result']['checks'] ?? [], true)) {
            return false;
        }
        foreach ($required as $check) {
            if (($data['result']['checks'][$check] ?? null) !== 'passed') {
                return false;
            }
        }

        return true;
    }

    private function setState(KycVerification $row, KycStatus $next, ?string $code): void
    {
        $row->status = $next;
        $row->reason_code = $code;
        if ($next === KycStatus::Submitted) {
            $row->setAttribute('submitted_at', now('UTC'));
        }
        if ($next === KycStatus::Processing) {
            $row->setAttribute('processing_started_at', now('UTC'));
        }
        if ($next === KycStatus::Approved) {
            $row->setAttribute('verified_at', now('UTC'));
            $row->expires_at = now('UTC')->toImmutable()->addDays((int) config('kyc.validity_days'));
        }
        if ($next === KycStatus::Rejected) {
            $row->setAttribute('rejected_at', now('UTC'));
        }
    }

    private function record(KycVerification $row, ?string $code, ?string $eventId = null): void
    {
        DB::table('kyc_events')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $row->tenant_id,
            'verification_id' => $row->id, 'event_id' => $eventId,
            'status' => $row->status->value, 'reason_code' => $code, 'occurred_at' => now('UTC'),
        ]);
        $this->audit->record(new AuditEntry(
            'identity.kyc.updated', 'kyc_verification', $row->id, AuditResult::Succeeded,
            after: ['status' => $row->status->value, 'reason_code' => $code],
        ));
    }

    /** @return array{tenant_id: string, subject_id: string} */
    private function scope(KycVerification $row): array
    {
        return ['tenant_id' => $this->tenant->get()->tenantId, 'subject_id' => $row->subject_id];
    }
}
