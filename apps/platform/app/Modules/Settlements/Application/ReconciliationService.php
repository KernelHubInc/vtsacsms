<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Application;

use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Payments\Application\ProviderFinancialGateway;
use App\Modules\Settlements\Domain\Models\ReconciliationLine;
use App\Modules\Settlements\Domain\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ReconciliationService
{
    public function __construct(private ProviderFinancialGateway $payments, private OutboxRecorder $outbox) {}

    public function reconcile(string $configurationId, CarbonImmutable $from, CarbonImmutable $to): ReconciliationRun
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException('Reconciliation period end must follow its start.');
        }
        $records = $this->payments->reconciliationExport($configurationId, $from, $to);

        return DB::transaction(function () use ($configurationId, $from, $to, $records): ReconciliationRun {
            $run = ReconciliationRun::query()->create(['provider_config_id' => $configurationId, 'status' => 'processing',
                'period_start' => $from, 'period_end' => $to, 'matched_count' => 0, 'mismatch_count' => 0]);
            $matched = 0;
            $mismatches = 0;
            foreach ($records as $record) {
                $intent = $this->payments->paymentByProviderReference($configurationId, $record->providerReference);
                $platformCaptured = $intent === null ? 0 : $intent->capturedMinor;
                $platformRefunded = $intent === null ? 0 : $intent->refundedMinor;
                $platformNet = $platformCaptured - $platformRefunded;
                $difference = $record->netMinor - $platformNet;
                $outcome = $intent !== null && $intent->currency === $record->currency && $difference === 0 ? 'matched' : 'mismatch';
                $line = ReconciliationLine::query()->create(['reconciliation_run_id' => $run->getKey(),
                    'provider_reference' => $record->providerReference, 'payment_intent_id' => $intent?->id,
                    'currency' => $record->currency, 'provider_gross_minor' => $record->grossMinor,
                    'provider_refund_minor' => $record->refundMinor, 'provider_fee_minor' => $record->feeMinor,
                    'provider_net_minor' => $record->netMinor, 'platform_captured_minor' => $platformCaptured,
                    'platform_refunded_minor' => $platformRefunded, 'difference_minor' => $difference,
                    'outcome' => $outcome, 'safe_evidence' => $record->safeEvidence]);
                if ($outcome === 'matched') {
                    $matched++;
                } else {
                    $mismatches++;
                }
            }
            $run->forceFill(['matched_count' => $matched, 'mismatch_count' => $mismatches,
                'status' => $mismatches === 0 ? 'completed' : 'review_required', 'completed_at' => now('UTC')])->save();
            $this->outbox->record('settlements.reconciliation.completed.v1', 'reconciliation_run', (string) $run->getKey(), [
                'matched_count' => $matched, 'mismatch_count' => $mismatches, 'period_start' => $from->toISOString(), 'period_end' => $to->toISOString(),
            ]);

            return $run;
        });
    }
}
