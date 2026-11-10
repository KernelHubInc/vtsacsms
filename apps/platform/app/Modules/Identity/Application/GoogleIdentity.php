<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public string $firstName,
        public string $lastName,
        public bool $emailAuthoritative,
    ) {}
}
