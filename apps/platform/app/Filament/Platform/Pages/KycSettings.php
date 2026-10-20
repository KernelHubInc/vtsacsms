<?php

declare(strict_types=1);

namespace App\Filament\Platform\Pages;

use App\Models\User;
use App\Modules\Identity\Application\Kyc\KycSettings as Settings;
use App\Modules\Organizations\Application\AuthorizationService;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tenancy\Application\CurrentTenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use UnitEnum;

final class KycSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Identity and audit';

    protected string $view = 'filament.platform.pages.kyc-settings';

    #[Validate('required|in:manual,automatic')]
    public string $mode = 'manual';

    #[Locked]
    public string $tenantId = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) config('kyc.enabled') && $user instanceof User && app(CurrentTenant::class)->has()
            && app(AuthorizationService::class)->allows($user, PermissionKey::KycSettingsManage);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->tenantId = app(CurrentTenant::class)->get()->tenantId;
        $this->mode = app(Settings::class)->mode();
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        abort_unless($this->tenantId === app(CurrentTenant::class)->get()->tenantId, 409);
        $this->validate();
        /** @var User $user */
        $user = auth()->user();
        app(Settings::class)->update($user, $this->mode);
        Notification::make()->title('KYC settings saved')->body('New submissions will use this review mode.')->success()->send();
    }
}
