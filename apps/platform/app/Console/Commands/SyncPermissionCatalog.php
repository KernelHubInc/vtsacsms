<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Organizations\Application\PermissionCatalog;
use App\Modules\Organizations\Application\WorkforceRolePresets;
use Illuminate\Console\Command;

final class SyncPermissionCatalog extends Command
{
    protected $signature = 'security:sync-permissions';

    protected $description = 'Synchronize the code-owned RBAC permission catalog';

    public function handle(PermissionCatalog $catalog, WorkforceRolePresets $presets): int
    {
        $catalog->sync();
        $presets->sync();
        $this->components->info('Permission catalog synchronized.');

        return self::SUCCESS;
    }
}
