<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class AdministratorSupportAccess
{
    private const ROLE_KEYS = ['platform-admin', 'platform-super-admin', 'production-administrator', 'production-super-administrator'];

    public function __construct(private CurrentTenant $tenant, private AuditRecorder $audit) {}

    /** @return list<array{role:string,needed:bool,changed:bool}> */
    public function repair(bool $apply = false, ?string $approvalReference = null): array
    {
        $context = $this->tenant->get();
        if ($context->actorType !== ActorType::Platform || $context->actorId !== null || ! app()->runningInConsole()) {
            throw new AuthorizationException('Administrator support repair is a deployment operation.');
        }
        if ($apply) {
            Validator::make(['approval' => $approvalReference], ['approval' => ['required', 'string', 'max:180']])->validate();
        }

        return DB::transaction(function () use ($context, $apply, $approvalReference): array {
            $tenant = Tenant::query()->whereKey($context->tenantId)->lockForUpdate()->firstOrFail();
            if (! $tenant->isActive()) {
                throw ValidationException::withMessages(['tenant' => 'Support repair requires an active tenant.']);
            }
            $roles = Role::query()->whereIn('key', self::ROLE_KEYS)->orderBy('key')->lockForUpdate()->get();
            if ($roles->isEmpty()) {
                throw ValidationException::withMessages(['roles' => 'No recognized administrator roles exist in this tenant.']);
            }
            $results = [];
            foreach ($roles as $role) {
                $permissions = DB::table('role_permissions')->where('tenant_id', $context->tenantId)->where('role_id', $role->getKey());
                $before = $permissions->orderBy('permission_key')->pluck('permission_key')->all();
                if (array_diff([PermissionKey::PlatformPanelAccess->value, PermissionKey::SupportView->value], $before) !== []) {
                    throw ValidationException::withMessages(['roles' => 'Review the existing administration and support-view grants before repairing '.$role->key.'.']);
                }
                $needed = ! in_array(PermissionKey::SupportManage->value, $before, true);
                if ($apply && $needed) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'tenant_id' => $context->tenantId, 'role_id' => $role->getKey(),
                        'permission_key' => PermissionKey::SupportManage->value, 'created_at' => now('UTC'),
                    ]);
                    $this->audit->record(new AuditEntry(
                        'identity.role.support_management_enabled', 'role', (string) $role->getKey(), AuditResult::Succeeded,
                        reason: $approvalReference, before: ['permissions' => $before],
                        after: ['permissions' => [...$before, PermissionKey::SupportManage->value]],
                    ));
                }
                $results[] = ['role' => (string) $role->key, 'needed' => $needed, 'changed' => $apply && $needed];
            }

            return $results;
        });
    }
}
