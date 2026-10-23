<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Tests\TestCase;

final class AdminThemeTest extends TestCase
{
    public function test_admin_and_operator_panels_use_matching_logos_for_each_appearance(): void
    {
        foreach (['admin', 'operator'] as $panelId) {
            $panel = Filament::getPanel($panelId);

            self::assertSame(asset('branding/power-solutions-logo-horizontal.svg'), $panel->getBrandLogo());
            self::assertSame(asset('branding/power-solutions-logo-horizontal-dark.svg'), $panel->getDarkModeBrandLogo());
            self::assertSame(ThemeMode::System, $panel->getDefaultThemeMode());
        }
    }
}
