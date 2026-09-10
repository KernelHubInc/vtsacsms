<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Payments\Application\ProviderFinancialGateway;
use App\Modules\Settlements\Domain\Models\SettlementAdjustment;
use App\Modules\Settlements\Domain\Models\SettlementBatch;
use App\Modules\Settlements\Domain\Models\SettlementItem;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SettlementService
{
    public function __construct(private CurrentTenant $tenant, private ProviderFinancialGateway $payments,
        private OutboxRecorder $outbox, private AuditRecorder $audit) {}

    /** @param list<array{beneficiary_type:string,beneficiary_id:string,source_type:string,source_id:string,gross_minor:int,fee_minor:int,adjustment_minor:int}> $items */
    public function prepare(string $configurationId, string $currency, CarbonImmutable $from, CarbonImmutable $to, array $items): SettlementBatch
    {
        if ($items === [] || $to <= $from || $this->tenant->get()->actorId === null) {
            throw new InvalidArgumentException('Settlement preparation requires an actor, period, and items.');
        }

        return DB::transaction(function () use ($configurationId, $currency, $from, $to, $items): SettlementBatch {
            $batch = SettlementBatch::query()->create(['provider_config_id' => $configurationId, 'reference' => 'SET-'.Str::ulid(),
                'status' => 'prepared', 'currency' => strtoupper($currency), 'period_start' => $from, 'period_end' => $to,
                'prepared_by' => $this->tenant->get()->actorId, 'prepared_at' => now('UTC')]);
            foreach ($items as $item) {
                $net = $item['gross_minor'] - $item['fee_minor'] + $item['adjustment_minor'];
                if ($item['gross_minor'] < 0 || $item['fee_minor'] < 0 || $net < 0) {
                    throw new InvalidArgumentException('Settlement item amounts are invalid.');
                }
                SettlementItem::query()->create([...$item, 'settlement_batch_id' => $batch->getKey(), 'net_minor' => $net]);
            }

            return $batch;
        });
    }

    public function addAdjustment(SettlementBatch $batch, string $beneficiaryType, string $beneficiaryId, int $amountMinor, string $reasonCode, string $notes): SettlementAdjustment
    {
        if ($batch->status !== 'prepared' || $amountMinor === 0 || $this->tenant->get()->actorId === null) {
            throw new InvalidArgumentException('Only prepared batches can receive non-zero adjustments.');
        }

        return SettlementAdjustment::query()->create(['settlement_batch_id' => $batch->getKey(), 'beneficiary_type' => $beneficiaryType,
            'beneficiary_id' => $beneficiaryId, 'amount_minor' => $amountMinor, 'currency' => $batch->currency,
            'reason_code' => $reasonCode, 'reason_notes' => $notes, 'created_by' => $this->tenant->get()->actorId]);
    }

    public function approve(SettlementBatch $batch): SettlementBatch
    {
        $actor = $this->tenant->get()->actorId;
        if ($batch->status !== 'prepared' || $actor === null || $actor === $batch->prepared_by) {
            throw new InvalidArgumentException('Settlement approval requires a different authorized actor.');
        }
        $batch->forceFill(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now('UTC')])->save();
        $this->audit->record(new AuditEntry('settlements.batch.approved', 'settlement_batch', (string) $batch->getKey(), AuditResult::Succeeded));
        $this->outbox->record('settlements.statement.approved.v1', 'settlement_batch', (string) $batch->getKey(), ['reference' => $batch->reference, 'currency' => $batch->currency]);

        return $batch->refresh();
    }

    public function submit(SettlementBatch $batch, string $idempotencyKey): SettlementBatch
    {
        if ($batch->status !== 'approved' || $batch->provider_config_id === null) {
            throw new InvalidArgumentException('Only an approved provider-backed batch can be submitted.');
        }
        $amount = (int) SettlementItem::query()->where('settlement_batch_id', $batch->getKey())->sum('net_minor')
            + (int) SettlementAdjustment::query()->where('settlement_batch_id', $batch->getKey())->sum('amount_minor');
        if ($amount < 0) {
            throw new InvalidArgumentException('Settlement total cannot be negative.');
        }
        $result = $this->payments->submitSettlement((string) $batch->provider_config_id, (string) $batch->getKey(), $amount, (string) $batch->currency, $idempotencyKey);
        if (! $result->succeeded()) {
            throw new InvalidArgumentException('Settlement provider did not accept the batch.');
        }
        $batch->forceFill(['status' => 'settled', 'settled_at' => now('UTC')])->save();

        return $batch->refresh();
    }
}
