<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Payments\Domain\Models\QrTopup;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReconcileWalletTopups extends Command
{
    protected $signature = 'wallet:reconcile {tenant}';

    protected $description = 'Retrieve expired or ambiguous AUB top-ups; unresolved query states require review';

    public function handle(CurrentTenant $tenant, QrTopups $topups): int
    {
        $id = (string) $this->argument('tenant');
        if (! config('wallet.aub.approved') || $id !== config('wallet.aub.tenant_id')
            || ! DB::table('tenants')->where('id', $id)->where('status', 'active')->exists()) {
            $this->error('AUB review approval and the configured merchant tenant are required.');

            return self::FAILURE;
        }
        $tenant->run(new TenantContext($id, ActorType::Service, null, (string) Str::ulid()), function () use ($topups): void {
            $rows = QrTopup::query()->where('book', 'live')->whereIn('status', ['creating', 'pending', 'unknown'])
                ->where('created_at', '<', now('UTC')->subMinutes(15))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '<', now('UTC')))
                ->orderBy('id')->limit(50)->get();
            foreach ($rows as $row) {
                $topups->reconcile($row);
            }
            $this->info('Checked '.$rows->count().' orders. Ambiguous results do not credit balances.');
        });

        return self::SUCCESS;
    }
}
