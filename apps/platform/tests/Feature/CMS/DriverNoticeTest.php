<?php

declare(strict_types=1);

namespace Tests\Feature\CMS;

use App\Filament\Platform\Resources\Content\DriverNoticeResource;
use App\Modules\CMS\Domain\Models\DriverNotice;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class DriverNoticeTest extends TenantSecurityTestCase
{
    public function test_driver_feed_only_exposes_active_published_notices_from_token_tenant(): void
    {
        $this->freezeTime();
        $tenant = $this->createTenant('notices');
        $otherTenant = $this->createTenant('notices-other');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $visible = $this->notice((string) $tenant->getKey(), 'Visible', 'published', -1, 1);
        $this->notice((string) $tenant->getKey(), 'Draft', 'draft', -1, 1);
        $this->notice((string) $tenant->getKey(), 'Future', 'published', 1, 2);
        $this->notice((string) $tenant->getKey(), 'Expired', 'published', -2, -1);
        $this->notice((string) $otherTenant->getKey(), 'Other tenant', 'published', -1, 1);

        $this->getJson('/api/v1/mobile/notices')->assertUnauthorized();
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->getKey(),
            'device_name' => 'Notice test',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/mobile/notices')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.show_on_open', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/mobile/notices', ['X-Tenant-ID' => (string) $otherTenant->getKey()])
            ->assertNotFound();
    }

    public function test_admin_list_is_tenant_scoped_and_requires_cms_permission(): void
    {
        $tenant = $this->createTenant('notice-admin');
        $otherTenant = $this->createTenant('notice-admin-other');
        $user = $this->createUser();
        $this->actingAs($user);
        $this->withinTenant($tenant, $user, fn () => Organization::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Notice administrator',
            'code' => 'NOTICE-ADMIN',
            'type' => OrganizationType::Platform,
            'is_active' => true,
        ]));
        $membership = $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $this->notice((string) $tenant->getKey(), 'Tenant notice', 'draft', -1, 1);
        $this->notice((string) $otherTenant->getKey(), 'Hidden notice', 'draft', -1, 1);

        $this->withinTenant($tenant, $user, fn () => $this->assertFalse(DriverNoticeResource::canViewAny()));
        $role = $this->withinTenant($tenant, $user, fn () => $this->createRole($tenant, 'notices', [
            PermissionKey::PlatformPanelAccess, PermissionKey::CmsEdit,
        ]));
        $this->withinTenant($tenant, $user, fn () => $this->assignDirectly($tenant, $membership, $role));
        $this->withinTenant($tenant, $user, function (): void {
            $this->assertTrue(DriverNoticeResource::canViewAny());
            $this->assertFalse(DriverNoticeResource::canPublish());
            $this->assertSame(['Tenant notice'], DriverNoticeResource::getEloquentQuery()->pluck('title')->all());
        });
        $this->get('/admin/content/driver-notices')
            ->assertOk()->assertSee('Tenant notice')->assertDontSee('Hidden notice');
    }

    private function notice(string $tenantId, string $title, string $status, int $startHours, int $endHours): DriverNotice
    {
        return DriverNotice::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'category' => 'announcement',
            'title' => $title,
            'message' => 'A driver-facing test notice.',
            'status' => $status,
            'show_on_open' => true,
            'starts_at' => now('UTC')->addHours($startHours),
            'ends_at' => now('UTC')->addHours($endHours),
        ]);
    }
}
