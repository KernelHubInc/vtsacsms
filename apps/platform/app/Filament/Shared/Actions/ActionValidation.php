<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use Closure;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class ActionValidation
{
    /** @param Closure(): Model $operation */
    public static function run(HasActions&HasSchemas $livewire, Closure $operation): Model
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $name = $livewire->getMountedActionSchemaName();
            $schema = $name === null ? null : $livewire->getSchema($name);
            if ($schema === null) {
                throw $exception;
            }
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$schema->getStatePath().'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
