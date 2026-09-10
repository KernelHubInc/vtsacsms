<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Foundation\Demo\DemoEnvironment;
use App\Foundation\Features\Feature;
use App\Foundation\Features\FeatureFlags;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RegistrationController extends Controller
{
    public function __invoke(RegisterRequest $request, FeatureFlags $flags): JsonResponse
    {
        abort_unless(
            app()->environment(['local', 'development', 'testing', 'demo'])
            && $flags->enabled(Feature::DemoMode)
            && $request->validated('tenant_id') === DemoEnvironment::TENANT_ID,
            404,
        );

        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->create([
                'name' => $request->validated('name'),
                'email' => mb_strtolower((string) $request->validated('email')),
                'password' => $request->validated('password'),
                'activated_at' => now('UTC'),
            ]);
            $membershipId = (string) Str::ulid();
            DB::table('memberships')->insert([
                'id' => $membershipId,
                'tenant_id' => DemoEnvironment::TENANT_ID,
                'user_id' => $user->getKey(),
                'organization_id' => DB::table('organizations')
                    ->where('tenant_id', DemoEnvironment::TENANT_ID)
                    ->where('type', 'fleet')->value('id'),
                'status' => 'active',
                'joined_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('role_assignments')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => DemoEnvironment::TENANT_ID,
                'membership_id' => $membershipId,
                'role_id' => DB::table('roles')
                    ->where('tenant_id', DemoEnvironment::TENANT_ID)
                    ->where('key', 'consumer-driver')->value('id'),
                'scope_type' => 'organization',
                'scope_id' => DB::table('organizations')
                    ->where('tenant_id', DemoEnvironment::TENANT_ID)
                    ->where('type', 'fleet')->value('id'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => [
            'message' => 'Registration complete. Check Mailpit to verify the local demo account.',
        ]], 201);
    }
}
