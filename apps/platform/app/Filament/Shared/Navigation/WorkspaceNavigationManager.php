<?php

declare(strict_types=1);

namespace App\Filament\Shared\Navigation;

use App\Models\User;
use App\Modules\Identity\Application\SidebarPreferences;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationManager;

final class WorkspaceNavigationManager extends NavigationManager
{
    /** @return array<NavigationGroup> */
    public function get(): array
    {
        $navigation = parent::get();
        $user = $this->panel->auth()->user();
        $layout = $user instanceof User ? app(SidebarPreferences::class)->load($user, $this->panel->getId()) : [];

        return $layout === [] ? $navigation : app(SidebarLayout::class)->apply($navigation, $layout);
    }
}
