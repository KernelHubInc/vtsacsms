<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Support\TenantSecurityTestCase;

final class EmailVerificationTest extends TenantSecurityTestCase
{
    public function test_email_link_verifies_without_an_app_token_and_retries_are_idempotent(): void
    {
        [$user, $tenant] = $this->subject();
        $url = (new VerifyEmailNotification)->toMail($user)->actionUrl;

        $this->get($url, ['Accept' => 'text/html'])->assertOk()->assertSee('Email verified');
        $verifiedAt = $user->fresh()?->email_verified_at;
        $this->assertNotNull($verifiedAt);
        $this->getJson($url)->assertOk()->assertJsonPath('data.email_verified', true);
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'identity.email.verified')
            ->where('tenant_id', $tenant->getKey())->where('target_id', $user->public_id)->count());
    }

    public function test_tampered_and_expired_links_cannot_verify_an_account(): void
    {
        [$user] = $this->subject();
        $url = $this->link($user);
        $this->get($url.'tampered', ['Accept' => 'text/html'])->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url, ['Accept' => 'text/html'])->assertForbidden();
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_a_link_cannot_be_used_for_another_tenants_user(): void
    {
        [$user] = $this->subject();
        [$other] = $this->subject('other');
        $url = str_replace((string) $user->public_id, (string) $other->public_id, $this->link($user));
        $this->getJson($url)->assertForbidden();
        $this->assertNull($other->fresh()?->email_verified_at);
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_changed_email_invalidates_previously_signed_link(): void
    {
        [$user] = $this->subject();
        $url = $this->link($user);
        $user->forceFill(['email' => 'changed@example.test'])->save();
        $this->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'invalid_verification_link');
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_suspended_membership_cannot_verify(): void
    {
        [$user, $tenant] = $this->subject();
        DB::table('memberships')->where('tenant_id', $tenant->getKey())->update(['status' => 'suspended']);
        $this->getJson($this->link($user))->assertForbidden();
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_disabled_user_cannot_verify(): void
    {
        [$user] = $this->subject();
        $user->forceFill(['disabled_at' => now('UTC')])->save();
        $this->getJson($this->link($user))->assertForbidden();
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_https_link_works_behind_the_configured_staging_proxy(): void
    {
        config()->set('trustedproxy.proxies', '127.0.0.1');
        [$user] = $this->subject();
        URL::forceRootUrl('https://staging.example.test');
        URL::forceScheme('https');
        $url = $this->link($user);
        $internalUrl = str_replace('https://staging.example.test', 'http://platform:8000', $url);

        $this->get($internalUrl, [
            'Accept' => 'text/html', 'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'staging.example.test', 'X-Forwarded-Port' => '443',
        ])->assertOk()->assertSee('Email verified');
        $this->assertNotNull($user->fresh()?->email_verified_at);
    }

    /** @return array{User, Tenant} */
    private function subject(string $slug = 'verification'): array
    {
        $this->freezeTime();
        $user = $this->createUser();
        $user->forceFill(['email_verified_at' => null])->save();
        $tenant = $this->createTenant($slug);
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));

        return [$user, $tenant];
    }

    private function link(User $user): string
    {
        return URL::temporarySignedRoute('api.v1.auth.email.verify', now('UTC')->addHour(), [
            'user' => $user->public_id, 'hash' => sha1($user->email),
        ]);
    }
}
