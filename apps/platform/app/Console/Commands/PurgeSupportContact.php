<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Support\Application\SupportContactRetention;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class PurgeSupportContact extends Command
{
    protected $signature = 'support:purge-expired-contact';

    protected $description = 'Remove expired visitor email addresses from resolved support tickets.';

    public function handle(CurrentTenant $tenant, SupportContactRetention $contacts): int
    {
        if (! config('support.enabled')) {
            return self::SUCCESS;
        }
        foreach (Tenant::query()->where('status', 'active')->pluck('id') as $id) {
            $tenant->run(new TenantContext((string) $id, ActorType::Service, null, (string) Str::ulid()), fn () => $contacts->purgeDue());
        }

        return self::SUCCESS;
    }
}
