<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $category
 * @property string $title
 * @property string $message
 * @property bool $show_on_open
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
final class DriverNotice extends CmsModel
{
    protected $table = 'cms_driver_notices';

    protected function casts(): array
    {
        return [
            'show_on_open' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $now = now('UTC');
        $query->where('status', 'published')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now);
    }
}
