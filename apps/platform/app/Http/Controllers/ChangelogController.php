<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CMS\Application\Changelog;
use App\Modules\CMS\Application\PublicContentRepository;
use Illuminate\Http\Response;

final class ChangelogController extends Controller
{
    public function __invoke(Changelog $changelog, PublicContentRepository $content): Response
    {
        $environment = $changelog->environment();
        $response = response()->view('public.changelog', [
            'page' => $content->page('changelog'), 'releases' => $changelog->releases(),
            'environment' => $environment, 'noindex' => $environment !== 'production',
        ])->header('Cache-Control', 'no-cache');

        return $environment === 'production' ? $response : $response->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
