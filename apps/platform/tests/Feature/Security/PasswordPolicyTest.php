<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Requests\Api\AcceptInvitationRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PasswordPolicyTest extends TestCase
{
    #[DataProvider('passwords')]
    public function test_password_policy_is_consistent_across_account_forms(string $password, bool $valid): void
    {
        foreach ([new RegisterRequest, new ResetPasswordRequest, new AcceptInvitationRequest] as $request) {
            $validator = Validator::make(
                ['password' => $password, 'password_confirmation' => $password],
                ['password' => $request->rules()['password']],
            );

            self::assertSame($valid, $validator->passes(), $request::class);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function passwords(): array
    {
        return [
            'exactly eight characters' => ['Abcdef1!', true],
            'long password' => ['Longer-Password-2026!', true],
            'unicode letters and symbol' => ['Äbcdef1€', true],
            'too short' => ['Abcde1!', false],
            'missing uppercase' => ['abcdef1!', false],
            'missing lowercase' => ['ABCDEF1!', false],
            'missing number' => ['Abcdefg!', false],
            'missing symbol' => ['Abcdef12', false],
            'space is not a symbol' => ['Abcdef1 ', false],
        ];
    }

    public function test_confirmation_must_match(): void
    {
        foreach ([new RegisterRequest, new ResetPasswordRequest, new AcceptInvitationRequest] as $request) {
            self::assertTrue(Validator::make(
                ['password' => 'Abcdef1!', 'password_confirmation' => 'Different1!'],
                ['password' => $request->rules()['password']],
            )->fails());
        }
    }
}
