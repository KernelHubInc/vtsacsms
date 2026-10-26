<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Content;

use App\Filament\Platform\Resources\Content\Pages\ManageDriverNotices;
use App\Models\User;
use App\Modules\CMS\Domain\Models\DriverNotice;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DriverNoticeResource extends CmsResource
{
    protected static ?string $model = DriverNotice::class;

    protected static ?string $navigationLabel = 'Driver notices';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category')
                ->options([
                    'maintenance' => 'Maintenance',
                    'announcement' => 'Announcement',
                    'promotion' => 'Promotion',
                ])->required(),
            Select::make('status')
                ->options(['draft' => 'Draft', 'published' => 'Published'])
                ->default('draft')->required()
                ->disabled(fn (): bool => ! self::canPublish()),
            TextInput::make('title')->required()->maxLength(120)->columnSpanFull(),
            Textarea::make('message')->required()->maxLength(500)->rows(4)->columnSpanFull(),
            DateTimePicker::make('starts_at')->label('Show from (UTC)')->timezone('UTC')
                ->default(fn () => now('UTC'))->required(),
            DateTimePicker::make('ends_at')->label('Show until (UTC)')->timezone('UTC')
                ->default(fn () => now('UTC')->addDays(7))
                ->required()->rule('after:starts_at'),
            Toggle::make('show_on_open')->label('Show banner when app opens')->default(true)
                ->helperText('The notice will also appear in the Updates list.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('category')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('starts_at')->dateTime()->sortable(),
            TextColumn::make('ends_at')->dateTime()->sortable(),
            IconColumn::make('show_on_open')->boolean(),
        ])->recordActions([self::auditedEditAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDriverNotices::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', app(CurrentTenant::class)->get()->tenantId);
    }

    public static function canViewAny(): bool
    {
        return self::allows(PermissionKey::CmsEdit);
    }

    public static function canCreate(): bool
    {
        return self::allows(PermissionKey::CmsEdit);
    }

    public static function canPublish(): bool
    {
        return self::allows(PermissionKey::CmsPublish);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof DriverNotice
            && $record->tenant_id === app(CurrentTenant::class)->get()->tenantId
            && self::allows($record->status === 'published' ? PermissionKey::CmsPublish : PermissionKey::CmsEdit);
    }

    public static function auditedCreateAction(): CreateAction
    {
        return parent::auditedCreateAction()->mutateDataUsing(static function (array $data): array {
            $data['tenant_id'] = app(CurrentTenant::class)->get()->tenantId;
            if (! self::canPublish()) {
                $data['status'] = 'draft';
            }

            return $data;
        });
    }

    public static function auditedEditAction(): EditAction
    {
        return parent::auditedEditAction()->mutateDataUsing(static function (array $data): array {
            unset($data['tenant_id']);
            if (! self::canPublish()) {
                unset($data['status']);
            }

            return $data;
        });
    }

    private static function allows(PermissionKey $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AuthorizationService::class)->allows($user, $permission);
    }
}
