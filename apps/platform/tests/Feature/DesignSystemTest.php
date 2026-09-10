<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class DesignSystemTest extends TestCase
{
    public function test_visual_catalog_renders_the_foundation_inventory(): void
    {
        $response = $this->get('/design-system');

        $response
            ->assertOk()
            ->assertSee('Designed for clear decisions.')
            ->assertSee('Operational state')
            ->assertSee('data-dialog-open="catalog-confirmation"', false)
            ->assertSee('aria-label="Primary"', false);
    }

    public function test_form_field_exposes_programmatic_error_state(): void
    {
        $html = Blade::render(
            '<x-ui.form-field name="connector" label="Connector" error="Check the identifier." required />',
        );

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="connector-error"', $html);
        $this->assertStringContainsString('<span class="sr-only">required</span>', $html);
    }

    public function test_status_and_feedback_components_keep_text_semantics(): void
    {
        $status = Blade::render('<x-ui.status-chip label="Charging" tone="charging" />');
        $toast = Blade::render('<x-ui.toast title="Updated" description="Fresh evidence retrieved." />');

        $this->assertStringContainsString('Charging', $status);
        $this->assertStringContainsString('role="status"', $toast);
        $this->assertStringContainsString('aria-label="Dismiss notification"', $toast);
    }
}
