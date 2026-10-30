<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\AuditEvents;

use App\Foundation\Audit\AuditLogQuery;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

final class AuditFilters
{
    /** @return list<Component> */
    public static function fields(): array
    {
        return [
            DatePicker::make('from')->label('From (UTC)'),
            DatePicker::make('to')->label('Before (UTC, exclusive)')->after('from'),
            Select::make('category')->options(AuditLogQuery::categories()),
            Select::make('result')->options(['succeeded' => 'Succeeded', 'failed' => 'Failed', 'denied' => 'Denied']),
            Select::make('actor_type')->options(['human' => 'Human', 'service' => 'Service', 'platform' => 'Platform']),
            TextInput::make('action')->label('Exact action')->maxLength(160)->placeholder('billing.prepaid.topup'),
            TextInput::make('actor_id')->label('User / actor ID')->rules(['nullable', 'ulid']),
            TextInput::make('target_id')->label('Record ID')->rules(['nullable', 'ulid']),
            TextInput::make('correlation_id')->label('Correlation ID')->rules(['nullable', 'ulid']),
        ];
    }
}
