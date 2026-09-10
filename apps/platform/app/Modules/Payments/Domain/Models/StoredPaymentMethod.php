<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class StoredPaymentMethod extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected $hidden = ['provider_token_encrypted', 'provider_token_hash'];

    protected function casts(): array
    {
        return ['provider_token_encrypted' => 'encrypted', 'is_default' => 'boolean', 'revoked_at' => 'immutable_datetime'];
    }
}
