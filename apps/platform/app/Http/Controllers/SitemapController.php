<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\CMS\Domain\Models\ContentArticle;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $pages = CmsPage::query()->published()
            ->whereDoesntHave('seo', fn ($query) => $query->where('noindex', true))
            ->orderBy('slug')->get(['slug', 'updated_at']);
        $articles = ContentArticle::query()->published()
            ->whereDoesntHave('seo', fn ($query) => $query->where('noindex', true))
            ->latest('published_at')->get(['slug', 'updated_at']);

        return response()->view('public.sitemap', compact('pages', 'articles'))
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
