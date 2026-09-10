<?php

declare(strict_types=1);

namespace App\Foundation\Database;

use LogicException;

trait PreventsFinancialMutation
{
    public static function bootPreventsFinancialMutation(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted financial records are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted financial records cannot be deleted.'));
    }
}
