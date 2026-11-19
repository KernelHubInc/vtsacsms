<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmSupportCode;
use App\Http\Requests\RequestSupportCode;
use App\Modules\Assets\Application\SupportStationQuery;
use App\Modules\CMS\Application\PublicContentRepository;
use App\Modules\Support\Application\PublicSupportContext;
use App\Modules\Support\Application\SupportIntake;
use App\Modules\Support\Application\WebsiteSupportRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

final class PublicSupportController extends Controller
{
    public function show(Request $request, PublicContentRepository $content): Response
    {
        if (! config('support.enabled')) {
            return response(app()->call([app(PublicPageController::class), '__invoke'], ['slug' => 'support']));
        }
        $stations = app(PublicSupportContext::class)->run((string) $request->attributes->get('correlation_id'), fn () => app(SupportStationQuery::class)->options());
        $request->session()->put('support.form_started_at', $request->session()->get('support.form_started_at', now('UTC')->getTimestamp()));

        return response()->view('public.support', ['page' => $content->page('support'), 'stations' => $stations, 'faqs' => collect(),
            'categories' => SupportIntake::CATEGORIES, 'awaitingCode' => $request->session()->has('support.challenge'),
            'receipt' => $request->session()->get('support.receipt'),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function send(RequestSupportCode $request, WebsiteSupportRequests $support): RedirectResponse
    {
        abort_unless(now('UTC')->getTimestamp() - (int) $request->session()->get('support.form_started_at', now('UTC')->getTimestamp()) >= 3, 429, 'Please take a moment to review your request.');
        $challenge = $support->start($request->safe()->except('company_website'), $request->session()->get('support.challenge'));
        $request->session()->put('support.challenge', $challenge);
        $request->session()->forget('support.receipt');

        return to_route('public.support');
    }

    public function confirm(ConfirmSupportCode $request, WebsiteSupportRequests $support): RedirectResponse
    {
        $challenge = $request->session()->get('support.challenge');
        $receipt = is_string($challenge) ? $support->confirm($challenge, $request->validated('support_code'), (string) $request->attributes->get('correlation_id')) : null;
        if ($receipt === null) {
            return to_route('public.support')->withErrors(['support_code' => 'That code is invalid or expired. Request a new code and try again.']);
        }
        $request->session()->put('support.receipt', $receipt);
        $request->session()->forget('support.challenge');

        return to_route('public.support');
    }

    public function reset(Request $request): RedirectResponse
    {
        if (is_string($request->session()->get('support.challenge'))) {
            Cache::forget('support:challenge:'.$request->session()->get('support.challenge'));
        }
        $request->session()->forget(['support.receipt', 'support.challenge', 'support.form_started_at']);

        return to_route('public.support');
    }
}
