<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain;

enum MapProvider: string
{
    case OpenStreetMap = 'openstreetmap';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::OpenStreetMap => 'OpenStreetMap',
            self::Google => 'Google Maps',
        };
    }
}
