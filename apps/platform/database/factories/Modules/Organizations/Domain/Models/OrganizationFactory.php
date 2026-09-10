<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Organizations\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\OrganizationType;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'name' => fake()->company(),
            'code' => Str::upper(fake()->unique()->bothify('ORG-####')),
            'type' => OrganizationType::ChargePointOperator,
            'is_active' => true,
        ];
    }
}
