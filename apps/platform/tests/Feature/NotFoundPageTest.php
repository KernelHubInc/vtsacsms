<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_web_page_shows_branded_recovery_with_a_real_404_status(): void
    {
        $this->get('/missing-power-solutions-page?token=private-value')
            ->assertNotFound()
            ->assertSee('Page not found | Power Solutions')
            ->assertSee('power-solutions-logo-horizontal.svg')
            ->assertSee('Go back to homepage')
            ->assertSee('Error 404.')
            ->assertSee('id="error-title">Page <span>not found.</span>', false)
            ->assertDontSee('eyebrow')
            ->assertDontSee('Find your way forward')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('content="noindex, nofollow"', false)
            ->assertDontSee('private-value');
    }

    public function test_json_not_found_responses_are_not_replaced_with_html(): void
    {
        $this->getJson('/api/v1/missing-power-solutions-endpoint')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message'])
            ->assertDontSee('<!DOCTYPE html>', false);
    }
}
