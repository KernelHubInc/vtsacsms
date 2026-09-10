<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\Memberships\Pages;

use App\Filament\Operator\Resources\Memberships\MembershipResource;
use App\Models\User;
use App\Modules\Identity\Application\InvitationService;
use App\Modules\Locations\Application\AccessibleSitesQuery;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Application\RoleAssignmentService;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListMemberships extends ListRecords
{
    protected static string $resource = MembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviteUser')
                ->label('Invite user')
                ->visible(fn (): bool => app(AuthorizationService::class)->holdsAnywhere(
                    $this->actor(),
                    PermissionKey::InvitationManage,
                ))
                ->schema($this->accessSchema(includeEmail: true, includeMembership: false))
                ->action(function (array $data): void {
                    $actor = $this->actor();
                    $role = Role::query()->findOrFail($this->stringValue($data, 'role_id'));
                    $organizationId = $data['organization_id'] ?? null;
                    $organization = is_string($organizationId) && $organizationId !== ''
                        ? Organization::query()->findOrFail($organizationId)
                        : null;
                    app(InvitationService::class)->invite(
                        $actor,
                        $this->stringValue($data, 'email'),
                        $organization,
                        $role,
                        $this->scope($data),
                    );
                    Notification::make()->title('Invitation sent')->success()->send();
                }),
            Action::make('assignRole')
                ->label('Assign role')
                ->visible(fn (): bool => app(AuthorizationService::class)->holdsAnywhere(
                    $this->actor(),
                    PermissionKey::RoleAssign,
                ))
                ->schema($this->accessSchema(includeEmail: false, includeMembership: true))
                ->action(function (array $data): void {
                    app(RoleAssignmentService::class)->assign(
                        $this->actor(),
                        Membership::query()->findOrFail($this->stringValue($data, 'membership_id')),
                        Role::query()->findOrFail($this->stringValue($data, 'role_id')),
                        $this->scope($data),
                        reason: 'portal.role_assignment',
                    );
                    Notification::make()->title('Role assigned')->success()->send();
                }),
        ];
    }

    /** @return list<Select|TextInput> */
    private function accessSchema(bool $includeEmail, bool $includeMembership): array
    {
        $fields = [];
        if ($includeEmail) {
            $fields[] = TextInput::make('email')->email()->required()->maxLength(254);
            $fields[] = Select::make('organization_id')
                ->label('Organization')
                ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable();
        }
        if ($includeMembership) {
            $fields[] = Select::make('membership_id')
                ->label('User membership')
                ->options(fn (): array => Membership::query()
                    ->with('user')
                    ->get()
                    ->mapWithKeys(fn (Membership $membership): array => [
                        (string) $membership->getKey() => $membership->user->name.' · '.$membership->user->email,
                    ])->all())
                ->searchable()
                ->required();
        }
        $fields[] = Select::make('role_id')
            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'id')->all())
            ->required();
        $fields[] = Select::make('scope_type')
            ->options(['tenant' => 'Entire tenant', 'site' => 'One site'])
            ->default('tenant')
            ->required();
        $fields[] = Select::make('site_id')
            ->label('Site (required for site scope)')
            ->options(function (): array {
                $actor = $this->actor();

                return app(AccessibleSitesQuery::class)->for($actor)->orderBy('name')->pluck('name', 'id')->all();
            })
            ->searchable();

        return $fields;
    }

    /** @param array<string, mixed> $data */
    private function scope(array $data): ResourceScope
    {
        if (($data['scope_type'] ?? null) === ScopeType::Tenant->value) {
            return ResourceScope::tenant();
        }

        return new ResourceScope(ScopeType::Site, $this->stringValue($data, 'site_id'));
    }

    /** @param array<string, mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("{$key} is required.");
        }

        return $value;
    }

    private function actor(): User
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new \LogicException('A panel actor is required.');
        }

        return $user;
    }
}
