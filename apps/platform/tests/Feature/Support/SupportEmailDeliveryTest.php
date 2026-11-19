<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Notifications\Application\SupportEmailDelivery;
use App\Modules\Notifications\Application\SupportEmailRequests;
use App\Modules\Notifications\Application\SupportMailTransport;
use App\Modules\Notifications\Domain\Models\SupportEmailDelivery as Delivery;
use App\Modules\Notifications\Mail\SupportTicketMail;
use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\TenantSecurityTestCase;

final class SupportEmailDeliveryTest extends TenantSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['support.enabled' => true]);
    }

    public function test_delivery_is_queued_once_and_is_not_resent_after_provider_acceptance(): void
    {
        $this->context(function (): void {
            $ticket = $this->ticket();
            $queue = app(SupportEmailRequests::class);
            $queue->enqueue((string) $ticket->getKey());
            $queue->enqueue((string) $ticket->getKey());
            $this->assertDatabaseCount('support_email_deliveries', 1);
            $pending = $this->createMock(PendingMail::class);
            $sent = $this->accepted('accepted@example.test');
            Mail::shouldReceive('to')->once()->with('visitor@example.test')->andReturn($pending);
            $pending->expects(self::once())->method('send')->with(self::isInstanceOf(SupportTicketMail::class))->willReturn($sent);
            app(SupportEmailDelivery::class)->deliverDue();
            app(SupportEmailDelivery::class)->deliverDue();
            self::assertNotNull(Delivery::query()->firstOrFail()->sent_at);
            self::assertSame('accepted@example.test', Delivery::query()->firstOrFail()->message_id);
        });
    }

    public function test_failed_transport_retries_without_losing_the_ticket_or_claiming_success(): void
    {
        $this->context(function (): void {
            $ticket = $this->ticket();
            app(SupportEmailRequests::class)->enqueue((string) $ticket->getKey());
            $pending = $this->createMock(PendingMail::class);
            $sent = $this->accepted('retry-accepted@example.test');
            Mail::shouldReceive('to')->twice()->andReturn($pending);
            $attempt = 0;
            $pending->expects(self::exactly(2))->method('send')->willReturnCallback(function () use (&$attempt, $sent): SentMessage {
                if (++$attempt === 1) {
                    throw new \RuntimeException('SMTP secret must not be persisted');
                }

                return $sent;
            });
            $worker = app(SupportEmailDelivery::class);
            $worker->deliverDue();
            $delivery = Delivery::query()->firstOrFail();
            self::assertNull($delivery->sent_at);
            self::assertNull($delivery->claimed_until);
            self::assertSame('mail_delivery_failed', $delivery->last_error_code);
            self::assertSame(1, $delivery->attempt_count);
            $worker->deliverDue();
            $this->travel(61)->seconds();
            $worker->deliverDue();
            self::assertNotNull($delivery->fresh()->sent_at);
            self::assertSame(2, $delivery->fresh()->attempt_count);
            $this->assertDatabaseCount('support_tickets', 1);
        });
    }

    public function test_internal_note_cannot_be_sent_even_if_a_delivery_record_is_forged(): void
    {
        $this->context(function (): void {
            $ticket = $this->ticket();
            $note = $ticket->messages()->create(['body' => 'Internal financial investigation.', 'is_internal' => true]);
            app(SupportEmailRequests::class)->enqueue((string) $ticket->getKey(), (string) $note->getKey());
            Delivery::query()->update(['attempt_count' => 7]);
            Mail::shouldReceive('to')->never();
            app(SupportEmailDelivery::class)->deliverDue();
            $delivery = Delivery::query()->firstOrFail();
            self::assertNull($delivery->sent_at);
            self::assertNotNull($delivery->failed_at);
            self::assertSame('support_recipient_unavailable', $delivery->last_error_code);
        });
    }

    public function test_log_or_log_fallback_mailer_is_not_treated_as_external_delivery(): void
    {
        $this->app->instance('env', 'staging');
        config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
            'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.log.transport' => 'log']);
        self::assertFalse(app(SupportMailTransport::class)->ready());
        config(['mail.default' => 'smtp']);
        self::assertTrue(app(SupportMailTransport::class)->ready());
    }

    public function test_customer_email_escapes_untrusted_text_and_explains_the_reply_channel(): void
    {
        $details = ['email' => 'visitor@example.test', 'ticket_number' => 'SUP-'.Str::ulid(), 'subject' => '<img src=x onerror=alert(1)>',
            'body' => '<script>alert(1)</script>', 'status' => 'pending_customer'];
        $mail = new SupportTicketMail($details, (string) Str::ulid());
        $html = $mail->render();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Replies to this email are not automatically added', $html);
    }

    public function test_real_mail_rendering_preserves_a_stable_message_id_without_external_delivery(): void
    {
        config(['mail.default' => 'array', 'app.url' => 'https://staging.example.test']);
        $this->context(function (): void {
            $ticket = $this->ticket();
            app(SupportEmailRequests::class)->enqueue((string) $ticket->getKey());
            $row = Delivery::query()->firstOrFail();
            app(SupportEmailDelivery::class)->deliverDue();
            self::assertNotNull($row->fresh()->sent_at);
            self::assertSame($row->getKey().'.support@staging.example.test', $row->fresh()->message_id);
            $transport = Mail::mailer('array')->getSymfonyTransport();
            self::assertInstanceOf(ArrayTransport::class, $transport);
            self::assertCount(1, $transport->messages());
        });
    }

    private function accepted(string $id): SentMessage
    {
        $email = (new Email)->from('sender@example.test')->to('visitor@example.test')->text('Support message');
        $email->getHeaders()->addIdHeader('Message-ID', $id);

        return new SentMessage(new SymfonySentMessage($email, Envelope::create($email)));
    }

    private function context(\Closure $operation): void
    {
        $tenant = $this->createTenant('support-delivery');
        $actor = $this->createUser();
        $this->withinTenant($tenant, $actor, $operation);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::query()->create(['ticket_number' => 'SUP-'.Str::ulid(), 'source' => 'website', 'category' => 'general',
            'subject' => 'Charging help', 'description' => 'Please help with the charging process.',
            'contact_email' => 'visitor@example.test', 'contact_verified_at' => now('UTC')]);
    }
}
