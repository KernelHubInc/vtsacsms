<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Identity\Notifications\ChargingAccountInvitationNotification;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\ChargingAccountRole;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ChargingAccountInvitations
{
    public function __construct(private ChargingAccounts $accounts, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public function invite(User $actor, string $id, string $email, ChargingAccountRole $role): array
    {
        $row = DB::transaction(function () use ($actor, $id, $email, $role): UserInvitation {
            $account = $this->accounts->require($actor, $id, PermissionKey::ChargingMembersManage, true);
            abort_if($account->charging_account_type === 'personal' || $role === ChargingAccountRole::Owner, 403);
            abort_unless($account->owner_subject_id === $actor->public_id || in_array($role, [ChargingAccountRole::Driver, ChargingAccountRole::FleetManager], true), 403);
            $email = Str::lower(trim($email));
            $recipient = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($recipient === null || ! $recipient->isEnabled() || ! $this->accounts->hasActiveMembership($recipient)) {
                throw ValidationException::withMessages(['email' => 'Use the email of a registered, active Power Solutions user. Ask them to register first.']);
            }
            if ($this->accounts->can($recipient, $id, PermissionKey::ChargingAccountView)) {
                throw ValidationException::withMessages(['email' => 'This user is already a member. Manage their role in Members.']);
            }
            $existing = UserInvitation::query()->where('purpose', 'charging_account')->where('organization_id', $id)
                ->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->first();
            abort_if($existing !== null, 409, 'A pending invitation already exists.');
            $row = UserInvitation::query()->create(['purpose' => 'charging_account', 'organization_id' => $id,
                'role_id' => $this->accounts->role($role)->getKey(), 'scope_type' => ScopeType::Organization, 'scope_id' => $id,
                'email' => $email, 'token_hash' => hash('sha256', Str::random(64)), 'invited_by_user_id' => $actor->getKey(),
                'expires_at' => now('UTC')->addDays(7)]);
            $this->record($row, 'created');

            return $row;
        });
        // Delivery failure does not revoke a durable invitation; the verified recipient can find it in-app.
        try {
            Notification::route('mail', $row->email)->notify(new ChargingAccountInvitationNotification($row->organization->name));
        } catch (\Throwable) {
            $this->record($row, 'delivery_failed');
        }

        return $this->present($row);
    }

    /** @return list<array<string, mixed>> */
    public function pending(User $actor, ?string $accountId = null): array
    {
        $query = UserInvitation::query()->where('purpose', 'charging_account')->with(['organization', 'role']);
        if ($accountId !== null) {
            $this->accounts->require($actor, $accountId, PermissionKey::ChargingMembersManage);
            $query->where('organization_id', $accountId);
        } else {
            abort_unless($actor->hasVerifiedEmail(), 403);
            $query->where('email', Str::lower($actor->email));
        }

        return array_values($query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))
            ->orderByDesc('id')->get()->map(fn (UserInvitation $i): array => $this->present($i))->all());
    }

    public function accept(User $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $pending = UserInvitation::query()->where('purpose', 'charging_account')->whereKey($id)->firstOrFail();
            abort_unless($actor->hasVerifiedEmail() && hash_equals(Str::lower($actor->email), $pending->email), 404);
            $inviter = $pending->invitedBy;
            abort_if($inviter === null, 403);
            // Match invite/revoke/start lock order: account before invitation or wallet.
            $this->accounts->require($inviter, (string) $pending->organization_id, PermissionKey::ChargingMembersManage, true);
            $row = UserInvitation::query()->where('purpose', 'charging_account')->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(Str::lower($actor->email), $row->email), 404);
            abort_unless($row->canBeAccepted(), 422, 'Invitation expired or already used.');
            $role = ChargingAccountRole::tryFrom(str_replace('charging_account_', '', $row->role->key));
            abort_if($role === null, 422);
            $inviter = $row->invitedBy;
            abort_if($inviter === null, 403);
            $account = $this->accounts->require($inviter, (string) $row->organization_id, PermissionKey::ChargingMembersManage);
            abort_unless($account->owner_subject_id === $inviter->public_id || in_array($role, [ChargingAccountRole::Driver, ChargingAccountRole::FleetManager], true), 403);
            $this->accounts->join($actor, $inviter, (string) $row->organization_id, $role);
            $row->forceFill(['accepted_at' => now('UTC')])->save();
            $this->record($row, 'accepted');
        });
    }

    public function revoke(User $actor, string $account, string $id): void
    {
        DB::transaction(function () use ($actor, $account, $id): void {
            $this->accounts->require($actor, $account, PermissionKey::ChargingMembersManage, true);
            $row = UserInvitation::query()->where('purpose', 'charging_account')->where('organization_id', $account)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($row->accepted_at !== null, 422, 'Revoke the membership for an accepted invitation.');
            $row->forceFill(['revoked_at' => $row->revoked_at ?? now('UTC')])->save();
            $this->record($row, 'revoked');
        });
    }

    /** @return array<string, mixed> */
    private function present(UserInvitation $row): array
    {
        return ['id' => (string) $row->getKey(), 'account_id' => $row->organization_id, 'account_name' => $row->organization->name,
            'email' => $row->email, 'role' => str_replace('charging_account_', '', $row->role->key), 'expires_at' => $row->expires_at->toISOString()];
    }

    private function record(UserInvitation $row, string $action): void
    {
        $this->audit->record(new AuditEntry('identity.charging_invitation.'.$action, 'user_invitation', (string) $row->getKey(), AuditResult::Succeeded));
    }
}
