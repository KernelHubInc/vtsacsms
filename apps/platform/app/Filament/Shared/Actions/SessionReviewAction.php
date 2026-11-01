<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Modules\Charging\Application\ManualSessionReviewService;
use App\Modules\Charging\Domain\Models\ChargingSessionReview;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class SessionReviewAction
{
    public static function make(): Action
    {
        return Action::make('resolveReview')->label('Resolve review')->requiresConfirmation()
            ->modalDescription('This finalizes the session through the charging workflow. Enter corrections only when supported by device records. Leave a measurement blank to retain its recorded value.')
            ->visible(fn (ChargingSessionReview $record): bool => $record->status === 'open' && Gate::allows('review', $record->session))
            ->schema([
                Select::make('decision')->options(['complete' => 'Complete using verified readings', 'estimated' => 'Complete using reviewed estimates', 'unbillable' => 'Mark unbillable'])->required(),
                Textarea::make('notes')->label('Evidence and reason')->required()->maxLength(2000),
                TextInput::make('energy_wh')->label('Energy (Wh)')->integer()->minValue(0)->maxValue(2147483647),
                TextInput::make('duration_seconds')->label('Duration (seconds)')->integer()->minValue(0)->maxValue(2147483647),
                TextInput::make('parking_seconds')->label('Parking (seconds)')->integer()->minValue(0)->maxValue(2147483647),
                TextInput::make('idle_seconds')->label('Idle time (seconds)')->integer()->minValue(0)->maxValue(2147483647),
            ])->action(function (ChargingSessionReview $record, array $data): void {
                $session = $record->session;
                Gate::authorize('review', $session);
                $rules = ['decision' => 'required|in:complete,estimated,unbillable', 'notes' => 'required|string|max:2000'];
                foreach (['energy_wh', 'duration_seconds', 'parking_seconds', 'idle_seconds'] as $field) {
                    $rules[$field] = 'nullable|integer|min:0|max:2147483647';
                }
                $data = Validator::make($data, $rules)->validate();
                $adjustments = [];
                foreach (['energy_wh', 'duration_seconds', 'parking_seconds', 'idle_seconds'] as $field) {
                    if (isset($data[$field])) {
                        $adjustments[$field] = (int) $data[$field];
                    }
                }
                WorkflowAction::run(fn () => app(ManualSessionReviewService::class)->resolve($session, (string) $data['decision'], (string) $data['notes'], $adjustments));
            });
    }
}
