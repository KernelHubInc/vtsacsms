<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Sites\Pages;

use App\Filament\Operator\Resources\Sites\SiteResource;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use Filament\Resources\Pages\EditRecord;

final class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function beforeSave(): void
    {
        $this->before = $this->getRecord()->attributesToArray();
    }

    protected function afterSave(): void
    {
        app(AuditRecorder::class)->record(new AuditEntry(
            'locations.site.updated',
            'site',
            (string) $this->getRecord()->getKey(),
            AuditResult::Succeeded,
            before: $this->before,
            after: $this->getRecord()->fresh()->attributesToArray(),
        ));
    }
}
