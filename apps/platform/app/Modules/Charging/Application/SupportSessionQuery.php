<?php

declare(strict_types=1);

namespace App\Modules\Charging\Application;

final readonly class SupportSessionQuery
{
    /** @return array{session_id:string,site_id:string} */
    public function forDriver(string $subjectId, string $sessionId): array
    {
        $session = app(CustomerCharging::class)->owned($subjectId, $sessionId);

        return ['session_id' => (string) $session->getKey(), 'site_id' => $session->site_id];
    }
}
