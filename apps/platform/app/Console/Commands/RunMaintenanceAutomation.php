<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Maintenance\Application\MaintenanceAutomationService;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class RunMaintenanceAutomation extends Command
{
    protected $signature = 'maintenance:run-automation';

    protected $description = 'Generate preventive work, escalate persistent faults, validate recovery, and notify SLA breaches.';

    public function handle(CurrentTenant $tenant, MaintenanceAutomationService $automation): int
    {
        $totals = ['preventive' => 0, 'escalated' => 0, 'recovered' => 0, 'sla_breaches' => 0];
        foreach (Tenant::query()->where('status', TenantStatus::Active->value)->pluck('id') as $tenantId) {
            $result = $tenant->run(new TenantContext(
                tenantId: (string) $tenantId,
                actorType: ActorType::Service,
                actorId: null,
                correlationId: (string) Str::ulid(),
            ), fn (): array => $automation->run(CarbonImmutable::now('UTC')));
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }
        $this->components->info(sprintf(
            'Maintenance automation: %d preventive, %d escalated, %d recovered, %d SLA breaches.',
            $totals['preventive'],
            $totals['escalated'],
            $totals['recovered'],
            $totals['sla_breaches'],
        ));

        return self::SUCCESS;
    }
}
