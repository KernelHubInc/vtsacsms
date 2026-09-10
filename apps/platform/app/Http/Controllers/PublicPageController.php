<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CMS\Application\PublicContentRepository;
use App\Modules\Integrations\Application\MapConfigurationResolver;
use App\Modules\Integrations\Domain\MapSurface;
use Illuminate\Contracts\View\View;

final class PublicPageController extends Controller
{
    public function __invoke(
        string $slug,
        PublicContentRepository $content,
        MapConfigurationResolver $maps,
    ): View {
        $page = $content->page($slug);
        $data = ['page' => $page, 'faqs' => collect(), 'articles' => collect(), 'partners' => collect(), 'testimonials' => collect(), 'appLinks' => collect(), 'contacts' => collect()];

        $data['faqs'] = in_array($page->template, ['home', 'faq'], true) ? $content->faqs() : collect();
        $data['articles'] = in_array($page->template, ['home', 'articles'], true) ? $content->articles() : collect();
        $data['partners'] = in_array($page->template, ['home', 'network'], true) ? $content->partners() : collect();
        $data['testimonials'] = $page->template === 'home' ? $content->testimonials() : collect();
        $data['appLinks'] = in_array($page->template, ['home', 'app'], true) ? $content->appLinks() : collect();
        $data['contacts'] = in_array($page->template, ['contact', 'support'], true) ? $content->contacts() : collect();
        $data['mapConfig'] = $page->template === 'map'
            ? $maps->forSurface(MapSurface::Public)->toWebArray()
            : null;

        return view($page->template === 'map' ? 'public.map' : 'public.page', $data);
    }
}
