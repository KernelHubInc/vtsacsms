<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Domain;

enum IncidentState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Mitigated = 'mitigated';
    case Resolved = 'resolved';
    case Recurrent = 'recurrent';
    case Dismissed = 'dismissed';

    public function active(): bool
    {
        return in_array($this, [self::Open, self::Acknowledged, self::Mitigated, self::Recurrent], true);
    }
}
