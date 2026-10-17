<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Identity\Application\MobileTokenService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\TenantSecurityTestCase;

final class DriverPasswordRecoveryTest extends TenantSecurityTestCase
{
    public function test_recovery_accepts_email_capitalization_consistently_with_login(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => strtoupper($user->email)])->assertAccepted();
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => strtoupper($user->email), 'token' => $token,
            'password' => 'Driver-new-password-2026!', 'password_confirmation' => 'Driver-new-password-2026!'])
            ->assertOk()->assertSee('Password updated');
    }

    public function test_password_reset_revokes_existing_browser_sessions(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        foreach (['recovery-one', 'recovery-two'] as $slug) {
            $tenant = $this->createTenant($slug);
            $this->withinTenant($tenant, $user, function () use ($tenant, $user, $other): void {
                $this->createMembership($tenant, $user);
                app(MobileTokenService::class)->issue($user, 'Previous login', []);
                app(MobileTokenService::class)->issue($other, 'Other user', []);
            });
        }
        DB::table('sessions')->insert([
            'id' => 'old-browser-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'Driver-new-password-2026!', 'password_confirmation' => 'Driver-new-password-2026!'])
            ->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $other->id)->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'identity.password.reset')->count());
    }

    public function test_browser_form_tolerates_malformed_email_query(): void
    {
        $this->get('/reset-password/'.str_repeat('a', 64).'?email[]=invalid')
            ->assertOk()->assertSee('Choose a new password');
    }

    public function test_reset_email_opens_a_private_browser_form_and_password_can_be_changed_once(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $url = $notification->toMail($user)->actionUrl;
        $this->get($url)->assertOk()->assertSee('Choose a new password')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');

        $fields = ['email' => $user->email, 'token' => $notification->token,
            'password' => 'Driver-new-password-2026!', 'password_confirmation' => 'Driver-new-password-2026!'];
        $this->post('/reset-password', $fields)->assertOk()->assertSee('Password updated');
        self::assertTrue(Hash::check($fields['password'], $user->fresh()->password));
        $this->post('/reset-password', $fields)->assertStatus(422)->assertSee('invalid or expired');
    }

    public function test_unknown_email_has_the_same_recovery_response_and_expired_tokens_fail(): void
    {
        Notification::fake();
        $user = $this->createUser();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])->assertAccepted()->json();
        self::assertSame($known, $unknown);
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'Driver-new-password-2026!', 'password_confirmation' => 'Driver-new-password-2026!'])
            ->assertStatus(422)->assertSee('invalid or expired');
    }
}
