<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class SeoMetadata extends CmsModel
{
    protected $table = 'cms_seo_metadata';

    protected function casts(): array
    {
        return ['noindex' => 'boolean', 'structured_data' => 'array'];
    }
}
