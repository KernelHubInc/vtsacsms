<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Models\User;
use App\Modules\Identity\Application\Contracts\MfaChallengeProvider;

final class UnavailableMfaChallengeProvider implements MfaChallengeProvider
{
    public function isAvailableFor(User $user): bool
    {
        return false;
    }
}
