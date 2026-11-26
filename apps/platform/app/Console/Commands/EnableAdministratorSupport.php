<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\AdministratorSupportAccess;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class EnableAdministratorSupport extends Command
{
    protected $signature = 'security:enable-administrator-support
        {--tenant= : Approved tenant ULID}
        {--apply : Apply the owner-approved support-management grant}
        {--approval-reference= : Owner approval or deployment reference}';

    protected $description = 'Preview or repair support-management access on existing administrator roles in one tenant';

    public function handle(CurrentTenant $tenant, AdministratorSupportAccess $access): int
    {
        $data = Validator::make($this->options(), [
            'tenant' => ['required', 'ulid'],
            'approval-reference' => ['nullable', 'string', 'max:180'],
        ])->validate();
        $context = new TenantContext($data['tenant'], ActorType::Platform, null, (string) Str::ulid());
        $results = $tenant->run($context, fn (): array => $access->repair((bool) $this->option('apply'), $data['approval-reference'] ?? null));
        $this->line(json_encode(['applied' => (bool) $this->option('apply'), 'roles' => $results], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
