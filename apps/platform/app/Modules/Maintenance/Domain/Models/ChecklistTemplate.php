<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChecklistTemplate extends TenantMaintenanceModel
{
    protected $table = 'maintenance_checklist_templates';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'requires_independent_verification' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ChecklistTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
