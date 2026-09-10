<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class CmsRedirect extends CmsModel
{
    protected $table = 'cms_redirects';

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'last_hit_at' => 'immutable_datetime'];
    }
}
