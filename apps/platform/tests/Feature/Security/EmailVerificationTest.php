<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TenantSecurityTestCase;

final class EmailVerificationTest extends TenantSecurityTestCase
{
    public function test_signed_browser_link_is_idempotent_and_audits_only_the_subjects_tenants(): void
    {
        $this->withoutVite();
        $this->freezeTime();
        $user = $this->unverifiedUser();
        $tenant = $this->createTenant('verification-first');
        $second = $this->createTenant('verification-second');
        $unrelated = $this->createTenant('verification-unrelated');
        $other = $this->unverifiedUser();

        foreach ([$tenant, $second] as $scope) {
            $this->withinTenant($scope, $user, fn () => $this->createMembership($scope, $user));
        }
        $this->withinTenant($unrelated, $other, fn () => $this->createMembership($unrelated, $other));
        $url = $this->link($user);

        $this->get($url, ['Accept' => 'text/html'])->assertOk()->assertSee('Email verified');
        $this->getJson($url)->assertOk()->assertJsonPath('data.email_verified', true);

        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertNull($other->fresh()?->email_verified_at);
        $this->assertDatabaseCount('audit_events', 2);
        foreach ([$tenant, $second] as $scope) {
            $this->assertDatabaseHas('audit_events', [
                'tenant_id' => $scope->getKey(),
                'actor_id' => $user->public_id,
                'target_id' => $user->public_id,
                'action' => 'identity.email.verified',
            ]);
        }
        $this->assertFalse(app(CurrentTenant::class)->has());
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_links_cannot_verify_an_account(string $scenario): void
    {
        $this->freezeTime();
        $user = $this->unverifiedUser();
        $tenant = $this->createTenant('verification-invalid');
        $membership = $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $url = $this->link($user);

        match ($scenario) {
            'unsigned' => $url = strtok($url, '?'),
            'tampered user' => $url = str_replace((string) $user->public_id, '01J00000000000000000000001', $url),
            'expired' => $this->travel(11)->minutes(),
            'changed email' => $user->forceFill(['email' => 'changed@example.test'])->save(),
            'disabled user' => $user->forceFill(['disabled_at' => now('UTC')])->save(),
            'suspended membership' => DB::table('memberships')->where('id', $membership->getKey())->update(['status' => 'suspended']),
            'expired membership' => DB::table('memberships')->where('id', $membership->getKey())->update(['expires_at' => now('UTC')->subMinute()]),
            'suspended tenant' => $tenant->forceFill(['status' => 'suspended'])->save(),
            default => throw new \InvalidArgumentException('Unknown verification test scenario.'),
        };

        $this->getJson((string) $url)->assertForbidden();
        $this->assertNull($user->fresh()?->email_verified_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    /** @return array<string, array{string}> */
    public static function invalidLinks(): array
    {
        $scenarios = ['unsigned', 'tampered user', 'expired', 'changed email', 'disabled user',
            'suspended membership', 'expired membership', 'suspended tenant'];

        return array_combine($scenarios, array_map(static fn (string $scenario): array => [$scenario], $scenarios));
    }

    private function unverifiedUser(): User
    {
        $user = $this->createUser();
        $user->forceFill(['email_verified_at' => null])->save();

        return $user;
    }

    private function link(User $user): string
    {
        return URL::temporarySignedRoute('api.v1.auth.email.verify', now('UTC')->addMinutes(10), [
            'user' => $user->public_id,
            'hash' => sha1($user->email),
        ]);
    }
}
