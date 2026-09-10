<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageTestimonials;
use App\Modules\CMS\Domain\Models\Testimonial;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TestimonialResource extends CmsResource
{
    protected static ?string $model = Testimonial::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Textarea::make('quote')->required()->columnSpanFull(), TextInput::make('person_name')->required()->maxLength(160), TextInput::make('person_role')->maxLength(160), TextInput::make('organization_name')->maxLength(160), TextInput::make('sort_order')->numeric()->minValue(0)->required(), Toggle::make('is_published')->disabled(fn (): bool => ! self::canPublish())])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('quote')->limit(60), TextColumn::make('person_name')->searchable(), TextColumn::make('organization_name'), IconColumn::make('is_published')->boolean()])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTestimonials::route('/')];
    }
}
