<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Organizations\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\MembershipStatus;
use App\Modules\Organizations\Domain\Models\Membership;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Membership> */
final class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
            'joined_at' => now('UTC'),
        ];
    }
}
