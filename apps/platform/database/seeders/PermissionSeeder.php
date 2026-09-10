<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Organizations\Application\PermissionCatalog;
use Illuminate\Database\Seeder;

final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionCatalog::class)->sync();
    }
}
