<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class Faq extends CmsModel
{
    protected $table = 'cms_faqs';

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
