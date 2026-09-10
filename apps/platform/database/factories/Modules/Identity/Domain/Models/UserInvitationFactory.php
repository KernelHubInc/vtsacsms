<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\UserInvitation;
use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserInvitation> */
final class UserInvitationFactory extends Factory
{
    protected $model = UserInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'role_id' => Role::factory(),
            'scope_type' => ScopeType::Tenant,
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(64)),
            'invited_by_user_id' => User::factory(),
            'expires_at' => now('UTC')->addDays(7),
        ];
    }
}
