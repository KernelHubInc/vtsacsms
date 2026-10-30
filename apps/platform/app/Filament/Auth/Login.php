<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Application\AuthenticationAudit;
use App\Modules\Identity\Application\PanelAccessService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

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
        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            $data = $this->data ?? [];
            $user = User::query()->where('email', mb_strtolower((string) ($data['email'] ?? '')))->first();
            $this->auditLogin($user, AuditResult::Failed);
            throw $exception;
        }

        if ($response !== null) {
            $user = Filament::auth()->user();
            $this->auditLogin($user instanceof User ? $user : null, AuditResult::Succeeded);
            // Only consecutive failures should consume the brute-force budget.
            $this->clearRateLimiter('authenticate');
        }

        return $response;
    }

    private function auditLogin(?User $user, AuditResult $result): void
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? 'unknown';
        $tenantId = $user === null ? null : app(PanelAccessService::class)->tenantIdFor($user, $panel);
        app(AuthenticationAudit::class)->record($user, $tenantId, $panel, $result);
    }
}
