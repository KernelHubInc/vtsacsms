<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmAccountDeletionCode;
use App\Http\Requests\RequestAccountDeletionCode;
use App\Modules\Identity\Application\AccountDeletionRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class AccountDeletionController extends Controller
{
    private const CHALLENGE = 'account_deletion.challenge';

    public function show(Request $request): Response
    {
        return response()->view('public.account-deletion', [
            'awaitingCode' => $request->session()->has(self::CHALLENGE),
            'received' => $request->session()->get('account_deletion.received', false),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function send(RequestAccountDeletionCode $request, AccountDeletionRequests $deletions): RedirectResponse
    {
        $challenge = $deletions->start(
            Str::lower($request->validated('email')),
            $request->session()->get(self::CHALLENGE),
        );
        $request->session()->put(self::CHALLENGE, $challenge);

        return to_route('public.account-deletion');
    }

    public function confirm(ConfirmAccountDeletionCode $request, AccountDeletionRequests $deletions): RedirectResponse
    {
        $challenge = $request->session()->get(self::CHALLENGE);
        if (! is_string($challenge) || ! $deletions->confirm(
            $challenge,
            $request->validated('account_deletion_code'),
            (string) $request->attributes->get('correlation_id'),
        )) {
            return to_route('public.account-deletion')->withErrors([
                'account_deletion_code' => 'That code is invalid or expired. Check your email or request a new code.',
            ]);
        }

        $request->session()->forget(self::CHALLENGE);

        return to_route('public.account-deletion')->with('account_deletion.received', true);
    }
}
