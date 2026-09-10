<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;

final class Login extends FilamentLogin
{
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
