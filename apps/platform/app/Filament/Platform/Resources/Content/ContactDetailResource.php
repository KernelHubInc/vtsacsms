<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageContactDetails;
use App\Modules\CMS\Domain\Models\ContactDetail;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ContactDetailResource extends CmsResource
{
    protected static ?string $model = ContactDetail::class;

    protected static ?string $navigationLabel = 'Contact details';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('key')->required()->maxLength(80)->unique(ignoreRecord: true), TextInput::make('label')->required()->maxLength(120), TextInput::make('value')->required()->maxLength(500), TextInput::make('sort_order')->numeric()->minValue(0)->required(), Toggle::make('is_public')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('label')->searchable(), TextColumn::make('key')->badge(), TextColumn::make('value')->limit(45), IconColumn::make('is_public')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageContactDetails::route('/')];
    }
}
