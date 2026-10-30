<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Filament\Platform\Resources\ChargingReceipts\ChargingReceiptResource;
use App\Filament\Platform\Resources\ChargingReceipts\Pages\ListChargingReceipts;
use App\Modules\Assets\Domain\Models\ChargingCurrentType;
use App\Modules\Assets\Domain\Models\ConnectorStandard;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Notifications\Application\ChargingSummaryDelivery;
use App\Modules\Notifications\Application\ChargingSummaryQueue;
use App\Modules\Notifications\Domain\Models\ChargingEmailDelivery;
use App\Modules\Notifications\Mail\ChargingSummaryMail;
use App\Modules\Organizations\Domain\PermissionKey;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\TenantSecurityTestCase;

final class ChargingReceiptTrackingTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConnectorStandard::query()->create(['code' => 'type2', 'name' => 'Type 2']);
        ChargingCurrentType::query()->create(['code' => 'ac', 'name' => 'AC']);
    }

    public function test_failed_submission_retries_and_records_acceptance_once_without_raw_error_details(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30T02:00:00Z'));
        config(['mail.default' => 'smtp']);
        $user = $this->createUser();
        $tenant = $this->createTenant('receipt-delivery');
        $this->withinTenant($tenant, $user, function () use ($user): void {
            $row = $this->receipt($user->public_id);
            $pending = $this->createMock(PendingMail::class);
            Mail::shouldReceive('to')->with($user->email)->twice()->andReturn($pending);
            $email = (new Email)->from('sender@example.test')->to('driver@example.test')->text('receipt');
            $message = new SentMessage(new SymfonySentMessage($email, Envelope::create($email)));
            $attempt = 0;
            $pending->expects($this->exactly(2))->method('send')->willReturnCallback(function () use (&$attempt, $message): SentMessage {
                if (++$attempt === 1) {
                    throw new TransportException('secret SMTP response driver@example.test');
                }

                return $message;
            });
            $service = app(ChargingSummaryDelivery::class);
            $service->deliverDue();
            $row->refresh();
            self::assertNull($row->sent_at);
            self::assertSame('mail_transport_failed', $row->last_error_code);
            self::assertSame('retrying', $row->deliveryStatus());
            self::assertSame(1, $row->attempt_count);
            self::assertTrue($row->last_attempt_at->equalTo(now('UTC')));
            $service->deliverDue();
            self::assertSame(1, $row->refresh()->attempt_count);
            $this->travel(61)->seconds();
            $service->deliverDue();
            $service->deliverDue();
            $row->refresh();
            self::assertSame(2, $row->attempt_count);
            self::assertNotNull($row->sent_at);
            self::assertSame($message->getMessageId(), $row->message_id);
            self::assertNull($row->last_error_code);
            self::assertSame('accepted', $row->deliveryStatus());
        });
    }

    public function test_log_and_log_fallback_mailers_never_mark_receipts_sent(): void
    {
        Mail::fake();
        $user = $this->createUser();
        $tenant = $this->createTenant('receipt-log');
        $this->withinTenant($tenant, $user, function () use ($user): void {
            foreach (['log', 'array', 'failover'] as $mailer) {
                config(['mail.default' => $mailer]);
                $row = $this->receipt($user->public_id);
                app(ChargingSummaryDelivery::class)->deliverDue();
                self::assertNull($row->refresh()->sent_at);
                self::assertSame('mailer_not_delivering', $row->last_error_code);
            }
            Mail::assertNothingSent();
        });
    }

    public function test_cancelled_send_is_not_reported_as_accepted(): void
    {
        config(['mail.default' => 'smtp']);
        $user = $this->createUser();
        $tenant = $this->createTenant('receipt-cancelled');
        $this->withinTenant($tenant, $user, function () use ($user): void {
            $row = $this->receipt($user->public_id);
            $pending = $this->createMock(PendingMail::class);
            Mail::shouldReceive('to')->once()->andReturn($pending);
            $pending->expects($this->once())->method('send')->willReturn(null);
            app(ChargingSummaryDelivery::class)->deliverDue();
            self::assertNull($row->refresh()->sent_at);
            self::assertSame('send_cancelled', $row->last_error_code);
        });
    }

    public function test_admin_table_is_read_only_permission_checked_and_tenant_isolated(): void
    {
        $user = $this->createUser();
        $tenant = $this->createTenant('receipt-admin');
        $other = $this->createTenant('receipt-other');
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        $foreign = $this->withinTenant($other, $user, fn () => $this->receipt($user->public_id));
        $this->withinTenant($tenant, $user, function () use ($user, $tenant, $foreign): void {
            $local = $this->receipt($user->public_id);
            self::assertFalse(ChargingReceiptResource::canViewAny());
            self::assertSame(0, ChargingReceiptResource::getEloquentQuery()->count());
            Livewire::test(ListChargingReceipts::class)->assertForbidden();
            $assignment = $this->assignDirectly($tenant, $this->createMembership($tenant, $user), $this->createRole($tenant, 'receipt-audit', [PermissionKey::AuditView]));
            $sent = $this->receipt($user->public_id);
            $sent->forceFill(['sent_at' => now('UTC')])->save();
            self::assertSame('sent_legacy', $sent->deliveryStatus());
            self::assertFalse(ChargingReceiptResource::canView($foreign));
            Livewire::test(ListChargingReceipts::class)->assertCanSeeTableRecords([$local, $sent])->assertCanNotSeeTableRecords([$foreign])
                ->filterTable('delivery_status', 'sent')->assertCanSeeTableRecords([$sent])->assertCanNotSeeTableRecords([$local]);
            Livewire::test(ListChargingReceipts::class)->searchTable($local->session_id)->assertCanSeeTableRecords([$local])->assertCanNotSeeTableRecords([$sent]);
            self::assertFalse(ChargingReceiptResource::canCreate());
            self::assertFalse(ChargingReceiptResource::canEdit($local));
            self::assertFalse(ChargingReceiptResource::canDelete($local));
            $assignment->delete();
            self::assertFalse(ChargingReceiptResource::canView($local));
        });
    }

    public function test_receipt_images_are_embedded_and_every_cid_has_an_image_attachment(): void
    {
        $sent = Mail::mailer('array')->to('preview@example.test')->send(new ChargingSummaryMail([
            'session_id' => '01M5YGQ66J4A463WRK1C0XVJK6', 'station' => 'Lumina Subic', 'connector' => 'Type 2',
            'energy_wh' => 3561, 'duration_seconds' => 2031, 'amount_minor' => 53, 'currency' => 'PHP', 'payment_method' => 'Prepaid wallet',
        ]));
        $message = $sent->getSymfonySentMessage()->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        $mime = $message->toString();
        preg_match_all('/src="cid:([^"]+)"/', (string) $message->getHtmlBody(), $matches);
        self::assertCount(3, $matches[1]);
        self::assertStringNotContainsString('/branding/', (string) $message->getHtmlBody());
        $inline = [];
        $pdf = 0;
        foreach ($message->getAttachments() as $attachment) {
            if ($attachment->getDisposition() === 'inline') {
                self::assertSame('image', $attachment->getMediaType());
                self::assertSame('png', $attachment->getMediaSubtype());
                self::assertStringStartsWith("\x89PNG", $attachment->getBody());
                $inline[] = $attachment->getContentId();
            } else {
                self::assertStringStartsWith('%PDF-', $attachment->getBody());
                $pdf++;
            }
        }
        self::assertEqualsCanonicalizing($matches[1], $inline);
        self::assertSame(1, $pdf);
        self::assertStringContainsString('multipart/related', $mime);
    }

    private function receipt(string $subject): ChargingEmailDelivery
    {
        $session = ChargingSession::factory()->create();
        app(ChargingSummaryQueue::class)->record((string) $session->getKey(), $subject, ['amount_minor' => 53]);

        return ChargingEmailDelivery::query()->where('session_id', $session->getKey())->sole();
    }
}
