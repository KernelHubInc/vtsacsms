<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class PartnerLogo extends CmsModel
{
    protected $table = 'cms_partner_logos';

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
