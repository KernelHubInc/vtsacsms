<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Roles;

use App\Modules\Organizations\Application\PermissionCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

final class RolePermissionFields
{
    /** @return list<Section> */
    public static function schema(): array
    {
        $sections = [];
        foreach (app(PermissionCatalog::class)->modules() as $module => $definition) {
            $path = 'module_permissions.'.$module;
            $sections[] = Section::make($definition['label'])
                ->key('module_'.$module)
                ->description(function (Get $get) use ($path): string {
                    $count = count($get($path) ?? []);

                    return match ($count) {
                        0 => 'No access. Choose the functions this role needs.',
                        1 => '1 function selected',
                        default => "{$count} functions selected",
                    };
                })
                ->collapsible()
                ->collapsed(fn (Get $get): bool => empty($get($path)))
                ->compact()
                ->headerActions([
                    Action::make('remove_'.$module)
                        ->label('Remove module')
                        ->color('gray')
                        ->visible(fn (Get $get): bool => ! empty($get($path)))
                        ->action(fn (Set $set) => $set($path, [])),
                ])
                ->schema([
                    CheckboxList::make($path)
                        ->label('Allowed functions')
                        ->options($definition['permissions'])
                        ->columns(2)
                        // Refresh the module summary and removal action after each selection.
                        ->live()
                        ->default([]),
                ]);
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $permissions
     * @return array<string, list<string>>
     */
    public static function group(array $permissions): array
    {
        $groups = [];
        foreach (app(PermissionCatalog::class)->modules() as $module => $definition) {
            $groups[$module] = [];
            foreach ($permissions as $permission) {
                if (! is_string($permission)) {
                    throw new \LogicException('Stored permission keys must be strings.');
                }
                if (isset($definition['permissions'][$permission])) {
                    $groups[$module][] = $permission;
                }
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function flatten(array $data): array
    {
        $modules = app(PermissionCatalog::class)->modules();
        $selections = $data['module_permissions'] ?? [];
        if (! is_array($selections) || array_diff(array_keys($selections), array_keys($modules)) !== []) {
            throw ValidationException::withMessages(['module_permissions' => 'Choose valid modules.']);
        }
        $permissions = [];
        foreach ($selections as $module => $values) {
            if (! is_array($values) || array_filter($values, fn (mixed $value): bool => ! is_string($value) || ! isset($modules[$module]['permissions'][$value])) !== []) {
                throw ValidationException::withMessages(['module_permissions.'.$module => 'Choose valid functions for this module.']);
            }
            array_push($permissions, ...$values);
        }
        unset($data['module_permissions']);
        $data['permissions'] = array_values(array_unique($permissions));

        return $data;
    }
}
