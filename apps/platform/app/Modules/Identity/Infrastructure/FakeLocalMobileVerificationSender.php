<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\MobileVerificationSender;
use LogicException;

final class FakeLocalMobileVerificationSender implements MobileVerificationSender
{
    public function send(string $mobileNumber, string $code): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('The fake mobile verification adapter is local-only.');
        }

        // The fake adapter intentionally performs no external I/O and never logs the code.
    }
}
