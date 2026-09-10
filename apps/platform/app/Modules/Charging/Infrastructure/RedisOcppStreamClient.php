<?php

declare(strict_types=1);

namespace App\Modules\Charging\Infrastructure;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class RedisOcppStreamClient
{
    public function ensureGroup(string $stream, string $group): void
    {
        try {
            $this->connection()->command('xgroup', ['CREATE', $stream, $group, '0', true]);
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw $exception;
            }
        }
    }

    /** @return list<array{id: string, fields: array<string, string>}> */
    public function read(string $stream, string $group, string $consumer, int $count, int $blockMilliseconds): array
    {
        $result = $this->connection()->command('xreadgroup', [
            $group,
            $consumer,
            [$stream => '>'],
            $count,
            $blockMilliseconds,
        ]);
        if (! is_array($result) || ! is_array($result[$stream] ?? null)) {
            return [];
        }

        $messages = [];
        foreach ($result[$stream] as $id => $fields) {
            if (is_string($id) && is_array($fields)) {
                $messages[] = ['id' => $id, 'fields' => array_filter($fields, 'is_string')];
            }
        }

        return $messages;
    }

    public function acknowledge(string $stream, string $group, string $id): void
    {
        $this->connection()->command('xack', [$stream, $group, [$id]]);
    }

    /** @param array<string, mixed> $response */
    public function respondToAuthorization(string $requestId, array $response): void
    {
        $key = (string) config('services.ocpp_gateway.redis_key_prefix').':authorization-response:'.$requestId;
        $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $connection = $this->connection();
        $connection->command('rpush', [$key, $json]);
        $connection->command('expire', [$key, 30]);
    }

    private function connection(): Connection
    {
        return Redis::connection('ocpp_gateway');
    }
}
