<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\GatewayStationQuery;
use App\Modules\Tenancy\Application\GatewayTenantQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final readonly class ChargerCredentials
{
    public function __construct(private GatewayStationQuery $stations, private GatewayTenantQuery $tenants, private AuditRecorder $audit) {}

    public function setPassword(User $user, string $stationId, #[\SensitiveParameter] string $password): void
    {
        Validator::make(['ocpp_password' => $password], ['ocpp_password' => ['required', 'string', 'min:16', 'max:72', 'regex:/\A[\x21-\x7E]+\z/']])->validate();
        $station = $this->stations->manageable($user, $stationId);
        $hash = Hash::make($password);
        DB::transaction(function () use ($station, $hash): void {
            $key = ['tenant_id' => $station['tenant_id'], 'charging_station_id' => $station['charger_id']];
            DB::table('identity_charger_credentials')->upsert([
                [...$key, 'id' => (string) Str::ulid(), 'password_hash' => $hash, 'created_at' => now('UTC'), 'updated_at' => now('UTC')],
            ], ['tenant_id', 'charging_station_id'], ['password_hash', 'updated_at']);
            $this->audit->record(new AuditEntry('identity.charger_credential.updated', 'charging_station', $station['charger_id'], AuditResult::Succeeded));
        });
    }

    /** @return array{tenant_id: string, charger_id: string, charge_point_identity: string}|null */
    public function authenticate(string $identity, #[\SensitiveParameter] string $password, string $protocol): ?array
    {
        $station = $this->stations->forIdentity($identity);
        if ($station === null || ! $station['active'] || $station['protocol'] !== $protocol || ! $this->tenants->isActive($station['tenant_id'])) {
            return null;
        }
        $hash = DB::table('identity_charger_credentials')->where('tenant_id', $station['tenant_id'])
            ->where('charging_station_id', $station['charger_id'])->value('password_hash');
        // Existing gateway Argon2id credentials remain valid after the one-time import.
        if (! is_string($hash) || (password_get_info($hash)['algoName'] === 'bcrypt' && strlen($password) > 72) || ! password_verify($password, $hash)) {
            return null;
        }

        return array_intersect_key($station, array_flip(['tenant_id', 'charger_id', 'charge_point_identity']));
    }
}
