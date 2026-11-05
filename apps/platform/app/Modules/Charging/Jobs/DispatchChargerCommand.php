<?php

declare(strict_types=1);

namespace App\Modules\Charging\Jobs;

use App\Modules\Charging\Application\ChargerCommandStateMachine;
use App\Modules\Charging\Application\CommandOutcomeService;
use App\Modules\Charging\Application\Gateway\GatewayCommandClient;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargerCommandType;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Tenancy\Application\Queue\TenantJobEnvelope;
use App\Modules\Tenancy\Application\Queue\TenantServiceJob;
use App\Modules\Tenancy\Application\Queue\UseTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchChargerCommand implements ShouldQueue, TenantServiceJob
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $commandId,
        private readonly TenantJobEnvelope $envelope,
    ) {}

    /** @return list<UseTenantContext> */
    public function middleware(): array
    {
        return [app(UseTenantContext::class)];
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    public function serviceContextIsAuthorized(): bool
    {
        $command = ChargerCommand::query()->find($this->commandId);
        if ($command === null || $this->envelope->causationId !== $this->commandId
            || $command->type !== ChargerCommandType::RemoteStop) {
            return false;
        }
        $session = $command->session()->first();
        $owner = CustomerChargingSession::query()->with('quote')->where('session_id', $command->session_id)->first();
        $quote = $owner?->quote()->first();
        if ($session === null || $owner === null || $quote === null || ! $command->targetsTransaction($session)
            || $command->actor_id !== $owner->subject_id || $quote->subject_id !== $owner->subject_id
            || $quote->connector_id !== $session->connector_id || $owner->budget_minor <= 0
            || $quote->budget_minor !== $owner->budget_minor) {
            return false;
        }

        return $command->reason_code === 'prepaid.spending_limit_or_stale_meter'
            || ($command->reason_code === 'driver.cancel_pending_start' && $session->cancellation_reason === 'customer_cancel_requested');
    }

    public function handle(
        GatewayCommandClient $gateway,
        ChargerCommandStateMachine $states,
        CommandOutcomeService $outcomes,
    ): void {
        $command = DB::transaction(function () use ($states): ?ChargerCommand {
            $command = $this->lockedCommand();
            $session = $command->session()->firstOrFail();
            if ($command->state->isTerminal()) {
                return $command;
            }
            // Duplicate queue delivery must not send a second wire command while the first is in flight.
            if ($command->state !== ChargerCommandState::Requested) {
                return null;
            }
            if ($command->expires_at->isPast()) {
                $states->transition($command, ChargerCommandState::Expired, 'command_deadline_elapsed');

                return $command;
            }
            if ($command->type === ChargerCommandType::RemoteStop
                && (! $command->targetsTransaction($session) || $session->stopped_at !== null
                    || (! $session->state->isPhysicallyActive() && $session->state->value !== 'starting'))) {
                $states->transition($command, ChargerCommandState::Rejected, 'stop_target_no_longer_active');

                return null;
            }
            $states->transition($command, ChargerCommandState::Dispatched, 'gateway_dispatch_started');

            return $command;
        });

        if ($command === null) {
            return;
        }

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
                $locked = $this->lockedCommand();
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
                $locked = $this->lockedCommand();
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

    private function lockedCommand(): ChargerCommand
    {
        $sessionId = ChargerCommand::query()->whereKey($this->commandId)->value('session_id');
        // Match the monitor's session-before-command order, including after the gateway call.
        ChargingSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();

        return ChargerCommand::query()->whereKey($this->commandId)->lockForUpdate()->firstOrFail();
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
