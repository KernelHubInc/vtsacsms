<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Modules\Charging\Domain\ConnectorAvailability;
use App\Modules\Charging\Domain\Models\ConnectorReservation;
use App\Modules\Charging\Domain\Models\ConnectorStatus;

final readonly class ConnectorReservationService
{
    public function __construct(private ConnectorAvailabilityStateMachine $availability) {}

    public function release(string $sessionId, string $reason, bool $restoreAvailability = false): void
    {
        $reservation = ConnectorReservation::query()
            ->where('session_id', $sessionId)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->first();
        if ($reservation === null) {
            return;
        }

        $reservation->forceFill([
            'released_at' => now('UTC'),
            'release_reason' => $reason,
        ])->save();

        if (! $restoreAvailability || ConnectorReservation::query()
            ->where('connector_id', $reservation->connector_id)
            ->whereNull('released_at')
            ->where('expires_at', '>', now('UTC'))
            ->exists()) {
            return;
        }

        $status = ConnectorStatus::query()->where('connector_id', $reservation->connector_id)->lockForUpdate()->first();
        if ($status?->status === ConnectorAvailability::Reserved) {
            $this->availability->transition($status, ConnectorAvailability::Available, $reason, [
                'observed_at' => now('UTC'),
                'source' => 'platform_reservation',
            ]);
        }
    }
}
