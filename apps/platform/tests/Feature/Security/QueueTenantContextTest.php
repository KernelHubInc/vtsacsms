<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\Queue\InvalidTenantJobContext;
use App\Modules\Tenancy\Application\Queue\TenantAwareJob;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use App\Modules\Tenancy\Application\Queue\UseTenantContext;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Support\Str;
use Tests\Support\TenantSecurityTestCase;

final class QueueTenantContextTest extends TenantSecurityTestCase
{
    public function test_worker_establishes_and_clears_context_between_tenant_jobs(): void
    {
        $actor = $this->createUser();
        $tenantA = $this->createTenant('queue-a');
        $tenantB = $this->createTenant('queue-b');
        $this->withinTenant($tenantA, $actor, function () use ($actor, $tenantA): void {
            $this->createMembership($tenantA, $actor);
            Organization::query()->create(['name' => 'A only', 'code' => 'SHARED']);
        });
        $this->withinTenant($tenantB, $actor, function () use ($actor, $tenantB): void {
            $this->createMembership($tenantB, $actor);
            Organization::query()->create(['name' => 'B only', 'code' => 'SHARED']);
        });
        $middleware = app(UseTenantContext::class);

        $nameA = $middleware->handle(
            $this->jobFor($tenantA->getKey(), $actor->public_id),
            fn (): string => (string) Organization::query()->sole()->name,
        );
        $this->assertSame('A only', $nameA);
        $this->assertFalse(app(CurrentTenant::class)->has());

        $nameB = $middleware->handle(
            $this->jobFor($tenantB->getKey(), $actor->public_id),
            fn (): string => (string) Organization::query()->sole()->name,
        );
        $this->assertSame('B only', $nameB);
        $this->assertFalse(app(CurrentTenant::class)->has());
    }

    public function test_suspended_tenant_job_is_rejected_without_leaving_context(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('queue-suspended', TenantStatus::Suspended);

        try {
            app(UseTenantContext::class)->handle(
                $this->jobFor($tenant->getKey(), $actor->public_id),
                fn (): null => null,
            );
            $this->fail('Suspended tenant job was not rejected.');
        } catch (InvalidTenantJobContext) {
            $this->assertFalse(app(CurrentTenant::class)->has());
        }
    }

    public function test_actor_membership_cannot_be_replayed_against_another_tenant(): void
    {
        $actor = $this->createUser();
        $authorizedTenant = $this->createTenant('queue-authorized');
        $forgedTenant = $this->createTenant('queue-forged');
        $this->withinTenant(
            $authorizedTenant,
            $actor,
            fn () => $this->createMembership($authorizedTenant, $actor),
        );

        $this->expectException(InvalidTenantJobContext::class);

        app(UseTenantContext::class)->handle(
            $this->jobFor($forgedTenant->getKey(), $actor->public_id),
            fn (): null => null,
        );
    }

    private function jobFor(string $tenantId, string $actorId): FakeTenantAwareJob
    {
        return new FakeTenantAwareJob(new TenantJobEnvelope(
            jobId: (string) Str::ulid(),
            schemaVersion: 1,
            tenantId: $tenantId,
            actorType: ActorType::Human,
            actorId: $actorId,
            correlationId: (string) Str::ulid(),
        ));
    }
}

final readonly class FakeTenantAwareJob implements TenantAwareJob
{
    public function __construct(private TenantJobEnvelope $envelope) {}

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }
}
