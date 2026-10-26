<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use RuntimeException;

final class ChargingReceiptPdf
{
    /** @param array<string, int|string|null> $summary
     * @return array<string, string>
     */
    public function details(array $summary): array
    {
        $amount = max(0, (int) $summary['amount_minor']);
        $energy = max(0, (int) $summary['energy_wh']);
        $duration = max(0, (int) $summary['duration_seconds']);

        return [
            'reference' => (string) $summary['session_id'],
            'station' => (string) $summary['station'],
            'connector' => (string) $summary['connector'],
            'started' => $this->time($summary['started_at'] ?? null),
            'stopped' => $this->time($summary['stopped_at'] ?? null),
            'energy' => number_format($energy / 1000, 3).' kWh',
            'duration' => sprintf('%d min %02d sec', intdiv($duration, 60), $duration % 60),
            'amount' => (string) $summary['currency'].' '.number_format(intdiv($amount, 100)).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT),
            'payment_method' => (string) $summary['payment_method'],
        ];
    }

    /** @param array<string, int|string|null> $summary */
    public function render(array $summary): string
    {
        $logo = file_get_contents(public_path('branding/power-solutions-email-logo.png'));
        if ($logo === false) {
            throw new RuntimeException('Charging receipt logo is unavailable.');
        }

        return Pdf::loadView('mail.charging-summary-pdf', [
            'receipt' => $this->details($summary),
            'logo' => 'data:image/png;base64,'.base64_encode($logo),
        ])->setPaper('a4')->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ])->output();
    }

    private function time(int|string|null $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'Not reported';
        }

        return CarbonImmutable::parse($value)->setTimezone('Asia/Manila')->format('d M Y, g:i A').' PHT';
    }
}
