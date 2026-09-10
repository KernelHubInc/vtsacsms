<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $tenant_id */
abstract class TenantInventoryModel extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];
}
