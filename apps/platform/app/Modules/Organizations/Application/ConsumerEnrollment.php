<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Demo\DemoEnvironment;
use App\Foundation\Features\Feature;
use App\Foundation\Features\FeatureFlags;
use App\Modules\Tenancy\Application\RegistrationTenantQuery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public signup grants only the configured consumer role and organization. */
final readonly class ConsumerEnrollment
{
    public function __construct(private FeatureFlags $flags, private RegistrationTenantQuery $tenants) {}

    /** @return array{tenant_id: string, organization_id: string, role_id: string} */
    public function scope(string $tenantId): array
    {
        $configured = config('registration.enabled') === true
            && config('registration.tenant_id') === $tenantId;
        $demo = ! config('registration.enabled')
            && app()->environment(['local', 'development', 'testing', 'demo', 'staging'])
            && $this->flags->enabled(Feature::DemoMode)
            && $tenantId === DemoEnvironment::TENANT_ID;

        if ((! $configured && ! $demo) || ! $this->tenants->isActive($tenantId)) {
            $this->unavailable();
        }

        $organizations = DB::table('organizations')->where('tenant_id', $tenantId)
            ->where('is_active', true)->where('type', 'fleet');
        if ($configured) {
            $organizations->where('id', (string) config('registration.organization_id'));
        }
        $organizationId = $organizations->orderBy('id')->value('id');
        $roleId = DB::table('roles')->where('tenant_id', $tenantId)->where('key', 'consumer-driver')->value('id');
        if (! is_string($organizationId) || ! is_string($roleId)) {
            $this->unavailable();
        }

        return ['tenant_id' => $tenantId, 'organization_id' => $organizationId, 'role_id' => $roleId];
    }

    public function enroll(int $userId, string $tenantId): void
    {
        $scope = $this->scope($tenantId);
        $membershipId = (string) Str::ulid();
        DB::table('memberships')->insert([
            'id' => $membershipId, 'tenant_id' => $tenantId, 'user_id' => $userId,
            'organization_id' => $scope['organization_id'], 'status' => 'active',
            'joined_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('role_assignments')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId,
            'membership_id' => $membershipId, 'role_id' => $scope['role_id'],
            'scope_type' => 'organization', 'scope_id' => $scope['organization_id'],
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    private function unavailable(): never
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code' => 'registration_unavailable',
            'message' => 'Account registration is currently unavailable. Please contact support.',
            'correlation_id' => request()->attributes->get('correlation_id'), 'errors' => [],
        ]], 404)->header('Cache-Control', 'no-store'));
    }
}
