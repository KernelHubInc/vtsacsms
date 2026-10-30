<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Application\ChargingReceiptPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ChargingSummaryMail extends Mailable
{
    /** @param array<string, int|string|null> $summary */
    public function __construct(public readonly array $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Power Solutions charging receipt');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.charging-summary', text: 'mail.text.charging-summary', with: [
            'receipt' => app(ChargingReceiptPdf::class)->details($this->summary),
            'embedReceiptImages' => true,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => app(ChargingReceiptPdf::class)->render($this->summary),
                'power-solutions-charging-'.$this->summary['session_id'].'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
