<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ProductionSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_seed_keeps_local_example_records(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(0, Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]));

        $this->assertDatabaseHas('payment_provider_configs', ['tenant_id' => $tenant->getKey(), 'provider' => 'fake']);
        $this->assertDatabaseHas('procurement_suppliers', ['tenant_id' => $tenant->getKey(), 'code' => 'LOCAL-DEMO']);
    }

    public function test_default_production_seed_preserves_roles_without_demo_business_records(): void
    {
        $tenant = Tenant::factory()->create();
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertSame(0, Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]));

        $this->assertDatabaseHas('roles', ['tenant_id' => $tenant->getKey(), 'key' => 'finance-reviewer']);
        $this->assertDatabaseMissing('payment_provider_configs', ['tenant_id' => $tenant->getKey(), 'provider' => 'fake']);
        $this->assertDatabaseMissing('tariffs', ['tenant_id' => $tenant->getKey(), 'name' => 'Local development draft tariff']);
        $this->assertDatabaseMissing('procurement_suppliers', ['tenant_id' => $tenant->getKey(), 'code' => 'LOCAL-DEMO']);
        $this->assertDatabaseMissing('inventory_warehouses', ['tenant_id' => $tenant->getKey(), 'code' => 'CENTRAL']);
    }
}
