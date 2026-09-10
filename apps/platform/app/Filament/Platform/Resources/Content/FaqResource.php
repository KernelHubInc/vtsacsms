<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageFaqs;
use App\Modules\CMS\Domain\Models\Faq;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class FaqResource extends CmsResource
{
    protected static ?string $model = Faq::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('category')->required()->maxLength(80), TextInput::make('question')->required()->maxLength(300)->columnSpanFull(), Textarea::make('answer')->required()->columnSpanFull(), TextInput::make('sort_order')->numeric()->minValue(0)->required(), Toggle::make('is_published')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('question')->searchable()->limit(70), TextColumn::make('category')->badge(), TextColumn::make('sort_order')->sortable(), IconColumn::make('is_published')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFaqs::route('/')];
    }
}
