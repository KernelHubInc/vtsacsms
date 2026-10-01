<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Filament\Platform\Pages\KycVerifications;
use App\Models\User;
use App\Modules\Identity\Application\Kyc\KycEligibility;
use App\Modules\Identity\Application\Kyc\KycException;
use App\Modules\Identity\Application\Kyc\KycService;
use App\Modules\Identity\Application\MobileTokenService;
use App\Modules\Identity\Domain\KycStatus;
use App\Modules\Identity\Domain\Models\KycVerification;
use App\Modules\Identity\Infrastructure\Kyc\KycSignature;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;

final class KycTest extends TenantSecurityTestCase
{
    private Tenant $tenant;

    private User $driver;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    private bool $serviceUnavailable = false;

    private bool $invalidChallenge = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config()->set('kyc.assurance_profile', 'issuer_v1');
        config()->set(['kyc.enabled' => true, 'kyc.request_secret' => str_repeat('a', 40), 'kyc.callback_secret' => str_repeat('b', 40)]);
        $this->tenant = $this->createTenant('kyc');
        $this->driver = $this->createUser();
        $this->withinTenant($this->tenant, $this->driver, fn () => $this->createMembership($this->tenant, $this->driver));
        $this->login($this->driver, $this->tenant);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($this->serviceUnavailable) {
                return Http::response(['secret' => 'DO_NOT_EXPOSE'], 500);
            }
            $data = $request->data();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $timestamp = $request->header('X-KYC-Timestamp')[0];
            $nonce = $request->header('X-KYC-Nonce')[0];
            $this->assertSame(KycSignature::sign(str_repeat('a', 40), $request->method(), $path, $timestamp, $nonce, $request->body()), $request->header('X-KYC-Signature')[0]);
            if ($request->method() === 'PUT') {
                $this->snapshot = [
                    'id' => basename($path), 'tenant_id' => $data['tenant_id'], 'subject_id' => $data['subject_id'],
                    'status' => 'PENDING_UPLOAD', 'version' => 1, 'evidence' => [], 'evidence_deleted' => false, 'result' => null,
                ];
            }
            if (str_ends_with($path, '/submit')) {
                $this->snapshot['status'] = 'SUBMITTED';
                $this->snapshot['version'] = 3;
            }
            if (str_ends_with($path, '/details')) {
                return Http::response(['snapshot' => $this->snapshot, 'personal' => ['full_name' => 'SYNTHETIC PRIVATE NAME', 'document_number' => 'TEST-123456'], 'extracted' => []]);
            }
            if (str_ends_with($path, '/image')) {
                return Http::response(['mime' => 'image/jpeg', 'content' => base64_encode('synthetic image bytes')]);
            }
            if (str_contains($path, '/live/')) {
                return Http::response([
                    'token' => str_repeat('c', 64), 'action' => 'center', 'step' => 0,
                    'total_steps' => 9, 'complete' => $this->invalidChallenge,
                    'feedback' => 'follow_prompt', 'expires_at' => now()->addMinutes(2)->toIso8601String(),
                ]);
            }

            return Http::response($this->snapshot);
        });
    }

    public function test_start_consent_idempotency_status_and_no_raw_identity_in_platform(): void
    {
        $this->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.status', 'NOT_STARTED');
        $data = $this->payload();
        $first = $this->postJson('/api/v1/kyc/start', $data)->assertCreated()->assertJsonPath('data.status', 'PENDING_UPLOAD');
        $this->postJson('/api/v1/kyc/start', $data)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('kyc_verifications', 1);
        $this->assertStringNotContainsString('SYNTHETIC', json_encode(DB::table('kyc_verifications')->first(), JSON_THROW_ON_ERROR));
        $data['personal']['full_name'] = 'Different synthetic subject';
        $this->postJson('/api/v1/kyc/start', $data)->assertConflict();
        $this->postJson('/api/v1/kyc/start', [...$this->payload(), 'consent' => false])->assertUnprocessable();
    }

    public function test_other_user_and_other_tenant_cannot_read_upload_or_submit(): void
    {
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $other = $this->createUser();
        $this->withinTenant($this->tenant, $other, fn () => $this->createMembership($this->tenant, $other));
        $this->login($other, $this->tenant);
        $this->getJson('/api/v1/kyc/verification/'.$id)->assertNotFound();
        $this->postJson('/api/v1/kyc/verification/'.$id.'/submit')->assertNotFound();
        $this->postJson('/api/v1/kyc/verification/'.$id.'/live/start')->assertNotFound();
        $second = $this->createTenant('other-kyc');
        $this->withinTenant($second, $this->driver, fn () => $this->createMembership($second, $this->driver));
        $this->login($this->driver, $second);
        $this->getJson('/api/v1/kyc/verification/'.$id)->assertNotFound();
        $this->postJson('/api/v1/kyc/verification/'.$id.'/live/start')->assertNotFound();
    }

    public function test_optical_attempt_snapshots_profile_and_uses_owned_signed_live_requests(): void
    {
        config()->set('kyc.assurance_profile', 'optical_v1');
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()
            ->assertJsonPath('data.live_capture_required', true)->json('data.id');
        config()->set('kyc.assurance_profile', 'issuer_v1');
        $path = '/api/v1/kyc/verification/'.$id;
        $this->getJson($path)->assertOk()->assertJsonPath('data.assurance_profile', 'optical_v1');
        $this->postJson($path.'/selfie', ['kind' => 'selfie', 'image' => UploadedFile::fake()->image('selfie.jpg')])->assertConflict();
        $this->postJson($path.'/live/start')->assertOk()->assertJsonPath('data.action', 'center');
        $this->postJson($path.'/live/frame', ['token' => str_repeat('c', 64), 'image' => UploadedFile::fake()->image('live.jpg'), 'tenant_id' => 'forged'])->assertOk();
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/live/frame') && $request['tenant_id'] === $this->tenant->id && $request['subject_id'] === $this->driver->public_id && $request['token'] === str_repeat('c', 64));
        $this->postJson($path.'/live/frame', ['token' => 'bad', 'image' => UploadedFile::fake()->image('live.jpg')])->assertUnprocessable();
        $this->postJson($path.'/live/frame', ['token' => str_repeat('c', 64), 'image' => UploadedFile::fake()->create('video.mp4', 1, 'video/mp4')])->assertUnprocessable();
    }

    public function test_live_challenge_with_inconsistent_completion_is_rejected(): void
    {
        config()->set('kyc.assurance_profile', 'optical_v1');
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $this->invalidChallenge = true;
        $this->postJson('/api/v1/kyc/verification/'.$id.'/live/start')->assertServiceUnavailable();
    }

    public function test_callback_replay_duplicates_out_of_order_and_manual_decision(): void
    {
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/kyc/verification/'.$id.'/submit')->assertAccepted();
        $this->snapshot['status'] = 'NEEDS_REVIEW';
        $this->snapshot['version'] = 5;
        $event = $this->event($id);
        $this->sendCallback($event)->assertOk();
        $this->sendCallback($event)->assertOk();
        $this->assertSame(1, DB::table('kyc_events')->where('event_id', $event['event_id'])->count());
        $this->snapshot['status'] = 'PROCESSING';
        $this->snapshot['version'] = 4;
        $this->sendCallback($this->event($id))->assertOk();
        $this->assertDatabaseHas('kyc_verifications', ['id' => $id, 'status' => 'NEEDS_REVIEW']);
        $admin = $this->createUser();
        $this->withinTenant($this->tenant, $admin, function () use ($admin): void {
            $membership = $this->createMembership($this->tenant, $admin);
            $role = $this->createRole($this->tenant, 'kyc-reviewer', [PermissionKey::KycView, PermissionKey::KycReview]);
            $this->assignDirectly($this->tenant, $membership, $role);
        });
        $this->withinTenant($this->tenant, $admin, function () use ($admin, $id): void {
            app(KycService::class)->review($admin, KycVerification::query()->findOrFail((string) $id), KycStatus::Approved, 'Synthetic evidence reviewed');
        });
        $this->snapshot['status'] = 'REJECTED';
        $this->snapshot['version'] = 6;
        $this->sendCallback($this->event($id))->assertOk();
        $this->assertDatabaseHas('kyc_verifications', ['id' => $id, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('audit_events', ['target_id' => $id, 'action' => 'identity.kyc.updated']);
    }

    public function test_callback_signature_timestamp_and_foreign_scope_fail_closed(): void
    {
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/webhooks/kyc', $this->event($id))->assertUnauthorized();
        $event = $this->event($id);
        $event['data']['subject_id'] = (string) Str::ulid();
        $this->sendCallback($event)->assertServiceUnavailable();
        $this->sendCallback($this->event($id), now()->subMinutes(10)->getTimestamp())->assertUnauthorized();
        $nonce = str_repeat('c', 32);
        $this->sendCallback($this->event($id), null, $nonce)->assertOk();
        $this->sendCallback($this->event($id), null, $nonce)->assertUnauthorized();
    }

    public function test_service_failure_is_safe_and_retryable(): void
    {
        $this->serviceUnavailable = true;
        $this->postJson('/api/v1/kyc/start', $this->payload())->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'KYC_SERVICE_UNAVAILABLE')->assertDontSee('DO_NOT_EXPOSE');
        $this->assertDatabaseCount('kyc_verifications', 1);
    }

    public function test_invalid_upload_and_disabled_flag(): void
    {
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $this->post('/api/v1/kyc/verification/'.$id.'/document', ['kind' => 'front', 'image' => UploadedFile::fake()->create('fake.jpg', 20, 'text/plain')], ['Accept' => 'application/json'])->assertUnprocessable();
        config()->set('kyc.enabled', false);
        $this->postJson('/api/v1/kyc/start', $this->payload())->assertNotFound();
        $this->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_review_permission_and_feature_gating(): void
    {
        $id = $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $this->withinTenant($this->tenant, $this->driver, function () use ($id): void {
            $eligibility = app(KycEligibility::class);
            $eligibility->assertAllowed($this->driver->public_id, 'charging');
            config()->set('kyc.required_for_charging', true);
            try {
                $eligibility->assertAllowed($this->driver->public_id, 'charging');
                $this->fail('Unverified charging must be denied');
            } catch (KycException $exception) {
                $this->assertSame('KYC_REQUIRED', $exception->errorCode);
            }
            $this->expectException(HttpException::class);
            app(KycService::class)->review($this->driver, KycVerification::query()->findOrFail((string) $id), KycStatus::Approved, 'Unauthorized review attempt');
        });
    }

    public function test_admin_page_limits_sensitive_access_and_audits_private_preview(): void
    {
        $id = (string) $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $admin = $this->createUser();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withinTenant($this->tenant, $admin, function () use ($admin, $id): void {
            $membership = $this->createMembership($this->tenant, $admin);
            $role = $this->createRole($this->tenant, 'kyc-metadata', [PermissionKey::KycView]);
            $this->assignDirectly($this->tenant, $membership, $role);
            Livewire::test(KycVerifications::class)->call('selectVerification', $id)
                ->assertSee('PENDING_UPLOAD')->assertDontSee('SYNTHETIC PRIVATE NAME');
            Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/details'));
            $sensitive = $this->createRole($this->tenant, 'kyc-evidence', [PermissionKey::KycSensitiveView]);
            $this->assignDirectly($this->tenant, $membership, $sensitive);
            Livewire::test(KycVerifications::class)->call('selectVerification', $id)
                ->set('evidenceKind', 'front')->assertSee('SYNTHETIC PRIVATE NAME')
                ->assertSee('****3456')->assertDontSee('TEST-123456');
            $this->assertDatabaseHas('audit_events', ['target_id' => $id, 'action' => 'identity.kyc.image_viewed']);
        });
    }

    public function test_failed_processing_resubmission_and_expiration(): void
    {
        $id = (string) $this->postJson('/api/v1/kyc/start', $this->payload())->assertCreated()->json('data.id');
        $this->snapshot['status'] = 'ACTION_REQUIRED';
        $this->snapshot['version'] = 5;
        $this->snapshot['result'] = ['provider' => 'mock', 'reason_code' => 'PROCESSING_FAILED', 'checks' => [], 'duration_ms' => 1];
        $this->sendCallback($this->event($id))->assertOk();
        $next = (string) $this->postJson('/api/v1/kyc/resubmit', $this->payload())->assertCreated()->json('data.id');
        $this->assertNotSame($id, $next);
        $this->withinTenant($this->tenant, $this->driver, fn () => KycVerification::query()->whereKey($next)->update(['review_mode' => 'automatic']));
        config()->set('kyc.automatic_verification_enabled', true);
        $this->snapshot['status'] = 'APPROVED';
        $this->snapshot['version'] = 5;
        $this->snapshot['result'] = ['provider' => 'test-assurance', 'reason_code' => 'PROVIDER_VERIFIED', 'checks' => ['ocr' => 'passed', 'document' => 'passed', 'face_match' => 'passed', 'liveness' => 'passed'], 'duration_ms' => 10];
        $this->sendCallback($this->event($next))->assertOk();
        $this->travel((int) config('kyc.validity_days') + 1)->days();
        $this->login($this->driver, $this->tenant);
        $this->getJson('/api/v1/kyc/status')->assertOk()->assertJsonPath('data.status', 'EXPIRED');
    }

    public function test_rate_limit_response_keeps_retry_after_and_safe_error(): void
    {
        for ($request = 0; $request < 30; $request++) {
            $this->getJson('/api/v1/kyc/status')->assertOk();
        }
        $this->getJson('/api/v1/kyc/status')->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    private function login(User $user, Tenant $tenant): void
    {
        $token = $this->withinTenant($tenant, $user, fn () => app(MobileTokenService::class)->issue($user, 'KYC test', []));
        $this->flushHeaders();
        $this->withToken($token->plainTextToken);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['idempotency_key' => (string) Str::ulid(), 'consent' => true, 'consent_version' => 'development-v1', 'document_type' => 'passport', 'personal' => ['full_name' => 'SYNTHETIC TEST PERSON', 'birth_date' => '1990-01-01', 'document_number' => 'TEST-0000', 'expiration_date' => '2035-01-01', 'nationality' => 'PH', 'issuing_country' => 'PH']];
    }

    /** @return array<string, mixed> */
    private function event(string $id): array
    {
        return ['event_id' => (string) Str::ulid(), 'event_type' => 'identity.kyc.processed.v1', 'schema_version' => 1, 'occurred_at' => now()->toIso8601String(), 'tenant_id' => $this->tenant->getKey(), 'aggregate_type' => 'kyc_verification', 'aggregate_id' => $id, 'correlation_id' => (string) Str::ulid(), 'causation_id' => $id, 'data' => $this->snapshot];
    }

    /** @param array<string, mixed> $event
     * @return TestResponse<Response>
     */
    private function sendCallback(array $event, ?int $timestamp = null, ?string $nonce = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($timestamp ?? now()->timestamp);
        $nonce ??= bin2hex(random_bytes(16));

        return $this->call('POST', '/api/v1/webhooks/kyc', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_KYC_TIMESTAMP' => $timestamp, 'HTTP_X_KYC_NONCE' => $nonce,
            'HTTP_X_KYC_SIGNATURE' => KycSignature::sign(str_repeat('b', 40), 'POST', '/api/v1/webhooks/kyc', $timestamp, $nonce, $body),
        ], $body);
    }
}
