<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Billing\Domain\Models\LedgerAccount;
use App\Modules\Billing\Domain\Models\PrepaidAccount;
use App\Modules\Billing\Domain\Models\PrepaidEntry;
use App\Modules\Billing\Domain\Models\PrepaidReservation;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Billing's application contract. Only verified Payments facts may call credit(). */
final readonly class PrepaidWallet
{
    public function __construct(private DoubleEntryLedger $ledger, private AuditRecorder $audit) {}

    public function account(string $subject, string $book, ?string $accountId = null): PrepaidAccount
    {
        if (! in_array($book, ['simulated', 'live'], true)) {
            throw new \InvalidArgumentException('Invalid wallet book.');
        }

        return PrepaidAccount::query()->firstOrCreate(['subject_id' => $accountId === null ? $subject : null,
            'charging_account_id' => $accountId, 'book' => $book, 'currency' => 'PHP']);
    }

    public function credit(string $subject, string $book, string $reference, int $amount, ?string $accountId = null): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit must be positive.');
        }
        DB::transaction(function () use ($subject, $book, $reference, $amount, $accountId): void {
            $account = $this->account($subject, $book, $accountId);
            $account = PrepaidAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $existing = PrepaidEntry::query()->where('account_id', $account->getKey())->where('reference_id', $reference)->where('kind', 'topup')->first();
            if ($existing !== null) {
                if ($existing->amount_minor !== $amount) {
                    throw new \LogicException('Conflicting wallet credit.');
                }

                return;
            }
            // Simulated funds never enter financial exports.
            if ($book === 'live') {
                $clearing = $this->ledger->account('PREPAID-CLEARING-'.$book, 'Prepaid provider clearing', 'asset', 'PHP');
                $liability = $this->liability($account);
                $this->ledger->post('qr_topup', $reference, 'prepaid_credit', 'PHP', 'prepaid-credit-'.$reference,
                    [new LedgerPosting($clearing, debitMinor: $amount), new LedgerPosting($liability, creditMinor: $amount)]);
            }
            $account->increment('balance_minor', $amount);
            $this->entry($account, $reference, 'topup', $amount);
        });
    }

    /** Reserve before charging; the caller owns authorization and the charging reference. */
    public function reserve(string $subject, string $book, string $reference, int $amount, ?string $accountId = null, ?string $vehicleId = null): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Reservation must be positive.');
        }
        DB::transaction(function () use ($subject, $book, $reference, $amount, $accountId, $vehicleId): void {
            $account = $this->account($subject, $book, $accountId);
            $account = PrepaidAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $existing = PrepaidReservation::query()->where('reference_id', $reference)->first();
            if ($existing !== null) {
                if ($existing->account_id !== $account->getKey() || $existing->amount_minor !== $amount) {
                    throw new \LogicException('Conflicting reservation.');
                }

                return;
            }
            if ($amount > $this->sessionCap($accountId, $subject, $vehicleId, $amount)) {
                throw ValidationException::withMessages(['balance' => ['The account spending limit changed. Review a fresh quote.']]);
            }
            if ($account->balance_minor - $account->reserved_minor < $amount) {
                throw ValidationException::withMessages(['balance' => ['Insufficient available balance.']]);
            }
            PrepaidReservation::query()->create(['account_id' => $account->getKey(), 'reference_id' => $reference, 'amount_minor' => $amount]);
            $account->increment('reserved_minor', $amount);
            $this->entry($account, $reference, 'reserved', $amount);
        });
    }

    /** Finalize only against a final rated charge; zero releases a failed start. */
    public function settle(string $subject, string $book, string $reference, int $spent, ?string $accountId = null): void
    {
        DB::transaction(function () use ($subject, $book, $reference, $spent, $accountId): void {
            $account = $this->account($subject, $book, $accountId);
            $account = PrepaidAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $hold = PrepaidReservation::query()->where('account_id', $account->getKey())->where('reference_id', $reference)->firstOrFail();
            if ($spent < 0 || $spent > $hold->amount_minor) {
                throw new \InvalidArgumentException('Spend exceeds reserved funds.');
            }
            if ($hold->status === 'settled') {
                if ($hold->spent_minor !== $spent) {
                    throw new \LogicException('Conflicting settlement.');
                }

                return;
            }
            if ($spent > 0 && $book === 'live') {
                $receivable = $this->ledger->account('1100-AR', 'Accounts receivable', 'asset', 'PHP');
                $this->ledger->post('prepaid_reservation', $reference, 'prepaid_spend', 'PHP', 'prepaid-spend-'.$reference,
                    [new LedgerPosting($this->liability($account), debitMinor: $spent), new LedgerPosting($receivable, creditMinor: $spent)]);
            }
            $account->forceFill(['balance_minor' => $account->balance_minor - $spent, 'reserved_minor' => $account->reserved_minor - $hold->amount_minor])->save();
            $hold->forceFill(['status' => 'settled', 'spent_minor' => $spent])->save();
            $this->entry($account, $reference, 'spent', $spent);
            if ($hold->amount_minor > $spent) {
                $this->entry($account, $reference, 'released', $hold->amount_minor - $spent);
            }
        });
    }

    private function liability(PrepaidAccount $account): LedgerAccount
    {
        return $this->ledger->account('PREPAID-'.$account->getKey(), 'Customer prepaid balance', 'liability', 'PHP',
            $account->charging_account_id === null ? 'subject' : 'organization', $account->charging_account_id ?? $account->subject_id);
    }

    private function entry(PrepaidAccount $account, string $reference, string $kind, int $amount): void
    {
        $entry = PrepaidEntry::query()->create(['account_id' => $account->getKey(), 'reference_id' => $reference, 'kind' => $kind, 'amount_minor' => $amount, 'created_at' => now('UTC')]);
        $this->audit->record(new AuditEntry('billing.prepaid.'.$kind, 'prepaid_entry', (string) $entry->getKey(), AuditResult::Succeeded,
            metadata: ['wallet_id' => (string) $account->getKey(), 'reference_id' => $reference,
                'charging_account_id' => $account->charging_account_id, 'book' => $account->book,
                'currency' => $account->currency, 'amount_minor' => $amount,
                'balance_minor' => $account->balance_minor, 'reserved_minor' => $account->reserved_minor]));
    }

    /** @return array<string,mixed> */
    public function summary(string $subject, string $book, ?string $accountId = null): array
    {
        $account = PrepaidAccount::query()->where('subject_id', $accountId === null ? $subject : null)->where('charging_account_id', $accountId)->where('book', $book)->first();

        return ['id' => $account?->getKey(), 'currency' => 'PHP', 'balance_minor' => $account->balance_minor ?? 0, 'reserved_minor' => $account->reserved_minor ?? 0,
            'available_minor' => ($account->balance_minor ?? 0) - ($account->reserved_minor ?? 0)];
    }

    /** @return array<string,mixed> */
    public function history(string $subject, string $book, ?string $accountId = null): array
    {
        $account = PrepaidAccount::query()->where('subject_id', $accountId === null ? $subject : null)->where('charging_account_id', $accountId)->where('book', $book)->first();
        $page = PrepaidEntry::query()->where('account_id', $account?->getKey())->orderByDesc('id')->cursorPaginate(20);

        return ['items' => $page->map(fn (PrepaidEntry $entry): array => ['id' => $entry->getKey(), 'kind' => $entry->kind, 'amount_minor' => $entry->amount_minor, 'created_at' => $entry->created_at?->toIso8601String()])->all(),
            'next_cursor' => $page->nextCursor()?->encode()];
    }

    public function sessionCap(?string $account, string $driver, ?string $vehicle, int $maximum): int
    {
        if ($account === null) {
            return $maximum;
        }
        $limits = DB::table('prepaid_spending_limits')->where('tenant_id', app(CurrentTenant::class)->get()->tenantId)
            ->where('charging_account_id', $account)->where(function ($q) use ($driver, $vehicle): void {
                $q->where(fn ($q) => $q->where('target_type', 'driver')->where('target_id', $driver));
                if ($vehicle !== null) {
                    $q->orWhere(fn ($q) => $q->where('target_type', 'vehicle')->where('target_id', $vehicle));
                }
            })->min('amount_minor');

        return $limits === null ? $maximum : min($maximum, (int) $limits);
    }

    /** Authorized account owner has validated the target through Organizations/Assets. */
    public function setLimit(string $actor, string $account, string $type, string $target, ?int $amount): void
    {
        if (! in_array($type, ['driver', 'vehicle'], true) || ($amount !== null && $amount < 0)) {
            throw new \InvalidArgumentException('Invalid spending limit.');
        }
        DB::transaction(function () use ($actor, $account, $type, $target, $amount): void {
            $wallet = $this->account($actor, 'live', $account);
            PrepaidAccount::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
            $key = ['tenant_id' => app(CurrentTenant::class)->get()->tenantId, 'charging_account_id' => $account, 'target_type' => $type, 'target_id' => $target];
            if ($amount === null) {
                DB::table('prepaid_spending_limits')->where($key)->delete();
            } else {
                $existing = DB::table('prepaid_spending_limits')->where($key)->first();
                DB::table('prepaid_spending_limits')->updateOrInsert($key, ['id' => $existing->id ?? (string) Str::ulid(), 'amount_minor' => $amount, 'created_at' => $existing->created_at ?? now('UTC'), 'updated_at' => now('UTC')]);
            }
            $this->audit->record(new AuditEntry('billing.prepaid.limit_changed', 'organization', $account, AuditResult::Succeeded,
                changes: ['target_type' => $type, 'target_id' => $target, 'amount_minor' => $amount, 'period' => 'per_session']));
        });
    }

    /** @return list<array<string, mixed>> */
    public function limits(string $account): array
    {
        return array_values(DB::table('prepaid_spending_limits')->where('tenant_id', app(CurrentTenant::class)->get()->tenantId)
            ->where('charging_account_id', $account)->get(['target_type', 'target_id', 'amount_minor'])->map(fn ($r): array => [
                'target_type' => (string) $r->target_type, 'target_id' => (string) $r->target_id, 'amount_minor' => (int) $r->amount_minor,
            ])->all());
    }
}
