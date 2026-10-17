<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Operator\Resources\ChargingStations\Pages\EditChargingStation;
use App\Modules\Assets\Application\DeleteDraftAsset;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ChargingStation;
use App\Modules\Assets\Domain\Models\Connector;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\Evse;
use App\Modules\Locations\Application\DeleteUnusedLocation;
use App\Modules\Organizations\Domain\PermissionKey;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\TenantSecurityTestCase;

final class NetworkDeletionTest extends TenantSecurityTestCase
{
    public function test_admin_can_delete_unused_draft_station_from_edit_page_and_audit_is_retained(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('delete-network');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'network-admin', [PermissionKey::PlatformPanelAccess, PermissionKey::AssetView, PermissionKey::AssetManage, PermissionKey::LocationView]);
            $this->assignDirectly($tenant, $membership, $role);
            $station = ChargingStation::factory()->create(['lifecycle_status' => 'draft']);
            Livewire::test(EditChargingStation::class, ['record' => $station->getKey()])
                ->callAction('delete')->assertNotified();
            $this->assertDatabaseMissing('charging_stations', ['id' => $station->getKey()]);
            $this->assertDatabaseHas('audit_events', ['target_id' => $station->getKey(), 'action' => 'assets.charging_station.deleted']);
        });
    }

    public function test_active_equipment_and_draft_records_with_children_cannot_be_deleted(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('delete-linked');
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'network-manager', [PermissionKey::AssetManage, PermissionKey::LocationManage]);
            $this->assignDirectly($tenant, $membership, $role);
            $station = ChargingStation::factory()->create(['lifecycle_status' => 'active']);
            $this->blocked(fn () => app(DeleteDraftAsset::class)->delete($user, $station));
            $station->update(['lifecycle_status' => 'draft']);
            ConnectorStandard::query()->create(['code' => 'type2', 'name' => 'Type 2']);
            ChargingCurrentType::query()->create(['code' => 'ac', 'name' => 'AC']);
            $evse = Evse::factory()->create(['charging_station_id' => $station->getKey(), 'lifecycle_status' => 'draft']);
            $connector = Connector::factory()->create(['evse_id' => $evse->getKey(), 'lifecycle_status' => 'draft']);
            $this->blocked(fn () => app(DeleteDraftAsset::class)->delete($user, $station));
            $this->blocked(fn () => app(DeleteDraftAsset::class)->delete($user, $evse));
            $site = $station->site;
            $site->update(['lifecycle_status' => 'draft']);
            $this->blocked(fn () => app(DeleteUnusedLocation::class)->delete($user, $site));
            app(DeleteDraftAsset::class)->delete($user, $connector);
            app(DeleteDraftAsset::class)->delete($user, $evse);
            app(DeleteDraftAsset::class)->delete($user, $station);
            app(DeleteUnusedLocation::class)->delete($user, $site);
            $this->assertDatabaseMissing('sites', ['id' => $site->getKey()]);
        });
    }

    public function test_reference_without_foreign_key_blocks_deletion_and_unauthorized_tenants_cannot_delete(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('delete-scope');
        $other = $this->createTenant('delete-other');
        $foreign = $this->withinTenant($other, $user, fn () => ChargingStation::factory()->create(['lifecycle_status' => 'draft']));
        $this->withinTenant($tenant, $user, function () use ($tenant, $user, $foreign): void {
            $membership = $this->createMembership($tenant, $user);
            $role = $this->createRole($tenant, 'delete-manager', [PermissionKey::AssetManage]);
            $this->assignDirectly($tenant, $membership, $role);
            $station = ChargingStation::factory()->create(['lifecycle_status' => 'draft']);
            DB::table('charging_station_connections')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenant->getKey(),
                'charging_station_id' => $station->getKey(), 'connection_id' => (string) Str::ulid(),
                'connected' => false, 'connected_at' => now(), 'last_event_at' => now(), 'last_seen_at' => now(),
            ]);
            $this->blocked(fn () => app(DeleteDraftAsset::class)->delete($user, $station));
            $viewer = $this->createUser();
            foreach ([[$user, $foreign], [$viewer, $station]] as [$actor, $record]) {
                try {
                    app(DeleteDraftAsset::class)->delete($actor, $record);
                    $this->fail('Unauthorized deletion succeeded.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseHas('charging_stations', ['id' => $record->getKey()]);
                }
            }
        });
    }

    private function blocked(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Linked or active record was deleted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('delete', $error->errors());
        }
    }
}
