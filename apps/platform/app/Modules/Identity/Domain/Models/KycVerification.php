<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $subject_id
 * @property string $request_fingerprint
 * @property string $document_type
 * @property string $review_mode
 * @property string $assurance_profile
 * @property KycStatus $status
 * @property int $service_version
 * @property array<int, array<string, mixed>>|null $evidence
 * @property array<string, mixed>|null $processing_result
 * @property bool $evidence_deleted
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $reason_code
 */
final class KycVerification extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected $hidden = ['request_fingerprint', 'review_reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'service_version' => 'integer',
            'evidence' => 'array',
            'processing_result' => 'array',
            'evidence_deleted' => 'boolean',
            'review_reason' => 'encrypted',
            'consented_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
