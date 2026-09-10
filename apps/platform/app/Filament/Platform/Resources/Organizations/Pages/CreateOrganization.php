<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Tenancy\Application\CurrentTenant;
use Filament\Resources\Pages\CreateRecord;

final class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(CurrentTenant::class)->get()->tenantId;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'organizations.organization.created',
            'organization',
            (string) $this->record->getKey(),
            AuditResult::Succeeded,
            after: $this->record->toArray(),
        ));
    }
}
