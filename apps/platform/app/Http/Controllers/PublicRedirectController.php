<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CMS\Domain\Models\CmsRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PublicRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $redirect = CmsRedirect::query()->where('source_path', '/'.ltrim($request->path(), '/'))->where('is_enabled', true)->firstOrFail();
        abort_unless(str_starts_with($redirect->destination_url, '/') || str_starts_with($redirect->destination_url, 'https://'), 404);
        $redirect->increment('hit_count');
        $redirect->forceFill(['last_hit_at' => now('UTC')])->save();

        return redirect()->to($redirect->destination_url, (int) $redirect->status_code);
    }
}
