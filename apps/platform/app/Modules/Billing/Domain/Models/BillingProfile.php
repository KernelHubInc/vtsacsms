<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class BillingProfile extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected $hidden = ['address_encrypted', 'tax_identifier_encrypted'];

    protected function casts(): array
    {
        return ['address_encrypted' => 'encrypted:array', 'tax_identifier_encrypted' => 'encrypted', 'is_default' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }
}
