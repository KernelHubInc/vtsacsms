<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\CMS\Application\PublishChangelog;
use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Database\Seeders\PublicCmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 27)->startOfDay());
    }

    public function test_production_only_displays_released_production_notes_and_ignores_query_overrides(): void
    {
        $this->app['env'] = 'production';
        $tenant = Tenant::factory()->create();
        $this->assertSame(0, Artisan::call('cms:publish-changelog', ['tenant' => $tenant->getKey()]));
        $this->assertSame(0, Artisan::call('cms:publish-changelog', ['tenant' => $tenant->getKey()]));
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'cms.changelog.published', 'tenant_id' => $tenant->getKey()]);
        $this->get('/changelog?environment=staging')->assertOk()
            ->assertSee('Platform updates · Production')->assertSee('Support ticket controls for administrators')
            ->assertDontSee('Email alerts for Level 3 support')->assertDontSee('staging.evcspowersolutions.com')
            ->assertSee('<meta name="robots" content="index, follow">', false);
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('public.changelog'));
    }

    public function test_staging_displays_its_own_notes_and_cannot_be_indexed_even_if_cms_seo_is_changed(): void
    {
        $this->app['env'] = 'staging';
        $page = app(PublishChangelog::class)->run();
        $page->seo()->update(['noindex' => false]);
        $this->get('/changelog')->assertOk()->assertSee('Platform updates · Staging')
            ->assertSee('Email alerts for Level 3 support')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_publication_is_idempotent_and_preserves_editor_changes_and_withdrawal(): void
    {
        $publisher = app(PublishChangelog::class);
        $page = $publisher->run();
        $page->update(['hero_heading' => 'Editorial title', 'status' => 'draft']);
        $page->seo()->update(['meta_title' => 'Editorial metadata']);
        $publisher->run();
        $this->assertSame(1, CmsPage::query()->where('slug', 'changelog')->count());
        $this->assertSame('Editorial title', $page->refresh()->hero_heading);
        $this->assertSame('Editorial metadata', $page->seo->meta_title);
        $this->get('/changelog')->assertNotFound();
    }

    public function test_future_notes_are_hidden_and_content_is_escaped(): void
    {
        app(PublishChangelog::class)->run();
        config()->set('changelog.releases', [
            ['id' => 'future', 'date' => '2026-11-28', 'environments' => ['staging'], 'title' => 'Future release', 'changes' => []],
            ['id' => 'current', 'date' => '2026-11-27', 'environments' => ['staging'], 'title' => '<script>unsafe()</script>', 'changes' => [['type' => 'Added', 'text' => '<img src=x onerror=unsafe()>']]],
        ]);
        $this->get('/changelog')->assertOk()->assertDontSee('Future release')
            ->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)
            ->assertDontSee('<img src=x', false);
        $this->travelTo(now('UTC')->addDay());
        $this->get('/changelog')->assertOk()->assertSee('Future release');
    }

    public function test_empty_history_and_website_navigation(): void
    {
        $this->seed(PublicCmsSeeder::class);
        config()->set('changelog.releases', []);
        $this->get('/changelog')->assertOk()->assertSee('No release notes have been published yet.');
        $this->get('/')->assertOk()->assertSee('href="'.route('public.changelog').'"', false);
    }

    public function test_invalid_publication_context_does_not_write_content(): void
    {
        $this->assertSame(1, Artisan::call('cms:publish-changelog', ['tenant' => 'invalid']));
        $this->assertDatabaseCount('cms_pages', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
