<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Organizations\Domain\Models;

use App\Modules\Organizations\Domain\Models\Role;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Role> */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'key' => Str::slug($name),
            'name' => $name,
            'is_system' => false,
        ];
    }
}
