<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Foundation\Database\PreventsFinancialMutation;
use App\Modules\Payments\Domain\ProviderAttemptState;
use App\Modules\Payments\Domain\ProviderOperation;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PaymentAttempt extends Model
{
    use BelongsToTenant, HasUlids, PreventsFinancialMutation;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['operation' => ProviderOperation::class, 'state' => ProviderAttemptState::class,
            'safe_evidence' => 'array', 'submitted_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
