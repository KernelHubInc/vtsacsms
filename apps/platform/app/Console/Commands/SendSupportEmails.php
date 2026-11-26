<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Notifications\Application\SupportEmailDelivery;
use App\Modules\Notifications\Application\SupportEscalationEmails;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SendSupportEmails extends Command
{
    protected $signature = 'notifications:send-support-emails';

    protected $description = 'Deliver queued support correspondence and Level 3 Superadmin alerts.';

    public function handle(CurrentTenant $tenant, SupportEmailDelivery $delivery, SupportEscalationEmails $escalations): int
    {
        if (! config('support.enabled')) {
            return self::SUCCESS;
        }
        foreach (Tenant::query()->where('status', 'active')->pluck('id') as $id) {
            $tenant->run(new TenantContext((string) $id, ActorType::Service, null, (string) Str::ulid()), function () use ($delivery, $escalations): void {
                $delivery->deliverDue();
                $escalations->deliverDue();
            });
        }

        return self::SUCCESS;
    }
}
