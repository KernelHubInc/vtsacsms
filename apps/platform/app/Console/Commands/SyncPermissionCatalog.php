<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Organizations\Application\PermissionCatalog;
use Illuminate\Console\Command;

final class SyncPermissionCatalog extends Command
{
    protected $signature = 'security:sync-permissions';

    protected $description = 'Synchronize the code-owned RBAC permission catalog';

    public function handle(PermissionCatalog $catalog): int
    {
        $catalog->sync();
        $this->components->info('Permission catalog synchronized.');

        return self::SUCCESS;
    }
}
