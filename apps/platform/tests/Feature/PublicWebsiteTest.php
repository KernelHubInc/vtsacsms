<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\CMS\Domain\Models\CmsRedirect;
use Database\Seeders\PublicCmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        $this->seed(PublicCmsSeeder::class);
    }

    public function test_every_required_public_page_is_published_from_cms(): void
    {
        foreach (['/', '/network', '/charging-map', '/drivers', '/operators-and-site-hosts', '/installation-and-maintenance', '/mobile-app', '/news-and-resources', '/partner-program', '/frequently-asked-questions', '/contact', '/support', '/privacy-policy', '/terms-of-service', '/charging-terms', '/refund-policy'] as $path) {
            $this->get($path)->assertOk()->assertSee('<main id="main-content"', false);
        }
    }

    public function test_map_has_accessible_fallback_filters_and_no_hard_coded_key(): void
    {
        $this->get('/charging-map')->assertOk()
            ->assertSee('public-site public-site--map', false)
            ->assertSee('data-filter-panel', false)
            ->assertSee('aria-label="Filter charging stations"', false)
            ->assertSee('aria-label="Charging station results"', false)
            ->assertSee('aria-label="Map quick links"', false)
            ->assertSee('data-station-drawer hidden role="dialog"', false)
            ->assertSee('data-map-unavailable', false)
            ->assertSee('Connector')->assertSee('Minimum power')->assertSee('Availability')
            ->assertDontSee('AIza');
    }

    public function test_refund_placeholder_is_noindex_and_cannot_look_approved(): void
    {
        $this->get('/refund-policy')->assertOk()
            ->assertSee('content="noindex, nofollow"', false)
            ->assertSee('Legal review required')
            ->assertSee('not approved policy');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/refund-policy');
    }

    public function test_redirects_are_exact_managed_paths_and_record_usage(): void
    {
        $redirect = CmsRedirect::query()->create(['source_path' => '/old-network', 'destination_url' => '/network', 'status_code' => 301, 'is_enabled' => true]);
        $this->get('/old-network?source=campaign')->assertRedirect('/network')->assertStatus(301);
        $this->assertSame(1, $redirect->refresh()->hit_count);
        $this->assertNotNull($redirect->last_hit_at);
    }

    public function test_public_homepage_stays_within_query_and_payload_budget(): void
    {
        Cache::clear();
        DB::enableQueryLog();
        $response = $this->get('/')->assertOk();
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        $this->assertLessThan(180_000, strlen((string) $response->getContent()));
    }
}
