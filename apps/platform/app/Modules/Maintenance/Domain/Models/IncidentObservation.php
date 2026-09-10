<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * @property array<string, mixed>|null $evidence
 * @property CarbonImmutable $observed_at
 */
final class IncidentObservation extends TenantMaintenanceModel
{
    protected $table = 'maintenance_incident_observations';

    protected function casts(): array
    {
        return ['evidence' => 'array', 'observed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new DomainException('Incident observations are immutable.');
        });
        self::deleting(static function (): never {
            throw new DomainException('Incident observations are immutable.');
        });
    }
}
