<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Models\User;
use App\Modules\Identity\Application\RequestUserPasswordReset;
use App\Modules\Organizations\Domain\Models\Membership;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

final class UserPasswordResetAction
{
    public static function make(): Action
    {
        return Action::make('resetPassword')->label('Reset password')
            ->icon('heroicon-o-key')->requiresConfirmation()
            ->modalHeading('Send password reset link?')
            ->modalDescription('Email a one-time recovery link to this user. Their current password remains valid until they choose a new one. Completing recovery signs out their existing sessions.')
            ->modalSubmitActionLabel('Send reset link')
            ->visible(fn (): bool => auth()->user() instanceof User
                && app(RequestUserPasswordReset::class)->canRequest(auth()->user()))
            ->action(function (Model $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $subject = match (true) {
                    $record instanceof User => $record,
                    $record instanceof Membership => $record->user,
                    default => throw new \LogicException('Password recovery requires a user or membership.'),
                };
                app(RequestUserPasswordReset::class)->send($actor, $subject);
                Notification::make()->title('Password reset link sent')->body('The user can set a new password using the link in their email.')->success()->send();
            });
    }
}
