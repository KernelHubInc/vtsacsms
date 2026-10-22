<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

final class Login extends FilamentLogin
{
    protected string $view = 'filament.auth.login';

    protected function getEmailFormComponent(): Component
    {
        $field = parent::getEmailFormComponent();

        if ($field instanceof TextInput) {
            $field->label('Email address')->placeholder('Enter your email address');
        }

        return $field;
    }

    protected function getPasswordFormComponent(): Component
    {
        $field = parent::getPasswordFormComponent();

        if ($field instanceof TextInput) {
            $field->placeholder('Enter your password')->revealable();
        }

        return $field;
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response !== null) {
            // Only consecutive failures should consume the brute-force budget.
            $this->clearRateLimiter('authenticate');
        }

        return $response;
    }
}
