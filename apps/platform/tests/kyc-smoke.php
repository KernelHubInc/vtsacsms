<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Organizations\Application\PermissionCatalog;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! filter_var(getenv('KYC_SMOKE'), FILTER_VALIDATE_BOOL)) {
    throw new RuntimeException('Only the isolated synthetic integration harness may run this script.');
}
$tenantId = '01J00000000000000000000001';
if (($argv[1] ?? 'seed') === 'seed') {
    Tenant::unguarded(fn () => Tenant::query()->updateOrCreate(['slug' => 'synthetic-kyc'], ['id' => $tenantId, 'name' => 'Synthetic KYC tenant', 'status' => 'active']));
    app(PermissionCatalog::class)->sync();
    CmsPage::query()->firstOrCreate(['slug' => 'charging-map'], [
        'title' => 'Synthetic charging map', 'template' => 'map', 'status' => 'published',
        'hero_heading' => 'Synthetic charging map', 'published_at' => now('UTC'),
    ]);
    app(CurrentTenant::class)->run(new TenantContext($tenantId, ActorType::Service, null, (string) Str::ulid()), function () use ($tenantId): void {
        Organization::query()->firstOrCreate(['code' => 'SYNTHETIC-KYC'], [
            'name' => 'Synthetic platform organization', 'type' => 'platform', 'is_active' => true,
        ]);
        foreach (['driver', 'other', 'reviewer'] as $name) {
            $user = User::query()->firstOrCreate(['email' => 'kyc.'.$name.'@example.test'], [
                'name' => 'Synthetic '.ucfirst($name), 'password' => (string) getenv('KYC_SMOKE_PASSWORD'),
                'activated_at' => now(),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $membership = Membership::query()->firstOrCreate(['user_id' => $user->getKey()], ['status' => 'active', 'joined_at' => now()]);
            if ($name === 'reviewer') {
                $role = Role::query()->firstOrCreate(['key' => 'synthetic-kyc-reviewer'], ['name' => 'Synthetic KYC reviewer']);
                foreach ([PermissionKey::PlatformPanelAccess, PermissionKey::KycView, PermissionKey::KycReview, PermissionKey::KycSensitiveView] as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $tenantId, 'role_id' => $role->getKey(), 'permission_key' => $permission->value, 'created_at' => now()]);
                }
                RoleAssignment::query()->firstOrCreate(['membership_id' => $membership->getKey(), 'role_id' => $role->getKey(), 'scope_type' => 'tenant', 'scope_id' => null]);
            }
        }
    });
    echo "Synthetic KYC users prepared. Credentials remain in the ignored integration environment.\n";
} else {
    $reviewer = User::query()->where('email', 'kyc.reviewer@example.test')->sole();
    app(CurrentTenant::class)->run(new TenantContext($tenantId, ActorType::Human, $reviewer->public_id, (string) Str::ulid()), function () use ($reviewer, $argv): void {
        $row = KycVerification::query()->findOrFail($argv[2]);
        app(KycService::class)->review($reviewer, $row, KycStatus::from($argv[1]), 'Synthetic integration review');
    });
    echo "Synthetic manual decision recorded.\n";
}
