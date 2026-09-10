<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Sites\Pages;

use App\Filament\Operator\Resources\Sites\SiteResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Resources\Pages\CreateRecord;

final class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected function afterCreate(): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'locations.site.created',
            'site',
            (string) $this->record->getKey(),
            AuditResult::Succeeded,
            after: $this->record->attributesToArray(),
        ));
    }
}
