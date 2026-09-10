<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Maintenance\Application\Contracts\FaultObservationContract;
use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\Models\Incident;
use App\Modules\Maintenance\Domain\Models\IncidentObservation;
use App\Modules\Maintenance\Domain\Models\IncidentRule;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class FaultAutomationService implements FaultObservationContract
{
    public function __construct(
        private CurrentTenant $tenant,
        private WorkOrderWorkflow $workOrders,
        private OutboxRecorder $outbox,
        private MaintenanceNotifier $notifier,
    ) {}

    public function observe(
        string $sourceEventId,
        string $siteId,
        string $assetType,
        string $assetId,
        string $status,
        ?string $faultCode,
        CarbonImmutable $observedAt,
        array $evidence = [],
    ): void {
        DB::transaction(function () use (
            $sourceEventId,
            $siteId,
            $assetType,
            $assetId,
            $status,
            $faultCode,
            $observedAt,
            $evidence,
        ): void {
            if (IncidentObservation::query()
                ->where(fn ($query) => $query
                    ->where('source_event_id', $sourceEventId)
                    ->orWhere('source_event_id', 'like', $sourceEventId.':%'))
                ->exists()) {
                return;
            }
            $normalizedStatus = mb_strtolower($status);
            $isFault = in_array($normalizedStatus, ['faulted', 'unavailable', 'offline'], true);
            $normalizedCode = trim((string) $faultCode);
            if ($isFault && $normalizedCode === '') {
                $normalizedCode = mb_strtoupper($normalizedStatus);
            }

            if ($isFault) {
                $rule = IncidentRule::query()
                    ->where('is_active', true)
                    ->where('asset_type', $assetType)
                    ->whereRaw('LOWER(fault_code) = ?', [mb_strtolower($normalizedCode)])
                    ->first();
                if ($rule === null) {
                    return;
                }
                $this->recordFault(
                    $rule,
                    $sourceEventId,
                    $siteId,
                    $assetType,
                    $assetId,
                    $normalizedStatus,
                    $normalizedCode,
                    $observedAt,
                    $evidence,
                );

                return;
            }

            $this->recordRecovery(
                $sourceEventId,
                $assetType,
                $assetId,
                $normalizedStatus,
                $observedAt,
                $evidence,
            );
        });
    }

    public function escalatePersistent(CarbonImmutable $asOf): int
    {
        $count = 0;
        $incidents = Incident::query()
            ->whereIn('state', [IncidentState::Open->value, IncidentState::Acknowledged->value, IncidentState::Recurrent->value])
            ->whereNull('resolved_at')
            ->with('rule')
            ->get();
        foreach ($incidents as $incident) {
            if ($incident->rule_id === null) {
                continue;
            }
            $rule = IncidentRule::query()->whereKey($incident->rule_id)->firstOrFail();
            $firstEscalation = $incident->escalated_at === null;
            $seconds = $firstEscalation
                ? max((int) $rule->persistent_after_seconds, (int) $rule->escalation_after_seconds)
                : (int) $rule->escalation_after_seconds;
            $anchor = $incident->escalated_at ?? $incident->first_observed_at;
            if ($seconds <= 0 || $anchor->addSeconds($seconds)->isAfter($asOf)) {
                continue;
            }
            $nextLevel = $incident->escalation_level + 1;
            $incident->forceFill([
                'escalation_level' => $nextLevel,
                'escalated_at' => $asOf,
                'state' => $incident->occurrence_count > 1 ? IncidentState::Recurrent : $incident->state,
            ])->save();
            $this->outbox->record('maintenance.incident.escalated.v1', 'maintenance_incident', (string) $incident->getKey(), [
                'escalation_level' => $nextLevel,
                'fault_code' => $incident->fault_code,
                'site_id' => $incident->site_id,
            ]);
            $this->notifier->sitePermission(
                (string) $incident->site_id,
                PermissionKey::MaintenanceDispatch,
                'incident_escalated',
                'Persistent charger fault escalated',
                "Incident {$incident->incident_number} remains active and has escalated.",
                [
                    'incident_id' => (string) $incident->getKey(),
                    'site_id' => (string) $incident->site_id,
                    'escalation_level' => $nextLevel,
                ],
            );
            $count++;
        }

        return $count;
    }

    public function validateRecoveries(CarbonImmutable $asOf): int
    {
        $count = 0;
        $incidents = Incident::query()
            ->whereNotNull('recovery_candidate_at')
            ->whereNull('resolved_at')
            ->with('rule')
            ->get();
        foreach ($incidents as $incident) {
            if ($incident->rule_id === null) {
                continue;
            }
            $rule = IncidentRule::query()->whereKey($incident->rule_id)->firstOrFail();
            $seconds = (int) $rule->recovery_after_seconds;
            if ($incident->recovery_candidate_at?->addSeconds($seconds)->isAfter($asOf)) {
                continue;
            }
            $incident->forceFill([
                'state' => IncidentState::Resolved,
                'resolved_at' => $asOf,
                'recovery_candidate_at' => null,
            ])->save();
            $this->outbox->record('maintenance.incident.recovered.v1', 'maintenance_incident', (string) $incident->getKey(), [
                'fault_code' => $incident->fault_code,
                'asset_type' => $incident->asset_type,
                'asset_id' => $incident->asset_id,
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $evidence */
    private function recordFault(
        IncidentRule $rule,
        string $sourceEventId,
        string $siteId,
        string $assetType,
        string $assetId,
        string $status,
        string $faultCode,
        CarbonImmutable $observedAt,
        array $evidence,
    ): void {
        $fingerprint = hash('sha256', implode('|', [
            $this->tenant->get()->tenantId,
            $assetType,
            $assetId,
            mb_strtolower($faultCode),
        ]));
        $incident = Incident::query()
            ->where('fingerprint', $fingerprint)
            ->whereNull('resolved_at')
            ->whereIn('state', [
                IncidentState::Open->value,
                IncidentState::Acknowledged->value,
                IncidentState::Mitigated->value,
                IncidentState::Recurrent->value,
            ])
            ->lockForUpdate()
            ->first();
        $created = $incident === null;
        if ($incident === null) {
            $incident = Incident::query()->create([
                'incident_number' => 'INC-'.Str::ulid(),
                'source' => 'ocpp',
                'source_key' => $sourceEventId,
                'site_id' => $siteId,
                'asset_type' => $assetType,
                'asset_id' => $assetId,
                'rule_id' => $rule->getKey(),
                'fault_code' => $faultCode,
                'fingerprint' => $fingerprint,
                'state' => IncidentState::Open,
                'priority_id' => $rule->priority_id,
                'sla_policy_id' => $rule->sla_policy_id,
                'title' => "Charger fault: {$faultCode}",
                'details' => 'Created from normalized OCPP status evidence.',
                'occurrence_count' => 1,
                'first_observed_at' => $observedAt,
                'last_observed_at' => $observedAt,
            ]);
        } else {
            $incident->forceFill([
                'occurrence_count' => $incident->occurrence_count + 1,
                'last_observed_at' => max($incident->last_observed_at, $observedAt),
                'recovery_candidate_at' => null,
                'state' => $incident->occurrence_count >= 1 ? IncidentState::Recurrent : $incident->state,
            ])->save();
        }
        IncidentObservation::query()->create([
            'incident_id' => $incident->getKey(),
            'source_event_id' => $sourceEventId,
            'status' => $status,
            'fault_code' => $faultCode,
            'evidence' => $this->safeEvidence($evidence),
            'observed_at' => $observedAt,
        ]);
        $this->outbox->record(
            $created ? 'maintenance.incident.opened.v1' : 'maintenance.incident.reoccurred.v1',
            'maintenance_incident',
            (string) $incident->getKey(),
            [
                'source' => 'ocpp',
                'site_id' => $siteId,
                'asset_type' => $assetType,
                'asset_id' => $assetId,
                'fault_code' => $faultCode,
                'occurrence_count' => $incident->occurrence_count,
            ],
            $sourceEventId,
        );
        if ($created && $rule->creates_work_order) {
            $workOrder = $this->workOrders->create([
                'work_type' => 'corrective',
                'site_id' => $siteId,
                'asset_type' => $assetType,
                'asset_id' => $assetId,
                'priority_id' => (string) $rule->priority_id,
                'sla_policy_id' => $rule->sla_policy_id === null ? null : (string) $rule->sla_policy_id,
                'title' => "Investigate {$faultCode}",
                'description' => 'Automatically generated from a selected OCPP fault rule.',
                'asset_restriction_required' => true,
            ]);
            DB::table('maintenance_work_order_incidents')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $this->tenant->get()->tenantId,
                'work_order_id' => $workOrder->getKey(),
                'incident_id' => $incident->getKey(),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }
    }

    /** @param array<string, mixed> $evidence */
    private function recordRecovery(
        string $sourceEventId,
        string $assetType,
        string $assetId,
        string $status,
        CarbonImmutable $observedAt,
        array $evidence,
    ): void {
        $incidents = Incident::query()
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->whereNull('resolved_at')
            ->whereIn('state', [
                IncidentState::Open->value,
                IncidentState::Acknowledged->value,
                IncidentState::Mitigated->value,
                IncidentState::Recurrent->value,
            ])
            ->lockForUpdate()
            ->get();
        foreach ($incidents as $incident) {
            IncidentObservation::query()->create([
                'incident_id' => $incident->getKey(),
                'source_event_id' => $sourceEventId.':'.$incident->getKey(),
                'status' => $status,
                'fault_code' => null,
                'evidence' => $this->safeEvidence($evidence),
                'observed_at' => $observedAt,
            ]);
            $incident->forceFill([
                'recovery_candidate_at' => $incident->recovery_candidate_at ?? $observedAt,
                'last_observed_at' => max($incident->last_observed_at, $observedAt),
            ])->save();
        }
    }

    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function safeEvidence(array $evidence): array
    {
        return array_intersect_key($evidence, array_flip([
            'protocol',
            'charge_point_identity',
            'evse_number',
            'connector_number',
            'vendor_error_code',
        ]));
    }
}
