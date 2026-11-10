<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\EstablishPanelTenantContext;
use App\Http\Middleware\TrackPanelSession;
use App\Modules\Identity\Application\PanelAccessService;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TenantSecurityTestCase;

final class PanelTenantContextTest extends TenantSecurityTestCase
{
    public function test_panels_deny_mfa_required_users_without_a_verified_challenge(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('panel-mfa');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            foreach ([OrganizationType::Platform, OrganizationType::ChargePointOperator] as $type) {
                Organization::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'name' => $type->value,
                    'code' => $type->value,
                    'type' => $type,
                    'is_active' => true,
                ]);
            }
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'panel-access', [
                PermissionKey::PlatformPanelAccess,
                PermissionKey::OperatorPanelAccess,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        $access = app(PanelAccessService::class);
        foreach (['admin', 'operator'] as $panel) {
            $this->assertTrue($access->canAccess($user, $panel));
        }

        $user->forceFill(['mfa_required' => true, 'mfa_enrolled_at' => now('UTC')])->save();

        foreach (['admin', 'operator'] as $panel) {
            $this->assertFalse($access->canAccess($user->fresh(), $panel));
            $this->actingAs($user)->get('/'.$panel)->assertForbidden();
        }
    }

    public function test_panel_authentication_and_tenant_context_are_persistent_livewire_middleware(): void
    {
        $middleware = Livewire::getPersistentMiddleware();

        $this->assertContains(Authenticate::class, $middleware);
        $this->assertContains(EstablishPanelTenantContext::class, $middleware);
        $this->assertContains(TrackPanelSession::class, $middleware);
    }

    public function test_livewire_update_keeps_verified_tenant_context_through_component_hydration(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('livewire-admin-context');

        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            Organization::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => 'VTSA Platform',
                'code' => 'PLATFORM',
                'type' => OrganizationType::Platform,
                'is_active' => true,
            ]);
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'platform-livewire', [
                PermissionKey::PlatformPanelAccess,
            ]);
            $this->assignDirectly($tenant, $membership, $role);
        });

        $originalRequest = $this->app->make('request');
        $request = Request::create('/admin/sites', 'GET', server: ['HTTP_X_LIVEWIRE' => '1']);
        $session = $this->app->make('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $request->attributes->set('correlation_id', (string) Str::ulid());
        $this->app->instance('request', $request);
        $request->setUserResolver(static fn () => $user);
        Filament::setCurrentPanel('admin');

        try {
            $this->assertSame($user, $request->user());
            $this->assertSame('admin', Filament::getCurrentPanel()?->getId());
            $currentTenant = $this->app->make(CurrentTenant::class);
            $response = $this->app->make(EstablishPanelTenantContext::class)->handle(
                $request,
                function () use ($currentTenant, $tenant): Response {
                    $this->assertSame((string) $tenant->getKey(), $currentTenant->get()->tenantId);

                    return new Response;
                },
            );

            $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
            $this->assertSame((string) $tenant->getKey(), $currentTenant->get()->tenantId);
        } finally {
            $this->app->make(CurrentTenant::class)->clear();
            Filament::setCurrentPanel(null);
            $this->app->instance('request', $originalRequest);
        }
    }
}
