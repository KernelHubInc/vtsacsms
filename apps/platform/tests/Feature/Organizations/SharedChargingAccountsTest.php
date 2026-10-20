<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Models\User;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Identity\Application\ChargingAccountInvitations;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\ChargingAccountRole;
use App\Modules\Payments\Application\Qr\QrTopups;
use App\Modules\Payments\Domain\Models\QrTopup;
use App\Modules\Payments\Infrastructure\Aub\AubQrProtocol;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TenantSecurityTestCase;

final class SharedChargingAccountsTest extends TenantSecurityTestCase
{
    private Tenant $tenant;

    private User $owner;

    private User $driver;

    private string $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Notification::fake();
        Http::preventStrayRequests();
        config(['wallet.mode' => 'simulated', 'features.simulated_payments' => true, 'kyc.required_for_wallet' => false]);
        $this->tenant = $this->createTenant('shared');
        $this->owner = $this->createUser();
        $this->driver = $this->createUser();
        $this->withinTenant($this->tenant, $this->owner, function (): void {
            $this->createMembership($this->tenant, $this->owner);
            $this->createMembership($this->tenant, $this->driver);
            $this->account = (string) app(ChargingAccounts::class)->create($this->owner, 'Test fleet', 'fleet')->getKey();
        });
    }

    /** @return array<string, string> */
    private function headers(User $user, ?string $account = null, ?Tenant $tenant = null): array
    {
        $this->flushHeaders();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password',
            'tenant_id' => ($tenant ?? $this->tenant)->getKey(), 'device_name' => 'Shared account test'])->assertOk()->json('data.token');

        return ['Authorization' => 'Bearer '.$token] + ($account === null ? [] : ['X-Charging-Account-ID' => $account]);
    }

    private function join(User $user, ChargingAccountRole $role = ChargingAccountRole::Driver): void
    {
        $this->withinTenant($this->tenant, $this->owner, function () use ($user, $role): void {
            $invitation = app(ChargingAccountInvitations::class)->invite($this->owner, $this->account, $user->email, $role);
            app(ChargingAccountInvitations::class)->accept($user, $invitation['id']);
        });
    }

    public function test_personal_backfill_is_repeatable_and_one_person_can_join_multiple_accounts(): void
    {
        $this->join($this->driver);
        $headers = $this->headers($this->driver);
        $this->getJson('/api/v1/charging-accounts', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/charging-accounts', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/charging-accounts', ['name' => 'Side business', 'type' => 'business'], $headers)->assertOk()->assertJsonPath('data.role', 'owner');
        $this->getJson('/api/v1/charging-accounts', $headers)->assertOk()->assertJsonCount(3, 'data');
        self::assertSame(1, DB::table('organizations')->where('personal_subject_id', $this->driver->public_id)->count());
        self::assertSame(2, DB::table('memberships')->count());
    }

    public function test_account_reads_have_a_separate_budget_from_account_changes_and_wallet(): void
    {
        $headers = $this->headers($this->owner);
        for ($attempt = 0; $attempt < 35; $attempt++) {
            $this->getJson('/api/v1/charging-accounts', $headers)->assertOk();
        }
        $this->getJson('/api/v1/wallet', $headers)->assertOk();
        $this->postJson('/api/v1/charging-accounts', ['name' => '   ', 'type' => 'business'], $headers)->assertUnprocessable();
    }

    public function test_only_owner_can_edit_shared_account_name_and_type_with_validation(): void
    {
        $owner = $this->headers($this->owner);
        $this->patchJson('/api/v1/charging-accounts/'.$this->account, ['name' => '  Lumina   Business  ', 'type' => 'business'], $owner)
            ->assertOk()->assertJsonPath('data.name', 'Lumina Business')->assertJsonPath('data.type', 'business');
        $this->assertDatabaseHas('organizations', ['id' => $this->account, 'name' => 'Lumina Business', 'type' => 'business', 'charging_account_type' => 'business']);
        foreach ([['name' => ' ', 'type' => 'fleet'], ['name' => 'A', 'type' => 'fleet'], ['name' => 'Valid', 'type' => 'personal']] as $payload) {
            $this->patchJson('/api/v1/charging-accounts/'.$this->account, $payload, $owner)->assertUnprocessable();
        }
        $personal = $this->withinTenant($this->tenant, $this->owner, fn () => (string) app(ChargingAccounts::class)->personal($this->owner)->getKey());
        self::assertNotSame($this->account, $personal);
        $this->patchJson('/api/v1/charging-accounts/'.$personal, ['name' => 'Changed', 'type' => 'business'], $owner)->assertForbidden();
        $this->join($this->driver);
        $driver = $this->headers($this->driver);
        $this->patchJson('/api/v1/charging-accounts/'.$this->account, ['name' => 'Other', 'type' => 'fleet'], $driver)->assertForbidden();
    }

    public function test_unused_shared_account_can_be_deleted_but_related_history_and_members_block_deletion(): void
    {
        $owner = $this->headers($this->owner);
        $unused = $this->postJson('/api/v1/charging-accounts', ['name' => 'Unused fleet', 'type' => 'fleet'], $owner)->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/charging-accounts/'.$unused, [], $owner)->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('organizations', ['id' => $unused]);
        $this->deleteJson('/api/v1/charging-accounts/'.$unused, [], $owner)->assertNotFound();

        $this->join($this->driver);
        $this->deleteJson('/api/v1/charging-accounts/'.$this->account, [], $owner)->assertConflict();
        $this->assertDatabaseHas('organizations', ['id' => $this->account]);

        $funded = $this->postJson('/api/v1/charging-accounts', ['name' => 'Funded business', 'type' => 'business'], $owner)->assertOk()->json('data.id');
        $this->withinTenant($this->tenant, $this->owner, fn () => app(PrepaidWallet::class)->credit($this->owner->public_id, 'simulated', (string) Str::ulid(), 100, $funded));
        $this->deleteJson('/api/v1/charging-accounts/'.$funded, [], $owner)->assertConflict();
        $this->assertDatabaseHas('organizations', ['id' => $funded]);
        $personal = $this->withinTenant($this->tenant, $this->owner, fn () => (string) app(ChargingAccounts::class)->personal($this->owner)->getKey());
        $this->deleteJson('/api/v1/charging-accounts/'.$personal, [], $owner)->assertForbidden();
    }

    public function test_account_edit_and_delete_cannot_cross_tenants(): void
    {
        $other = $this->createTenant('other-account-tenant');
        $this->withinTenant($other, $this->owner, fn () => $this->createMembership($other, $this->owner));
        $headers = $this->headers($this->owner, tenant: $other);

        $this->patchJson('/api/v1/charging-accounts/'.$this->account, ['name' => 'Wrong tenant', 'type' => 'fleet'], $headers)
            ->assertNotFound();
        $this->deleteJson('/api/v1/charging-accounts/'.$this->account, [], $headers)->assertNotFound();
        $this->assertDatabaseHas('organizations', ['id' => $this->account, 'name' => 'Test fleet']);
    }

    public function test_invitation_requires_verified_recipient_expires_and_cannot_be_reused(): void
    {
        $owner = $this->headers($this->owner);
        $id = $this->postJson('/api/v1/charging-accounts/'.$this->account.'/invitations', ['email' => $this->driver->email, 'role' => 'driver'], $owner)->assertOk()->json('data.id');
        $this->postJson('/api/v1/charging-accounts/invitations/'.$id.'/accept', [], $owner)->assertNotFound();
        $driver = $this->headers($this->driver);
        $this->getJson('/api/v1/charging-accounts/invitations', $driver)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/charging-accounts/invitations/'.$id.'/accept', [], $driver)->assertOk();
        $this->postJson('/api/v1/charging-accounts/invitations/'.$id.'/accept', [], $driver)->assertUnprocessable();
        $this->withinTenant($this->tenant, $this->owner, function (): void {
            $recipient = $this->createUser();
            $this->createMembership($this->tenant, $recipient);
            $id = app(ChargingAccountInvitations::class)->invite($this->owner, $this->account, $recipient->email, ChargingAccountRole::Driver)['id'];
            $row = UserInvitation::query()->whereKey($id)->firstOrFail();
            $this->assertNull($row->accepted_at);
            $this->assertSame(64, strlen($row->getRawOriginal('token_hash')));
        });
    }

    public function test_invites_require_a_registered_active_recipient_in_this_tenant(): void
    {
        $headers = $this->headers($this->owner);
        $outside = $this->createUser();
        $disabled = $this->createUser();
        $disabled->forceFill(['disabled_at' => now()])->save();
        $this->withinTenant($this->tenant, $this->owner, fn () => $this->createMembership($this->tenant, $disabled));
        foreach (['missing@example.test', $outside->email, $disabled->email] as $email) {
            $this->postJson('/api/v1/charging-accounts/'.$this->account.'/invitations', ['email' => $email, 'role' => 'driver'], $headers)
                ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['email']]]);
        }
        $this->assertDatabaseCount('user_invitations', 0);
        Notification::assertNothingSent();
        $this->postJson('/api/v1/charging-accounts/'.$this->account.'/invitations', ['email' => strtoupper($this->driver->email), 'role' => 'driver'], $headers)->assertOk();
        $this->postJson('/api/v1/charging-accounts/'.$this->account.'/invitations', ['email' => $this->owner->email, 'role' => 'driver'], $headers)
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_revoked_expired_and_unverified_invitations_fail_and_do_not_grant_access(): void
    {
        $id = $this->withinTenant($this->tenant, $this->owner, fn () => app(ChargingAccountInvitations::class)->invite($this->owner, $this->account, $this->driver->email, ChargingAccountRole::Driver)['id']);
        $owner = $this->headers($this->owner);
        $driver = $this->headers($this->driver);
        $this->driver->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/v1/charging-accounts/invitations/'.$id.'/accept', [], $driver)->assertForbidden();
        $this->driver->forceFill(['email_verified_at' => now()])->save();
        $this->deleteJson('/api/v1/charging-accounts/'.$this->account.'/invitations/'.$id, [], $owner)->assertOk();
        $this->postJson('/api/v1/charging-accounts/invitations/'.$id.'/accept', [], $driver)->assertUnprocessable();
        $second = $this->withinTenant($this->tenant, $this->owner, fn () => app(ChargingAccountInvitations::class)->invite($this->owner, $this->account, $this->driver->email, ChargingAccountRole::Driver)['id']);
        $this->travel(8)->days();
        $driver = $this->headers($this->driver);
        $this->postJson('/api/v1/charging-accounts/invitations/'.$second.'/accept', [], $driver)->assertUnprocessable();
    }

    public function test_roles_cannot_escalate_and_revocation_takes_effect_with_existing_token(): void
    {
        $this->join($this->driver, ChargingAccountRole::Admin);
        $driver = $this->headers($this->driver, $this->account);
        $owner = $this->headers($this->owner, $this->account);
        $this->getJson('/api/v1/charging-accounts/'.$this->account.'/members', $driver)->assertOk()->assertJsonMissingPath('data.0.birth_date')->assertJsonMissingPath('data.0.email');
        $this->postJson('/api/v1/charging-accounts/'.$this->account.'/invitations', ['email' => 'billing@example.test', 'role' => 'billing_manager'], $driver)->assertForbidden();
        $this->patchJson('/api/v1/charging-accounts/'.$this->account.'/members/'.$this->driver->public_id, ['role' => 'billing_manager'], $driver)->assertForbidden();
        $this->patchJson('/api/v1/charging-accounts/'.$this->account.'/members/'.$this->driver->public_id, ['role' => 'billing_manager'], $owner)->assertOk();
        $this->getJson('/api/v1/wallet', $driver)->assertOk();
        $this->patchJson('/api/v1/charging-accounts/'.$this->account.'/members/'.$this->owner->public_id, ['role' => null], $owner)->assertUnprocessable();
        $this->patchJson('/api/v1/charging-accounts/'.$this->account.'/members/'.$this->driver->public_id, ['role' => null], $owner)->assertOk();
        $this->getJson('/api/v1/wallet', $driver)->assertNotFound();
        $this->getJson('/api/v1/vehicles', $driver)->assertNotFound();
        $this->assertDatabaseCount('memberships', 2);
    }

    public function test_vehicle_assignments_wallet_privacy_and_cross_account_access(): void
    {
        $this->join($this->driver);
        $owner = $this->headers($this->owner, $this->account);
        $driver = $this->headers($this->driver, $this->account);
        $vehicle = (string) Str::ulid();
        $payload = ['nickname' => 'Fleet car', 'plate_pending' => true, 'is_default' => true, 'connector_standards' => ['Type 2']];
        $this->putJson('/api/v1/vehicles/'.$vehicle, $payload, $owner)->assertOk();
        $this->getJson('/api/v1/vehicles', $driver)->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/charging-accounts/'.$this->account.'/vehicles/'.$vehicle.'/assignments', ['subject_id' => $this->driver->public_id, 'assigned' => true], $owner)->assertOk();
        $this->getJson('/api/v1/vehicles', $driver)->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/vehicles/'.$vehicle, $payload, $driver)->assertForbidden();
        $this->getJson('/api/v1/wallet', $driver)->assertForbidden();
        $this->postJson('/api/v1/wallet/topups', ['amount_minor' => 100, 'idempotency_key' => (string) Str::ulid()], $driver)->assertForbidden();
        $other = $this->withinTenant($this->tenant, $this->owner, fn () => app(ChargingAccounts::class)->create($this->owner, 'Other business', 'business'));
        $this->getJson('/api/v1/vehicles', array_replace($driver, ['X-Charging-Account-ID' => (string) $other->getKey()]))->assertNotFound();
        $this->putJson('/api/v1/charging-accounts/'.$this->account.'/vehicles/'.Str::ulid().'/assignments', ['subject_id' => $this->driver->public_id, 'assigned' => true], $owner)->assertNotFound();
        $this->getJson('/api/v1/vehicles', array_replace($owner, ['X-Charging-Account-ID' => 'invalid']))->assertUnprocessable();
    }

    public function test_shared_qr_funding_is_separate_idempotent_and_keeps_original_payer(): void
    {
        $this->join($this->driver, ChargingAccountRole::BillingManager);
        $billing = $this->headers($this->driver, $this->account);
        $payload = ['amount_minor' => 500, 'idempotency_key' => (string) Str::ulid()];
        $id = $this->postJson('/api/v1/wallet/topups', $payload, $billing)->assertOk()->json('data.id');
        $this->postJson('/api/v1/wallet/topups', $payload, $billing)->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/wallet/topups', $payload, ['Authorization' => $billing['Authorization']])->assertConflict();
        $this->withinTenant($this->tenant, $this->owner, function () use ($id): void {
            app(QrTopups::class)->simulate($id);
            app(QrTopups::class)->simulate($id);
            self::assertSame(500, app(PrepaidWallet::class)->summary($this->owner->public_id, 'simulated', $this->account)['balance_minor']);
            self::assertSame(0, app(PrepaidWallet::class)->summary($this->driver->public_id, 'simulated')['balance_minor']);
            self::assertSame($this->driver->public_id, QrTopup::query()->whereKey($id)->firstOrFail()->subject_id);
        });
        $this->getJson('/api/v1/wallet/topups/'.$id, ['Authorization' => $billing['Authorization']])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_per_session_caps_reject_failed_and_repeated_reservations_without_spending_personal_funds(): void
    {
        $this->join($this->driver);
        $this->withinTenant($this->tenant, $this->owner, function (): void {
            $wallet = app(PrepaidWallet::class);
            $wallet->credit($this->owner->public_id, 'live', (string) Str::ulid(), 1000, $this->account);
            $wallet->credit($this->driver->public_id, 'live', (string) Str::ulid(), 1000);
            $wallet->setLimit($this->owner->public_id, $this->account, 'driver', $this->driver->public_id, 50);
            $ref = (string) Str::ulid();
            try {
                $wallet->reserve($this->driver->public_id, 'live', $ref, 100, $this->account);
                self::fail('Limit bypassed');
            } catch (ValidationException) {
            }
            $wallet->reserve($this->driver->public_id, 'live', $ref, 50, $this->account);
            $wallet->reserve($this->driver->public_id, 'live', $ref, 50, $this->account);
            self::assertSame(50, $wallet->summary($this->owner->public_id, 'live', $this->account)['reserved_minor']);
            $wallet->settle($this->driver->public_id, 'live', $ref, 25, $this->account);
            $wallet->settle($this->driver->public_id, 'live', $ref, 25, $this->account);
            self::assertSame(975, $wallet->summary($this->owner->public_id, 'live', $this->account)['available_minor']);
            self::assertSame(1000, $wallet->summary($this->driver->public_id, 'live')['available_minor']);
        });
        $driver = $this->headers($this->driver, $this->account);
        $this->putJson('/api/v1/charging-accounts/'.$this->account.'/limits', ['target_type' => 'driver', 'target_id' => $this->driver->public_id, 'amount_minor' => 999], $driver)->assertForbidden();
    }

    public function test_signed_payment_callback_credits_shared_wallet_after_funder_revocation_once(): void
    {
        $this->join($this->driver, ChargingAccountRole::BillingManager);
        $id = (string) Str::ulid();
        $secret = bin2hex(random_bytes(32));
        config(['wallet.aub.signing_key' => $secret, 'wallet.aub.tenant_id' => $this->tenant->getKey(), 'wallet.aub.approved' => true]);
        $this->withinTenant($this->tenant, $this->owner, function () use ($id): void {
            QrTopup::query()->create(['id' => $id, 'subject_id' => $this->driver->public_id, 'charging_account_id' => $this->account,
                'book' => 'live', 'merchant_id' => 'merchant-test', 'amount_minor' => 500, 'idempotency_key' => (string) Str::ulid()]);
            app(ChargingAccounts::class)->member($this->owner, $this->account, $this->driver->public_id, null);
        });
        $xml = app(AubQrProtocol::class)->encode(['status' => '0', 'result_code' => '0', 'pay_result' => '0', 'mch_id' => 'merchant-test',
            'out_trade_no' => $id, 'total_fee' => '500', 'fee_type' => 'PHP', 'trade_type' => 'pay.instapay.native.v2',
            'transaction_id' => 'shared-verified-payment', 'nonce_str' => 'test'], $secret);
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/webhooks/aub/qrph', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml)->assertOk();
        }
        $this->withinTenant($this->tenant, $this->owner, function (): void {
            self::assertSame(500, app(PrepaidWallet::class)->summary($this->owner->public_id, 'live', $this->account)['balance_minor']);
            self::assertSame(0, app(PrepaidWallet::class)->summary($this->driver->public_id, 'live')['balance_minor']);
        });
        $this->assertDatabaseCount('prepaid_entries', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        self::assertSame(500, (int) DB::table('ledger_entries')->sum('credit_minor'));
        Http::assertNothingSent();
    }

    public function test_cross_tenant_account_and_invitation_ids_cannot_be_used(): void
    {
        $other = $this->createTenant('other-shared');
        $this->withinTenant($other, $this->driver, fn () => $this->createMembership($other, $this->driver));
        $id = $this->withinTenant($this->tenant, $this->owner, fn () => app(ChargingAccountInvitations::class)->invite($this->owner, $this->account, $this->driver->email, ChargingAccountRole::Driver)['id']);
        $headers = $this->headers($this->driver, $this->account, $other);
        $this->getJson('/api/v1/wallet', $headers)->assertNotFound();
        $this->getJson('/api/v1/charging-accounts/'.$this->account.'/members', $headers)->assertNotFound();
        $this->postJson('/api/v1/charging-accounts/invitations/'.$id.'/accept', [], $headers)->assertNotFound();
    }

    public function test_legacy_invitation_acceptance_cannot_bypass_verified_account_acceptance(): void
    {
        $this->withinTenant($this->tenant, $this->owner, function (): void {
            $invitation = app(ChargingAccountInvitations::class)->invite($this->owner, $this->account, $this->driver->email, ChargingAccountRole::Driver);
            $token = Str::random(64);
            UserInvitation::query()->whereKey($invitation['id'])->update(['token_hash' => hash('sha256', $token)]);
            $this->expectException(ValidationException::class);
            app(InvitationService::class)->accept($token, null, null, (string) Str::ulid());
        });
    }
}
