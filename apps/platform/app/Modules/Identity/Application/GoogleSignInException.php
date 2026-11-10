<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class GoogleSignInException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 401)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'correlation_id' => request()->attributes->get('correlation_id'),
            'errors' => [],
        ]], $this->status)->header('Cache-Control', 'no-store');
    }
}
