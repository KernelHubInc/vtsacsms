<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Charging\Application\OcppAuthorizationService;
use App\Modules\Charging\Infrastructure\RedisOcppStreamClient;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;
use Throwable;

final class ConsumeOcppAuthorizations extends Command
{
    protected $signature = 'charging:consume-ocpp-authorizations {--once : Read one bounded batch and exit}';

    protected $description = 'Answer charger authorization requests from the OCPP gateway.';

    public function handle(
        RedisOcppStreamClient $redis,
        CurrentTenant $currentTenant,
        OcppAuthorizationService $authorizations,
    ): int {
        $stream = (string) config('services.ocpp_gateway.authorization_stream');
        $group = (string) config('services.ocpp_gateway.consumer_group').'-authorization';
        $consumerName = gethostname().'-'.getmypid();
        $redis->ensureGroup($stream, $group);

        do {
            $messages = $redis->read($stream, $group, $consumerName, 25, $this->option('once') ? 1 : 5000);
            foreach ($messages as $message) {
                $request = $this->decode($message['fields']['event'] ?? null);
                $requestId = $request['request_id'] ?? null;
                if (! is_string($requestId) || ! Ulid::isValid($requestId)) {
                    $redis->acknowledge($stream, $group, $message['id']);

                    continue;
                }
                $response = ['status' => 'Invalid', 'expires_at' => null, 'parent_token' => null];
                $tenantId = $request['tenant_id'] ?? null;
                if (is_string($tenantId) && Ulid::isValid($tenantId) && $this->tenantIsActive($tenantId)) {
                    $correlationId = is_string($request['correlation_id'] ?? null) && Ulid::isValid($request['correlation_id'])
                        ? $request['correlation_id']
                        : (string) Str::ulid();
                    $response = $currentTenant->run(new TenantContext(
                        tenantId: $tenantId,
                        actorType: ActorType::Service,
                        actorId: null,
                        correlationId: $correlationId,
                    ), fn (): array => $authorizations->authorize($request));
                }
                $redis->respondToAuthorization($requestId, $response);
                $redis->acknowledge($stream, $group, $message['id']);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function decode(?string $json): array
    {
        try {
            $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function tenantIsActive(string $tenantId): bool
    {
        return Tenant::query()->whereKey($tenantId)->where('status', TenantStatus::Active->value)->exists();
    }
}
