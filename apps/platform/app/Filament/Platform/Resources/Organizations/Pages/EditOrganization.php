<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Resources\Pages\EditRecord;

final class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = $this->getRecord()->toArray();
    }

    protected function afterSave(): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'organizations.organization.updated',
            'organization',
            (string) $this->getRecord()->getKey(),
            AuditResult::Succeeded,
            before: $this->before,
            after: $this->getRecord()->fresh()->toArray(),
        ));
    }
}
