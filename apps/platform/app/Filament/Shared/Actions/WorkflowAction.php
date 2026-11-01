<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use DomainException;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

final class WorkflowAction
{
    public static function run(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            Notification::make()->title('Action could not be completed')->body($exception->getMessage())->danger()->send();
            throw new Halt;
        }
    }
}
