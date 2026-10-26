<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use Barryvdh\DomPDF\Facade\Pdf;
use Generator;

final class PortalPdfReport
{
    private const DETAIL_LIMIT = 200;

    /**
     * @param  array{headers:list<string>,rows:Generator<int, list<scalar|null>>}  $report
     * @param  array<string, scalar|null>  $filters
     * @return array{bytes:string,count:int}
     */
    public function render(string $type, array $report, array $filters): array
    {
        $columns = $this->columns($type);
        $details = [];
        $count = 0;
        $energyWh = 0;
        $money = [];

        foreach ($report['rows'] as $row) {
            $count++;
            if ($type === 'sessions') {
                $energyWh += (int) ($row[9] ?? 0);
                $this->addMoney($money, $row[11] ?? null, $row[13] ?? null);
            } elseif ($type === 'finance') {
                $this->addMoney($money, $row[3] ?? null, $row[6] ?? null);
            }

            if (count($details) < self::DETAIL_LIMIT) {
                $details[] = array_map(fn (int $index): string => $this->display($row[$index] ?? null, $report['headers'][$index]), array_keys($columns));
            }
        }

        $pdf = Pdf::loadView('reports.portal-export', [
            'title' => PortalReportAccess::titles()[$type],
            'columns' => array_values($columns),
            'details' => $details,
            'count' => $count,
            'limit' => self::DETAIL_LIMIT,
            'energyKwh' => number_format($energyWh / 1000, 3),
            'money' => $money,
            'filters' => $filters,
            'generatedAt' => now('UTC')->format('Y-m-d H:i').' UTC',
        ])->setPaper('a4', 'landscape')->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        return ['bytes' => $pdf->output(), 'count' => $count];
    }

    /** @return array<int, string> */
    private function columns(string $type): array
    {
        return match ($type) {
            'sessions' => [0 => 'Session ID', 5 => 'State', 7 => 'Started (UTC)', 8 => 'Stopped (UTC)', 9 => 'Energy (kWh)', 10 => 'Duration (min)', 11 => 'Currency', 13 => 'Final amount', 14 => 'Reason'],
            'assets' => [2 => 'Site', 1 => 'Code', 3 => 'Status', 9 => 'Stations', 6 => 'Address', 4 => 'Timezone'],
            'finance' => [0 => 'Payment ID', 1 => 'Session ID', 2 => 'State', 3 => 'Currency', 6 => 'Captured', 7 => 'Refunded', 8 => 'Created (UTC)'],
            default => [],
        };
    }

    private function display(mixed $value, string $key): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if ($key === 'energy_wh') {
            return number_format(((int) $value) / 1000, 3);
        }
        if ($key === 'duration_seconds') {
            return number_format(((int) $value) / 60, 1);
        }
        if (str_ends_with($key, '_minor')) {
            return $this->minor((int) $value);
        }

        return (string) $value;
    }

    /** @param array<string, int> $money */
    private function addMoney(array &$money, mixed $currency, mixed $minor): void
    {
        if (! is_string($currency) || $currency === '' || $minor === null) {
            return;
        }

        $money[$currency] = ($money[$currency] ?? 0) + (int) $minor;
    }

    public function minor(int $value): string
    {
        $absolute = abs($value);

        return ($value < 0 ? '-' : '').number_format(intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
