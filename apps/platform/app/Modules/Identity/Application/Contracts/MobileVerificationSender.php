<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

interface MobileVerificationSender
{
    public function send(string $mobileNumber, string $code): void;
}
