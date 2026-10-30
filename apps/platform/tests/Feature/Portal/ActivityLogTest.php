<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Filament\Auth\Login;
use App\Filament\Platform\Pages\Reports;
use App\Filament\Platform\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Foundation\Audit\AuditChainVerifier;
use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditLogQuery;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Foundation\Audit\Models\AuditEvent;
use App\Modules\Assets\Application\DriverVehicles;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Charging\Application\StateTransitionRecorder;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Organizations\Domain\ResourceScope;
use App\Modules\Organizations\Domain\ScopeType;
use App\Modules\Reporting\Application\PortalExcelReport;
use App\Modules\Reporting\Application\PortalExportRowsQuery;
use App\Modules\Reporting\Application\PortalExportService;
use App\Modules\Reporting\Application\PortalPdfReport;
use App\Modules\Reporting\Application\PortalReportAccess;
use App\Modules\Reporting\Domain\Models\PortalExport;
use App\Modules\Reporting\Jobs\GeneratePortalExport;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TenantSecurityTestCase;
use ZipArchive;

final class ActivityLogTest extends TenantSecurityTestCase
{
    public function test_admin_filters_and_exports_match_without_disclosing_other_tenants_or_payload_secrets(): void
    {
        Queue::fake();
        Storage::fake('local');
        config()->set('filesystems.report_disk', 'local');
        $this->travelTo(now('UTC')->setDate(2026, 10, 28)->startOfDay());
        $tenant = $this->createTenant('audit-admin');
        $other = $this->createTenant('audit-other');
        $admin = $this->createUser();
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        $this->withinTenant($other, $admin, fn () => app(AuditRecorder::class)->record(new AuditEntry('billing.prepaid.topup', 'prepaid_entry', (string) Str::ulid(), AuditResult::Succeeded)));
        $this->withinTenant($tenant, $admin, function () use ($tenant, $admin): void {
            Organization::factory()->create(['type' => 'platform']);
            $assignment = $this->assignDirectly($tenant, $this->createMembership($tenant, $admin), $this->createRole($tenant, 'audit-reader', [
                PermissionKey::AuditView, PermissionKey::AuditExport, PermissionKey::ReportingView, PermissionKey::ReportingExport, PermissionKey::PlatformPanelAccess,
            ]));
            $event = app(AuditRecorder::class)->record(new AuditEntry('billing.prepaid.topup', 'prepaid_entry', (string) Str::ulid(), AuditResult::Succeeded,
                metadata: ['currency' => 'PHP', 'amount_minor' => 50000, 'password' => 'must-not-export'], before: ['token' => 'must-not-export']));
            $otherEvent = app(AuditRecorder::class)->record(new AuditEntry('assets.driver_vehicle.created', 'driver_vehicle', (string) Str::ulid(), AuditResult::Succeeded));
            $filters = ['from' => '2026-10-27', 'to' => '2026-10-29', 'category' => 'billing', 'result' => 'succeeded'];
            self::assertSame([$event->id], app(AuditLogQuery::class)->for($admin, $filters)->pluck('id')->all());
            Livewire::test(ListAuditEvents::class)->assertCanSeeTableRecords([$event, $otherEvent])
                ->filterTable('activity', $filters)->assertCanSeeTableRecords([$event])->assertCanNotSeeTableRecords([$otherEvent]);
            $this->travel(2)->seconds();
            Livewire::test(ListAuditEvents::class)->filterTable('activity', $filters)
                ->callAction('exportActivity', data: $filters + ['format' => 'xlsx'])->assertHasNoActionErrors();
            $export = PortalExport::query()->sole();
            self::assertSame('billing', $export->filters['category']);
            Queue::assertPushed(GeneratePortalExport::class, function (GeneratePortalExport $job): bool {
                $job->handle(app(PortalExportRowsQuery::class), app(PortalReportAccess::class));

                return true;
            });
            $export->refresh();
            self::assertSame(1, $export->row_count);
            $xml = $this->sheet(Storage::disk('local')->get($export->path));
            self::assertStringContainsString('50000', $xml);
            self::assertStringNotContainsString('must-not-export', $xml);
            self::assertStringContainsString($event->id, $xml);
            $response = (new Reports)->downloadExport((string) $export->getKey());
            self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
            self::assertSame(1, AuditEvent::query()->where('action', 'reporting.portal_export.downloaded')->count());
            $report = app(PortalExportRowsQuery::class)->for('audit', $admin, $filters);
            $pdf = app(PortalPdfReport::class)->render('audit', $report, $filters);
            self::assertStringStartsWith('%PDF-', $pdf['bytes']);
            self::assertSame(1, $pdf['count']);
            $assignment->delete();
            self::assertFalse(app(PortalReportAccess::class)->canExport($admin, 'audit'));
            try {
                (new Reports)->downloadExport((string) $export->getKey());
                self::fail('Revoked export remained downloadable');
            } catch (HttpException $e) {
                self::assertSame(403, $e->getStatusCode());
            }
        });
    }

    public function test_scoped_permissions_cannot_read_tenant_audit(): void
    {
        $tenant = $this->createTenant('audit-site');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'site-auditor', [
                PermissionKey::AuditView, PermissionKey::AuditExport, PermissionKey::ReportingView, PermissionKey::ReportingExport,
            ]), new ResourceScope(ScopeType::Site, (string) Str::ulid()));
            self::assertFalse(app(PortalReportAccess::class)->canExport($user, 'audit'));
            $this->expectException(HttpException::class);
            app(AuditLogQuery::class)->for($user);
        });
    }

    public function test_audit_view_and_reporting_permissions_do_not_imply_audit_export_permission(): void
    {
        $tenant = $this->createTenant('audit-view-only');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'reader', [
                PermissionKey::AuditView, PermissionKey::ReportingView, PermissionKey::ReportingExport,
            ]));
            self::assertSame(0, app(AuditLogQuery::class)->for($user)->count());
            self::assertFalse(app(PortalReportAccess::class)->canExport($user, 'audit'));
            $this->expectException(\DomainException::class);
            app(PortalExportService::class)->queue($user, 'audit', [], 'xlsx');
        });
    }

    public function test_vehicle_and_wallet_audits_are_specific_and_do_not_duplicate_financial_effects(): void
    {
        $tenant = $this->createTenant('audit-effects');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            $id = (string) Str::ulid();
            $vehicles = app(DriverVehicles::class);
            $vehicles->save($user->public_id, $id, ['nickname' => 'Private nickname', 'plate_number' => 'SECRET123']);
            $vehicles->save($user->public_id, $id, ['nickname' => 'Changed nickname', 'plate_number' => 'SECRET123']);
            $vehicles->remove($user->public_id, $id);
            foreach (['created', 'updated', 'removed'] as $action) {
                self::assertSame(1, AuditEvent::query()->where('action', 'assets.driver_vehicle.'.$action)->count());
            }
            $wallet = app(PrepaidWallet::class);
            $ref = (string) Str::ulid();
            $session = (string) Str::ulid();
            $wallet->credit($user->public_id, 'simulated', $ref, 50000);
            $wallet->credit($user->public_id, 'simulated', $ref, 50000);
            $wallet->reserve($user->public_id, 'simulated', $session, 40000);
            $wallet->settle($user->public_id, 'simulated', $session, 30000);
            $wallet->settle($user->public_id, 'simulated', $session, 30000);
            self::assertSame(4, AuditEvent::query()->where('action', 'like', 'billing.prepaid.%')->count());
            $event = AuditEvent::query()->where('action', 'billing.prepaid.spent')->sole();
            self::assertSame(20000, $event->metadata['balance_minor']);
            self::assertSame(30000, $event->metadata['amount_minor']);
            self::assertSame($session, $event->metadata['reference_id']);
            self::assertStringNotContainsString('SECRET123', AuditEvent::all()->toJson());
            self::assertStringNotContainsString('Private nickname', AuditEvent::all()->toJson());
            self::assertTrue(app(AuditChainVerifier::class)->verify((string) $tenant->getKey()));
        });
    }

    public function test_mobile_login_failure_and_success_are_audited_without_credentials(): void
    {
        $tenant = $this->createTenant('audit-login');
        $other = $this->createTenant('audit-spoof');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, fn () => $this->createMembership($tenant, $user));
        $payload = ['email' => $user->email, 'password' => 'wrong-password', 'tenant_id' => $tenant->id, 'device_name' => 'Audit test'];
        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', array_replace($payload, ['tenant_id' => $other->id]))->assertUnauthorized();
        self::assertSame(0, AuditEvent::query()->where('tenant_id', $other->id)->count());
        $this->postJson('/api/v1/auth/login', array_replace($payload, ['password' => 'password']))->assertOk();
        $failed = AuditEvent::query()->where('action', 'identity.login.failed')->sole();
        self::assertNull($failed->actor_id);
        self::assertSame($user->public_id, $failed->target_id);
        self::assertSame($user->public_id, AuditEvent::query()->where('action', 'identity.login.succeeded')->sole()->actor_id);
        self::assertStringNotContainsString('wrong-password', AuditEvent::all()->toJson());
        self::assertStringNotContainsString($user->email, AuditEvent::all()->toJson());
    }

    public function test_browser_login_records_outcomes_after_panel_authorization(): void
    {
        $tenant = $this->createTenant('audit-browser');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function () use ($tenant, $user): void {
            Organization::factory()->create(['type' => 'platform']);
            $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'admin', [PermissionKey::PlatformPanelAccess]));
        });
        Filament::setCurrentPanel('admin');
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong'])->call('authenticate')->assertHasErrors();
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasNoErrors();
        self::assertSame(1, AuditEvent::query()->where('action', 'identity.login.succeeded')->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'identity.login.failed')->count());
    }

    public function test_excel_preserves_identifiers_and_formula_text_as_literal_strings(): void
    {
        $rows = (function () {
            yield ['000123', '=HYPERLINK("https://invalid.test")', '< & >', "bad\x01value"];
        })();
        $result = app(PortalExcelReport::class)->render(['headers' => ['ID', 'Name', 'Text', 'Control'], 'rows' => $rows]);
        $xml = $this->sheet($result['bytes']);
        self::assertStringContainsString('000123', $xml);
        self::assertStringNotContainsString('<f>', $xml);
        self::assertStringContainsString('=HYPERLINK', $xml);
        self::assertStringNotContainsString("\x01", $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function test_charging_state_history_is_also_visible_in_the_activity_log(): void
    {
        $tenant = $this->createTenant('audit-charging');
        $user = $this->createUser();
        $this->withinTenant($tenant, $user, function (): void {
            $id = (string) Str::ulid();
            app(StateTransitionRecorder::class)->record('session', $id, 'charging', 'stopping', 'user_requested_stop', ['private' => 'not-exported']);
            $event = AuditEvent::query()->where('action', 'charging.session.state_changed')->sole();
            self::assertSame($id, $event->target_id);
            self::assertSame('stopping', $event->metadata['to_state']);
            self::assertStringNotContainsString('not-exported', $event->toJson());
        });
    }

    private function sheet(string $bytes): string
    {
        $file = tmpfile();
        fwrite($file, $bytes);
        $zip = new ZipArchive;
        $path = stream_get_meta_data($file)['uri'] ?? null;
        self::assertIsString($path);
        self::assertTrue($zip->open($path));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        fclose($file);
        self::assertIsString($xml);

        return $xml;
    }
}
