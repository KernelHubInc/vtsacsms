<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SimulateWalletTopup extends Command
{
    protected $signature = 'wallet:simulate {tenant} {topup}';

    protected $description = 'Confirm a simulated top-up; never sends money or contacts AUB';

    public function handle(CurrentTenant $tenant, QrTopups $topups): int
    {
        $id = (string) $this->argument('tenant');
        if (! Str::isUlid($id) || ! Str::isUlid((string) $this->argument('topup')) || ! DB::table('tenants')->where('id', $id)->where('status', 'active')->exists()) {
            $this->error('Invalid tenant or top-up reference.');

            return self::FAILURE;
        }
        $tenant->run(new TenantContext($id, ActorType::Service, null, (string) Str::ulid()), fn () => $topups->simulate((string) $this->argument('topup')));
        $this->info('Simulated funds confirmed. No money moved.');

        return self::SUCCESS;
    }
}
