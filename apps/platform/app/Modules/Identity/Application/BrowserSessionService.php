<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\Models\AuthSession;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class BrowserSessionService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditRecorder $audit,
    ) {}

    public function track(User $user, Request $request): AuthSession
    {
        $tenantId = $this->currentTenant->get()->tenantId;

        return AuthSession::query()->updateOrCreate(
            ['session_id_hash' => hash('sha256', $tenantId.'|'.$request->session()->getId())],
            [
                'tenant_id' => $tenantId,
                'user_id' => $user->getKey(),
                'device_name' => Str::limit($request->userAgent() ?: 'Browser session', 120, ''),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit($request->userAgent(), 500, ''),
                'last_active_at' => now('UTC'),
            ],
        );
    }

    public function revoke(User $actor, AuthSession $session, string $reason): void
    {
        $session->forceFill(['revoked_at' => now('UTC')])->save();
        $this->audit->record(new AuditEntry(
            action: 'identity.browser_session.revoked',
            targetType: 'auth_session',
            targetId: (string) $session->getKey(),
            result: AuditResult::Succeeded,
            reason: $reason,
            before: ['revoked_at' => null],
            after: ['revoked_at' => $session->revoked_at?->utc()->toIso8601String()],
            metadata: ['actor_user_id' => $actor->public_id],
        ));
    }
}
