<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ReconcileKyc extends Command
{
    protected $signature = 'kyc:reconcile';

    protected $description = 'Reconcile KYC jobs and evidence retention with the private service.';

    public function handle(CurrentTenant $tenant, KycService $kyc): int
    {
        if (! config('kyc.enabled')) {
            return self::SUCCESS;
        }
        $failed = 0;
        foreach (Tenant::query()->cursor() as $record) {
            $tenant->run(new TenantContext((string) $record->getKey(), ActorType::Service, null, (string) Str::ulid()), function () use ($kyc, &$failed): void {
                KycVerification::query()->where('evidence_deleted', false)->orderBy('id')->chunkById(100, function ($rows) use ($kyc, &$failed): void {
                    foreach ($rows as $row) {
                        try {
                            $kyc->reconcile($row);
                        } catch (KycException) {
                            $failed++;
                        }
                    }
                });
            });
        }
        $this->components->info('KYC reconciliation completed; unavailable jobs: '.$failed);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
