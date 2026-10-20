<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Application\GatewayStationQuery;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ImportChargerCredentials
{
    public function __construct(private GatewayStationQuery $stations, private CurrentTenant $tenant, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $registry */
    public function import(#[\SensitiveParameter] array $registry): int
    {
        return DB::transaction(function () use ($registry): int {
            $count = 0;
            foreach ($registry as $identity => $entry) {
                $station = $this->stations->forIdentity($identity);
                if (! is_array($entry) || $station === null || ($entry['tenant_id'] ?? null) !== $station['tenant_id'] || ($entry['charger_id'] ?? null) !== $station['charger_id'] || ($entry['enabled'] ?? null) !== true || ! empty($entry['certificate_fingerprint_sha256'])) {
                    throw new InvalidArgumentException('Registry entry cannot be migrated; verify its asset binding and authentication mode.');
                }
                $hash = $entry['basic_password_hash'] ?? null;
                if (! is_string($hash) || ! in_array(password_get_info($hash)['algoName'], ['argon2id', 'bcrypt'], true)) {
                    throw new InvalidArgumentException('Registry entry requires a supported password hash.');
                }
                $context = new TenantContext($station['tenant_id'], ActorType::Service, null, (string) Str::ulid());
                $count += $this->tenant->run($context, function () use ($station, $hash): int {
                    $created = DB::table('identity_charger_credentials')->insertOrIgnore([
                        'id' => (string) Str::ulid(), 'tenant_id' => $station['tenant_id'],
                        'charging_station_id' => $station['charger_id'], 'password_hash' => $hash,
                        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                    ]);
                    if ($created) {
                        $this->audit->record(new AuditEntry('identity.charger_credential.imported', 'charging_station', $station['charger_id'], AuditResult::Succeeded));
                    }

                    return $created;
                });
            }

            return $count;
        });
    }
}
