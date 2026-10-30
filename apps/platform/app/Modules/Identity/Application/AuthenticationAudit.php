<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class AuthenticationAudit
{
    public function record(?User $user, ?string $tenantId, string $channel, AuditResult $result): void
    {
        $correlation = request()->attributes->get('correlation_id') ?? (string) Str::ulid();
        // Resolve membership before attributing a failed claim to a tenant. Never log credentials or an entered email.
        if ($user === null || $tenantId === null || ! DB::table('memberships')
            ->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->exists()) {
            Log::notice('Unattributed authentication attempt', ['channel' => $channel, 'result' => $result->value, 'correlation_id' => $correlation]);

            return;
        }
        $current = app(CurrentTenant::class);
        $previous = $current->getOrNull();
        $current->clear();
        try {
            $current->run(new TenantContext($tenantId, ActorType::Human,
                $result === AuditResult::Succeeded ? $user->public_id : null, $correlation),
                fn () => app(AuditRecorder::class)->record(new AuditEntry(
                    'identity.login.'.($result === AuditResult::Succeeded ? 'succeeded' : 'failed'),
                    'identity', $user->public_id, $result, metadata: ['channel' => $channel],
                )));
        } finally {
            if ($previous !== null) {
                $current->establish($previous);
            }
        }
    }
}
