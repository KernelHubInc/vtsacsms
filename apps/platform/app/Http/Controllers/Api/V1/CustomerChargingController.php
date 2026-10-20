<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CustomerChargingRequest;
use App\Models\User;
use App\Modules\Charging\Application\CustomerCharging;
use App\Modules\Charging\Application\RemoteStopService;
use App\Modules\Charging\Domain\Models\ChargingSession;
use App\Modules\Charging\Domain\Models\CustomerChargingSession;
use App\Modules\Organizations\Application\ChargingAccounts;
use App\Modules\Organizations\Domain\PermissionKey;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CustomerChargingController extends Controller
{
    public function __construct(private readonly CustomerCharging $charging) {}

    public function prepare(CustomerChargingRequest $request): JsonResponse
    {
        return $this->attempt($request, fn (): array => $this->charging->prepare($this->subject($request), $request->validated('charger_code'),
            $this->account($request), $request->validated('vehicle_id')));
    }

    public function start(CustomerChargingRequest $request): JsonResponse
    {
        return $this->attempt($request, fn (): array => $this->charging->present($this->charging->start(
            $this->subject($request), $request->validated('quote_id'), $request->validated('connector_id'), $request->validated('idempotency_key'),
            $this->account($request),
        )), 202);
    }

    public function active(Request $request): JsonResponse
    {
        $session = $this->charging->active($this->subject($request));
        if ($session !== null) {
            $this->charging->owned($this->subject($request), (string) $session->getKey());
        }

        return response()->json(['data' => $session === null ? null : $this->charging->present($session)])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return response()->json(['data' => $this->charging->present($this->charging->owned($this->subject($request), $session))])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $owners = CustomerChargingSession::query();
        if ($account === null) {
            $owners->where('subject_id', $this->subject($request))->whereNull('payer_account_id');
        } else {
            $record = app(ChargingAccounts::class)->require($request->user(), $account);
            if ($record->charging_account_type === 'personal') {
                // Include personal sessions created before account attribution was introduced.
                $owners->where('subject_id', $this->subject($request))->whereNull('payer_account_id');
            } else {
                $owners->where('charging_account_id', $account);
            }
            if (! app(ChargingAccounts::class)->can($request->user(), $account, PermissionKey::ChargingHistoryView)) {
                $owners->where('subject_id', $this->subject($request));
            }
        }
        $page = ChargingSession::query()->whereIn('id', $owners->select('session_id'))
            ->with(['customer.quote', 'latestPower'])->orderByDesc('id')->cursorPaginate(20);

        return response()->json(['data' => $page->map(fn (ChargingSession $session): array => $this->charging->present($session))->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()]])->header('Cache-Control', 'no-store');
    }

    public function stop(CustomerChargingRequest $request, string $session): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $session): array {
            return DB::transaction(function () use ($request, $session): array {
                $owned = $this->charging->controllable($this->subject($request), $session);
                $result = app(RemoteStopService::class)->request($owned, 'mobile-stop-'.hash('sha256', $this->subject($request).':'.$request->validated('idempotency_key')));

                return $this->charging->present($result->session->refresh());
            });
        }, 202);
    }

    public function cancel(CustomerChargingRequest $request, string $session): JsonResponse
    {
        return $this->attempt($request, fn (): array => $this->charging->present($this->charging->cancel(
            $this->subject($request), $session, $request->validated('reason'),
        )));
    }

    private function subject(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return (string) $user->public_id;
    }

    private function account(Request $request): ?string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $account = app(ChargingAccounts::class)->selected($user, $request->header('X-Charging-Account-ID'));

        return $account === null ? null : (string) $account->getKey();
    }

    private function attempt(Request $request, \Closure $operation, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $operation()], $status)->header('Cache-Control', 'no-store');
        } catch (DomainException $exception) {
            return response()->json(['error' => ['code' => 'charging_request_rejected', 'message' => $exception->getMessage(),
                'correlation_id' => $request->attributes->get('correlation_id')]], 422);
        }
    }
}
