<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GoogleRegisterRequest;
use App\Http\Requests\Api\GoogleSignInRequest;
use App\Http\Resources\Api\V1\MobileSessionResource;
use App\Modules\Identity\Application\GoogleSignIn;
use App\Modules\Identity\Application\GoogleSignInException;
use App\Modules\Identity\Application\GoogleTokenVerifier;
use App\Modules\Identity\Application\MobileSignIn;
use App\Modules\Identity\Application\RegisterDriver;
use App\Modules\Organizations\Application\ConsumerEnrollment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class GoogleAuthController extends Controller
{
    public function login(GoogleSignInRequest $request, GoogleTokenVerifier $verifier, GoogleSignIn $google, ConsumerEnrollment $enrollment): JsonResponse
    {
        $tenantId = (string) $request->validated('tenant_id');
        $identity = $verifier->verify((string) $request->validated('id_token'), $tenantId);
        $result = $google->login($identity, $tenantId, (string) $request->validated('device_name'), $request->validated('password'));
        if ($result === null) {
            $enrollment->scope($tenantId);

            return response()->json(['data' => [
                'status' => 'registration_required', 'email' => $identity->email,
                'first_name' => $identity->firstName, 'last_name' => $identity->lastName,
            ]])->header('Cache-Control', 'no-store');
        }

        return MobileSessionResource::response($result['user'], $tenantId, $result['token']);
    }

    public function register(GoogleRegisterRequest $request, RegisterDriver $registration, MobileSignIn $sessions): JsonResponse
    {
        try {
            // Consumer membership, vehicle, provider link and token commit together.
            [$user, $token] = DB::transaction(function () use ($request, $registration, $sessions): array {
                $user = $registration->create($request->validated(), $request->identity())->refresh();
                $token = $sessions->issue($user, (string) $request->validated('tenant_id'), (string) $request->validated('device_name'), 'google');

                return [$user, $token];
            });
        } catch (UniqueConstraintViolationException) {
            throw new GoogleSignInException('google_account_changed', 'An account already exists. Return to sign-in and choose your Google account again.', 409);
        }
        $registration->sendVerification($user);

        return MobileSessionResource::response($user, (string) $request->validated('tenant_id'), $token, 201);
    }
}
