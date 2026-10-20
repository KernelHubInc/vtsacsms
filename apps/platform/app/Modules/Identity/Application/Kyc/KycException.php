<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Kyc;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class KycException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct('The identity verification request could not be completed.');
    }

    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'request_id' => $request->attributes->get('request_id'),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]], $this->status)->header('Cache-Control', 'no-store');
    }
}
