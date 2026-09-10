<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Foundation\Audit\AuditChainVerifier;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Foundation\Audit\Models\AuditEvent;
use App\Foundation\Audit\TenantAuditReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\TenantSecurityTestCase;

final class AuditCompletenessTest extends TenantSecurityTestCase
{
    public function test_hash_chain_is_complete_and_tenant_reader_cannot_cross_boundaries(): void
    {
        $actor = $this->createUser();
        $tenantA = $this->createTenant('audit-a');
        $tenantB = $this->createTenant('audit-b');

        $this->withinTenant($tenantA, $actor, function (): void {
            app(AuditRecorder::class)->record(new AuditEntry(
                action: 'identity.membership.created',
                targetType: 'membership',
                targetId: null,
                result: AuditResult::Succeeded,
                changes: ['status' => 'active'],
            ));
            app(AuditRecorder::class)->record(new AuditEntry(
                action: 'identity.role_assignment.created',
                targetType: 'role_assignment',
                targetId: null,
                result: AuditResult::Succeeded,
            ));
        });
        $this->withinTenant($tenantB, $actor, fn () => app(AuditRecorder::class)->record(
            new AuditEntry(
                action: 'identity.membership.created',
                targetType: 'membership',
                targetId: null,
                result: AuditResult::Succeeded,
            ),
        ));

        $this->assertTrue(app(AuditChainVerifier::class)->verify($tenantA->getKey()));
        $this->assertTrue(app(AuditChainVerifier::class)->verify($tenantB->getKey()));

        $events = $this->withinTenant(
            $tenantA,
            $actor,
            fn () => app(TenantAuditReader::class)->recent(),
        );
        $this->assertCount(2, $events);
        $this->assertSame([$tenantA->getKey()], $events->pluck('tenant_id')->unique()->values()->all());
    }

    public function test_audit_events_are_immutable_through_eloquent_and_database_queries(): void
    {
        $actor = $this->createUser();
        $tenant = $this->createTenant('audit-immutable');
        $event = $this->withinTenant($tenant, $actor, fn (): AuditEvent => app(AuditRecorder::class)->record(
            new AuditEntry(
                action: 'identity.api_token.issued',
                targetType: 'api_token',
                targetId: null,
                result: AuditResult::Succeeded,
            ),
        ));

        try {
            $event->forceFill(['result' => AuditResult::Failed])->save();
            $this->fail('Audit event mutation was not rejected by the model.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_events', [
                'id' => $event->getKey(),
                'result' => AuditResult::Succeeded->value,
            ]);
        }

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->getKey())->update([
            'result' => AuditResult::Failed->value,
        ]);
    }
}
