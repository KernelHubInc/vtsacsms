<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use Closure;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;

final class RecordViewAction
{
    /**
     * @param  array<string, string>  $fields  Attribute or relationship path => safe display label.
     * @param  (Closure(string, Model): bool)|null  $allowsField
     */
    public static function make(array $fields, ?Closure $allowsField = null): ViewAction
    {
        return ViewAction::make()
            ->schema(array_map(
                static fn (string $label, string $field): TextEntry => TextEntry::make($field)
                    ->label($label)
                    ->placeholder('—')
                    ->visible($allowsField === null ? true : fn (Model $record): bool => $allowsField($field, $record)),
                $fields,
                array_keys($fields),
            ))
            ->modalDescription('Read-only operational detail. Changes require an authorized lifecycle action.');
    }
}
