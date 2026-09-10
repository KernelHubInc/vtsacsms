<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Identity\Notifications\UserInvitationNotification;
use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\Models\RoleAssignment;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class InvitationService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuthorizationService $authorization,
        private AuditRecorder $audit,
    ) {}

    public function invite(
        User $actor,
        string $email,
        ?Organization $organization,
        Role $role,
        ResourceScope $scope,
    ): IssuedInvitation {
        if (! $this->authorization->allows($actor, PermissionKey::InvitationManage, $scope)) {
            throw new AuthorizationException('Invitation creation is not permitted.');
        }

        $tenantId = $this->currentTenant->get()->tenantId;

        if (
            $role->tenant_id !== $tenantId
            || ($organization !== null && $organization->tenant_id !== $tenantId)
        ) {
            throw new AuthorizationException('Invitation target is outside the tenant.');
        }

        $this->validateScope($scope);

        $plainTextToken = Str::random(64);
        $invitation = UserInvitation::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization?->getKey(),
            'role_id' => $role->getKey(),
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'email' => Str::lower($email),
            'token_hash' => hash('sha256', $plainTextToken),
            'invited_by_user_id' => $actor->getKey(),
            'expires_at' => now('UTC')->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)->notify(new UserInvitationNotification(
            plainTextToken: $plainTextToken,
            organizationName: $organization instanceof Organization ? $organization->name : 'Power Solutions',
        ));

        $this->audit->record(new AuditEntry(
            action: 'identity.invitation.created',
            targetType: 'user_invitation',
            targetId: (string) $invitation->getKey(),
            result: AuditResult::Succeeded,
            after: [
                'email_hash' => hash('sha256', $invitation->email),
                'organization_id' => $invitation->organization_id,
                'role_id' => $invitation->role_id,
                'scope_type' => $scope->type->value,
                'scope_id' => $scope->id,
                'expires_at' => $invitation->expires_at->utc()->toIso8601String(),
            ],
        ));

        return new IssuedInvitation($invitation, $plainTextToken);
    }

    /** @return array{user: User, tenant_id: string} */
    public function accept(string $plainTextToken, ?string $name, ?string $password, string $correlationId): array
    {
        $invitation = UserInvitation::query()
            ->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($invitation === null || ! $invitation->canBeAccepted()) {
            throw ValidationException::withMessages(['token' => 'The invitation is invalid or expired.']);
        }

        $existing = User::query()->where('email', $invitation->email)->first();

        if ($existing === null && ($name === null || $password === null)) {
            throw ValidationException::withMessages([
                'password' => 'A name and password are required for a new account.',
            ]);
        }

        $actorId = $existing instanceof User ? (string) $existing->public_id : (string) Str::ulid();
        $context = new TenantContext(
            tenantId: $invitation->tenant_id,
            actorType: ActorType::Human,
            actorId: $actorId,
            correlationId: $correlationId,
        );

        return $this->currentTenant->run($context, function () use (
            $invitation,
            $existing,
            $name,
            $password,
            $actorId,
        ): array {
            return DB::transaction(function () use (
                $invitation,
                $existing,
                $name,
                $password,
                $actorId,
            ): array {
                $user = $existing ?? User::query()->create([
                    'public_id' => $actorId,
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => Hash::make((string) $password),
                ]);
                $user->forceFill([
                    'email_verified_at' => $user->email_verified_at ?? now('UTC'),
                    'activated_at' => $user->activated_at ?? now('UTC'),
                    'disabled_at' => null,
                ])->save();

                $membership = Membership::query()->updateOrCreate(
                    ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->getKey()],
                    [
                        'organization_id' => $invitation->organization_id,
                        'status' => MembershipStatus::Active,
                        'joined_at' => now('UTC'),
                        'expires_at' => null,
                    ],
                );
                RoleAssignment::query()->firstOrCreate([
                    'tenant_id' => $invitation->tenant_id,
                    'membership_id' => $membership->getKey(),
                    'role_id' => $invitation->role_id,
                    'scope_type' => $invitation->scope_type,
                    'scope_id' => $invitation->scope_id,
                ], [
                    'granted_by_user_id' => $invitation->invited_by_user_id,
                    'expires_at' => null,
                ]);
                $invitation->forceFill(['accepted_at' => now('UTC')])->save();

                $this->audit->record(new AuditEntry(
                    action: 'identity.invitation.accepted',
                    targetType: 'user_invitation',
                    targetId: (string) $invitation->getKey(),
                    result: AuditResult::Succeeded,
                    before: ['accepted_at' => null],
                    after: [
                        'accepted_at' => $invitation->accepted_at?->utc()->toIso8601String(),
                        'membership_id' => (string) $membership->getKey(),
                    ],
                ));

                return ['user' => $user, 'tenant_id' => $invitation->tenant_id];
            });
        });
    }

    public function revoke(User $actor, UserInvitation $invitation, string $reason): void
    {
        $scope = new ResourceScope($invitation->scope_type, $invitation->scope_id);

        if (! $this->authorization->allows($actor, PermissionKey::InvitationManage, $scope)) {
            throw new AuthorizationException('Invitation revocation is not permitted.');
        }

        $invitation->forceFill(['revoked_at' => now('UTC')])->save();
        $this->audit->record(new AuditEntry(
            action: 'identity.invitation.revoked',
            targetType: 'user_invitation',
            targetId: (string) $invitation->getKey(),
            result: AuditResult::Succeeded,
            reason: $reason,
            before: ['revoked_at' => null],
            after: ['revoked_at' => $invitation->revoked_at?->utc()->toIso8601String()],
        ));
    }

    private function validateScope(ResourceScope $scope): void
    {
        match ($scope->type) {
            ScopeType::Tenant => null,
            ScopeType::Organization => Organization::query()->whereKey($scope->id)->firstOrFail(),
            ScopeType::Site => Site::query()->whereKey($scope->id)->firstOrFail(),
            default => throw new AuthorizationException('Invitation scope is not supported.'),
        };
    }
}
