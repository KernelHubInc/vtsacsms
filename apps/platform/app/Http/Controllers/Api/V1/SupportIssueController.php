<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubmitSupportIssue;
use App\Models\User;
use App\Modules\Support\Application\SupportIntake;
use Illuminate\Http\JsonResponse;

final class SupportIssueController extends Controller
{
    public function __invoke(SubmitSupportIssue $request, SupportIntake $support): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $ticket = $support->mobile($actor, $request->safe()->except('idempotency_key'), (string) $request->validated('idempotency_key'));

        return response()->json(['data' => ['id' => $ticket->getKey(), 'ticket_number' => $ticket->ticket_number, 'status' => $ticket->status]], 201)
            ->header('Cache-Control', 'no-store');
    }
}
