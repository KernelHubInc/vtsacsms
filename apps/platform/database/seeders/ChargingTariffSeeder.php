<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class ChargingTariffSeeder extends Seeder
{
    public function run(CurrentTenant $currentTenant): void
    {
        $currency = mb_strtoupper(trim((string) config('app.seed_demo_currency')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return;
        }

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($currentTenant, $currency): void {
            $currentTenant->run(new TenantContext(
                tenantId: (string) $tenant->getKey(),
                actorType: ActorType::Platform,
                actorId: null,
                correlationId: (string) Str::ulid(),
            ), fn () => Tariff::query()->firstOrCreate(
                ['name' => 'Local development draft tariff'],
                [
                    'currency' => $currency,
                    'description' => 'Draft only. Configure reviewed prices before publishing.',
                    'status' => TariffStatus::Draft,
                ],
            ));
        });
    }
}
