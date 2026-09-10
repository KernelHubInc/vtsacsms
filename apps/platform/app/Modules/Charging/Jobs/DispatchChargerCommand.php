<?php

declare(strict_types=1);

namespace App\Modules\Charging\Jobs;

use App\Modules\Charging\Application\ChargerCommandStateMachine;
use App\Modules\Charging\Application\CommandOutcomeService;
use App\Modules\Charging\Application\Gateway\GatewayCommandClient;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Tenancy\Application\Queue\TenantAwareJob;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use App\Modules\Tenancy\Application\Queue\UseTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchChargerCommand implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $commandId,
        private readonly TenantJobEnvelope $envelope,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [app(UseTenantContext::class)];
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    public function handle(
        GatewayCommandClient $gateway,
        ChargerCommandStateMachine $states,
        CommandOutcomeService $outcomes,
    ): void {
        $command = DB::transaction(function () use ($states): ChargerCommand {
            $command = ChargerCommand::query()->whereKey($this->commandId)->lockForUpdate()->firstOrFail();
            if ($command->state->isTerminal()) {
                return $command;
            }
            if ($command->expires_at->isPast()) {
                $states->transition($command, ChargerCommandState::Expired, 'command_deadline_elapsed');

                return $command;
            }
            if ($command->state === ChargerCommandState::Requested) {
                $states->transition($command, ChargerCommandState::Dispatched, 'gateway_dispatch_started');
            }

            return $command;
        });

        if ($command->state->isTerminal()) {
            DB::transaction(fn () => $outcomes->apply($command));

            return;
        }

        try {
            $result = $gateway->dispatch($command);
            [$next, $reason] = match ($result->status) {
                'completed' => $this->protocolOutcome($result->response),
                'rejected' => [ChargerCommandState::Rejected, 'gateway_rejected_command'],
                'timed_out' => [ChargerCommandState::TimedOut, 'gateway_command_timeout'],
                'not_connected' => [ChargerCommandState::DeliveryUnknown, 'charger_not_connected'],
                default => [ChargerCommandState::DeliveryUnknown, 'gateway_delivery_failed'],
            };
            DB::transaction(function () use ($states, $outcomes, $next, $reason, $result): void {
                $locked = ChargerCommand::query()->whereKey($this->commandId)->lockForUpdate()->firstOrFail();
                if (! $locked->state->isTerminal()) {
                    $locked->forceFill([
                        'error_code' => $result->error === null ? null : 'gateway_result',
                        'error_message' => $result->error,
                    ])->save();
                    $states->transition($locked, $next, $reason, ['gateway_response' => $result->response]);
                    $outcomes->apply($locked);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($states, $outcomes, $exception): void {
                $locked = ChargerCommand::query()->whereKey($this->commandId)->lockForUpdate()->firstOrFail();
                if (! $locked->state->isTerminal()) {
                    $locked->forceFill([
                        'error_code' => 'gateway_transport_error',
                        'error_message' => str($exception->getMessage())->limit(500, ''),
                    ])->save();
                    $states->transition($locked, ChargerCommandState::DeliveryUnknown, 'gateway_transport_error');
                    $outcomes->apply($locked);
                }
            });
        }
    }

    /** @param array<string, mixed>|null $response
     * @return array{ChargerCommandState, string}
     */
    private function protocolOutcome(?array $response): array
    {
        $status = is_string($response['status'] ?? null) ? mb_strtolower($response['status']) : null;

        return $status === null || in_array($status, ['accepted', 'scheduled'], true)
            ? [ChargerCommandState::Acknowledged, 'charger_acknowledged_command']
            : [ChargerCommandState::Rejected, 'charger_rejected_command'];
    }
}
