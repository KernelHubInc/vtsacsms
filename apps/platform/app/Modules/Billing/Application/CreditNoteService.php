<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\CreditNote;
use App\Modules\Billing\Domain\Models\Invoice;
use App\Modules\Integrations\Application\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreditNoteService
{
    public function __construct(private DoubleEntryLedger $ledger, private OutboxRecorder $outbox) {}

    public function issue(Invoice $invoice, int $amountMinor, string $reasonCode, ?string $notes = null): CreditNote
    {
        if ($amountMinor <= 0 || $amountMinor > (int) $invoice->total_minor) {
            throw new InvalidArgumentException('Invalid credit-note amount.');
        }

        return DB::transaction(function () use ($invoice, $amountMinor, $reasonCode, $notes): CreditNote {
            $note = CreditNote::query()->create(['invoice_id' => $invoice->getKey(), 'document_reference' => 'CRN-'.Str::ulid(),
                'currency' => $invoice->currency, 'amount_minor' => $amountMinor, 'reason_code' => $reasonCode,
                'reason_notes' => $notes, 'legal_review_required' => true, 'issued_at' => now('UTC')]);
            $returns = $this->ledger->account('4900-SALES-RETURNS', 'Sales returns and allowances', 'expense', (string) $invoice->currency);
            $receivable = $this->ledger->account('1100-AR', 'Accounts receivable', 'asset', (string) $invoice->currency);
            $this->ledger->post('credit_note', (string) $note->getKey(), 'credit_note_issued', (string) $invoice->currency,
                'credit-note-'.(string) $note->getKey(), [new LedgerPosting($returns, debitMinor: $amountMinor), new LedgerPosting($receivable, creditMinor: $amountMinor)]);
            $this->outbox->record('billing.credit_note.issued.v1', 'credit_note', (string) $note->getKey(), ['invoice_id' => (string) $invoice->getKey(), 'amount_minor' => $amountMinor, 'currency' => $invoice->currency]);

            return $note;
        });
    }
}
