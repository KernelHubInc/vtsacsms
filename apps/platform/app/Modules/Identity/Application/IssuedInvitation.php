<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\UserInvitation;

final readonly class IssuedInvitation
{
    public function __construct(
        public UserInvitation $invitation,
        public string $plainTextToken,
    ) {}
}
