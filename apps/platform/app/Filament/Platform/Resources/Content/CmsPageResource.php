<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageCmsPages;
use App\Modules\CMS\Domain\Models\CmsPage;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CmsPageResource extends CmsResource
{
    protected static ?string $model = CmsPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Pages and heroes';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(180), TextInput::make('slug')->required()->maxLength(180)->unique(ignoreRecord: true),
            TextInput::make('eyebrow')->maxLength(120), Select::make('template')->options(array_combine(['home', 'network', 'map', 'default', 'app', 'articles', 'faq', 'contact', 'support', 'legal'], ['Home', 'Network', 'Map', 'Default', 'App', 'Articles', 'FAQ', 'Contact', 'Support', 'Legal']))->required(),
            TextInput::make('hero_heading')->maxLength(240)->columnSpanFull(), Textarea::make('hero_copy')->columnSpanFull(), Textarea::make('body')->columnSpanFull(),
            TextInput::make('primary_action_label')->maxLength(80), TextInput::make('primary_action_url')->maxLength(500), TextInput::make('secondary_action_label')->maxLength(80), TextInput::make('secondary_action_url')->maxLength(500),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->required()->disabled(fn (): bool => ! self::canPublish()), DateTimePicker::make('published_at')->timezone('UTC')->disabled(fn (): bool => ! self::canPublish()),
            Toggle::make('legal_review_required')->disabled(fn (): bool => ! self::canPublish()),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()->sortable(), TextColumn::make('slug')->searchable(), TextColumn::make('template')->badge(), TextColumn::make('status')->badge(), IconColumn::make('legal_review_required')->boolean(), TextColumn::make('updated_at')->dateTime()->sortable()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCmsPages::route('/')];
    }
}
