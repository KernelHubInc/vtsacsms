<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Charging\Application\OcppEventConsumer;
use App\Modules\Charging\Infrastructure\RedisOcppStreamClient;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Uid\Ulid;

final class ConsumeOcppEvents extends Command
{
    protected $signature = 'charging:consume-ocpp-events {--once : Read one bounded batch and exit}';

    protected $description = 'Consume normalized OCPP gateway events into the Charging context.';

    public function handle(
        RedisOcppStreamClient $redis,
        CurrentTenant $currentTenant,
        OcppEventConsumer $consumer,
    ): int {
        $stream = (string) config('services.ocpp_gateway.event_stream');
        $group = (string) config('services.ocpp_gateway.consumer_group');
        $consumerName = gethostname().'-'.getmypid();
        $redis->ensureGroup($stream, $group);

        do {
            $messages = $redis->read($stream, $group, $consumerName, 25, $this->option('once') ? 1 : 5000);
            foreach ($messages as $message) {
                $event = $this->decode($message['fields']['event'] ?? null);
                $tenantId = $event['tenant_id'] ?? null;
                if (! is_string($tenantId) || ! Ulid::isValid($tenantId) || ! $this->tenantIsActive($tenantId)) {
                    $this->components->error('OCPP event has no active tenant binding; message remains pending.');

                    continue;
                }
                $correlationId = is_string($event['correlation_id'] ?? null) && Ulid::isValid($event['correlation_id'])
                    ? $event['correlation_id']
                    : (string) Str::ulid();
                $result = $currentTenant->run(new TenantContext(
                    tenantId: $tenantId,
                    actorType: ActorType::Service,
                    actorId: null,
                    correlationId: $correlationId,
                ), fn () => $consumer->consume($event));
                $redis->acknowledge($stream, $group, $message['id']);
                $this->components->info("{$message['id']}: {$result->outcome}");
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** @return array<string, mixed>
     * @throws JsonException
     */
    private function decode(?string $json): array
    {
        if ($json === null) {
            throw new JsonException('Redis Stream entry has no event field.');
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new JsonException('Redis Stream event must be a JSON object.');
        }

        return $decoded;
    }

    private function tenantIsActive(string $tenantId): bool
    {
        return Tenant::query()->whereKey($tenantId)->where('status', TenantStatus::Active->value)->exists();
    }
}
