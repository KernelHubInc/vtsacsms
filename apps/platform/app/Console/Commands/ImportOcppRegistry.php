<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\ImportChargerCredentials;
use Illuminate\Console\Command;
use Throwable;

final class ImportOcppRegistry extends Command
{
    protected $signature = 'ocpp:import-registry';

    protected $description = 'Import existing OCPP password hashes from JSON on stdin; never replace existing credentials';

    public function handle(ImportChargerCredentials $credentials): int
    {
        try {
            $raw = stream_get_contents(STDIN, 1_048_577);
            if ($raw === false || strlen($raw) > 1_048_576) {
                throw new \InvalidArgumentException('Input is too large.');
            }
            $registry = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($registry) || array_is_list($registry)) {
                throw new \InvalidArgumentException('Expected a registry object.');
            }
            $count = $credentials->import($registry);
        } catch (Throwable) {
            $this->error('Import failed. No credentials were changed; verify the registry bindings and database migration.');

            return self::FAILURE;
        }
        $this->info("Imported {$count} credentials; existing credentials preserved.");

        return self::SUCCESS;
    }
}
