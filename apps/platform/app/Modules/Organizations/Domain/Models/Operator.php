<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Models;

use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Operator extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'organizations';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope('organization_type', fn (Builder $query) => $query->where('type', OrganizationType::ChargePointOperator->value));
        self::creating(fn (self $operator) => $operator->type = OrganizationType::ChargePointOperator->value);
    }
}
