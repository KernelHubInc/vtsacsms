<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManagePartnerLogos;
use App\Modules\CMS\Domain\Models\PartnerLogo;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PartnerLogoResource extends CmsResource
{
    protected static ?string $model = PartnerLogo::class;

    protected static ?string $navigationLabel = 'Partner logos';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(160), TextInput::make('website_url')->url()->maxLength(500), FileUpload::make('image_path')->image()->imageEditor()->imageResizeMode('contain')->imageResizeTargetWidth('640')->imageResizeTargetHeight('320')->disk('s3')->directory('cms/partners')->maxSize(2048)->required()->columnSpanFull(), TextInput::make('image_alt')->required()->maxLength(240), TextInput::make('sort_order')->numeric()->minValue(0)->required(), Toggle::make('is_published')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('website_url')->limit(40), TextColumn::make('sort_order')->sortable(), IconColumn::make('is_published')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePartnerLogos::route('/')];
    }
}
