<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Application\AdministratorBootstrap;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class BootstrapAdministrators extends Command
{
    protected $signature = 'security:bootstrap-administrators
        {--tenant= : Approved tenant ULID}
        {--super-admin= : Approved new super administrator email}
        {--admin= : Approved new restricted administrator email}
        {--organization= : Approved platform organization name}
        {--approval-reference= : Owner approval or change reference}
        {--confirm-bootstrap : Acknowledge initial persistent privileged access}
        {--resend-pending : Replace setup links for accounts still awaiting enrollment}';

    protected $description = 'Initialize an owner-approved tenant administrator pair and queue private one-time password enrollment';

    public function handle(CurrentTenant $tenant, AdministratorBootstrap $bootstrap): int
    {
        if (! $this->option('confirm-bootstrap')) {
            $this->error('Explicit --confirm-bootstrap is required.');

            return self::FAILURE;
        }
        $data = Validator::make($this->options(), [
            'tenant' => ['required', 'ulid'], 'super-admin' => ['required', 'email'], 'admin' => ['required', 'email'],
            'organization' => ['required', 'string', 'max:180'], 'approval-reference' => ['required', 'string', 'max:180'],
        ])->validate();
        $context = new TenantContext($data['tenant'], ActorType::Platform, null, (string) Str::ulid());
        $result = $tenant->run($context, fn (): array => $bootstrap->create($data['super-admin'], $data['admin'], $data['organization'], $data['approval-reference'], (bool) $this->option('resend-pending')));
        $this->info('Administrator bootstrap recorded. Pending setup emails are queued for private delivery.');
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
