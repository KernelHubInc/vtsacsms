<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use InvalidArgumentException;

final class PromotionalMail extends Mailable
{
    public function __construct(
        public readonly string $unsubscribeUrl,
        public readonly ?string $preferencesUrl = null,
    ) {
        foreach (array_filter([$unsubscribeUrl, $preferencesUrl], fn ($url) => $url !== null) as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
                || parse_url($url, PHP_URL_USER) !== null
                || preg_match('/[\s<>]/', $url) === 1) {
                throw new InvalidArgumentException('Campaign preference links must be valid HTTPS URLs.');
            }
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your day. With charging built in.');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.promotional', text: 'mail.text.promotional');
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>']);
    }
}
