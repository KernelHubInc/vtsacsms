<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Kyc;

final class KycSignature
{
    public static function sign(string $secret, string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]), $secret);
    }
}
