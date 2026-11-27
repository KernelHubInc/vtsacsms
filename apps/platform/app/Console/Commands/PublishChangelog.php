<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Modules\CMS\Application\PublishChangelog as Publisher;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PublishChangelog extends Command
{
    protected $signature = 'cms:publish-changelog {tenant : Platform tenant ULID for the publication audit}';

    protected $description = 'Create the changelog publication without overwriting existing CMS edits.';

    public function handle(Publisher $publisher, CurrentTenant $tenant, AuditRecorder $audit): int
    {
        $tenantId = $this->argument('tenant');
        if (! is_string($tenantId) || ! Str::isUlid($tenantId)) {
            $this->error('Provide the existing platform tenant ULID.');

            return self::FAILURE;
        }
        $tenant->run(new TenantContext($tenantId, ActorType::Service, null, (string) Str::ulid()), function () use ($publisher, $audit): void {
            DB::transaction(function () use ($publisher, $audit): void {
                $page = $publisher->run();
                if ($page->wasRecentlyCreated) {
                    $audit->record(new AuditEntry('cms.changelog.published', 'cms_page', (string) $page->getKey(), AuditResult::Succeeded));
                }
            });
        });
        $this->info('Changelog publication ready; existing CMS edits preserved.');

        return self::SUCCESS;
    }
}
