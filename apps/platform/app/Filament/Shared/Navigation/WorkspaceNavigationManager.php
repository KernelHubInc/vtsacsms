<?php

declare(strict_types=1);

namespace App\Filament\Shared\Navigation;

use App\Models\User;
use App\Modules\Identity\Application\SidebarPreferences;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationManager;

final class WorkspaceNavigationManager extends NavigationManager
{
    private bool $applyPreferences = true;

    /** @return array<NavigationGroup> */
    public function get(): array
    {
        $navigation = parent::get();
        if (! $this->applyPreferences) {
            return $navigation;
        }

        $user = $this->panel->auth()->user();
        $layout = $user instanceof User ? app(SidebarPreferences::class)->load($user, $this->panel->getId()) : [];

        return $layout === [] ? $navigation : app(SidebarLayout::class)->apply($navigation, $layout);
    }

    /** @return array<NavigationGroup> */
    public function defaults(): array
    {
        $this->applyPreferences = false;

        try {
            return $this->panel->getNavigation();
        } finally {
            $this->applyPreferences = true;
        }
    }
}
