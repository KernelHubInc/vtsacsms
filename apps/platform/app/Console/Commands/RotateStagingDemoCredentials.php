<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\StagingDemoCredentials;
use Illuminate\Console\Command;
use Throwable;

final class RotateStagingDemoCredentials extends Command
{
    protected $signature = 'identity:rotate-staging-demo {--apply : Apply the change; default is a preview} {--password-stdin : Read the password from standard input without echo}';

    protected $description = 'Preview or rotate the 19 existing staging demo logins without reseeding data';

    public function handle(StagingDemoCredentials $credentials): int
    {
        if (! app()->environment(['staging', 'testing'])) {
            $this->error('This command is restricted to staging.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->table(['Existing login', 'New login'], array_map(fn (array $account): array => [$account['old_email'], $account['email']], $credentials->accounts()));

            return self::SUCCESS;
        }
        $password = $this->option('password-stdin') ? rtrim((string) fgets(STDIN, 256), "\r\n") : $this->secret('New staging demo password');
        try {
            $count = $credentials->rotate((string) $password);
        } catch (Throwable) {
            $this->error('Rotation failed. No credentials were changed. Check the environment, seeded identity bindings, target email collisions, and password length.');

            return self::FAILURE;
        } finally {
            unset($password);
        }
        $this->info("Updated {$count} existing demo accounts and revoked their old sessions. Roles, wallets, vehicles, and history were preserved.");

        return self::SUCCESS;
    }
}
