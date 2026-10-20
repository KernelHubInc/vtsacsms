<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Modules\Organizations\Application\ChargingAccounts;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\TenantSecurityTestCase;

final class SharedChargingAccountMigrationTest extends TenantSecurityTestCase
{
    public function test_unused_migration_can_be_reversed_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_10_13_000001_add_shared_charging_accounts.php');
        $migration->down();
        self::assertFalse(Schema::hasColumn('prepaid_accounts', 'charging_account_id'));
        self::assertFalse(Schema::hasTable('driver_vehicle_assignments'));
        $migration->up();
        self::assertTrue(Schema::hasColumn('prepaid_accounts', 'charging_account_id'));
        self::assertTrue(Schema::hasTable('driver_vehicle_assignments'));
    }

    public function test_rollback_refuses_to_erase_existing_charging_account_evidence(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('migration-owner');
        $this->withinTenant($tenant, $user, function () use ($user, $tenant): void {
            $this->createMembership($tenant, $user);
            app(ChargingAccounts::class)->personal($user);
        });
        $migration = require database_path('migrations/2026_10_13_000001_add_shared_charging_accounts.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
