<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

final class Testimonial extends CmsModel
{
    protected $table = 'cms_testimonials';

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
