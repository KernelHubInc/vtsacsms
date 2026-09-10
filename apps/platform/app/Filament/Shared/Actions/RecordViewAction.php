<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;

final class RecordViewAction
{
    /**
     * @param  array<string, string>  $fields  Attribute or relationship path => safe display label.
     */
    public static function make(array $fields): ViewAction
    {
        return ViewAction::make()
            ->schema(array_map(
                static fn (string $label, string $field): TextEntry => TextEntry::make($field)
                    ->label($label)
                    ->placeholder('—'),
                $fields,
                array_keys($fields),
            ))
            ->modalDescription('Read-only operational detail. Changes require an authorized lifecycle action.');
    }
}
