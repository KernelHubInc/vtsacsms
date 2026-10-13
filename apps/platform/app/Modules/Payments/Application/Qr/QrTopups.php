<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Qr;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Identity\Application\Kyc\KycEligibility;
use App\Modules\Payments\Domain\Models\QrTopup;
use App\Modules\Payments\Infrastructure\Aub\AubQrGateway;
use App\Modules\Payments\Infrastructure\Aub\AubQrProtocol;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class QrTopups
{
    public function __construct(private QrAvailability $availability, private AubQrGateway $gateway, private AubQrProtocol $protocol,
        private PrepaidWallet $wallet, private KycEligibility $eligibility, private AuditRecorder $audit) {}

    public function create(string $subject, int $amount, string $key): QrTopup
    {
        $book = $this->availability->requireCollection();
        $this->eligibility->assertAllowed($subject, 'wallet');
        if ($amount < (int) config('wallet.minimum_minor') || $amount > (int) config('wallet.maximum_minor')) {
            throw new WalletException('INVALID_TOPUP_AMOUNT', 'Choose an amount within the displayed limits.', 422);
        }
        // Persist the order before the network call. A retry always returns this same order.
        $topup = DB::transaction(function () use ($subject, $amount, $key, $book): QrTopup {
            $row = QrTopup::query()->firstOrCreate(['subject_id' => $subject, 'book' => $book, 'idempotency_key' => $key],
                ['amount_minor' => $amount, 'currency' => 'PHP', 'merchant_id' => $book === 'live' ? config('wallet.aub.merchant_id') : null]);
            if ($row->amount_minor !== $amount) {
                throw new WalletException('IDEMPOTENCY_CONFLICT', 'This request reference belongs to a different amount.');
            }
            if ($row->wasRecentlyCreated) {
                $this->record($row, 'created');
            }

            return $row;
        });
        if (! $topup->wasRecentlyCreated) {
            return $topup;
        }
        if ($book === 'simulated') {
            $topup->forceFill(['status' => 'pending', 'expires_at' => now('UTC')->addMinutes(10)])->save();

            return $topup;
        }
        try {
            $result = $this->gateway->create($topup);
            DB::transaction(function () use ($topup, $result): void {
                $row = QrTopup::query()->whereKey($topup->getKey())->lockForUpdate()->firstOrFail();
                if ($row->status === 'paid') {
                    return;
                }
                if (($result['mch_id'] ?? '') !== $row->merchant_id || ($result['status'] ?? '') !== '0' || ($result['result_code'] ?? '') !== '0') {
                    $row->forceFill(['status' => 'review_required'])->save();
                    $this->creationFailure($row, ($result['mch_id'] ?? '') !== $row->merchant_id ? 'merchant_mismatch' : 'provider_rejected', $result);

                    return;
                }
                $qr = $result['code_url'] ?? '';
                $invoice = $result['invoiceId'] ?? '';
                // Keep an authenticated invoice for inquiry even if another QR field is invalid.
                if ($invoice !== '' && strlen($invoice) <= 32) {
                    $row->forceFill(['invoice_id' => $invoice])->save();
                }
                if ($qr === '' || strlen($qr) > 512 || strlen($invoice) > 32 || ! preg_match('/^[0-9]{14}$/D', $result['expiration_date'] ?? '')) {
                    $row->forceFill(['status' => 'review_required'])->save();
                    $this->creationFailure($row, 'invalid_qr_fields', $result);

                    return;
                }
                $expiry = CarbonImmutable::createFromFormat('!YmdHis', $result['expiration_date'], 'Asia/Manila');
                if ($expiry === null || $expiry->format('YmdHis') !== $result['expiration_date']) {
                    throw new \UnexpectedValueException('Invalid expiry.');
                }
                // AUB's observed signed QR Ph response omits invoiceId. It gates inquiry, not QR display.
                $row->forceFill(['status' => 'pending', 'qr_content' => $qr, 'invoice_id' => $invoice === '' ? null : $invoice, 'expires_at' => $expiry->utc()])->save();
                if ($invoice === '') {
                    Log::warning('payments.qr_topup.invoice_unavailable', [
                        'tenant_id' => (string) $row->tenant_id, 'topup_id' => (string) $row->getKey(),
                    ]);
                }
            });
        } catch (\Throwable $error) {
            // Preserve a callback that won the race; never expose provider payloads or credentials.
            QrTopup::query()->whereKey($topup->getKey())->where('status', 'creating')->update(['status' => 'unknown']);
            $reason = match (true) {
                $error instanceof ConnectionException => 'connection_failed',
                $error instanceof RequestException => 'http_failed',
                $error instanceof WalletException && $error->errorCode === 'INVALID_AUB_MESSAGE' => 'unverified_response',
                $error instanceof \UnexpectedValueException => 'invalid_expiry',
                default => 'processing_failed',
            };
            $this->creationFailure($topup, $reason);
        }

        return $topup->refresh();
    }

    public function notify(string $raw): void
    {
        $fields = $this->protocol->verify($raw, (string) config('wallet.aub.signing_key'));
        if (($fields['status'] ?? '') !== '0' || ($fields['result_code'] ?? '') !== '0' || ($fields['pay_result'] ?? '') !== '0'
            || ($fields['trade_type'] ?? '') !== 'pay.instapay.native.v2' || ($fields['fee_type'] ?? '') !== 'PHP'
            || ! preg_match('/^[0-9]{1,12}$/D', $fields['total_fee'] ?? '') || ! Str::isUlid($fields['out_trade_no'] ?? '')
            || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $fields['transaction_id'] ?? '')) {
            throw new WalletException('INVALID_PAYMENT_FACT', 'Payment confirmation could not be matched.', 400);
        }
        $this->confirm($fields['out_trade_no'], 'live', (int) $fields['total_fee'], $fields['transaction_id'], $fields['mch_id'] ?? '', hash('sha256', $raw));
    }

    public function simulate(string $id): void
    {
        if ($this->availability->requireCollection() !== 'simulated') {
            throw new WalletException('SIMULATION_DISABLED', 'Simulation is not available.', 403);
        }
        $row = QrTopup::query()->where('book', 'simulated')->findOrFail($id);
        $this->confirm($id, 'simulated', $row->amount_minor, 'SIM-'.$id, '', hash('sha256', 'simulation:'.$id));
    }

    private function confirm(string $id, string $book, int $amount, string $transaction, string $merchant, string $evidenceHash): void
    {
        DB::transaction(function () use ($id, $book, $amount, $transaction, $merchant, $evidenceHash): void {
            $row = QrTopup::query()->where('book', $book)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->amount_minor !== $amount || (string) $row->merchant_id !== $merchant) {
                throw new WalletException('PAYMENT_MISMATCH', 'Payment confirmation could not be matched.', 400);
            }
            if ($row->status === 'paid') {
                if ($row->transaction_id !== $transaction) {
                    throw new WalletException('PAYMENT_CONFLICT', 'Payment requires review.', 409);
                }

                return;
            }
            // Billing owns balance writes. This contract participates in the same local DB transaction.
            $this->wallet->credit($row->subject_id, $book, $id, $amount);
            $row->forceFill(['status' => 'paid', 'transaction_id' => $transaction, 'confirmation_hash' => $evidenceHash, 'paid_at' => now('UTC'), 'qr_content' => null])->save();
            $this->record($row, 'confirmed');
        });
    }

    public function reconcile(QrTopup $topup): void
    {
        if ($topup->book !== 'live' || $topup->status === 'paid' || ! $topup->invoice_id) {
            return;
        }
        try {
            $this->gateway->retrieve($topup);
        } catch (\Throwable) {
            return;
        }
        // The QR Ph query success/pending schema is unresolved. Retrieval alone must never mint credit.
        QrTopup::query()->whereKey($topup->getKey())->where('status', '!=', 'paid')->update(['status' => 'review_required']);
    }

    /** @param array<string,string> $fields */
    private function creationFailure(QrTopup $row, string $reason, array $fields = []): void
    {
        // Log shape and bounded codes only; provider messages can contain credentials or payment data.
        $codes = [];
        foreach (['status', 'result_code', 'err_code'] as $name) {
            $value = $fields[$name] ?? '';
            $codes[$name] = preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $value) ? $value : null;
        }
        $lengths = [];
        foreach (['code_url', 'code_img_url', 'invoiceId', 'expiration_date'] as $name) {
            $lengths[$name] = strlen($fields[$name] ?? '');
        }
        Log::warning('payments.qr_topup.creation_failed', [
            'tenant_id' => (string) $row->tenant_id, 'topup_id' => (string) $row->getKey(),
            'reason' => $reason, 'provider_codes' => $codes, 'field_lengths' => $lengths,
        ]);
    }

    /** @return array<string,mixed> */
    public function present(QrTopup $row): array
    {
        $expired = $row->expires_at?->isPast() ?? false;

        return ['id' => $row->getKey(), 'amount_minor' => $row->amount_minor, 'currency' => $row->currency, 'mode' => $row->book,
            'status' => $row->status === 'pending' && $expired ? 'expired' : $row->status,
            'qr_content' => $row->status === 'pending' && ! $expired ? $row->qr_content : null,
            'expires_at' => $row->expires_at?->toIso8601String(), 'created_at' => $row->created_at?->toIso8601String()];
    }

    private function record(QrTopup $row, string $action): void
    {
        $this->audit->record(new AuditEntry('payments.qr_topup.'.$action, 'qr_topup', (string) $row->getKey(), AuditResult::Succeeded));
    }
}
