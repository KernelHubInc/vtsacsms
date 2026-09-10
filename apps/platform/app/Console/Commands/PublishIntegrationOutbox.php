<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Domain\ActorType;
use App\Modules\Integrations\Domain\Models\OutboxEvent;
use App\Modules\Integrations\Infrastructure\RedisOutboxPublisher;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class PublishIntegrationOutbox extends Command
{
    protected $signature = 'integrations:publish-outbox {--limit=100 : Maximum events per tenant}';

    protected $description = 'Publish committed integration outbox events to the approved Redis Stream.';

    public function handle(CurrentTenant $currentTenant, RedisOutboxPublisher $publisher): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $failures = 0;
        $published = 0;

        foreach (Tenant::query()->where('status', TenantStatus::Active->value)->pluck('id') as $tenantId) {
            [$tenantPublished, $tenantFailures] = $currentTenant->run(new TenantContext(
                tenantId: (string) $tenantId,
                actorType: ActorType::Service,
                actorId: null,
                correlationId: (string) Str::ulid(),
            ), function () use ($publisher, $limit): array {
                $published = 0;
                $failures = 0;
                $events = OutboxEvent::query()
                    ->whereNull('published_at')
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->limit($limit)
                    ->get();

                foreach ($events as $event) {
                    try {
                        // Publish outside a database transaction; a crash before marking creates an allowed duplicate.
                        $publisher->publish($event);
                        OutboxEvent::query()
                            ->whereKey($event->getKey())
                            ->whereNull('published_at')
                            ->update([
                                'published_at' => now('UTC'),
                                'publish_attempts' => (int) $event->publish_attempts + 1,
                                'updated_at' => now('UTC'),
                            ]);
                        $published++;
                    } catch (Throwable $exception) {
                        report($exception);
                        OutboxEvent::query()->whereKey($event->getKey())->increment('publish_attempts');
                        $failures++;
                    }
                }

                return [$published, $failures];
            });
            $published += $tenantPublished;
            $failures += $tenantFailures;
        }

        $this->components->info("Published {$published} outbox events; {$failures} failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
