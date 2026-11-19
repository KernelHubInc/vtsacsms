<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class SupportVerificationMail extends Mailable
{
    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your Power Solutions support request');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support-verification', text: 'mail.text.support-verification');
    }
}
