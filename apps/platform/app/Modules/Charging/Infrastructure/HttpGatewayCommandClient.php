<?php

declare(strict_types=1);

namespace App\Modules\Charging\Infrastructure;

use App\Modules\Charging\Application\Gateway\GatewayCommandClient;
use App\Modules\Charging\Application\Gateway\GatewayCommandResult;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpGatewayCommandClient implements GatewayCommandClient
{
    public function dispatch(ChargerCommand $command): GatewayCommandResult
    {
        $token = config('services.ocpp_gateway.token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('OCPP gateway internal authentication is not configured.');
        }

        $payload = [...$command->payload, ...($command->secret_payload ?? [])];
        $timeout = max(1, min(120, (int) config('services.ocpp_gateway.timeout_seconds', 20)));
        $response = Http::baseUrl((string) config('services.ocpp_gateway.url'))
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout($timeout + 2)
            ->post('/internal/v1/commands', [
                'command_id' => (string) $command->getKey(),
                'tenant_id' => (string) $command->tenant_id,
                'charger_id' => (string) $command->charging_station_id,
                'charge_point_identity' => (string) $command->charge_point_identity,
                'action' => (string) $command->ocpp_action,
                'payload' => $payload,
                'correlation_id' => (string) $command->correlation_id,
                'actor_id' => (string) $command->actor_id,
                'reason_code' => (string) $command->reason_code,
                'expected_state' => $command->expected_state,
                'deadline_at' => $command->expires_at->utc()->toISOString(),
                'timeout_seconds' => $timeout,
            ]);

        $response->throw();
        $data = $response->json('data');
        if (! is_array($data) || ! is_string($data['status'] ?? null)) {
            throw new RuntimeException('OCPP gateway returned an invalid command result.');
        }

        return new GatewayCommandResult(
            status: $data['status'],
            response: is_array($data['response'] ?? null) ? $data['response'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
        );
    }
}
