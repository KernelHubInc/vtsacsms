<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CMS\Application\PublicContentRepository;
use Illuminate\Contracts\View\View;

final class PublicArticleController extends Controller
{
    public function __invoke(string $slug, PublicContentRepository $content): View
    {
        return view('public.article', ['article' => $content->article($slug)]);
    }
}
