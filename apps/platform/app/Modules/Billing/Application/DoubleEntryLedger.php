<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\LedgerAccount;
use App\Modules\Billing\Domain\Models\LedgerEntry;
use App\Modules\Billing\Domain\Models\LedgerTransaction;
use App\Modules\Integrations\Application\OutboxRecorder;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class DoubleEntryLedger
{
    public function __construct(private CurrentTenant $tenant, private OutboxRecorder $outbox) {}

    public function account(string $code, string $name, string $type, string $currency, ?string $ownerType = null, ?string $ownerId = null): LedgerAccount
    {
        return LedgerAccount::query()->firstOrCreate(['code' => $code, 'currency' => strtoupper($currency)],
            ['name' => $name, 'type' => $type, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'is_active' => true]);
    }

    /** @param list<LedgerPosting> $postings */
    public function post(string $referenceType, string $referenceId, string $eventType, string $currency, string $idempotencyKey, array $postings, ?string $description = null): LedgerTransaction
    {
        $existing = LedgerTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (count($postings) < 2) {
            throw new InvalidArgumentException('A ledger transaction requires at least two entries.');
        }
        $debits = 0;
        $credits = 0;
        foreach ($postings as $posting) {
            if (($posting->debitMinor > 0) === ($posting->creditMinor > 0) || $posting->account->currency !== strtoupper($currency)) {
                throw new InvalidArgumentException('Each posting must have one positive side and use the transaction currency.');
            }
            $debits += $posting->debitMinor;
            $credits += $posting->creditMinor;
        }
        if ($debits !== $credits) {
            throw new InvalidArgumentException('Double-entry transaction is not balanced.');
        }

        return DB::transaction(function () use ($referenceType, $referenceId, $eventType, $currency, $idempotencyKey, $postings, $description, $debits): LedgerTransaction {
            $transaction = LedgerTransaction::query()->create(['reference_type' => $referenceType, 'reference_id' => $referenceId,
                'event_type' => $eventType, 'currency' => strtoupper($currency), 'idempotency_key' => $idempotencyKey,
                'description' => $description, 'posted_by' => $this->tenant->get()->actorId, 'posted_at' => now('UTC')]);
            foreach ($postings as $posting) {
                LedgerEntry::query()->create(['ledger_transaction_id' => $transaction->getKey(), 'ledger_account_id' => $posting->account->getKey(),
                    'debit_minor' => $posting->debitMinor, 'credit_minor' => $posting->creditMinor]);
            }
            $this->outbox->record('billing.ledger_transaction.posted.v1', 'ledger_transaction', (string) $transaction->getKey(), [
                'event_type' => $eventType, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'amount_minor' => $debits, 'currency' => strtoupper($currency),
            ]);

            return $transaction;
        });
    }
}
