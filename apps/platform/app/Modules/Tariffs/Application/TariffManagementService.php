<?php

declare(strict_types=1);

namespace App\Modules\Tariffs\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Locations\Application\LocationScopeQuery;
use App\Modules\Organizations\Application\OrganizationScopeQuery;
use App\Modules\Tariffs\Domain\DiscountKind;
use App\Modules\Tariffs\Domain\Models\Tariff;
use App\Modules\Tariffs\Domain\Models\TariffComponent;
use App\Modules\Tariffs\Domain\Models\TariffDiscount;
use App\Modules\Tariffs\Domain\Models\TariffVersion;
use App\Modules\Tariffs\Domain\TariffDimension;
use App\Modules\Tariffs\Domain\TariffStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class TariffManagementService
{
    public function __construct(
        private CurrentTenant $tenant,
        private ConnectorSnapshotQuery $connectors,
        private LocationScopeQuery $locations,
        private OrganizationScopeQuery $organizations,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    /** @param array{name: string, description?: string|null, currency: string} $data */
    public function create(array $data): Tariff
    {
        return DB::transaction(function () use ($data): Tariff {
            $tariff = Tariff::query()->create([
                ...$data,
                'currency' => mb_strtoupper($data['currency']),
                'status' => TariffStatus::Draft,
                'created_by' => $this->tenant->get()->actorId,
            ]);
            $this->audit->record(new AuditEntry(
                'tariffs.tariff.created',
                'tariff',
                (string) $tariff->getKey(),
                AuditResult::Succeeded,
                after: $tariff->toArray(),
            ));

            return $tariff;
        });
    }

    /** @param array<string, mixed> $data */
    public function addVersion(Tariff $tariff, array $data): TariffVersion
    {
        $this->validateScope($data);
        $this->validateRatingRules($data);

        return DB::transaction(function () use ($tariff, $data): TariffVersion {
            $lockedTariff = Tariff::query()->whereKey($tariff->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedTariff->status === TariffStatus::Retired) {
                throw new DomainException('A retired tariff cannot receive new versions.');
            }
            $version = TariffVersion::query()->create([
                'tariff_id' => $lockedTariff->getKey(),
                'version' => ((int) TariffVersion::query()->where('tariff_id', $lockedTariff->getKey())->max('version')) + 1,
                'status' => TariffStatus::Draft,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'tax_treatment' => $data['tax_treatment'],
                'tax_rate_basis_points' => $data['tax_rate_basis_points'] ?? null,
                'minimum_fee_minor' => $data['minimum_fee_minor'] ?? null,
                'maximum_fee_minor' => $data['maximum_fee_minor'] ?? null,
                'operator_id' => $data['operator_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'connector_id' => $data['connector_id'] ?? null,
                'timezone' => $data['timezone'],
            ]);
            foreach ($data['components'] as $component) {
                $dimension = TariffDimension::from($component['dimension']);
                TariffComponent::query()->create([
                    'tariff_version_id' => $version->getKey(),
                    'dimension' => $dimension,
                    'price_minor' => $component['price_minor'],
                    'unit_quantity' => $component['unit_quantity'] ?? $dimension->defaultUnitQuantity(),
                    'day_of_week_mask' => $component['day_of_week_mask'] ?? 127,
                    'starts_at_local' => $component['starts_at_local'] ?? null,
                    'ends_at_local' => $component['ends_at_local'] ?? null,
                    'priority' => $component['priority'] ?? 0,
                ]);
            }
            foreach ($data['discounts'] ?? [] as $discount) {
                TariffDiscount::query()->create([
                    'tariff_version_id' => $version->getKey(),
                    'name' => $discount['name'],
                    'code' => isset($discount['code']) ? mb_strtoupper($discount['code']) : null,
                    'kind' => $discount['kind'],
                    'value' => $discount['value'],
                    'is_automatic' => $discount['is_automatic'] ?? false,
                    'effective_from' => $discount['effective_from'] ?? null,
                    'effective_to' => $discount['effective_to'] ?? null,
                ]);
            }
            $this->audit->record(new AuditEntry(
                'tariffs.version.created',
                'tariff',
                (string) $lockedTariff->getKey(),
                AuditResult::Succeeded,
                metadata: ['tariff_version_id' => (string) $version->getKey(), 'version' => $version->version],
            ));

            return $version->load(['tariff', 'components', 'discounts']);
        });
    }

    public function publish(TariffVersion $version): TariffVersion
    {
        return DB::transaction(function () use ($version): TariffVersion {
            $locked = TariffVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== TariffStatus::Draft) {
                throw new DomainException('Only draft tariff versions can be published.');
            }
            if (! $locked->components()->exists()) {
                throw new DomainException('A tariff version requires at least one price component.');
            }
            $overlap = TariffVersion::query()
                ->where('tariff_id', $locked->tariff_id)
                ->where('status', TariffStatus::Published->value)
                ->where($this->sameScope($locked))
                ->where('effective_from', '<', $locked->effective_to ?? '9999-12-31 23:59:59+00')
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $locked->effective_from))
                ->exists();
            if ($overlap) {
                throw new DomainException('Published effective periods cannot overlap for the same tariff scope.');
            }

            $locked->forceFill([
                'status' => TariffStatus::Published,
                'published_at' => now('UTC'),
                'published_by' => $this->tenant->get()->actorId,
            ])->save();
            $tariff = Tariff::query()->whereKey($locked->tariff_id)->lockForUpdate()->firstOrFail();
            if ($tariff->status === TariffStatus::Draft) {
                $tariff->forceFill(['status' => TariffStatus::Published])->save();
            }
            $this->outbox->record('tariffs.tariff.published.v1', 'tariff_version', (string) $locked->getKey(), [
                'tariff_id' => (string) $locked->tariff_id,
                'version' => (int) $locked->version,
                'effective_from' => $locked->effective_from->utc()->toISOString(),
                'effective_to' => $locked->effective_to?->utc()->toISOString(),
            ]);
            $this->audit->record(new AuditEntry(
                'tariffs.version.published',
                'tariff',
                (string) $tariff->getKey(),
                AuditResult::Succeeded,
                metadata: ['tariff_version_id' => (string) $locked->getKey()],
            ));

            return $locked->load(['tariff', 'components', 'discounts']);
        });
    }

    /** @param array<string, mixed> $data */
    private function validateScope(array $data): void
    {
        if (isset($data['connector_id']) && $this->connectors->byId($data['connector_id']) === null) {
            throw new DomainException('Tariff connector scope does not exist in this tenant.');
        }
        if (isset($data['site_id']) && ! $this->locations->siteExists($data['site_id'])) {
            throw new DomainException('Tariff site scope does not exist in this tenant.');
        }
        if (isset($data['operator_id']) && ! $this->organizations->operatorExists($data['operator_id'])) {
            throw new DomainException('Tariff operator scope does not exist in this tenant.');
        }
    }

    /** @param array<string, mixed> $data */
    private function validateRatingRules(array $data): void
    {
        $minimum = $data['minimum_fee_minor'] ?? null;
        $maximum = $data['maximum_fee_minor'] ?? null;
        if (is_int($minimum) && is_int($maximum) && $maximum < $minimum) {
            throw new DomainException('The maximum fee cannot be lower than the minimum fee.');
        }

        foreach ($data['components'] ?? [] as $component) {
            if (! is_array($component)) {
                throw new DomainException('Tariff components must be structured objects.');
            }
            $hasStart = isset($component['starts_at_local']);
            $hasEnd = isset($component['ends_at_local']);
            if ($hasStart !== $hasEnd) {
                throw new DomainException('Time-of-day tariff components require both start and end times.');
            }
        }

        foreach ($data['discounts'] ?? [] as $discount) {
            if (! is_array($discount)) {
                throw new DomainException('Tariff discounts must be structured objects.');
            }
            $kind = $discount['kind'] instanceof DiscountKind
                ? $discount['kind']
                : DiscountKind::tryFrom((string) ($discount['kind'] ?? ''));
            if ($kind === DiscountKind::Percentage && (int) ($discount['value'] ?? 0) > 10_000) {
                throw new DomainException('Percentage discounts cannot exceed 100 percent.');
            }
        }
    }

    /** @return \Closure(Builder<TariffVersion>): void */
    private function sameScope(TariffVersion $version): \Closure
    {
        return static function (Builder $query) use ($version): void {
            foreach (['operator_id', 'site_id', 'connector_id'] as $field) {
                $version->{$field} === null ? $query->whereNull($field) : $query->where($field, $version->{$field});
            }
        };
    }
}
