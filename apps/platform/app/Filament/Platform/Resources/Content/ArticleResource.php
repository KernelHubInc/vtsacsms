<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageArticles;
use App\Modules\CMS\Domain\Models\ContentArticle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ArticleResource extends CmsResource
{
    protected static ?string $model = ContentArticle::class;

    protected static ?string $navigationLabel = 'Articles and news';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('kind')->options(['article' => 'Article', 'news' => 'News'])->required(), TextInput::make('slug')->required()->maxLength(180)->unique(ignoreRecord: true), TextInput::make('title')->required()->maxLength(240)->columnSpanFull(), Textarea::make('excerpt')->maxLength(500)->columnSpanFull(), Textarea::make('body')->required()->rows(14)->columnSpanFull(), TextInput::make('author_name')->maxLength(160), Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->required()->disabled(fn (): bool => ! self::canPublish()), DateTimePicker::make('published_at')->timezone('UTC')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()->sortable(), TextColumn::make('kind')->badge(), TextColumn::make('status')->badge(), TextColumn::make('published_at')->dateTime()->sortable()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageArticles::route('/')];
    }
}
