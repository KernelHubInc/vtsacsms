<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

final class SupportEscalationMail extends Mailable
{
    public readonly string $ticketUrl;

    /** @param array{ticket_number:string,priority:string} $details */
    public function __construct(public readonly array $details, public readonly string $deliveryId)
    {
        $origin = rtrim((string) config('app.url'), '/');
        $this->ticketUrl = $origin.'/admin/support-tickets?'.http_build_query([
            'tableSearch' => $details['ticket_number'], 'page' => 1,
            'tableFilters' => array_fill_keys(['support_level', 'category', 'status', 'priority'], ['value' => '']),
        ]);
        $host = parse_url($origin, PHP_URL_HOST) ?: 'localhost';
        $this->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addIdHeader('Message-ID', $this->deliveryId.'.support-escalation@'.$host));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Level 3 support escalation · '.$this->details['ticket_number']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-escalation', text: 'mail.text.support-escalation');
    }
}
