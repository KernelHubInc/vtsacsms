<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final readonly class PortalPricing
{
    public function __construct(private TariffManagementService $tariffs, private CurrentTenant $tenant) {}

    /** @param array<string, mixed> $input */
    public function publish(Tariff $tariff, array $input): TariffVersion
    {
        $data = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'mode' => ['required', Rule::in(['energy', 'session', 'time'])],
            'price' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'time_unit' => ['required', Rule::in(['minute', 'hour'])],
            'connector_id' => ['nullable', 'ulid'],
            'site_id' => ['nullable', 'ulid'],
            'timezone' => ['required', 'timezone:all'],
            'tax_treatment' => ['required', Rule::in(['inclusive', 'exclusive'])],
            'tax_rate_basis_points' => ['required', 'integer', 'between:0,10000'],
        ])->validate();
        [$whole, $fraction] = array_pad(explode('.', $data['price'], 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return DB::transaction(function () use ($tariff, $data, $minor): TariffVersion {
            $locked = Tariff::query()->whereKey($tariff->getKey())->lockForUpdate()->firstOrFail();
            $previousName = $locked->name;
            if (isset($data['name'])) {
                $locked->forceFill(['name' => $data['name']])->save();
            }
            $now = now('UTC');
            $versions = TariffVersion::query()->where('tariff_id', $locked->getKey())
                ->where('status', TariffStatus::Published->value)
                ->whereNull('operator_id')
                ->where('connector_id', $data['connector_id'] ?? null)
                ->where('site_id', $data['site_id'] ?? null)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('tariff_version_endings')
                    ->whereColumn('tariff_version_endings.tenant_id', 'tariff_versions.tenant_id')
                    ->whereColumn('tariff_version_endings.tariff_version_id', 'tariff_versions.id'))
                ->lockForUpdate()->get();
            foreach ($versions as $previous) {
                if ($previous->effective_from->gt($now)) {
                    throw new DomainException('This scope has scheduled pricing. Resolve the scheduled version before publishing an immediate price.');
                }
                // End applicability without modifying published prices or historical session snapshots.
                DB::table('tariff_version_endings')->insert([
                    'id' => (string) Str::ulid(), 'tenant_id' => $locked->tenant_id,
                    'tariff_version_id' => $previous->getKey(), 'ended_at' => $now,
                    'actor_id' => $this->tenant->get()->actorId, 'created_at' => $now,
                ]);
            }
            $version = $this->tariffs->addVersion($locked, [
                'effective_from' => $now, 'tax_treatment' => $data['tax_treatment'],
                'tax_rate_basis_points' => (int) $data['tax_rate_basis_points'],
                'timezone' => $data['timezone'], 'time_basis' => 'energy_flow',
                'connector_id' => $data['connector_id'] ?? null, 'site_id' => $data['site_id'] ?? null,
                'components' => [[
                    'dimension' => $data['mode'], 'price_minor' => $minor,
                    'unit_quantity' => match ($data['mode']) {
                        'energy' => 1000, 'session' => 1,
                        default => $data['time_unit'] === 'hour' ? 3600 : 60,
                    },
                ]],
            ]);

            $published = $this->tariffs->publish($version);
            app(AuditRecorder::class)->record(new AuditEntry(
                'tariffs.pricing.replaced', 'tariff', (string) $locked->getKey(), AuditResult::Succeeded,
                metadata: ['previous_name' => $previousName, 'name' => $locked->name, 'ended_version_ids' => $versions->modelKeys(), 'new_version_id' => (string) $published->getKey(), 'effective_at' => $now->toISOString()],
            ));

            return $published;
        });
    }
}
