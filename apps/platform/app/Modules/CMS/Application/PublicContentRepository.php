<?php

declare(strict_types=1);

namespace App\Modules\CMS\Application;

use App\Modules\CMS\Domain\Models\AppStoreLink;
use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\CMS\Domain\Models\ContactDetail;
use App\Modules\CMS\Domain\Models\ContentArticle;
use App\Modules\CMS\Domain\Models\Faq;
use App\Modules\CMS\Domain\Models\PartnerLogo;
use App\Modules\CMS\Domain\Models\Testimonial;
use Illuminate\Database\Eloquent\Collection;

final class PublicContentRepository
{
    public function page(string $slug): CmsPage
    {
        return CmsPage::query()
            ->published()
            ->with(['sections' => fn ($query) => $query->where('is_enabled', true), 'seo'])
            ->where('slug', $slug)
            ->sole();
    }

    /** @return Collection<int, Faq> */
    public function faqs(): Collection
    {
        return Faq::query()->where('is_published', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return Collection<int, ContentArticle> */
    public function articles(): Collection
    {
        return ContentArticle::query()->published()->with('seo')->latest('published_at')->get();
    }

    public function article(string $slug): ContentArticle
    {
        return ContentArticle::query()->published()->with('seo')->where('slug', $slug)->sole();
    }

    /** @return Collection<int, PartnerLogo> */
    public function partners(): Collection
    {
        return PartnerLogo::query()->where('is_published', true)->orderBy('sort_order')->get();
    }

    /** @return Collection<int, Testimonial> */
    public function testimonials(): Collection
    {
        return Testimonial::query()->where('is_published', true)->orderBy('sort_order')->get();
    }

    /** @return Collection<int, AppStoreLink> */
    public function appLinks(): Collection
    {
        return AppStoreLink::query()->where('is_published', true)->whereNotNull('url')->orderBy('store')->get();
    }

    /** @return Collection<int, ContactDetail> */
    public function contacts(): Collection
    {
        return ContactDetail::query()->where('is_public', true)->orderBy('sort_order')->get();
    }
}
