<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

final class SupportTicketMail extends Mailable
{
    /** @param array{email:string,ticket_number:string,subject:string,body:string,status:string} $details */
    public function __construct(public readonly array $details, public readonly string $deliveryId)
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $this->withSymfonyMessage(fn (Email $message) => $message->getHeaders()->addIdHeader('Message-ID', $this->deliveryId.'.support@'.$host));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Power Solutions support · '.$this->details['ticket_number']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-ticket', text: 'mail.text.support-ticket');
    }
}
