<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ContentArticle extends CmsModel
{
    protected $table = 'cms_articles';

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')->where('published_at', '<=', now('UTC'));
    }

    /** @return HasOne<SeoMetadata, $this> */
    public function seo(): HasOne
    {
        return $this->hasOne(SeoMetadata::class, 'target_id')->where('target_type', 'article');
    }
}
