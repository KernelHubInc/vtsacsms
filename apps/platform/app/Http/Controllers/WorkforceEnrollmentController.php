<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CompleteWorkforceEnrollmentRequest;
use App\Modules\Identity\Application\WorkforceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkforceEnrollmentController extends Controller
{
    public function show(): Response
    {
        return response()->view('auth.workforce-setup')->withHeaders([
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    public function complete(CompleteWorkforceEnrollmentRequest $request, WorkforceEnrollmentService $enrollments): JsonResponse
    {
        try {
            $enrollments->complete($request->validated('enrollment_token'), $request->validated('name'), $request->validated('password'), (string) Str::ulid(), $request->validated('email'));
        } catch (ValidationException $exception) {
            return response()->json(['error' => [
                'code' => 'enrollment_unavailable', 'message' => 'This setup credential is unavailable. Ask the administrator for a new one.',
                'correlation_id' => $request->attributes->get('correlation_id'), 'fields' => $exception->errors(),
            ]], 422)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json(['data' => ['code' => 'workforce_enrollment_completed']])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
