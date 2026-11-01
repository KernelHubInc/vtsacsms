<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ScopeType;
use Illuminate\Database\Eloquent\Builder;

final readonly class AccessibleMembershipsQuery
{
    public function __construct(private AuthorizationService $authorization) {}

    /** @return Builder<Membership> */
    public function for(User $user): Builder
    {
        $query = Membership::query();
        if (! $user->isEnabled()) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->authorization->allows($user, PermissionKey::MembershipView)) {
            return $query;
        }
        $membership = Membership::query()->where('user_id', $user->getKey())->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))->first();
        if ($membership === null) {
            return $query->whereRaw('1 = 0');
        }
        $grants = $membership->assignments()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
            ->whereHas('role.permissions', fn ($query) => $query->where('key', PermissionKey::MembershipView->value))->get();
        $organizations = Organization::query()->where('is_active', true)->whereIn('id', $grants->where('scope_type', ScopeType::Organization)->pluck('scope_id'))->pluck('id');
        $sites = $grants->where('scope_type', ScopeType::Site)->pluck('scope_id');

        return $query->where(function (Builder $query) use ($organizations, $sites): void {
            $query->whereIn('organization_id', $organizations)->orWhereHas('assignments', fn ($query) => $query->where('scope_type', ScopeType::Site)->whereIn('scope_id', $sites));
        });
    }
}
