<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain;

enum MapSurface: string
{
    case Default = 'default';
    case Admin = 'admin';
    case Operator = 'operator';
    case UserWeb = 'user_web';
    case Public = 'public';
    case Mobile = 'mobile';

    public function settingsColumn(): string
    {
        return $this->value.'_provider';
    }
}
