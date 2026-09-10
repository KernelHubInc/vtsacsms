<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $tenant_id */
abstract class TenantAssetModel extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];
}
