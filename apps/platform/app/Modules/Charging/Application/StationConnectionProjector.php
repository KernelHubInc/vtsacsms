<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Assets\Application\GatewayStationQuery;
use App\Modules\Charging\Domain\Models\StationConnection;
use Carbon\CarbonImmutable;
use DomainException;
use Symfony\Component\Uid\Ulid;

final readonly class StationConnectionProjector
{
    public function __construct(private GatewayStationQuery $stations) {}

    /** @param array<string, mixed> $event */
    public function observe(array $event, string $action): bool
    {
        $data = $event['data'];
        $this->stations->validateAndLock((string) $event['aggregate_id'], (string) ($data['charge_point_identity'] ?? ''), (string) ($data['protocol'] ?? ''));
        $connectionId = $data['connection_id'] ?? null;
        if (! is_string($connectionId) || ! Ulid::isValid($connectionId)) {
            throw new DomainException('OCPP connection ID must be a ULID.');
        }
        $at = CarbonImmutable::parse((string) $event['occurred_at'])->utc();
        $row = StationConnection::query()->where('charging_station_id', $event['aggregate_id'])->lockForUpdate()->first();
        if ($row !== null) {
            // A replaced socket may close after its successor is already online.
            if ($connectionId < $row->connection_id) {
                return false;
            }
            if ($connectionId === $row->connection_id && $at->lessThan($row->last_event_at)) {
                if ($row->connected && $action === 'charger_connected' && $at->lessThan($row->connected_at)) {
                    $row->forceFill(['connected_at' => $at])->save();
                }

                return $row->connected;
            }
            if ($connectionId === $row->connection_id && ! $row->connected && $action !== 'charger_disconnected') {
                return false;
            }
        }
        $newConnection = $row === null || $connectionId !== $row->connection_id;
        $row ??= new StationConnection(['charging_station_id' => $event['aggregate_id']]);
        $row->forceFill([
            'connection_id' => $connectionId,
            'connected' => $action !== 'charger_disconnected',
            'connected_at' => $newConnection ? $at : $row->connected_at,
            'last_event_at' => $at,
            'last_seen_at' => $action === 'charger_disconnected' && ! $newConnection ? $row->last_seen_at : $at,
        ])->save();

        return true;
    }
}
