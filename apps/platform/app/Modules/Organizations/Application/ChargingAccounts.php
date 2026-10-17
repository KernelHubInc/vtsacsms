<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Organizations\Domain\ChargingAccountRole;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Organizations' customer-account contract; tenant/workforce membership is preserved. */
final readonly class ChargingAccounts
{
    public function __construct(private AuthorizationService $authorization, private CurrentTenant $tenant, private AuditRecorder $audit) {}

    public function hasActiveMembership(User $user): bool
    {
        return Membership::query()->where('user_id', $user->getKey())->first()?->isActive() ?? false;
    }

    public function personal(User $user): Organization
    {
        return DB::transaction(function () use ($user): Organization {
            $member = Membership::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($member->isActive() && $user->isEnabled(), 403);
            $account = Organization::query()->where('personal_subject_id', $user->public_id)->first();
            if ($account !== null) {
                return $account;
            }

            return $this->create($user, 'Personal', 'personal');
        });
    }

    public function create(User $user, string $name, string $type): Organization
    {
        abort_unless(in_array($type, ['personal', 'business', 'fleet'], true), 422);

        return DB::transaction(function () use ($user, $name, $type): Organization {
            $member = Membership::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($member->isActive() && $user->isEnabled(), 403);
            if ($type === 'personal') {
                $existing = Organization::query()->where('personal_subject_id', $user->public_id)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $id = (string) Str::ulid();
            $account = Organization::query()->create(['id' => $id, 'code' => 'CUSTOMER-'.$id, 'name' => $name,
                'type' => $type, 'charging_account_type' => $type, 'owner_subject_id' => $user->public_id,
                'personal_subject_id' => $type === 'personal' ? $user->public_id : null, 'is_active' => true]);
            $this->assign($user, $account, ChargingAccountRole::Owner, $user);
            $this->record('created', $account);

            return $account;
        });
    }

    /** @return list<array<string, mixed>> */
    public function list(User $user): array
    {
        $this->personal($user);
        $grants = $this->authorization->chargingAccountPermissions($user);
        $roles = $this->assignments($user)->with('role')->get()->keyBy('scope_id');

        return array_values(Organization::query()->whereNotNull('charging_account_type')->where('is_active', true)
            ->whereIn('id', $this->assignments($user)->select('scope_id'))->orderBy('id')->get()
            ->map(fn (Organization $account): array => ['id' => (string) $account->getKey(), 'name' => $account->name,
                'type' => $account->charging_account_type, 'role' => str_replace('charging_account_', '', (string) $roles->get($account->getKey())?->role->key),
                'permissions' => $grants[$account->getKey()] ?? []])->all());
    }

    public function require(User $user, string $id, PermissionKey $permission = PermissionKey::ChargingAccountView, bool $lock = false): Organization
    {
        $query = Organization::query()->whereNotNull('charging_account_type')->where('is_active', true)->whereKey($id);
        $account = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($this->authorization->allowsChargingAccount($user, PermissionKey::ChargingAccountView, $id), 404);
        abort_unless($this->authorization->allowsChargingAccount($user, $permission, $id), 403);
        abort_if($account->charging_account_type === 'personal' && $account->owner_subject_id !== $user->public_id, 404);

        return $account;
    }

    public function selected(User $user, ?string $id, PermissionKey $permission = PermissionKey::ChargingAccountView): ?Organization
    {
        if ($id === null) {
            return null;
        }
        abort_unless(Str::isUlid($id), 422, 'Invalid charging account selector.');

        return $this->require($user, $id, $permission);
    }

    /** @return array<string, mixed> */
    public function present(User $user, Organization $account): array
    {
        $assignment = $this->assignments($user)->where('scope_id', $account->getKey())->with('role')->first();

        return ['id' => (string) $account->getKey(), 'name' => $account->name, 'type' => $account->charging_account_type,
            'role' => str_replace('charging_account_', '', (string) $assignment?->role->key),
            'permissions' => $this->authorization->chargingAccountPermissions($user)[$account->getKey()] ?? []];
    }

    /** @return list<array<string, mixed>> */
    public function members(User $user, string $id): array
    {
        $account = $this->require($user, $id);
        abort_unless($this->can($user, $id, PermissionKey::ChargingMembersManage) || $this->can($user, $id, PermissionKey::ChargingVehiclesManage)
            || $account->owner_subject_id === $user->public_id, 403);

        return array_values(RoleAssignment::query()->where('scope_type', ScopeType::Organization)->where('scope_id', $id)
            ->whereHas('role', fn (Builder $q) => $q->where('key', 'like', 'charging_account_%'))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->with(['membership.user', 'role'])->get()->filter(fn (RoleAssignment $a): bool => $a->membership->isActive())
            ->map(fn (RoleAssignment $a): array => ['subject_id' => $a->membership->user->public_id,
                'name' => $a->membership->user->name, 'role' => str_replace('charging_account_', '', $a->role->key)])
            ->all());
    }

    public function can(User $user, string $id, PermissionKey $permission): bool
    {
        return $this->authorization->allowsChargingAccount($user, $permission, $id);
    }

    public function member(User $actor, string $id, string $subject, ?ChargingAccountRole $role): void
    {
        DB::transaction(function () use ($actor, $id, $subject, $role): void {
            $account = $this->require($actor, $id, PermissionKey::ChargingMembersManage, true);
            abort_unless($account->owner_subject_id === $actor->public_id && $account->charging_account_type !== 'personal', 403);
            abort_if($subject === $account->owner_subject_id || $role === ChargingAccountRole::Owner, 422, 'The account owner cannot be replaced or revoked here.');
            $user = User::query()->where('public_id', $subject)->firstOrFail();
            $this->require($user, $id);
            $this->assignments($user)->where('scope_id', $id)->update(['expires_at' => now('UTC')]);
            if ($role !== null) {
                $this->assign($user, $account, $role, $actor);
            }
            $this->record($role === null ? 'member_revoked' : 'member_role_changed', $account, ['subject_id' => $subject, 'role' => $role?->value]);
        });
    }

    /** Trusted invitation acceptance/bootstrap only; callers validate account authority. */
    public function join(User $user, User $inviter, string $id, ChargingAccountRole $role): void
    {
        $account = $this->require($inviter, $id, PermissionKey::ChargingMembersManage, true);
        abort_if($account->charging_account_type === 'personal' || $role === ChargingAccountRole::Owner, 403);
        abort_unless($account->owner_subject_id === $inviter->public_id || in_array($role, [ChargingAccountRole::Driver, ChargingAccountRole::FleetManager], true), 403);
        abort_if($this->assignments($user)->where('scope_id', $id)->exists(), 409, 'Already a member.');
        $this->assign($user, $account, $role, $inviter);
        $this->record('member_joined', $account, ['subject_id' => $user->public_id, 'role' => $role->value]);
    }

    public function role(ChargingAccountRole $role): Role
    {
        app(PermissionCatalog::class)->sync();
        $record = Role::query()->firstOrCreate(['key' => 'charging_account_'.$role->value], ['name' => 'Charging account '.Str::headline($role->value), 'is_system' => true]);
        foreach ($role->permissions() as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['tenant_id' => $this->tenant->get()->tenantId,
                'role_id' => $record->getKey(), 'permission_key' => $permission->value, 'created_at' => now('UTC')]);
        }

        return $record;
    }

    private function assign(User $user, Organization $account, ChargingAccountRole $role, User $grantor): void
    {
        $member = Membership::query()->where('user_id', $user->getKey())->firstOrFail();
        abort_unless($member->isActive() && $user->isEnabled(), 403);
        RoleAssignment::query()->create(['membership_id' => $member->getKey(), 'role_id' => $this->role($role)->getKey(),
            'scope_type' => ScopeType::Organization, 'scope_id' => $account->getKey(), 'granted_by_user_id' => $grantor->getKey()]);
    }

    /** @return Builder<RoleAssignment> */
    private function assignments(User $user): Builder
    {
        return RoleAssignment::query()->where('scope_type', ScopeType::Organization)
            ->whereIn('membership_id', Membership::query()->where('user_id', $user->getKey())->select('id'))
            ->whereHas('role', fn (Builder $q) => $q->where('key', 'like', 'charging_account_%'))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')));
    }

    /** @param array<string, mixed> $changes */
    private function record(string $action, Organization $account, array $changes = []): void
    {
        $this->audit->record(new AuditEntry('organizations.charging_account.'.$action, 'organization', (string) $account->getKey(), AuditResult::Succeeded, changes: $changes));
    }
}
