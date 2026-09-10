<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assets\Domain\Models\AssetClass;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Assets\Domain\Models\OcppSecurityProfile;
use App\Modules\Assets\Domain\Models\OcppVersion;
use App\Modules\Locations\Domain\Models\Country;
use App\Modules\Locations\Domain\Models\SiteAmenity;
use Illuminate\Database\Seeder;

final class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        Country::query()->updateOrCreate(['iso_alpha_2' => 'PH'], ['iso_alpha_3' => 'PHL', 'name' => 'Philippines']);
        $this->codes(OcppVersion::class, [['1.6J', 'OCPP 1.6 JSON'], ['2.0.1', 'OCPP 2.0.1'], ['2.1', 'OCPP 2.1']]);
        $this->codes(OcppSecurityProfile::class, [['1', 'Basic authentication'], ['2', 'TLS with basic authentication'], ['3', 'TLS with client certificates']]);
        $this->codes(ChargingCurrentType::class, [['AC', 'Alternating current'], ['DC', 'Direct current']]);
        $this->codes(ConnectorStandard::class, [['TYPE_1', 'Type 1'], ['TYPE_2', 'Type 2'], ['CCS_1', 'CCS Combo 1'], ['CCS_2', 'CCS Combo 2'], ['CHADEMO', 'CHAdeMO'], ['GBT_AC', 'GB/T AC'], ['GBT_DC', 'GB/T DC']]);
        $this->codes(AssetClass::class, [['CHARGER', 'Charging station'], ['EVSE', 'EVSE'], ['CONNECTOR', 'Connector'], ['COMPONENT', 'Replaceable component']]);
        $this->codes(SiteAmenity::class, [['RESTROOM', 'Restroom'], ['FOOD', 'Food and drink'], ['SHOPPING', 'Shopping'], ['WIFI', 'Wi-Fi'], ['ACCESSIBLE', 'Accessible parking']]);
    }

    /**
     * @param  class-string  $model
     * @param  list<array{string, string}>  $rows
     */
    private function codes(string $model, array $rows): void
    {
        foreach ($rows as [$code, $name]) {
            $model::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
