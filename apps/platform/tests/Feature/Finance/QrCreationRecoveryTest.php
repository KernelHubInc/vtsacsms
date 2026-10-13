<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Payments\Infrastructure\Aub\AubQrProtocol;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class QrCreationRecoveryTest extends TenantSecurityTestCase
{
    public function test_verified_qr_without_invoice_is_displayed_and_only_callback_can_credit_it(): void
    {
        $this->withLiveWallet(function (string $subject, string $secret): void {
            $this->freezeTime();
            $observed = ['message' => '', 'context' => []];
            Log::shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context) use (&$observed): void {
                $observed = ['message' => $message, 'context' => $context];
            });
            Http::fake(['*' => Http::response(app(AubQrProtocol::class)->encode([
                'status' => '0', 'result_code' => '0', 'mch_id' => 'merchant-test',
                'code_url' => 'synthetic-qr', 'code_img_url' => 'https://provider.example.test/qr',
                'expiration_date' => now('Asia/Manila')->addMinutes(10)->format('YmdHis'),
            ], $secret))]);
            $service = app(QrTopups::class);
            $key = (string) Str::ulid();
            $row = $service->create($subject, 100, $key);
            $this->assertSame('pending', $row->status);
            $this->assertSame('synthetic-qr', $service->present($row)['qr_content']);
            $this->assertNull($row->invoice_id);
            $service->reconcile($row);
            $this->assertSame('pending', $row->refresh()->status);
            $this->assertSame($row->getKey(), $service->create($subject, 100, $key)->getKey());
            Http::assertSentCount(1);
            $this->assertDatabaseCount('prepaid_entries', 0);
            $this->assertSame('payments.qr_topup.invoice_unavailable', $observed['message']);
            $this->assertSame($row->getKey(), $observed['context']['topup_id']);
            $xml = app(AubQrProtocol::class)->encode([
                'status' => '0', 'result_code' => '0', 'pay_result' => '0', 'mch_id' => 'merchant-test',
                'out_trade_no' => (string) $row->getKey(), 'total_fee' => '100', 'fee_type' => 'PHP',
                'trade_type' => 'pay.instapay.native.v2', 'transaction_id' => 'transaction-test',
            ], $secret);
            $service->notify($xml);
            $service->notify($xml);
            $this->assertSame('paid', $row->refresh()->status);
            $this->assertDatabaseCount('prepaid_entries', 1);
        });
    }

    public function test_incomplete_signed_response_keeps_invoice_and_logs_only_safe_diagnostics(): void
    {
        $this->withLiveWallet(function (string $subject, string $secret): void {
            $observed = ['message' => '', 'context' => []];
            Log::shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context) use (&$observed): void {
                $observed = ['message' => $message, 'context' => $context];
            });
            Http::fake(['*' => Http::response(app(AubQrProtocol::class)->encode([
                'status' => '0', 'result_code' => '0', 'mch_id' => 'merchant-test',
                'invoiceId' => 'invoice-1', 'code_url' => 'private-qr-payload',
                'err_msg' => 'private-provider-message',
            ], $secret))]);
            $service = app(QrTopups::class);
            $key = (string) Str::ulid();
            $row = $service->create($subject, 100, $key);
            $this->assertSame('review_required', $row->status);
            $this->assertSame('invoice-1', $row->invoice_id);
            $this->assertNull($service->present($row)['qr_content']);
            $message = $observed['message'];
            $context = $observed['context'];
            $this->assertSame('payments.qr_topup.creation_failed', $message);
            $this->assertSame($row->getKey(), $context['topup_id']);
            $this->assertSame((string) $row->tenant_id, $context['tenant_id']);
            $this->assertSame('invalid_qr_fields', $context['reason']);
            $this->assertSame(0, $context['field_lengths']['expiration_date']);
            foreach ([$secret, 'private-qr-payload', 'private-provider-message'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, json_encode($context, JSON_THROW_ON_ERROR));
            }

            $service->create($subject, 100, $key);
            Http::assertSentCount(1);
            $this->assertDatabaseCount('prepaid_entries', 0);
        });
    }

    public function test_unverified_response_cannot_supply_an_invoice_or_change_to_review_without_inquiry(): void
    {
        $this->withLiveWallet(function (string $subject): void {
            $observed = ['message' => '', 'context' => []];
            Log::shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context) use (&$observed): void {
                $observed = ['message' => $message, 'context' => $context];
            });
            Http::fake(['*' => Http::response('<xml><status>0</status><invoiceId>untrusted</invoiceId></xml>')]);
            $service = app(QrTopups::class);
            $row = $service->create($subject, 100, (string) Str::ulid());
            $this->assertSame('unknown', $row->status);
            $this->assertNull($row->invoice_id);
            $service->reconcile($row);
            $this->assertSame('unknown', $row->refresh()->status);
            $this->assertSame('unverified_response', $observed['context']['reason']);
            Http::assertSentCount(1);
            $this->assertDatabaseCount('prepaid_entries', 0);
        });
    }

    public function test_documented_signed_qr_is_exposed_until_expiry_without_crediting_balance(): void
    {
        $this->withLiveWallet(function (string $subject, string $secret): void {
            $this->freezeTime();
            $expiry = now('Asia/Manila')->addMinutes(10);
            Http::fake(['*' => Http::response(app(AubQrProtocol::class)->encode([
                'status' => '0', 'result_code' => '0', 'mch_id' => 'merchant-test',
                'invoiceId' => 'invoice-1', 'code_url' => 'synthetic-qr',
                'expiration_date' => $expiry->format('YmdHis'),
            ], $secret))]);
            $service = app(QrTopups::class);
            $row = $service->create($subject, 100, (string) Str::ulid());
            $this->assertSame('pending', $row->status);
            $this->assertSame('synthetic-qr', $service->present($row)['qr_content']);
            $this->assertSame($expiry->utc()->timestamp, $row->expires_at?->timestamp);
            $this->travel(11)->minutes();
            $this->assertSame('expired', $service->present($row)['status']);
            $this->assertNull($service->present($row)['qr_content']);
            $this->assertDatabaseCount('prepaid_entries', 0);
        });
    }

    private function withLiveWallet(\Closure $test): void
    {
        Http::preventStrayRequests();
        $tenant = $this->createTenant('qr-recovery');
        $user = $this->createUser();
        $secret = bin2hex(random_bytes(32));
        config(['wallet.mode' => 'live', 'features.real_payments' => true, 'kyc.required_for_wallet' => false,
            'wallet.aub.approved' => true, 'wallet.aub.tenant_id' => $tenant->getKey(),
            'wallet.aub.merchant_id' => 'merchant-test', 'wallet.aub.signing_key' => $secret,
            'wallet.aub.server_ip' => '192.0.2.1', 'wallet.aub.notify_url' => 'https://merchant.example.test/callback']);
        $this->withinTenant($tenant, $user, fn () => $test($user->public_id, $secret));
    }
}
