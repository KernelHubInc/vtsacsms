<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageCmsSections;
use App\Modules\CMS\Domain\Models\CmsSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CmsSectionResource extends CmsResource
{
    protected static ?string $model = CmsSection::class;

    protected static ?string $navigationLabel = 'Feature sections';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cms_page_id')->relationship('page', 'title')->searchable()->preload()->required(), TextInput::make('kind')->required()->maxLength(32), TextInput::make('eyebrow')->maxLength(120), TextInput::make('heading')->required()->maxLength(240), Textarea::make('copy')->columnSpanFull(), TagsInput::make('items')->columnSpanFull(), TextInput::make('action_label')->maxLength(80), TextInput::make('action_url')->maxLength(500), TextInput::make('sort_order')->numeric()->minValue(0)->required(), Toggle::make('is_enabled'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('page.title')->label('Page')->sortable(), TextColumn::make('heading')->searchable(), TextColumn::make('kind')->badge(), TextColumn::make('sort_order')->sortable(), IconColumn::make('is_enabled')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCmsSections::route('/')];
    }
}
