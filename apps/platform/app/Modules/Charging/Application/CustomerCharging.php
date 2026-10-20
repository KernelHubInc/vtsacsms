<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

use App\Foundation\Audit\AuditEntry;
use App\Foundation\Audit\AuditRecorder;
use App\Foundation\Audit\AuditResult;
use App\Models\User;
use App\Modules\Assets\Application\ConnectorSnapshotQuery;
use App\Modules\Assets\Application\DriverVehicles;
use App\Modules\Billing\Application\PrepaidWallet;
use App\Modules\Charging\Domain\ChargerCommandState;
use App\Modules\Charging\Domain\Models\AuthorizationToken;
use App\Modules\Charging\Domain\Models\ChargerCommand;
use App\Modules\Charging\Domain\Models\ChargingQuote;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\PermissionKey;
use App\Modules\Tariffs\Application\RatingInput;
use App\Modules\Tariffs\Application\TariffEngine;
use App\Modules\Tariffs\Application\TariffSelector;
use App\Modules\Tariffs\Application\TariffSnapshotFactory;
use App\Modules\Tenancy\Application\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CustomerCharging
{
    public function __construct(private CurrentTenant $tenant, private ConnectorSnapshotQuery $connectors, private PrepaidWallet $wallet) {}

    /** @return array<string, mixed> */
    public function prepare(string $subject, string $code, ?string $accountId = null, ?string $vehicleId = null): array
    {
        $user = User::query()->where('public_id', $subject)->firstOrFail();
        $accounts = app(ChargingAccounts::class);
        $account = $accounts->require($user, $accountId ?? (string) $accounts->personal($user)->getKey(), PermissionKey::ChargingAccountCharge);
        $accountId = (string) $account->getKey();
        $payer = $account->charging_account_type === 'personal' ? null : $accountId;
        if ($payer !== null && $vehicleId === null) {
            throw new DomainException('Select an assigned account vehicle before charging.');
        }
        $vehicle = $vehicleId === null ? null : app(DriverVehicles::class)->chargeVehicle($subject, $accountId, $vehicleId);
        $target = $this->connectors->publicChargeTarget($code) ?? throw new DomainException('Use the code for one public, active connector.');
        $connector = $this->connectors->byId($target['connector_id']) ?? throw new DomainException('Connector is unavailable.');
        $at = CarbonImmutable::now('UTC');
        $version = app(TariffSelector::class)->select($connector, $at) ?? throw new DomainException('This connector has no published price.');
        if ($version->tariff->currency !== 'PHP') {
            throw new DomainException('The prepaid wallet supports PHP charging only.');
        }
        $line = $version->components->first();
        if ($version->components->count() !== 1 || $line === null || $version->discounts->isNotEmpty()
            || ! in_array($line->dimension->value, ['energy', 'time', 'session'], true)
            || (int) $line->day_of_week_mask !== 127 || $line->starts_at_local !== null || $line->ends_at_local !== null
            || $version->minimum_fee_minor !== null || $version->maximum_fee_minor !== null) {
            throw new DomainException('Mobile prepaid charging currently requires one all-day energy, session or energy-flow time price without discounts or extra fees.');
        }
        $snapshot = app(TariffSnapshotFactory::class)->create($version, $connector, $at)['snapshot'];
        $balance = $this->wallet->summary($subject, 'live', $payer);
        $budget = min((int) $balance['available_minor'], (int) config('mobile_charging.maximum_reservation_minor'));
        $budget = $this->wallet->sessionCap($payer, $subject, $vehicleId, $budget);
        if ($budget <= 0) {
            throw new DomainException('The selected wallet has no available charging budget. Ask its owner to fund it or review your spending limit.');
        }
        $minimum = app(TariffEngine::class)->rate($snapshot, new RatingInput(0, 0, 0, 0, $at));
        if ($minimum->totalMinor > $budget) {
            throw new DomainException('Your available charging budget is below the session fee.');
        }
        $quote = ChargingQuote::query()->create([
            'subject_id' => $subject, 'connector_id' => $connector->connectorId,
            'charging_account_id' => $accountId, 'vehicle_id' => $vehicleId, 'payer_account_id' => $payer, 'prepaid_account_id' => $balance['id'],
            'tariff_version_id' => $version->getKey(), 'budget_minor' => $budget,
            'disclosure' => ['target' => $target, 'tariff' => $snapshot, 'attribution' => [
                'driver_id' => $subject, 'driver_name' => $user->name, 'account_id' => $accountId, 'account_name' => $account->name,
                'vehicle' => $vehicle, 'payer' => $payer === null ? 'Personal prepaid wallet' : $account->name.' prepaid wallet',
                'wallet_id' => $balance['id'], 'limit_period' => 'per_session']],
            'expires_at' => $at->addSeconds((int) config('mobile_charging.quote_seconds')),
        ]);

        return ['target' => $target, 'attribution' => $quote->disclosure['attribution'], 'quote' => [
            'id' => (string) $quote->getKey(), 'tariff_version_id' => (string) $version->getKey(),
            'name' => $version->tariff->name, 'currency' => 'PHP', 'tax_inclusive' => $version->tax_treatment->value === 'inclusive',
            'tax_rate_basis_points' => (int) $version->tax_rate_basis_points,
            'lines' => $version->components->map(fn ($line): array => [
                'dimension' => $line->dimension->value, 'price_minor' => (int) $line->price_minor,
                'unit_quantity' => (int) $line->unit_quantity,
                'description' => match ($line->dimension->value) {
                    'energy' => 'Per '.($line->unit_quantity === 1000 ? 'kWh' : $line->unit_quantity.' Wh'),
                    'session' => 'Once per session',
                    'time' => 'Per '.$line->unit_quantity.' seconds of metered energy flow',
                    default => $line->dimension->value.' / '.$line->unit_quantity.' seconds',
                },
            ])->all(),
            'estimated_preauthorization' => ['amount_minor' => $budget, 'currency' => 'PHP'],
            'expires_at' => $quote->expires_at->toISOString(),
        ], 'payment_methods' => [['id' => 'prepaid_wallet', 'label' => $quote->disclosure['attribution']['payer'].' (PHP)', 'is_default' => true]]];
    }

    public function start(string $subject, string $quoteId, string $connectorId, string $key, ?string $selectedAccount = null): ChargingSession
    {
        return DB::transaction(function () use ($subject, $quoteId, $connectorId, $key, $selectedAccount): ChargingSession {
            $tenant = $this->tenant->get()->tenantId;
            DB::table('charging_customer_guards')->insertOrIgnore(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'subject_id' => $subject]);
            DB::table('charging_customer_guards')->where('tenant_id', $tenant)->where('subject_id', $subject)->lockForUpdate()->first();
            $hash = hash('sha256', $key);
            $existing = CustomerChargingSession::query()->where('subject_id', $subject)->where('idempotency_hash', $hash)->first();
            if ($existing !== null) {
                if ($selectedAccount !== null && $existing->charging_account_id !== $selectedAccount) {
                    throw new DomainException('This request belongs to another charging account.');
                }
                $session = $this->owned($subject, (string) $existing->session_id);
                if ($existing->quote_id !== $quoteId || $session->connector_id !== $connectorId) {
                    throw new DomainException('This request key belongs to a different charging request.');
                }

                return $session;
            }
            if ($this->active($subject) !== null) {
                throw new DomainException('Finish your current charging session before starting another.');
            }
            $quote = ChargingQuote::query()->where('subject_id', $subject)->whereKey($quoteId)->lockForUpdate()->firstOrFail();
            if ($selectedAccount !== null && $quote->charging_account_id !== $selectedAccount) {
                throw new DomainException('The selected account changed. Review a fresh quote.');
            }
            if ($quote->charging_account_id !== null) {
                app(ChargingAccounts::class)->require(User::query()->where('public_id', $subject)->firstOrFail(), $quote->charging_account_id,
                    PermissionKey::ChargingAccountCharge, true);
            }
            if ($quote->vehicle_id !== null) {
                app(DriverVehicles::class)->chargeVehicle($subject, $quote->charging_account_id, $quote->vehicle_id);
            }
            if ($quote->expires_at->isPast() || $quote->connector_id !== $connectorId
                || CustomerChargingSession::query()->where('quote_id', $quoteId)->exists()) {
                throw new DomainException('This quote has expired or was already used. Review a fresh price.');
            }
            $target = $this->connectors->publicChargeTarget($connectorId);
            if (($target['readiness'] ?? null) !== 'available') {
                throw new DomainException('The connector is not ready to start.');
            }
            $connector = $this->connectors->byId($connectorId) ?? throw new DomainException('Connector is unavailable.');
            $current = app(TariffSelector::class)->select($connector, CarbonImmutable::now('UTC'));
            if ($current === null || $current->getKey() !== $quote->tariff_version_id) {
                throw new DomainException('The price changed. Review a fresh quote before starting.');
            }
            $result = app(RemoteStartService::class)->request($connectorId, 'mobile-start-'.hash('sha256', $subject.':'.$key), (string) $quote->tariff_version_id);
            $this->wallet->reserve($subject, 'live', (string) $result->session->getKey(), (int) $quote->budget_minor, $quote->payer_account_id, $quote->vehicle_id);
            CustomerChargingSession::query()->create([
                'subject_id' => $subject, 'session_id' => $result->session->getKey(), 'quote_id' => $quoteId,
                'idempotency_hash' => $hash, 'budget_minor' => $quote->budget_minor,
                'charging_account_id' => $quote->charging_account_id, 'vehicle_id' => $quote->vehicle_id,
                'payer_account_id' => $quote->payer_account_id, 'prepaid_account_id' => $quote->prepaid_account_id,
            ]);

            return $result->session;
        });
    }

    public function owned(string $subject, string $id): ChargingSession
    {
        $owner = CustomerChargingSession::query()->where('session_id', $id)->firstOrFail();
        if ($owner->charging_account_id !== null) {
            app(ChargingAccounts::class)->require(User::query()->where('public_id', $subject)->firstOrFail(), $owner->charging_account_id,
                $owner->subject_id === $subject ? PermissionKey::ChargingAccountView : PermissionKey::ChargingHistoryView);
        } else {
            abort_unless($owner->subject_id === $subject, 404);
        }

        return ChargingSession::query()->findOrFail($id);
    }

    public function controllable(string $subject, string $id): ChargingSession
    {
        $session = $this->owned($subject, $id);
        $owner = CustomerChargingSession::query()->where('session_id', $id)->firstOrFail();
        if ($owner->charging_account_id !== null) {
            app(ChargingAccounts::class)->require(User::query()->where('public_id', $subject)->firstOrFail(), $owner->charging_account_id,
                $owner->subject_id === $subject ? PermissionKey::ChargingAccountCharge : PermissionKey::ChargingSessionControl, true);
            // Revoking a vehicle assignment blocks future starts; the original active driver can still request a safe stop while a member.
        }

        return $session;
    }

    public function cancel(string $subject, string $id, ?string $reason): ChargingSession
    {
        return DB::transaction(function () use ($subject, $id, $reason): ChargingSession {
            $session = $this->controllable($subject, $id);
            if ($session->state->value === 'cancelled') {
                return $session;
            }
            $command = ChargerCommand::query()->where('session_id', $id)->where('type', 'remote_start')->lockForUpdate()->firstOrFail();
            $session = ChargingSession::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($session->state->isTerminal()) {
                app(PrepaidChargingMonitor::class)->reconcile($id);

                return $session->refresh();
            }
            if (in_array($session->state->value, ['finalizing', 'review_required'], true)) {
                throw new DomainException('This session is already being finalized. Its charger and payment evidence must be checked.');
            }
            if ($session->started_at === null && $session->protocol_transaction_id === null) {
                AuthorizationToken::query()->where('session_id', $id)->whereNull('revoked_at')
                    ->update(['status' => 'revoked', 'revoked_at' => now('UTC')]);
            }
            if ($command->dispatched_at === null && $command->state->value === 'requested'
                && $session->started_at === null && $session->protocol_transaction_id === null && ! $session->state->isPhysicallyActive()) {
                app(ChargerCommandStateMachine::class)->transition($command, ChargerCommandState::Expired, 'customer_cancelled_before_dispatch');
                app(SessionLifecycleService::class)->cancel($session, $reason ?? 'Customer cancelled before dispatch');
            } elseif ($session->cancellation_reason !== 'customer_cancel_requested') {
                // A sent start may still succeed. Persist withdrawal without inventing a stop or releasing its hold.
                $session->forceFill(['cancellation_reason' => 'customer_cancel_requested',
                    'aggregate_version' => (int) $session->aggregate_version + 1])->save();
                app(AuditRecorder::class)->record(new AuditEntry('charging.start_cancellation.requested',
                    'charging_session', $id, AuditResult::Succeeded));
            }
            app(PrepaidChargingMonitor::class)->reconcile($id);

            return $session->refresh();
        });
    }

    public function active(string $subject): ?ChargingSession
    {
        return ChargingSession::query()->whereIn('id', CustomerChargingSession::query()->where('subject_id', $subject)->select('session_id'))
            ->where(fn ($query) => $query->whereNotIn('state', ['completed', 'failed', 'cancelled', 'expired'])
                ->orWhereIn('id', CustomerChargingSession::query()->where('subject_id', $subject)->whereNull('settled_at')->select('session_id')))
            ->latest('id')->first();
    }

    /** @return array<string, mixed> */
    public function present(ChargingSession $session): array
    {
        $session->loadMissing(['customer.quote', 'latestPower']);
        $owner = $session->customer ?? throw new DomainException('This session has no customer ownership record.');
        $quote = $owner->quote;
        $target = $quote->disclosure['target'];
        $power = $session->latestPower;
        if ($power !== null && ($power->sampled_at->lt(now('UTC')->subSeconds(60)) || $session->stopped_at !== null)) {
            $power = null;
        }

        return [
            'id' => (string) $session->getKey(), 'state' => $session->state->value,
            'station_id' => $session->charging_station_id, 'station_name' => $target['station_name'],
            'connector_id' => $session->connector_id, 'connector_name' => $target['connector_name'],
            'energy_wh' => (int) $session->energy_wh, 'duration_seconds' => (int) $session->duration_seconds,
            'energy_flow_seconds' => (int) $session->energy_flow_seconds, 'current_power_w' => $power === null ? null : (int) $power->value,
            'currency' => $session->currency, 'estimated_cost_minor' => $session->estimated_cost_minor === null ? null : (int) $session->estimated_cost_minor,
            'final_cost_minor' => $session->final_cost_minor === null ? null : (int) $session->final_cost_minor,
            'requested_at' => $session->requested_at->toISOString(), 'started_at' => $session->started_at?->toISOString(),
            'stopped_at' => $session->stopped_at?->toISOString(), 'start_deadline_at' => $session->start_deadline_at?->toISOString(),
            'updated_at' => $session->updated_at?->toISOString(), 'aggregate_version' => (int) $session->aggregate_version,
            'failure_reason' => $session->failure_reason, 'anomaly_flags' => $session->anomaly_flags,
            'cancellation_requested' => $session->cancellation_reason === 'customer_cancel_requested',
            'payment_state' => $owner->payment_state, 'reserved_budget_minor' => (int) $owner->budget_minor,
            'attribution' => $quote->disclosure['attribution'] ?? ['driver_id' => $owner->subject_id, 'payer' => 'Personal prepaid wallet'],
            'refundable' => false, 'receipt' => null, 'invoice' => null,
        ];
    }
}
