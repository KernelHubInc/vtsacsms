<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        $this->call(MasterDataSeeder::class);
        $this->call(PublicCmsSeeder::class);
        $this->call(FoundationRoleSeeder::class);
        $this->call(ChargingTariffSeeder::class);
        $this->call(FinancialFoundationSeeder::class);
        $this->call(ProcurementInventorySeeder::class);
        $this->call(MaintenanceSeeder::class);
    }
}
