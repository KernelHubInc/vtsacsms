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
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerChargingController extends Controller
{
    public function __construct(private readonly CustomerCharging $charging) {}

    public function prepare(CustomerChargingRequest $request): JsonResponse
    {
        return $this->attempt($request, fn (): array => $this->charging->prepare($this->subject($request), $request->validated('charger_code')));
    }

    public function start(CustomerChargingRequest $request): JsonResponse
    {
        return $this->attempt($request, fn (): array => $this->charging->present($this->charging->start(
            $this->subject($request), $request->validated('quote_id'), $request->validated('connector_id'), $request->validated('idempotency_key'),
        )), 202);
    }

    public function active(Request $request): JsonResponse
    {
        $session = $this->charging->active($this->subject($request));

        return response()->json(['data' => $session === null ? null : $this->charging->present($session)])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return response()->json(['data' => $this->charging->present($this->charging->owned($this->subject($request), $session))])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $page = ChargingSession::query()->whereIn('id', CustomerChargingSession::query()->where('subject_id', $this->subject($request))->select('session_id'))
            ->with(['customer.quote', 'latestPower'])->orderByDesc('id')->cursorPaginate(20);

        return response()->json(['data' => $page->map(fn (ChargingSession $session): array => $this->charging->present($session))->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()]])->header('Cache-Control', 'no-store');
    }

    public function stop(CustomerChargingRequest $request, string $session): JsonResponse
    {
        return $this->attempt($request, function () use ($request, $session): array {
            $owned = $this->charging->owned($this->subject($request), $session);
            $result = app(RemoteStopService::class)->request($owned, 'mobile-stop-'.hash('sha256', $this->subject($request).':'.$request->validated('idempotency_key')));

            return $this->charging->present($result->session->refresh());
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
