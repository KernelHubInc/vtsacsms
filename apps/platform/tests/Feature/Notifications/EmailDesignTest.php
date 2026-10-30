<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Notifications\Mail\ChargingSummaryMail;
use App\Modules\Notifications\Mail\PromotionalMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class EmailDesignTest extends TestCase
{
    public function test_receipt_renders_real_values_escapes_content_and_keeps_its_attachment(): void
    {
        config(['app.url' => 'https://charging.example.test']);
        $mail = new ChargingSummaryMail([
            'session_id' => '01M5YGQ66J4A463WRK1C0XVJK6',
            'station' => '<script>alert(1)</script> Lumina',
            'connector' => 'Type 2', 'started_at' => '2026-10-29T02:00:00Z',
            'stopped_at' => '2026-10-29T02:15:30Z', 'duration_seconds' => 930,
            'energy_wh' => 3443, 'amount_minor' => 50001, 'currency' => 'PHP',
            'payment_method' => 'Business prepaid wallet',
        ]);
        $html = $mail->render();
        self::assertStringContainsString('All charged up.', $html);
        self::assertStringContainsString('PHP 500.01', $html);
        self::assertStringContainsString('3.443 kWh', $html);
        self::assertStringContainsString('29 Oct 2026, 10:15 AM PHT', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('USD', strip_tags($html));
        self::assertStringNotContainsString('4242', strip_tags($html));
        self::assertStringContainsString('https://charging.example.test/mobile/', $html);
        self::assertStringContainsString('Sign in and open Activity', $html);
        self::assertCount(1, $mail->attachments());
        self::assertSame('application/pdf', $mail->attachments()[0]->mime);
        self::assertSame('mail.text.charging-summary', $mail->content()->text);
    }

    public function test_welcome_uses_the_signed_verification_url_in_html_and_plain_text(): void
    {
        $this->freezeTime();
        $user = new User(['email' => 'driver@example.test']);
        $user->public_id = '01M5YGQ66J4A463WRK1C0XVJK6';
        $message = (new VerifyEmailNotification)->toMail($user);
        $url = $message->actionUrl;
        self::assertTrue(URL::hasValidSignature(Request::create($url)));
        $html = view('mail.welcome', $message->viewData)->render();
        self::assertStringContainsString('More of life.', $html);
        self::assertStringContainsString(e($url), $html);
        self::assertStringContainsString('Verify email address', $html);
        self::assertStringContainsString('60 minutes', $html);
        $plain = view('mail.text.welcome', $message->viewData)->render();
        self::assertStringContainsString($url, $plain);
        self::assertStringNotContainsString('&amp;', $plain);
        $this->travel(61)->minutes();
        self::assertFalse(URL::hasValidSignature(Request::create($url)));
    }

    public function test_promotional_template_requires_real_preference_links_and_has_no_sample_offer(): void
    {
        config(['app.url' => 'https://charging.example.test']);
        $url = 'https://mail.example.test/unsubscribe?id=opaque&token=signed';
        $mail = new PromotionalMail($url);
        $html = $mail->render();
        self::assertStringContainsString(e($url), $html);
        self::assertStringNotContainsString('Manage email preferences', $html);
        self::assertStringContainsString('/branding/email/journey.png', $html);
        self::assertStringNotContainsString('figma.com', $html);
        self::assertStringContainsString('https://charging.example.test/charging-map', $html);
        self::assertSame('<'.$url.'>', $mail->headers()->text['List-Unsubscribe']);
        self::assertStringContainsString($url, view('mail.text.promotional', ['unsubscribeUrl' => $url, 'preferencesUrl' => null])->render());
        self::assertStringContainsString('Manage email preferences', (new PromotionalMail($url, 'https://mail.example.test/preferences'))->render());
    }

    public function test_promotional_message_builds_html_text_and_unsubscribe_header_without_network_delivery(): void
    {
        $url = 'https://mail.example.test/unsubscribe?id=opaque&token=signed';
        $sent = Mail::mailer('array')->to('preview@example.test')->send(new PromotionalMail($url));
        self::assertNotNull($sent);
        $message = $sent->getSymfonySentMessage()->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertStringContainsString('With charging', (string) $message->getHtmlBody());
        self::assertStringContainsString($url, (string) $message->getTextBody());
        self::assertSame('<'.$url.'>', $message->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
    }

    #[DataProvider('invalidUrls')]
    public function test_promotional_links_cannot_be_missing_or_unsafe(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PromotionalMail($url);
    }

    /** @return array<string, array{string}> */
    public static function invalidUrls(): array
    {
        return [
            'missing' => [''], 'script' => ['javascript:alert(1)'],
            'relative' => ['/unsubscribe'], 'insecure' => ['http://example.test/unsubscribe'],
            'credentials' => ['https://user:password@example.test/unsubscribe'],
            'header injection' => ["https://example.test/\r\nBcc: victim@example.test"],
        ];
    }
}
