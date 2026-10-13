<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Charging\Application\PrepaidChargingMonitor;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class MonitorPrepaidCharging extends Command
{
    protected $signature = 'charging:monitor-prepaid';

    protected $description = 'Stop prepaid sessions near their budget and settle final charging evidence once.';

    public function handle(CurrentTenant $tenant, PrepaidChargingMonitor $monitor): int
    {
        foreach (Tenant::query()->where('status', 'active')->pluck('id') as $id) {
            $tenant->run(new TenantContext((string) $id, ActorType::Service, null, (string) Str::ulid()), function () use ($monitor): void {
                CustomerChargingSession::query()->whereNull('settled_at')->orderBy('id')->chunkById(100, function ($rows) use ($monitor): void {
                    foreach ($rows as $row) {
                        $monitor->reconcile((string) $row->session_id);
                    }
                });
            });
        }

        return self::SUCCESS;
    }
}
