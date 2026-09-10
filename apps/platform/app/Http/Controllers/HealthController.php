<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HealthController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        return response()->json($this->payload($request, 'ok'));
    }

    public function ready(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            Log::warning('health_readiness_failed', [
                'dependency' => 'database',
                'exception_class' => $exception::class,
            ]);

            return response()->json(
                $this->payload($request, 'unavailable', ['database' => 'unavailable']),
                503,
            );
        }

        return response()->json($this->payload($request, 'ready', ['database' => 'ok']));
    }

    /**
     * @param  array<string, string>  $checks
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $status, array $checks = []): array
    {
        return [
            'status' => $status,
            'service' => 'platform',
            'timestamp' => Carbon::now('UTC')->toIso8601String(),
            'request_id' => (string) $request->attributes->get('request_id'),
            'correlation_id' => (string) $request->attributes->get('correlation_id'),
            'checks' => $checks,
        ];
    }
}
