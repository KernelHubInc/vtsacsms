<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class AppStoreLink extends CmsModel
{
    protected $table = 'cms_app_store_links';

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
