<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Assets\Application\NormalizeVehicleNames as Normalizer;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class NormalizeVehicleNames extends Command
{
    protected $signature = 'assets:normalize-vehicle-names {tenant : Tenant ULID} {--apply : Save normalized names; otherwise preview the count}';

    protected $description = 'Normalize existing free-text vehicle brands and models in resumable batches.';

    public function handle(CurrentTenant $context, Normalizer $normalizer): int
    {
        $id = $this->argument('tenant');
        if (! is_string($id)) {
            $this->error('A tenant ULID is required.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->findOrFail($id);
        $apply = (bool) $this->option('apply');
        $count = $context->run(new TenantContext((string) $tenant->getKey(), ActorType::Service, null, (string) Str::ulid()),
            fn (): int => $normalizer->run($apply));
        $this->info($count.($apply ? ' vehicles updated.' : ' vehicles would be updated. Run with --apply to save.'));

        return self::SUCCESS;
    }
}
