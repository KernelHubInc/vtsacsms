<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Charging\Application\ChargerCommandStateMachine;
use App\Modules\Charging\Application\ChargingSessionStateMachine;
use App\Modules\Charging\Application\CommandOutcomeService;
use App\Modules\Charging\Application\ConnectorReservationService;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\ChargingSessionState;
use App\Modules\Charging\Domain\Models\AuthorizationToken;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Identity\Domain\ActorType;
use App\Modules\Tenancy\Application\CurrentTenant;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExpireChargingOperations extends Command
{
    protected $signature = 'charging:expire-operations';

    protected $description = 'Expire charger commands and remote starts whose deadlines elapsed.';

    public function handle(
        CurrentTenant $currentTenant,
        ChargerCommandStateMachine $commandStates,
        ChargingSessionStateMachine $sessionStates,
        CommandOutcomeService $outcomes,
        ConnectorReservationService $reservations,
    ): int {
        $expired = 0;
        $tenantIds = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->pluck('id');

        foreach ($tenantIds as $tenantId) {
            $expired += $currentTenant->run(new TenantContext(
                tenantId: (string) $tenantId,
                actorType: ActorType::Service,
                actorId: null,
                correlationId: (string) Str::ulid(),
            ), function () use ($commandStates, $sessionStates, $outcomes, $reservations): int {
                $count = 0;
                $commandIds = ChargerCommand::query()
                    ->whereIn('state', [ChargerCommandState::Requested->value, ChargerCommandState::Dispatched->value])
                    ->where('expires_at', '<=', now('UTC'))
                    ->pluck('id');

                foreach ($commandIds as $commandId) {
                    DB::transaction(function () use ($commandId, $commandStates, $outcomes, &$count): void {
                        $command = ChargerCommand::query()->whereKey($commandId)->lockForUpdate()->first();
                        if ($command === null || $command->state->isTerminal() || $command->expires_at->isFuture()) {
                            return;
                        }
                        $next = $command->state === ChargerCommandState::Requested
                            ? ChargerCommandState::Expired
                            : ChargerCommandState::TimedOut;
                        $commandStates->transition($command, $next, 'command_deadline_elapsed');
                        $outcomes->apply($command->refresh());
                        $count++;
                    });
                }

                $sessionIds = ChargingSession::query()
                    ->whereIn('state', [
                        ChargingSessionState::Requested->value,
                        ChargingSessionState::Authorizing->value,
                        ChargingSessionState::Authorized->value,
                        ChargingSessionState::Starting->value,
                    ])
                    ->whereNull('started_at')
                    ->whereNotNull('start_deadline_at')
                    ->where('start_deadline_at', '<=', now('UTC'))
                    ->pluck('id');

                foreach ($sessionIds as $sessionId) {
                    DB::transaction(function () use ($sessionId, $sessionStates, $reservations, &$count): void {
                        $session = ChargingSession::query()->whereKey($sessionId)->lockForUpdate()->first();
                        if ($session === null || $session->state->isTerminal() || $session->started_at !== null || $session->start_deadline_at?->isFuture()) {
                            return;
                        }
                        $sessionStates->transition($session, ChargingSessionState::Expired, 'start_deadline_elapsed');
                        $reservations->release((string) $session->getKey(), 'start_deadline_elapsed', true);
                        if ($session->authorization_token_id !== null) {
                            AuthorizationToken::query()->whereKey($session->authorization_token_id)->update([
                                'status' => 'revoked',
                                'revoked_at' => now('UTC'),
                                'updated_at' => now('UTC'),
                            ]);
                        }
                        $count++;
                    });
                }

                return $count;
            });
        }

        $this->components->info("Expired {$expired} charging operations.");

        return self::SUCCESS;
    }
}
