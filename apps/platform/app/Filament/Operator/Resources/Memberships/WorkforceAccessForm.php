<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Memberships;

use App\Models\User;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\PermissionCatalog;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Organizations\Domain\WorkforceRolePreset;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

final readonly class WorkforceAccessForm
{
    public function __construct(private AuthorizationService $authorization, private AccessibleSitesQuery $sites) {}

    /** @return list<Select|Section|Text> */
    public function fields(User $actor, PermissionKey $authority, ?Membership $member = null): array
    {
        return [
            ...($member === null ? [] : [Text::make(fn (): string => $this->currentGrants($actor, $member))]),
            Select::make('coverage')->label('Where should this access apply?')
                ->options(fn (): array => $this->coverageOptions($actor, $authority, $member))
                ->required()->searchable()->live()
                ->afterStateUpdated(fn (Set $set) => $set('role_id', null)),
            Select::make('role_id')->label('Choose a role')
                ->options(fn (Get $get): array => $this->roleOptions($actor, $this->scope($get('coverage')), $authority))
                ->disabled(fn (Get $get): bool => $this->scope($get('coverage')) === null)
                ->required()->searchable()->live(),
            Section::make('Review access')->compact()->schema(fn (Get $get): array => $this->preview($actor, $authority, $get, $member)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ResourceScope, Role}
     */
    public function selection(User $actor, array $data, PermissionKey $authority, ?Membership $member = null): array
    {
        $coverage = $data['coverage'] ?? null;
        $options = $this->coverageOptions($actor, $authority, $member);
        $scope = $this->scope($coverage);
        if ($scope === null || ! is_string($coverage) || ! collect($options)->contains(fn (array $items): bool => isset($items[$coverage]))) {
            throw ValidationException::withMessages(['coverage' => 'Choose an operator or site you can manage.']);
        }
        $roleId = $data['role_id'] ?? null;
        $roles = $this->roleOptions($actor, $scope, $authority);
        if (! is_string($roleId) || ! collect($roles)->contains(fn (array $items): bool => isset($items[$roleId]))) {
            throw ValidationException::withMessages(['role_id' => 'Choose a role you can grant at this scope.']);
        }

        return [$scope, Role::query()->findOrFail($roleId)];
    }

    public function organization(ResourceScope $scope): ?Organization
    {
        $id = match ($scope->type) {
            ScopeType::Organization => $scope->id,
            ScopeType::Site => Site::query()->whereKey($scope->id)->value('operator_organization_id'),
            default => null,
        };

        return $id === null ? null : Organization::query()->where('is_active', true)->whereKey($id)->firstOrFail();
    }

    /** @return array<string, string> */
    public function removableRoles(User $actor, Membership $member): array
    {
        $options = [];
        foreach ($member->assignments()->with('role')->get() as $assignment) {
            $scope = new ResourceScope($assignment->scope_type, $assignment->scope_id);
            if (! $this->authorization->allows($actor, PermissionKey::RoleAssign, $scope)) {
                continue;
            }
            $label = match ($scope->type) {
                ScopeType::Tenant => 'Entire tenant',
                ScopeType::Organization => Organization::query()->whereKey($scope->id)->value('name') ?? 'Organization',
                ScopeType::Site => Site::query()->whereKey($scope->id)->value('name') ?? 'Site',
                default => $scope->type->value,
            };
            $options[(string) $assignment->getKey()] = $assignment->role->name.' · '.$label;
        }

        return $options;
    }

    /** @return array<string, array<string, string>> */
    private function coverageOptions(User $actor, PermissionKey $authority, ?Membership $member): array
    {
        $operatorId = $member?->organization?->type === OrganizationType::ChargePointOperator ? $member->organization_id : null;
        $groups = [];
        if (Filament::getCurrentPanel()?->getId() === 'admin' && $this->authorization->allows($actor, $authority)) {
            $groups['Tenant'] = ['tenant' => 'Entire tenant'];
        }
        $operators = Organization::query()->where('is_active', true)->where('type', OrganizationType::ChargePointOperator->value)
            ->when($operatorId !== null, fn ($query) => $query->whereKey($operatorId))->orderBy('name')->get(['id', 'name']);
        foreach ($operators as $operator) {
            if ($this->authorization->allows($actor, $authority, new ResourceScope(ScopeType::Organization, (string) $operator->getKey()))) {
                $groups['All sites for an operator']['organization:'.$operator->getKey()] = (string) $operator->name;
            }
        }
        $sites = $this->sites->for($actor, $authority, includeHostedSites: false)->where('is_active', true)
            ->whereIn('operator_organization_id', Organization::query()->where('is_active', true)->where('type', OrganizationType::ChargePointOperator->value)->select('id'))
            ->when($operatorId !== null, fn ($query) => $query->where('operator_organization_id', $operatorId))->orderBy('name')->get(['id', 'name']);
        foreach ($sites as $site) {
            $groups['One site']['site:'.$site->getKey()] = (string) $site->name;
        }

        return $groups;
    }

    /** @return array<string, array<string, string>> */
    private function roleOptions(User $actor, ?ResourceScope $scope, PermissionKey $authority): array
    {
        if ($scope === null) {
            return [];
        }
        $held = $this->authorization->effectivePermissions($actor, $scope);
        if (! in_array($authority->value, $held, true)) {
            return [];
        }
        $groups = ['Predefined roles' => [], 'Other roles' => []];
        foreach (Role::query()->with('permissions')->orderBy('name')->get() as $role) {
            $keys = $role->permissions->pluck('key')->all();
            if (array_diff($keys, $held) !== [] || str_starts_with((string) $role->key, 'charging_account_')
                || (Filament::getCurrentPanel()?->getId() === 'operator' && in_array(PermissionKey::PlatformPanelAccess->value, $keys, true))) {
                continue;
            }
            $preset = $role->is_system ? WorkforceRolePreset::tryFrom((string) $role->key) : null;
            if ($preset !== null && ! $preset->supportsScope($scope->type)) {
                continue;
            }
            $label = $preset?->label() ?? (string) $role->name;
            $groups[$preset !== null ? 'Predefined roles' : 'Other roles'][(string) $role->getKey()] = $label;
        }

        return array_filter($groups);
    }

    private function scope(mixed $coverage): ?ResourceScope
    {
        if ($coverage === 'tenant') {
            return ResourceScope::tenant();
        }
        if (! is_string($coverage) || preg_match('/^(organization|site):([0-9A-HJKMNP-TV-Z]{26})$/iD', $coverage, $parts) !== 1) {
            return null;
        }

        return new ResourceScope(ScopeType::from(strtolower($parts[1])), $parts[2]);
    }

    private function currentGrants(User $actor, Membership $member): string
    {
        $names = $member->assignments()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->with('role')->get()->filter(fn (RoleAssignment $assignment): bool => $this->authorization->allows($actor, PermissionKey::MembershipView, new ResourceScope($assignment->scope_type, $assignment->scope_id)))
            ->pluck('role.name')->unique()->all();

        return $names === [] ? 'No active role assignments in your scope.' : 'Current roles in your scope: '.implode(', ', $names).'.';
    }

    /** @return list<Text> */
    private function preview(User $actor, PermissionKey $authority, Get $get, ?Membership $member): array
    {
        $scope = $this->scope($get('coverage'));
        $roleId = $get('role_id');
        if ($scope === null || ! is_string($roleId)
            || ! collect($this->roleOptions($actor, $scope, $authority))->contains(fn (array $items): bool => isset($items[$roleId]))) {
            return [Text::make('Choose a scope and role to review access before saving.')];
        }
        $role = Role::query()->with('permissions')->findOrFail($roleId);
        $keys = $role->permissions->pluck('key')->all();
        if ($member !== null && $this->authorization->allows($actor, PermissionKey::MembershipView, $scope)) {
            $keys = array_unique([...$keys, ...$this->authorization->effectivePermissions($member->user, $scope)]);
        }
        $preset = $role->is_system ? WorkforceRolePreset::tryFrom((string) $role->key) : null;
        $summary = [Text::make(($preset?->description() ?? (string) $role->name).($member === null ? '' : ' Existing roles still apply.'))];
        foreach (app(PermissionCatalog::class)->modules() as $module) {
            $labels = array_intersect_key($module['permissions'], array_fill_keys($keys, true));
            if ($labels !== []) {
                $summary[] = Text::make($module['label'].': '.implode(', ', $labels));
            }
        }

        return $summary;
    }
}
