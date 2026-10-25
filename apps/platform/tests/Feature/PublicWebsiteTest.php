<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\CMS\Application\PublicContentRepository;
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
            $this->get($path)->assertOk()->assertSee('<main id="main-content"', false)
                ->assertDontSee('class="eyebrow"', false)
                ->assertDontSee('Demo');
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
            ->assertDontSee('AIza')
            ->assertDontSee('Demo environment')
            ->assertDontSee('data-demo-data', false);
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
        // The live map adds a schema check and the platform map-settings lookup.
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
        $this->assertLessThan(180_000, strlen((string) $response->getContent()));
    }

    public function test_homepage_uses_responsive_design_and_searches_the_live_map(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('One connected network, built place by place.')
            ->assertSee('branding/home/hero-desktop.png')
            ->assertSee('branding/home/hero-mobile.png')
            ->assertSee('action="'.route('public.charging-map').'"', false)
            ->assertSee('name="query"', false)
            ->assertSee('name="connector"', false)
            ->assertSee('name="availability"', false)
            ->assertSee('All statuses')
            ->assertSee('Published stations stay on the map when offline.')
            ->assertSee('data-home-map', false)
            ->assertSee('data-map-canvas', false)
            ->assertSee('data-station-list', false)
            ->assertSee('data-station-drawer', false)
            ->assertSee('A clearer view of charging.')
            ->assertSee('Good questions.')
            ->assertDontSee('site-footer__wordmark')
            ->assertDontSee('3 example stations')
            ->assertDontSee('Map illustration');

        foreach (['hero', 'driver', 'destination-1', 'destination-2', 'destination-3'] as $scene) {
            foreach (['desktop', 'mobile'] as $size) {
                $this->assertGreaterThan(0, filesize(public_path("branding/home/{$scene}-{$size}.png")));
            }
        }
    }

    public function test_station_detail_shows_a_compact_status_without_demo_copy(): void
    {
        $station = [
            'site_name' => 'Sample charging site',
            'address_line_1' => 'Sample address',
            'city_name' => null,
            'availability' => 'unknown',
            'is_stale' => false,
            'connectors' => [],
            'operator_name' => 'Sample operator',
            'site_type' => 'workplace',
            'open_now' => false,
            'amenities' => [],
        ];

        $html = view('public.station', [
            'page' => app(PublicContentRepository::class)->page('charging-map'),
            'station' => $station,
            'siteStations' => collect([$station]),
            'mapConfig' => [],
            'directionsUrl' => 'https://example.com/directions',
        ])->render();

        $this->assertStringContainsString('<h2>Availability</h2>', $html);
        $this->assertStringContainsString('branding/interface/status-unknown.svg', $html);
        $this->assertStringNotContainsString('Demo · Test data', $html);
        $this->assertStringNotContainsString('fictional location', $html);
        $this->assertStringNotContainsString('Milestone 1 page', $html);
    }
}
