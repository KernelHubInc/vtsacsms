<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

use App\Foundation\Audit\Models\AuditEvent;
use App\Models\User;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Read contract shared by the administrative log and Reporting exports. */
final class AuditLogQuery
{
    /** @return array<string, string> */
    public static function categories(): array
    {
        return ['identity' => 'Sign-ins and identity', 'assets' => 'Vehicles and equipment',
            'billing' => 'Wallet and billing', 'payments' => 'Payments', 'charging' => 'Charging',
            'organizations' => 'Accounts and permissions', 'locations' => 'Locations',
            'tariffs' => 'Tariffs', 'settlements' => 'Settlements', 'maintenance' => 'Maintenance',
            'reporting' => 'Exports', 'notifications' => 'Notifications', 'support' => 'Support',
            'procurement' => 'Procurement', 'inventory' => 'Inventory', 'cms' => 'Website',
            'integrations' => 'Integrations', 'tenancy' => 'Tenancy'];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public static function validate(array $filters): array
    {
        return Validator::make(array_filter($filters, fn ($v) => $v !== null && $v !== ''), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...(empty($filters['from']) ? [] : ['after:from'])],
            'category' => ['sometimes', Rule::in(array_keys(self::categories()))],
            'result' => ['sometimes', Rule::in(['succeeded', 'failed', 'denied'])],
            'actor_type' => ['sometimes', Rule::in(['human', 'service', 'platform'])],
            'actor_id' => ['sometimes', 'ulid'],
            'target_id' => ['sometimes', 'ulid'],
            'correlation_id' => ['sometimes', 'ulid'],
            'action' => ['sometimes', 'string', 'max:160', 'regex:/^[a-zA-Z0-9_.-]+$/'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditEvent>
     */
    public function for(User $user, array $filters = []): Builder
    {
        abort_unless(app(AuthorizationService::class)->allows($user, PermissionKey::AuditView), 403);
        $filters = self::validate($filters);
        $query = AuditEvent::query()->where('tenant_id', app(CurrentTenant::class)->get()->tenantId);
        foreach (['result', 'actor_type', 'actor_id', 'target_id', 'correlation_id', 'action'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['category'])) {
            $query->where('action', 'like', $filters['category'].'.%');
        }
        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from'].' 00:00:00');
        }
        if (isset($filters['to'])) {
            $query->where('occurred_at', '<', $filters['to'].' 00:00:00');
        }

        return $query;
    }

    public static function details(AuditEvent $event): string
    {
        // Explicit projection: never export arbitrary historical payloads or secret-bearing fields.
        $safe = array_intersect_key($event->metadata, array_flip([
            'channel', 'wallet_id', 'reference_id', 'charging_account_id', 'book', 'currency',
            'amount_minor', 'balance_minor', 'reserved_minor', 'operation', 'changed_fields',
            'from_state', 'to_state', 'reason_code',
        ]));

        return json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
