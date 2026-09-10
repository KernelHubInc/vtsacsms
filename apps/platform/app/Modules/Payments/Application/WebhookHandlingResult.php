<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

final readonly class WebhookHandlingResult
{
    public function __construct(public string $outcome, public ?string $paymentIntentId = null) {}
}
