<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ChargingSummaryMail extends Mailable
{
    /** @param array<string, int|string|null> $summary */
    public function __construct(public readonly array $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Power Solutions charging payment confirmation');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.charging-summary');
    }
}
