<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Models\User;

interface MfaChallengeProvider
{
    public function isAvailableFor(User $user): bool;
}
