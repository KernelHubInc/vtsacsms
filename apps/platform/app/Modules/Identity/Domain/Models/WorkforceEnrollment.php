<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $subject_id
 * @property string $email_hash
 * @property string $plan_hash
 * @property string|null $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $email_sent_at
 */
final class WorkforceEnrollment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'identity_workforce_enrollments';

    protected $fillable = ['tenant_id', 'subject_id', 'email_hash', 'plan_hash', 'token_hash', 'expires_at', 'completed_at', 'email_sent_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'email_sent_at' => 'immutable_datetime'];
    }
}
