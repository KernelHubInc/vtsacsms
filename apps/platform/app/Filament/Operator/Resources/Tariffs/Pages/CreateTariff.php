<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Tariffs\Pages;

use App\Filament\Operator\Resources\Tariffs\TariffResource;
use App\Modules\Tariffs\Application\TariffManagementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateTariff extends CreateRecord
{
    protected static string $resource = TariffResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $name = $data['name'] ?? null;
        $currency = $data['currency'] ?? null;
        $description = $data['description'] ?? null;
        if (! is_string($name) || ! is_string($currency) || ($description !== null && ! is_string($description))) {
            throw new \InvalidArgumentException('Tariff form data is invalid.');
        }

        return app(TariffManagementService::class)->create([
            'name' => $name,
            'currency' => $currency,
            'description' => $description,
        ]);
    }
}
