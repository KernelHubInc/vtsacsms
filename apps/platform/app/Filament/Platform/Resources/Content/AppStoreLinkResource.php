<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageAppStoreLinks;
use App\Modules\CMS\Domain\Models\AppStoreLink;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AppStoreLinkResource extends CmsResource
{
    protected static ?string $model = AppStoreLink::class;

    protected static ?string $navigationLabel = 'App store links';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('store')->options(['apple' => 'Apple App Store', 'google' => 'Google Play'])->required()->unique(ignoreRecord: true), TextInput::make('label')->required()->maxLength(100), TextInput::make('url')->url()->maxLength(500), Toggle::make('is_published')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('store')->badge(), TextColumn::make('label'), TextColumn::make('url')->limit(45), IconColumn::make('is_published')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAppStoreLinks::route('/')];
    }
}
