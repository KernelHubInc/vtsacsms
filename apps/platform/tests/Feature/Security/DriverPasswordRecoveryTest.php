<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\TenantSecurityTestCase;

final class DriverPasswordRecoveryTest extends TenantSecurityTestCase
{
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
