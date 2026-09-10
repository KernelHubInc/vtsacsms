<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

enum AuditResult: string
{
    case Succeeded = 'succeeded';
    case Denied = 'denied';
    case Failed = 'failed';
}
