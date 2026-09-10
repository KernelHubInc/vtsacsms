<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide, non-secret rendering preferences. Credentials remain deployment-managed.
 */
final class MapSetting extends Model
{
    use HasUlids;

    protected $fillable = [
        'scope',
        'default_provider',
        'admin_provider',
        'operator_provider',
        'user_web_provider',
        'public_provider',
        'mobile_provider',
        'default_latitude',
        'default_longitude',
        'default_zoom',
        'minimum_zoom',
        'maximum_zoom',
        'tile_url_template',
        'tile_attribution',
        'tile_maximum_native_zoom',
        'clustering_enabled',
    ];

    protected function casts(): array
    {
        return [
            'default_latitude' => 'float',
            'default_longitude' => 'float',
            'default_zoom' => 'integer',
            'minimum_zoom' => 'integer',
            'maximum_zoom' => 'integer',
            'tile_maximum_native_zoom' => 'integer',
            'clustering_enabled' => 'boolean',
        ];
    }
}
