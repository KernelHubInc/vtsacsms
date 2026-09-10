<?php

declare(strict_types=1);

namespace App\Foundation\Audit;

use JsonException;

final class AuditHasher
{
    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws JsonException
     */
    public function hash(?string $previousHash, array $payload): string
    {
        $canonicalPayload = $this->sortRecursively($payload);
        $encoded = json_encode(
            $canonicalPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', ($previousHash ?? '')."\n".$encoded);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursively(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        return $value;
    }
}
