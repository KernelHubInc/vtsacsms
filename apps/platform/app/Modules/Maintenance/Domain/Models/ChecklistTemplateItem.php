<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

final class ChecklistTemplateItem extends TenantMaintenanceModel
{
    protected $table = 'maintenance_checklist_template_items';

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_required' => 'boolean',
            'requires_photo' => 'boolean',
            'requires_pass' => 'boolean',
        ];
    }
}
