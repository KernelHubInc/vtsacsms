<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\WorkforceEnrollmentService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class IssueWorkforceTemporaryPassword extends Command
{
    protected $signature = 'security:issue-workforce-temporary-password
        {--tenant= : Approved tenant ULID}
        {--email= : Existing pending administrator email}
        {--approval-reference= : Owner approval or change reference}';

    protected $description = 'Replace a pending administrator setup credential and show its one-time password in a private terminal';

    public function handle(CurrentTenant $tenant, WorkforceEnrollmentService $enrollments): int
    {
        if (! defined('STDIN') || ! defined('STDOUT') || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)
            || ! $this->input->isInteractive() || $this->output->isQuiet()) {
            $this->error('Use an interactive private terminal to receive the temporary password.');

            return self::FAILURE;
        }

        $data = Validator::make($this->options(), [
            'tenant' => ['required', 'ulid'],
            'email' => ['required', 'email', 'max:254'],
            'approval-reference' => ['required', 'string', 'max:180'],
        ])->validate();

        $context = new TenantContext($data['tenant'], ActorType::Platform, null, (string) Str::ulid());
        $temporaryPassword = $tenant->run($context, fn (): string => $enrollments->issueTemporaryPassword($data['email'], $data['approval-reference']));
        $this->info('Temporary password. Valid for one setup within 24 hours.');
        $this->line($temporaryPassword);

        return self::SUCCESS;
    }
}
