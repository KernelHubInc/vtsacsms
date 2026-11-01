<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Reporting\Application\DemoDataAudit;
use Database\Seeders\ChargingTariffSeeder;
use Database\Seeders\FinancialFoundationSeeder;
use Database\Seeders\ProcurementInventorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\TenantSecurityTestCase;

final class DemoDataAuditTest extends TenantSecurityTestCase
{
    public function test_inventory_is_read_only_tenant_scoped_and_does_not_expose_contact_details(): void
    {
        $tenant = $this->createTenant('audit-one');
        $other = $this->createTenant('audit-two');
        $user = $this->createUser();
        $user->update(['email' => 'private@demo.vsta.local']);
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $first = $this->withinTenant($tenant, $user, fn () => Organization::factory()->create(['name' => 'Power Solutions', 'code' => 'DEMO-CPO-A']));
        $this->withinTenant($tenant, $user, fn () => Organization::factory()->create(['name' => 'Demosthenes', 'code' => 'REAL']));
        $foreign = $this->withinTenant($other, $user, fn () => Organization::factory()->create(['name' => 'Other Demo', 'code' => 'DEMO-OTHER']));
        $before = DB::table('organizations')->orderBy('id')->get()->toJson();

        $report = app(DemoDataAudit::class)->inspect((string) $tenant->id);

        $this->assertTrue($report['read_only']);
        $this->assertSame(1, $report['tables']['organizations']['matched_rows']);
        $this->assertSame((string) $first->id, $report['tables']['organizations']['samples'][0]['id']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $foreign->id, $json);
        $this->assertStringNotContainsString('private@demo.vsta.local', $json);
        $this->assertSame($before, DB::table('organizations')->orderBy('id')->get()->toJson());
        $this->assertSame(1, $report['tables']['users']['matched_rows']);
    }

    public function test_samples_are_bounded_without_understating_the_total(): void
    {
        $tenant = $this->createTenant('bounded');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => Organization::factory()->count(3)->create(['name' => 'Demo organization']));
        $entry = app(DemoDataAudit::class)->inspect((string) $tenant->id, 1)['tables']['organizations'];
        $this->assertSame(3, $entry['matched_rows']);
        $this->assertCount(1, $entry['samples']);
        $this->assertTrue($entry['truncated']);
        $missing = $this->artisan('data:audit-demo missing');
        $this->assertInstanceOf(PendingCommand::class, $missing);
        $missing->assertFailed();
        $invalid = $this->artisan('data:audit-demo', ['tenant' => $tenant->id, '--sample-limit' => 0]);
        $this->assertInstanceOf(PendingCommand::class, $invalid);
        $invalid->assertFailed();
    }

    public function test_shared_environment_seeders_do_not_recreate_sample_suppliers_tariffs_or_payment_adapters(): void
    {
        $this->createTenant('shared');
        config()->set('app.seed_demo_currency', 'PHP');
        foreach (['staging', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            foreach ([ProcurementInventorySeeder::class, ChargingTariffSeeder::class, FinancialFoundationSeeder::class] as $seeder) {
                app()->call([app($seeder), 'run']);
            }
            $this->assertDatabaseMissing('procurement_suppliers', ['code' => 'LOCAL-DEMO']);
            $this->assertDatabaseMissing('tariffs', ['name' => 'Local development draft tariff']);
            $this->assertDatabaseMissing('payment_provider_configs', ['provider' => 'fake']);
        }
        $this->app->instance('env', 'testing');
        $this->seed([ProcurementInventorySeeder::class, ChargingTariffSeeder::class, FinancialFoundationSeeder::class]);
        $this->assertDatabaseHas('procurement_suppliers', ['code' => 'LOCAL-DEMO']);
        $this->assertDatabaseHas('tariffs', ['name' => 'Local development draft tariff']);
        $this->assertDatabaseHas('payment_provider_configs', ['provider' => 'fake']);
    }
}
