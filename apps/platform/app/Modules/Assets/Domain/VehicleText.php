<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

final class VehicleText
{
    public static function normalize(?string $value): string
    {
        return mb_strtoupper(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value ?? '') ?? ''), 'UTF-8');
    }
}
