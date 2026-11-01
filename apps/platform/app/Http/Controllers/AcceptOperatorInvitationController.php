<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Api\AcceptInvitationRequest;
use App\Modules\Identity\Application\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class AcceptOperatorInvitationController extends Controller
{
    public function show(Request $request): Response
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);

        return response()->view('auth.accept-invitation', ['token' => $token, 'completed' => false])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(AcceptInvitationRequest $request, InvitationService $invitations): Response
    {
        $invitations->accept($request->validated('token'), $request->validated('name'), $request->validated('password'), (string) Str::ulid());

        return response()->view('auth.accept-invitation', ['token' => '', 'completed' => true])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
