<?php

declare(strict_types=1);

namespace App\Modules\Charging\Events;

use App\Modules\Charging\Domain\Models\ChargingSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ChargingSessionProjectionUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /** @var array<string, mixed> */
    public readonly array $projection;

    public function __construct(ChargingSession $session)
    {
        $this->projection = [
            'id' => (string) $session->getKey(),
            'tenant_id' => (string) $session->tenant_id,
            'connector_id' => (string) $session->connector_id,
            'state' => $session->state->value,
            'energy_wh' => (int) $session->energy_wh,
            'duration_seconds' => (int) $session->duration_seconds,
            'estimated_cost_minor' => $session->estimated_cost_minor,
            'final_cost_minor' => $session->final_cost_minor,
            'currency' => $session->currency,
            'updated_at' => $session->updated_at?->utc()->toISOString(),
        ];
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenants.'.$this->projection['tenant_id'].'.charging-sessions'),
            new PrivateChannel('tenants.'.$this->projection['tenant_id'].'.charging-sessions.'.$this->projection['id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'charging.session.updated.v1';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['data' => $this->projection];
    }
}
