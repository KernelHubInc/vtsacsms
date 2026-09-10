<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Assets\Application\Contracts\AssetMaintenanceContract;
use App\Modules\Maintenance\Domain\Models\MaintenanceAttachment;
use App\Modules\Maintenance\Domain\Models\MaintenanceInspection;
use App\Modules\Maintenance\Domain\Models\Rma;
use App\Modules\Maintenance\Domain\Models\VendorRepair;
use App\Modules\Maintenance\Domain\Models\Warranty;
use App\Modules\Maintenance\Domain\Models\WorkOrder;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class MaintenanceEvidenceService
{
    public function __construct(
        private CurrentTenant $tenant,
        private WorkOrderWorkflow $workOrders,
        private AssetMaintenanceContract $assets,
    ) {}

    public function storeAttachment(
        WorkOrder $workOrder,
        UploadedFile $file,
        string $kind,
        ?CarbonImmutable $capturedAt = null,
    ): MaintenanceAttachment {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $allowed, true) || $file->getSize() > 10 * 1024 * 1024) {
            throw new DomainException('Maintenance evidence must be a JPEG, PNG, WebP, or PDF up to 10 MB.');
        }
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new DomainException('Uploaded maintenance evidence is unavailable.');
        }
        $checksum = hash_file('sha256', $realPath);
        $extension = $file->guessExtension() ?: 'bin';
        $path = sprintf(
            'maintenance/%s/work-orders/%s/%s.%s',
            $this->tenant->get()->tenantId,
            $workOrder->getKey(),
            Str::ulid(),
            $extension,
        );
        $disk = (string) config('filesystems.default', 'local');
        $stored = Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private'],
        );
        if ($stored === false) {
            throw new DomainException('Maintenance evidence could not be stored.');
        }

        return MaintenanceAttachment::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'incident_id' => null,
            'kind' => $kind,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => $checksum,
            'scan_status' => 'pending',
            'uploaded_by' => $this->actorId(),
            'captured_at' => $capturedAt,
        ]);
    }

    public function inspect(WorkOrder $workOrder, string $type, string $outcome, ?string $notes): MaintenanceInspection
    {
        if (! in_array($outcome, ['pass', 'fail', 'conditional'], true)) {
            throw new DomainException('Unsupported maintenance inspection outcome.');
        }

        return MaintenanceInspection::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'type' => $type,
            'outcome' => $outcome,
            'notes' => $notes,
            'inspected_by' => $this->actorId(),
            'inspected_at' => now('UTC'),
        ]);
    }

    public function approveInspection(MaintenanceInspection $inspection): MaintenanceInspection
    {
        $actorId = $this->actorId();
        if ($inspection->inspected_by === $actorId) {
            throw new DomainException('Inspection approval requires a different user.');
        }
        $inspection->forceFill(['approved_by' => $actorId, 'approved_at' => now('UTC')])->save();

        return $inspection;
    }

    public function openRma(
        WorkOrder $workOrder,
        string $vendorOrganizationId,
        string $rmaNumber,
        string $reason,
        int $claimedMinor,
        string $currency,
    ): Rma {
        if ($claimedMinor < 0) {
            throw new DomainException('RMA claimed amount cannot be negative.');
        }
        $this->vendorOrganization($vendorOrganizationId);
        $currency = $this->workOrderCurrency($workOrder, $currency);

        return Rma::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'warranty_id' => $workOrder->warranty_id,
            'vendor_organization_id' => $vendorOrganizationId,
            'rma_number' => $rmaNumber,
            'status' => 'requested',
            'reason' => $reason,
            'claimed_minor' => $claimedMinor,
            'currency' => $currency,
            'submitted_at' => now('UTC'),
        ]);
    }

    public function recordRmaOutcome(
        Rma $rma,
        string $status,
        int $approvedMinor,
        int $recoveredMinor,
    ): Rma {
        if (! in_array($status, ['approved', 'partially_approved', 'rejected', 'recovered', 'closed'], true)
            || $approvedMinor < 0
            || $recoveredMinor < 0
            || $recoveredMinor > $approvedMinor
            || $approvedMinor > $rma->claimed_minor) {
            throw new DomainException('RMA outcome amounts or status are invalid.');
        }
        $rma->forceFill([
            'status' => $status,
            'approved_minor' => $approvedMinor,
            'recovered_minor' => $recoveredMinor,
            'resolved_at' => in_array($status, ['rejected', 'recovered', 'closed'], true) ? now('UTC') : null,
        ])->save();
        $this->workOrders->recalculateCost(WorkOrder::query()->whereKey($rma->work_order_id)->firstOrFail());

        return $rma;
    }

    public function recordVendorRepair(
        WorkOrder $workOrder,
        string $vendorOrganizationId,
        int $costMinor,
        string $currency,
        ?string $vendorReference = null,
        ?string $rmaId = null,
    ): VendorRepair {
        if ($costMinor < 0) {
            throw new DomainException('Vendor repair cost cannot be negative.');
        }
        $this->vendorOrganization($vendorOrganizationId);
        $currency = $this->workOrderCurrency($workOrder, $currency);
        if ($rmaId !== null) {
            Rma::query()
                ->whereKey($rmaId)
                ->where('work_order_id', $workOrder->getKey())
                ->firstOrFail();
        }
        $repair = VendorRepair::query()->create([
            'work_order_id' => $workOrder->getKey(),
            'rma_id' => $rmaId,
            'vendor_organization_id' => $vendorOrganizationId,
            'status' => 'awaiting_dispatch',
            'vendor_reference' => $vendorReference,
            'cost_minor' => $costMinor,
            'currency' => $currency,
        ]);
        $this->workOrders->recalculateCost($workOrder);

        return $repair;
    }

    public function createWarranty(
        string $siteId,
        string $assetType,
        string $assetId,
        string $reference,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $vendorOrganizationId = null,
        ?string $coverageNotes = null,
    ): Warranty {
        if (! $endsAt->isAfter($startsAt)) {
            throw new DomainException('Warranty end must be after its start.');
        }
        $this->assets->assertBelongsToSite($assetType, $assetId, $siteId);
        if ($vendorOrganizationId !== null) {
            $this->vendorOrganization($vendorOrganizationId);
        }

        return Warranty::query()->create([
            'asset_type' => $assetType,
            'asset_id' => $assetId,
            'vendor_organization_id' => $vendorOrganizationId,
            'warranty_reference' => $reference,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'active',
            'coverage_notes' => $coverageNotes,
        ]);
    }

    private function actorId(): string
    {
        return $this->tenant->get()->actorId
            ?? throw new DomainException('Maintenance evidence requires an accountable actor.');
    }

    private function workOrderCurrency(WorkOrder $workOrder, string $currency): string
    {
        $normalized = mb_strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1 || $normalized !== $workOrder->currency) {
            throw new DomainException('Maintenance cost entries must use the work-order currency.');
        }

        return $normalized;
    }

    private function vendorOrganization(string $organizationId): Organization
    {
        $organization = Organization::query()->whereKey($organizationId)->firstOrFail();
        if (! in_array($organization->type, [OrganizationType::Vendor, OrganizationType::ServiceContractor], true)) {
            throw new DomainException('RMA and vendor repair records require a vendor or service contractor.');
        }

        return $organization;
    }
}
