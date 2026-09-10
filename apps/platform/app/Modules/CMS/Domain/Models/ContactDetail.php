<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class ContactDetail extends CmsModel
{
    protected $table = 'cms_contact_details';

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }
}
