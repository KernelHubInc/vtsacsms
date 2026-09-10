<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\ItemCategory;
use App\Modules\Inventory\Domain\Models\UnitOfMeasure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class InventoryCatalogCsvService
{
    public function template(): string
    {
        return "sku,name,description,category_code,uom_code,tracking_type,valuation_method,currency,standard_cost_minor\n";
    }

    /** @return array{created: int, errors: list<array{row: int, messages: list<string>}>} */
    public function import(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new DomainException('Unable to prepare the import stream.');
        }
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream);
        $expected = [
            'sku', 'name', 'description', 'category_code', 'uom_code', 'tracking_type',
            'valuation_method', 'currency', 'standard_cost_minor',
        ];
        if ($header !== $expected) {
            fclose($stream);
            throw new DomainException('The inventory CSV header does not match the published template.');
        }
        $created = 0;
        $errors = [];
        $rowNumber = 1;
        while (($values = fgetcsv($stream)) !== false) {
            $rowNumber++;
            if (count($values) !== count($expected)) {
                $errors[] = ['row' => $rowNumber, 'messages' => ['Column count is invalid.']];

                continue;
            }
            $row = array_combine($expected, $values);
            $validator = Validator::make($row, [
                'sku' => ['required', 'string', 'max:80'],
                'name' => ['required', 'string', 'max:200'],
                'description' => ['nullable', 'string', 'max:5000'],
                'category_code' => ['required', 'string', 'max:40'],
                'uom_code' => ['required', 'string', 'max:24'],
                'tracking_type' => ['required', 'in:none,lot,serial'],
                'valuation_method' => ['required', 'in:moving_average,standard'],
                'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
                'standard_cost_minor' => ['nullable', 'integer', 'min:0'],
            ]);
            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'messages' => array_values($validator->errors()->all())];

                continue;
            }
            if (InventoryItem::query()->where('sku', $row['sku'])->exists()) {
                $errors[] = ['row' => $rowNumber, 'messages' => ['SKU already exists; imports never overwrite items.']];

                continue;
            }
            $category = ItemCategory::query()->where('code', $row['category_code'])->first();
            $uom = UnitOfMeasure::query()->where('code', $row['uom_code'])->first();
            if ($category === null || $uom === null) {
                $errors[] = ['row' => $rowNumber, 'messages' => ['Category or unit of measure was not found.']];

                continue;
            }
            DB::transaction(static function () use ($row, $category, $uom): void {
                InventoryItem::query()->create([
                    'category_id' => $category->getKey(),
                    'base_uom_id' => $uom->getKey(),
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'description' => $row['description'] === '' ? null : $row['description'],
                    'tracking_type' => $row['tracking_type'],
                    'valuation_method' => $row['valuation_method'],
                    'currency' => mb_strtoupper($row['currency']),
                    'standard_cost_minor' => $row['standard_cost_minor'] === ''
                        ? null
                        : (int) $row['standard_cost_minor'],
                ]);
            });
            $created++;
        }
        fclose($stream);

        return ['created' => $created, 'errors' => $errors];
    }

    public function export(): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new DomainException('Unable to prepare the export stream.');
        }
        fputcsv($stream, [
            'id', 'sku', 'name', 'description', 'category_code', 'uom_code', 'tracking_type',
            'valuation_method', 'currency', 'standard_cost_minor', 'active',
        ]);
        InventoryItem::query()->with(['category', 'baseUnit'])->orderBy('sku')->chunkById(
            500,
            static function ($items) use ($stream): void {
                foreach ($items as $item) {
                    fputcsv($stream, [
                        $item->getKey(),
                        $item->sku,
                        $item->name,
                        $item->description,
                        $item->category->code,
                        $item->baseUnit->code,
                        $item->tracking_type->value,
                        $item->valuation_method->value,
                        $item->currency,
                        $item->standard_cost_minor,
                        $item->is_active ? '1' : '0',
                    ]);
                }
            },
        );
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }
}
