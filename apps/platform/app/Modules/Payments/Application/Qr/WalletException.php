<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Qr;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class WalletException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage(),
            'correlation_id' => $request->attributes->get('correlation_id')]], $this->status)->header('Cache-Control', 'no-store');
    }
}
