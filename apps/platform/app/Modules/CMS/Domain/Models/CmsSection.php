<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CmsSection extends CmsModel
{
    protected $table = 'cms_sections';

    protected function casts(): array
    {
        return ['items' => 'array', 'is_enabled' => 'boolean'];
    }

    /** @return BelongsTo<CmsPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }
}
