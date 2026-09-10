<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\Contracts\AssetMaintenanceContract;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Maintenance\Domain\IncidentState;
use App\Modules\Maintenance\Domain\Models\Incident;
use App\Modules\Maintenance\Domain\Models\ServiceRequest;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class MaintenanceIntakeService
{
    public function __construct(
        private CurrentTenant $tenant,
        private AssetMaintenanceContract $assets,
        private WorkOrderWorkflow $workOrders,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     * site_id:string,title:string,description:string,source?:string,asset_type?:string|null,
     * asset_id?:string|null,priority_id?:string|null
     * } $data
     */
    public function submitServiceRequest(array $data): ServiceRequest
    {
        if (($data['asset_type'] ?? null) !== null && ($data['asset_id'] ?? null) !== null) {
            $this->assets->assertBelongsToSite($data['asset_type'], $data['asset_id'], $data['site_id']);
        }
        $request = ServiceRequest::query()->create([
            ...$data,
            'request_number' => 'SR-'.Str::ulid(),
            'source' => $data['source'] ?? 'operator',
            'status' => 'submitted',
            'requested_by' => $this->userKey(),
            'submitted_at' => now('UTC'),
        ]);
        $this->outbox->record('maintenance.service_request.submitted.v1', 'maintenance_service_request', (string) $request->getKey(), [
            'site_id' => $request->site_id,
            'asset_type' => $request->asset_type,
            'asset_id' => $request->asset_id,
        ]);

        return $request;
    }

    /**
     * @param array{
     * site_id:string,asset_type:string,asset_id:string,priority_id:string,
     * fault_code:string,title:string,details?:string|null,sla_policy_id?:string|null
     * } $data
     */
    public function openManualIncident(array $data): Incident
    {
        $this->assets->assertBelongsToSite($data['asset_type'], $data['asset_id'], $data['site_id']);
        $now = now('UTC');
        $sourceKey = (string) Str::ulid();
        $incident = Incident::query()->create([
            ...$data,
            'incident_number' => 'INC-'.Str::ulid(),
            'source' => 'manual',
            'source_key' => $sourceKey,
            'fingerprint' => hash('sha256', implode('|', [
                $this->tenant->get()->tenantId,
                'manual',
                $sourceKey,
            ])),
            'state' => IncidentState::Open,
            'first_observed_at' => $now,
            'last_observed_at' => $now,
        ]);
        $this->outbox->record('maintenance.incident.opened.v1', 'maintenance_incident', (string) $incident->getKey(), [
            'source' => 'manual',
            'site_id' => $incident->site_id,
            'asset_type' => $incident->asset_type,
            'asset_id' => $incident->asset_id,
            'fault_code' => $incident->fault_code,
        ]);
        $this->audit->record(new AuditEntry(
            'maintenance.incident.opened',
            'maintenance_incident',
            (string) $incident->getKey(),
            AuditResult::Succeeded,
        ));

        return $incident;
    }

    public function convert(ServiceRequest $request, string $priorityId): ServiceRequest
    {
        return DB::transaction(function () use ($request, $priorityId): ServiceRequest {
            $locked = ServiceRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'submitted' || $locked->asset_type === null || $locked->asset_id === null) {
                throw new DomainException('Only an unconverted asset service request can be converted.');
            }
            $incident = $this->openManualIncident([
                'site_id' => (string) $locked->site_id,
                'asset_type' => (string) $locked->asset_type,
                'asset_id' => (string) $locked->asset_id,
                'priority_id' => $priorityId,
                'fault_code' => 'SERVICE_REQUEST',
                'title' => (string) $locked->title,
                'details' => (string) $locked->description,
            ]);
            $workOrder = $this->workOrders->create([
                'work_type' => 'corrective',
                'site_id' => (string) $locked->site_id,
                'asset_type' => (string) $locked->asset_type,
                'asset_id' => (string) $locked->asset_id,
                'priority_id' => $priorityId,
                'service_request_id' => (string) $locked->getKey(),
                'title' => (string) $locked->title,
                'description' => (string) $locked->description,
            ]);
            DB::table('maintenance_work_order_incidents')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $this->tenant->get()->tenantId,
                'work_order_id' => $workOrder->getKey(),
                'incident_id' => $incident->getKey(),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $locked->forceFill([
                'status' => 'converted',
                'converted_at' => now('UTC'),
                'converted_incident_id' => $incident->getKey(),
                'converted_work_order_id' => $workOrder->getKey(),
            ])->save();

            return $locked;
        });
    }

    private function userKey(): int
    {
        $actorId = $this->tenant->get()->actorId
            ?? throw new DomainException('Maintenance intake requires an accountable actor.');

        return (int) User::query()->where('public_id', $actorId)->firstOrFail()->getKey();
    }
}
