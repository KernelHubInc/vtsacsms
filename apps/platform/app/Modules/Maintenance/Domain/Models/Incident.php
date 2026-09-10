<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use App\Modules\Maintenance\Domain\IncidentState;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Maintenance\Domain\Models\IncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property IncidentState $state
 * @property int $occurrence_count
 * @property int $escalation_level
 * @property CarbonImmutable $first_observed_at
 * @property CarbonImmutable $last_observed_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $recovery_candidate_at
 * @property CarbonImmutable|null $mitigated_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $escalated_at
 */
final class Incident extends TenantMaintenanceModel
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    protected $table = 'maintenance_incidents';

    protected function casts(): array
    {
        return [
            'state' => IncidentState::class,
            'occurrence_count' => 'integer',
            'escalation_level' => 'integer',
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'recovery_candidate_at' => 'immutable_datetime',
            'mitigated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<IncidentObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(IncidentObservation::class);
    }

    /** @return BelongsTo<IncidentRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(IncidentRule::class, 'rule_id');
    }
}
