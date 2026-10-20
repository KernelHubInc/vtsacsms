<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class DriverVehicle extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['plate_number' => 'encrypted', 'plate_pending' => 'boolean', 'connector_standards' => 'array', 'is_default' => 'boolean'];
    }
}
