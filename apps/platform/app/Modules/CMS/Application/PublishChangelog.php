<?php

declare(strict_types=1);

namespace App\Modules\CMS\Application;

use App\Modules\CMS\Domain\Models\CmsPage;
use App\Modules\CMS\Domain\Models\SeoMetadata;
use Illuminate\Support\Facades\DB;

final class PublishChangelog
{
    public function run(): CmsPage
    {
        return DB::transaction(function (): CmsPage {
            $page = CmsPage::query()->firstOrCreate(['slug' => 'changelog'], [
                'title' => 'Changelog', 'eyebrow' => 'Platform updates',
                'summary' => 'Recent changes to EVCS Power Solutions, with a clear record of what is available.',
                'hero_heading' => 'What has changed.', 'template' => 'changelog',
                'status' => 'published', 'legal_review_required' => false, 'published_at' => now('UTC'),
            ]);
            SeoMetadata::query()->firstOrCreate(['target_type' => 'page', 'target_id' => $page->getKey()], [
                'meta_title' => 'Changelog | EVCS Power Solutions',
                'meta_description' => $page->summary,
                'noindex' => ! app()->environment('production'),
            ]);

            return $page;
        });
    }
}
