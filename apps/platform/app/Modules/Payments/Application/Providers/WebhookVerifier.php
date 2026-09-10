<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Providers;

interface WebhookVerifier
{
    public function verifyWebhook(string $rawBody, string $signature): VerifiedWebhook;
}
