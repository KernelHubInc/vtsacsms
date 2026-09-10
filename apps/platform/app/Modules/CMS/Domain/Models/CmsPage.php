<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CmsPage extends CmsModel
{
    protected $table = 'cms_pages';

    protected function casts(): array
    {
        return ['legal_review_required' => 'boolean', 'published_at' => 'immutable_datetime'];
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now('UTC'));
    }

    /** @return HasMany<CmsSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CmsSection::class)->orderBy('sort_order');
    }

    /** @return HasOne<SeoMetadata, $this> */
    public function seo(): HasOne
    {
        return $this->hasOne(SeoMetadata::class, 'target_id')->where('target_type', 'page');
    }
}
