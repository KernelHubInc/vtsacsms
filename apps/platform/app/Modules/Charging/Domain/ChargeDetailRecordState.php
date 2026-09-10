<?php

declare(strict_types=1);

namespace App\Modules\Charging\Domain;

enum ChargeDetailRecordState: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case ReviewRequired = 'review_required';
    case Finalized = 'finalized';
    case Failed = 'failed';
    case Superseded = 'superseded';

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Pending => [self::Generating, self::Failed],
            self::Generating => [self::ReviewRequired, self::Finalized, self::Failed],
            self::ReviewRequired => [self::Generating, self::Finalized, self::Failed],
            self::Finalized => [self::Superseded],
            self::Failed, self::Superseded => [],
        }, true);
    }
}
