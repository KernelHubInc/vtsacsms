<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageSeoMetadata;
use App\Modules\CMS\Domain\Models\SeoMetadata;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SeoMetadataResource extends CmsResource
{
    protected static ?string $model = SeoMetadata::class;

    protected static ?string $navigationLabel = 'SEO metadata';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('target_type')->options(['page' => 'Page', 'article' => 'Article'])->required(), TextInput::make('target_id')->required()->ulid(), TextInput::make('meta_title')->required()->maxLength(70)->columnSpanFull(), Textarea::make('meta_description')->required()->maxLength(180)->columnSpanFull(), TextInput::make('canonical_url')->url()->maxLength(500), TextInput::make('social_image_url')->maxLength(500), Toggle::make('noindex')->disabled(fn (): bool => ! self::canPublish()), KeyValue::make('structured_data')->helperText('Optional flat JSON-LD properties; public templates also emit safe baseline metadata.')->columnSpanFull()])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('meta_title')->searchable(), TextColumn::make('target_type')->badge(), TextColumn::make('target_id')->limit(20), IconColumn::make('noindex')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSeoMetadata::route('/')];
    }
}
