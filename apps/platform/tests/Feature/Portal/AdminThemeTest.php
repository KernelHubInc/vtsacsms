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

            self::assertSame(asset('branding/power-solutions-logo.png?v=20261103-theme'), $panel->getBrandLogo());
            self::assertSame(asset('branding/power-solutions-logo-white.png?v=20261103-theme'), $panel->getDarkModeBrandLogo());
            self::assertSame(ThemeMode::System, $panel->getDefaultThemeMode());
        }
    }

    public function test_shared_logo_supports_theme_changes_and_explicit_dark_surfaces(): void
    {
        $this->blade('<x-brand.logo />')
            ->assertSee('brand-logo-light', false)
            ->assertSee('brand-logo-dark', false)
            ->assertSee('power-solutions-logo.png?v=20261103-theme', false)
            ->assertSee('power-solutions-logo-white.png?v=20261103-theme', false);

        $this->blade('<x-brand.logo inverse />')
            ->assertSee('power-solutions-logo-white.png?v=20261103-theme', false)
            ->assertDontSee('brand-logo-light', false);
    }
}
