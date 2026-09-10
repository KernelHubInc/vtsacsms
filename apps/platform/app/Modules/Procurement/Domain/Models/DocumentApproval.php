<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Models;

final class DocumentApproval extends TenantProcurementModel
{
    protected $table = 'procurement_document_approvals';

    protected function casts(): array
    {
        return [
            'document_revision' => 'integer',
            'sequence' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
