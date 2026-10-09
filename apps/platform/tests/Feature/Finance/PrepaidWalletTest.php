<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Payments\Application\Qr\QrAvailability;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Payments\Application\Qr\WalletException;
use App\Modules\Payments\Domain\Models\QrTopup;
use App\Modules\Payments\Infrastructure\Aub\AubQrProtocol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TenantSecurityTestCase;

final class PrepaidWalletTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        config(['wallet.mode' => 'simulated', 'features.simulated_payments' => true, 'kyc.required_for_wallet' => false]);
    }

    public function test_simulated_topup_is_idempotent_and_posts_balanced_ledger_once(): void
    {
        $tenant = $this->createTenant('wallet');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($user): void {
            $service = app(QrTopups::class);
            $key = (string) Str::ulid();
            $one = $service->create($user->public_id, 10000, $key);
            $two = $service->create($user->public_id, 10000, $key);
            $this->assertSame($one->getKey(), $two->getKey());
            $this->assertSame(0, app(PrepaidWallet::class)->summary($user->public_id, 'simulated')['balance_minor']);
            $service->simulate((string) $one->getKey());
            $service->simulate((string) $one->getKey());
            $this->assertSame(10000, app(PrepaidWallet::class)->summary($user->public_id, 'simulated')['available_minor']);
            $this->assertSame(0, app(PrepaidWallet::class)->summary($user->public_id, 'live')['available_minor']);
            $this->assertDatabaseCount('prepaid_entries', 1);
            $this->assertDatabaseCount('ledger_transactions', 0);
            $this->assertEquals(DB::table('ledger_entries')->sum('debit_minor'), DB::table('ledger_entries')->sum('credit_minor'));
            $this->expectException(WalletException::class);
            $service->create($user->public_id, 20000, $key);
        });
        Http::assertNothingSent();
    }

    public function test_reservations_prevent_overspend_and_release_unused_funds_idempotently(): void
    {
        $tenant = $this->createTenant('reserves');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($user): void {
            $wallet = app(PrepaidWallet::class);
            $subject = $user->public_id;
            $ref = (string) Str::ulid();
            $wallet->credit($subject, 'simulated', (string) Str::ulid(), 10000);
            $wallet->reserve($subject, 'simulated', $ref, 8000);
            $wallet->reserve($subject, 'simulated', $ref, 8000);
            $this->assertSame(2000, $wallet->summary($subject, 'simulated')['available_minor']);
            try {
                $wallet->reserve($subject, 'simulated', (string) Str::ulid(), 3000);
                $this->fail('Overspending allowed');
            } catch (ValidationException) {
            }
            $wallet->settle($subject, 'simulated', $ref, 5000);
            $wallet->settle($subject, 'simulated', $ref, 5000);
            $this->assertSame(5000, $wallet->summary($subject, 'simulated')['available_minor']);
            $this->assertSame(0, $wallet->summary($subject, 'simulated')['reserved_minor']);
            $this->assertDatabaseCount('prepaid_entries', 4);
            $this->expectException(\LogicException::class);
            $wallet->settle($subject, 'simulated', $ref, 4000);
        });
    }

    public function test_wallet_api_requires_authentication_and_scopes_owner_tenant_and_book(): void
    {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
        $tenant = $this->createTenant('api-wallet');
        $otherTenant = $this->createTenant('api-other');
        $user = $this->createUser();
        $other = $this->createUser();
        foreach ([[$tenant, $user], [$tenant, $other], [$otherTenant, $user]] as [$scope,$actor]) {
            $this->withinTenant($scope, $actor, fn () => $this->createMembership($scope, $actor));
        }
        $login = function ($actor, $scope): string {
            $this->flushHeaders();

            return (string) $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'password', 'tenant_id' => $scope->getKey(), 'device_name' => 'Wallet test'])->assertOk()->json('data.token');
        };
        $token = $login($user, $tenant);
        $this->withToken($token)->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.mode', 'simulated')->assertHeader('Cache-Control', 'no-store, private');
        $id = $this->postJson('/api/v1/wallet/topups', ['amount_minor' => 500, 'idempotency_key' => (string) Str::ulid()])->assertOk()->json('data.id');
        $this->postJson('/api/v1/wallet/topups', ['amount_minor' => 0, 'idempotency_key' => (string) Str::ulid()])->assertUnprocessable();
        $this->withToken($login($other, $tenant))->getJson('/api/v1/wallet/topups/'.$id)->assertNotFound();
        $this->withToken($login($user, $otherTenant))->getJson('/api/v1/wallet/topups/'.$id)->assertNotFound();
        $this->getJson('/api/v1/wallet/topups')->assertJsonCount(0, 'data.items');
        Http::assertNothingSent();
    }

    public function test_live_collection_cannot_be_enabled_by_mobile_and_kyc_is_enforced(): void
    {
        $tenant = $this->createTenant('gates');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($user): void {
            config(['wallet.mode' => 'live', 'wallet.aub.approved' => false]);
            $this->assertSame('disabled', app(QrAvailability::class)->mode());
            try {
                app(QrTopups::class)->create($user->public_id, 100, (string) Str::ulid());
                $this->fail('Live collection allowed');
            } catch (WalletException) {
            }
            config(['wallet.mode' => 'simulated', 'kyc.required_for_wallet' => true]);
            $this->expectException(KycException::class);
            app(QrTopups::class)->create($user->public_id, 100, (string) Str::ulid());
        });
    }

    public function test_simulation_is_denied_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->assertSame('disabled', app(QrAvailability::class)->mode());
    }

    public function test_signed_callback_credits_once_even_after_expiry_and_collection_is_disabled(): void
    {
        $tenant = $this->createTenant('callback');
        $user = $this->createUser();
        $id = (string) Str::ulid();
        $key = bin2hex(random_bytes(32));
        config(['wallet.aub.tenant_id' => $tenant->getKey(), 'wallet.aub.approved' => true, 'wallet.aub.signing_key' => $key, 'wallet.mode' => 'disabled']);
        $this->withinTenant($tenant, $user, fn () => QrTopup::query()->create(['id' => $id, 'subject_id' => $user->public_id, 'book' => 'live', 'merchant_id' => 'merchant-test', 'amount_minor' => 12345, 'idempotency_key' => (string) Str::ulid(), 'expires_at' => now()->subMinute()]));
        $xml = app(AubQrProtocol::class)->encode($this->payment($id), $key);
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/webhooks/aub/qrph', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml)->assertOk()->assertSeeText('success');
        }
        $this->assertDatabaseCount('prepaid_entries', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertEquals(12345, DB::table('ledger_entries')->sum('debit_minor'));
        $this->assertEquals(12345, DB::table('ledger_entries')->sum('credit_minor'));
        $this->withinTenant($tenant, $user, fn () => $this->assertSame(12345, app(PrepaidWallet::class)->summary($user->public_id, 'live')['balance_minor']));
        $wrong = app(AubQrProtocol::class)->encode($this->payment($id) + ['extra' => 'forward-compatible'], bin2hex(random_bytes(32)));
        $this->call('POST', '/api/v1/webhooks/aub/qrph', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $wrong)->assertStatus(400);
        $this->assertDatabaseCount('prepaid_entries', 1);
    }

    public function test_authenticated_but_mismatched_amount_currency_merchant_and_transaction_do_not_credit(): void
    {
        $tenant = $this->createTenant('mismatch');
        $user = $this->createUser();
        $id = (string) Str::ulid();
        $key = bin2hex(random_bytes(32));
        config(['wallet.aub.signing_key' => $key]);
        $this->withinTenant($tenant, $user, function () use ($user, $id, $key): void {
            QrTopup::query()->create(['id' => $id, 'subject_id' => $user->public_id, 'book' => 'live', 'merchant_id' => 'merchant-test', 'amount_minor' => 12345, 'idempotency_key' => (string) Str::ulid()]);
            foreach ([['total_fee' => '12346'], ['fee_type' => 'USD'], ['mch_id' => 'other'], ['pay_result' => '1'], ['transaction_id' => '']] as $change) {
                try {
                    app(QrTopups::class)->notify(app(AubQrProtocol::class)->encode(array_replace($this->payment($id), $change), $key));
                    $this->fail('Invalid payment credited');
                } catch (WalletException) {
                }
            }
            $this->assertDatabaseCount('prepaid_entries', 0);
        });
    }

    public function test_unknown_create_is_not_retried_and_ambiguous_query_cannot_credit(): void
    {
        $tenant = $this->createTenant('timeout');
        $user = $this->createUser();
        $key = bin2hex(random_bytes(32));
        config(['wallet.mode' => 'live', 'wallet.aub.approved' => true, 'wallet.aub.tenant_id' => $tenant->getKey(), 'wallet.aub.merchant_id' => 'merchant-test', 'wallet.aub.signing_key' => $key, 'wallet.aub.server_ip' => '192.0.2.1', 'wallet.aub.notify_url' => 'https://merchant.example.test/api/v1/webhooks/aub/qrph']);
        Http::fake(['*' => Http::failedConnection()]);
        $this->withinTenant($tenant, $user, function () use ($user): void {
            $key = (string) Str::ulid();
            $row = app(QrTopups::class)->create($user->public_id, 12345, $key);
            $this->assertSame('unknown', $row->status);
            app(QrTopups::class)->create($user->public_id, 12345, $key);
            app(QrTopups::class)->reconcile($row);
            $this->assertDatabaseCount('prepaid_entries', 0);
        });
        Http::assertSentCount(1);
    }

    public function test_successful_qr_creation_does_not_credit_and_valid_query_still_requires_review(): void
    {
        $tenant = $this->createTenant('qr');
        $user = $this->createUser();
        $secret = bin2hex(random_bytes(32));
        config(['wallet.mode' => 'live', 'wallet.aub.approved' => true, 'wallet.aub.tenant_id' => $tenant->getKey(), 'wallet.aub.merchant_id' => 'merchant-test', 'wallet.aub.signing_key' => $secret, 'wallet.aub.server_ip' => '192.0.2.1', 'wallet.aub.notify_url' => 'https://merchant.example.test/api/v1/webhooks/aub/qrph']);
        $result = ['status' => '0', 'result_code' => '0', 'mch_id' => 'merchant-test', 'code_url' => 'synthetic-test-code', 'invoiceId' => 'invoice-1', 'expiration_date' => now('Asia/Manila')->addMinutes(10)->format('YmdHis')];
        Http::fake(['*' => Http::response(app(AubQrProtocol::class)->encode($result, $secret))]);
        $this->withinTenant($tenant, $user, function () use ($user): void {
            $row = app(QrTopups::class)->create($user->public_id, 12345, (string) Str::ulid());
            $this->assertSame('pending', $row->status);
            $this->assertSame('invoice-1', $row->invoice_id);
            app(QrTopups::class)->reconcile($row);
            $this->assertSame('review_required', $row->refresh()->status);
            $this->assertDatabaseCount('prepaid_entries', 0);
        });
    }

    /** @return array<string,string> */
    private function payment(string $id): array
    {
        return ['status' => '0', 'result_code' => '0', 'pay_result' => '0', 'mch_id' => 'merchant-test', 'out_trade_no' => $id, 'total_fee' => '12345', 'fee_type' => 'PHP', 'trade_type' => 'pay.instapay.native.v2', 'transaction_id' => 'transaction-test', 'nonce_str' => 'notification-test'];
    }
}
