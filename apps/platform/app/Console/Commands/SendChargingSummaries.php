<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Notifications\Application\ChargingSummaryDelivery;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SendChargingSummaries extends Command
{
    protected $signature = 'notifications:send-charging-summaries';

    protected $description = 'Send due charging payment confirmations to verified drivers.';

    public function handle(CurrentTenant $tenant, ChargingSummaryDelivery $delivery): int
    {
        foreach (Tenant::query()->where('status', 'active')->pluck('id') as $id) {
            $tenant->run(new TenantContext((string) $id, ActorType::Service, null, (string) Str::ulid()),
                fn () => $delivery->deliverDue());
        }

        return self::SUCCESS;
    }
}
