<?php

declare(strict_types=1);

namespace App\Foundation\Demo;

final class DemoEnvironment
{
    public const TENANT_ID = '01J0000000VTSADEMA00000000';

    public static function allowsSampleData(): bool
    {
        return app()->environment(['local', 'development', 'testing', 'demo']);
    }

    private function __construct() {}
}
