<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageRedirects;
use App\Modules\CMS\Domain\Models\CmsRedirect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RedirectResource extends CmsResource
{
    protected static ?string $model = CmsRedirect::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('source_path')->required()->startsWith('/')->maxLength(500)->unique(ignoreRecord: true)->helperText('Exact path beginning with /. Query strings are ignored.'), TextInput::make('destination_url')->required()->regex('/^(\/|https:\/\/)/')->maxLength(500)->helperText('Use an internal path or an approved HTTPS URL.'), Select::make('status_code')->options([301 => '301 permanent', 302 => '302 temporary', 307 => '307 temporary', 308 => '308 permanent'])->required(), Toggle::make('is_enabled')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('source_path')->searchable(), TextColumn::make('destination_url')->limit(50), TextColumn::make('status_code')->badge(), IconColumn::make('is_enabled')->boolean(), TextColumn::make('hit_count')->numeric()->sortable()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRedirects::route('/')];
    }
}
