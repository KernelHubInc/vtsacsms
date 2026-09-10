<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Locations\Domain\Models;

use App\Modules\Locations\Domain\Models\Site;
use App\Modules\Locations\Domain\SiteLifecycleStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tenancy\Application\CurrentTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Site> */
final class SiteFactory extends Factory
{
    protected $model = Site::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => app(CurrentTenant::class)->get()->tenantId,
            'operator_organization_id' => Organization::factory(),
            'name' => fake()->streetName().' Charging Site',
            'code' => Str::upper(fake()->unique()->bothify('SITE-####')),
            'is_active' => true,
            'public_slug' => fake()->unique()->slug(3),
            'timezone' => 'Asia/Manila',
            'latitude' => fake()->latitude(5, 20),
            'longitude' => fake()->longitude(116, 127),
            'lifecycle_status' => SiteLifecycleStatus::Draft,
            'is_public' => false,
        ];
    }

    public function activePublic(): static
    {
        return $this->state(fn (): array => [
            'lifecycle_status' => SiteLifecycleStatus::Active,
            'is_public' => true,
            'published_at' => now('UTC'),
        ]);
    }
}
