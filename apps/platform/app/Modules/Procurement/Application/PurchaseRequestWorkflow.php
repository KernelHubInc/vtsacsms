<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Procurement\Domain\Models\ApprovalRule;
use App\Modules\Procurement\Domain\Models\CostCenter;
use App\Modules\Procurement\Domain\Models\Department;
use App\Modules\Procurement\Domain\Models\DocumentApproval;
use App\Modules\Procurement\Domain\Models\PurchaseRequest;
use App\Modules\Procurement\Domain\Models\PurchaseRequestLine;
use App\Modules\Procurement\Domain\PurchaseRequestStatus;
use App\Modules\Tenancy\Application\CurrentTenant;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class PurchaseRequestWorkflow
{
    public function __construct(
        private CurrentTenant $tenant,
        private OutboxRecorder $outbox,
        private AuditRecorder $audit,
        private ProcurementNotifier $notifier,
        private ApprovalActorGuard $approvalActors,
    ) {}

    /**
     * @param array{
     *   request_number: string, department_id: string, cost_center_id: string, currency: string,
     *   needed_by?: string|null, business_reason: string,
     *   lines: list<array{
     *     item_id?: string|null, uom_id?: string|null, description: string,
     *     quantity_base: int, estimated_unit_minor: int
     *   }>
     * } $data
     */
    public function create(array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($data): PurchaseRequest {
            Department::query()->whereKey($data['department_id'])->where('is_active', true)->firstOrFail();
            CostCenter::query()->whereKey($data['cost_center_id'])->where('is_active', true)->firstOrFail();
            if ($data['lines'] === []) {
                throw new DomainException('A purchase request requires at least one line.');
            }
            $actorId = $this->actorId();
            $total = 0;
            foreach ($data['lines'] as $line) {
                if ($line['quantity_base'] <= 0 || $line['estimated_unit_minor'] < 0) {
                    throw new DomainException('Purchase-request quantities must be positive and prices non-negative.');
                }
                $total += $line['quantity_base'] * $line['estimated_unit_minor'];
            }

            $request = PurchaseRequest::query()->create([
                'request_number' => $data['request_number'],
                'requested_by' => $actorId,
                'department_id' => $data['department_id'],
                'cost_center_id' => $data['cost_center_id'],
                'status' => PurchaseRequestStatus::Draft,
                'currency' => mb_strtoupper($data['currency']),
                'total_minor' => $total,
                'needed_by' => $data['needed_by'] ?? null,
                'business_reason' => $data['business_reason'],
            ]);
            foreach ($data['lines'] as $line) {
                PurchaseRequestLine::query()->create([
                    'purchase_request_id' => $request->getKey(),
                    'item_id' => $line['item_id'] ?? null,
                    'uom_id' => $line['uom_id'] ?? null,
                    'description' => $line['description'],
                    'quantity_base' => $line['quantity_base'],
                    'estimated_unit_minor' => $line['estimated_unit_minor'],
                    'estimated_total_minor' => $line['quantity_base'] * $line['estimated_unit_minor'],
                ]);
            }
            $this->audit->record(new AuditEntry(
                'procurement.purchase_request.created',
                'purchase_request',
                (string) $request->getKey(),
                AuditResult::Succeeded,
                after: $request->toArray(),
            ));

            return $request->load(['department', 'costCenter', 'lines']);
        });
    }

    public function submit(PurchaseRequest $request): PurchaseRequest
    {
        return DB::transaction(function () use ($request): PurchaseRequest {
            $locked = PurchaseRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [
                PurchaseRequestStatus::Draft,
                PurchaseRequestStatus::RevisionRequested,
                PurchaseRequestStatus::Rejected,
            ], true)) {
                throw new DomainException('Only draft or returned purchase requests can be submitted.');
            }

            $rules = ApprovalRule::query()
                ->where('document_type', 'purchase_request')
                ->where('currency', $locked->currency)
                ->where('is_active', true)
                ->where('minimum_amount_minor', '<=', $locked->total_minor)
                ->where(function ($query) use ($locked): void {
                    $query->whereNull('maximum_amount_minor')
                        ->orWhere('maximum_amount_minor', '>=', $locked->total_minor);
                })
                ->where(function ($query) use ($locked): void {
                    $query->whereNull('department_id')->orWhere('department_id', $locked->department_id);
                })
                ->where(function ($query) use ($locked): void {
                    $query->whereNull('cost_center_id')->orWhere('cost_center_id', $locked->cost_center_id);
                })
                ->orderBy('sequence')
                ->get();
            if ($rules->isEmpty()) {
                throw new DomainException('No approval matrix covers this purchase request.');
            }

            foreach ($rules as $rule) {
                DocumentApproval::query()->create([
                    'document_type' => 'purchase_request',
                    'document_id' => $locked->getKey(),
                    'document_revision' => $locked->revision,
                    'sequence' => $rule->sequence,
                    'approver_role_key' => $rule->approver_role_key,
                    'status' => 'pending',
                ]);
            }
            $firstApproval = DocumentApproval::query()
                ->where('document_type', 'purchase_request')
                ->where('document_id', $locked->getKey())
                ->where('document_revision', $locked->revision)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->firstOrFail();
            DB::afterCommit(fn () => $this->notifier->approvalRequired(
                $firstApproval,
                (string) $locked->request_number,
            ));
            $before = $locked->toArray();
            $locked->forceFill([
                'status' => PurchaseRequestStatus::UnderApproval,
                'submitted_at' => now('UTC'),
                'decision_reason' => null,
                'rejected_at' => null,
            ])->save();
            $this->audit->record(new AuditEntry(
                'procurement.purchase_request.submitted',
                'purchase_request',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                before: $before,
                after: $locked->toArray(),
            ));

            return $locked->load('lines');
        });
    }

    public function approve(PurchaseRequest $request, string $approverRoleKey, ?string $reason = null): PurchaseRequest
    {
        return $this->decide($request, $approverRoleKey, 'approved', $reason);
    }

    public function reject(PurchaseRequest $request, string $approverRoleKey, string $reason): PurchaseRequest
    {
        return $this->decide($request, $approverRoleKey, 'rejected', $reason);
    }

    public function requestRevision(PurchaseRequest $request, string $reason): PurchaseRequest
    {
        if (trim($reason) === '') {
            throw new DomainException('A revision reason is required.');
        }

        return DB::transaction(function () use ($request, $reason): PurchaseRequest {
            $locked = PurchaseRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [
                PurchaseRequestStatus::UnderApproval,
                PurchaseRequestStatus::Rejected,
            ], true)) {
                throw new DomainException('This purchase request cannot be returned for revision.');
            }
            DocumentApproval::query()
                ->where('document_type', 'purchase_request')
                ->where('document_id', $locked->getKey())
                ->where('document_revision', $locked->revision)
                ->where('status', 'pending')
                ->update(['status' => 'canceled', 'decision_reason' => 'Revision requested', 'decided_at' => now('UTC')]);
            $locked->forceFill([
                'status' => PurchaseRequestStatus::RevisionRequested,
                'revision' => (int) $locked->revision + 1,
                'decision_reason' => $reason,
            ])->save();
            $this->audit->record(new AuditEntry(
                'procurement.purchase_request.revision_requested',
                'purchase_request',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reason,
                after: $locked->toArray(),
            ));

            return $locked;
        });
    }

    private function decide(
        PurchaseRequest $request,
        string $approverRoleKey,
        string $decision,
        ?string $reason,
    ): PurchaseRequest {
        return DB::transaction(function () use ($request, $approverRoleKey, $decision, $reason): PurchaseRequest {
            $locked = PurchaseRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseRequestStatus::UnderApproval) {
                throw new DomainException('Only purchase requests under approval can be decided.');
            }
            $approval = DocumentApproval::query()
                ->where('document_type', 'purchase_request')
                ->where('document_id', $locked->getKey())
                ->where('document_revision', $locked->revision)
                ->where('status', 'pending')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->firstOrFail();
            if (! hash_equals((string) $approval->approver_role_key, $approverRoleKey)) {
                throw new DomainException('This approval belongs to a different approval role.');
            }
            $this->approvalActors->assertAssignedRole($approverRoleKey);
            $this->approvalActors->assertDifferentActor((string) $locked->requested_by);
            if ($decision === 'rejected' && ($reason === null || trim($reason) === '')) {
                throw new DomainException('A rejection requires a reason.');
            }

            $approval->forceFill([
                'status' => $decision,
                'decided_by' => $this->actorId(),
                'decision_reason' => $reason,
                'decided_at' => now('UTC'),
            ])->save();
            if ($decision === 'rejected') {
                $locked->forceFill([
                    'status' => PurchaseRequestStatus::Rejected,
                    'decision_reason' => $reason,
                    'rejected_at' => now('UTC'),
                ])->save();
            } else {
                $pending = DocumentApproval::query()
                    ->where('document_type', 'purchase_request')
                    ->where('document_id', $locked->getKey())
                    ->where('document_revision', $locked->revision)
                    ->where('status', 'pending')
                    ->exists();
                if (! $pending) {
                    $locked->forceFill([
                        'status' => PurchaseRequestStatus::Approved,
                        'approved_at' => now('UTC'),
                    ])->save();
                    $this->outbox->record(
                        'procurement.requisition.approved.v1',
                        'purchase_request',
                        (string) $locked->getKey(),
                        ['request_number' => (string) $locked->request_number, 'revision' => (int) $locked->revision],
                    );
                } else {
                    $next = DocumentApproval::query()
                        ->where('document_type', 'purchase_request')
                        ->where('document_id', $locked->getKey())
                        ->where('document_revision', $locked->revision)
                        ->where('status', 'pending')
                        ->orderBy('sequence')
                        ->firstOrFail();
                    DB::afterCommit(fn () => $this->notifier->approvalRequired(
                        $next,
                        (string) $locked->request_number,
                    ));
                }
            }
            $this->audit->record(new AuditEntry(
                "procurement.purchase_request.{$decision}",
                'purchase_request',
                (string) $locked->getKey(),
                AuditResult::Succeeded,
                reason: $reason,
                metadata: ['approval_id' => (string) $approval->getKey(), 'role_key' => $approverRoleKey],
            ));

            return $locked;
        });
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Procurement changes require an accountable actor.');
    }
}
