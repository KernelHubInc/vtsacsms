<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;

final readonly class VerifiedDriverEmailQuery
{
    public function forUserId(int $userId): ?string
    {
        return User::query()->whereKey($userId)->whereNotNull('email_verified_at')
            ->whereNotNull('activated_at')->whereNull('disabled_at')->value('email');
    }

    public function forPublicId(string $subjectId): ?string
    {
        return User::query()
            ->where('public_id', $subjectId)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('activated_at')
            ->whereNull('disabled_at')
            ->value('email');
    }
}
