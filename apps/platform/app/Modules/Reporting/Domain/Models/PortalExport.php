<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $requested_by
 * @property string $type
 * @property string $status
 * @property array<string, scalar|null> $filters
 * @property string|null $disk
 * @property string|null $path
 * @property int $row_count
 */
final class PortalExport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
