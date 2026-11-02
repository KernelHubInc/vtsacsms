<?php

declare(strict_types=1);

namespace App\Modules\CMS\Application;

use App\Modules\CMS\Domain\Models\DriverNotice;

final class PushNoticeQuery
{
    /** @return iterable<string> */
    public function expiredHistoryIds(string $tenantId): iterable
    {
        foreach (DriverNotice::query()->where('tenant_id', $tenantId)->where('ends_at', '<', now()->subDays(90))
            ->select('id')->lazyById(100) as $notice) {
            yield $notice->id;
        }
    }

    /** @return iterable<string> */
    public function activeIds(string $tenantId): iterable
    {
        foreach (DriverNotice::query()->where('tenant_id', $tenantId)->where('send_push', true)
            ->active()->select('id')->lazyById(100) as $notice) {
            yield $notice->id;
        }
    }

    /** @return array{title: string, message: string, category: string, ttl: int}|null */
    public function find(string $tenantId, string $id): ?array
    {
        $notice = DriverNotice::query()->where('tenant_id', $tenantId)->where('send_push', true)
            ->active()->find($id);
        if ($notice === null) {
            return null;
        }

        return ['title' => $notice->title, 'message' => $notice->message, 'category' => $notice->category,
            'ttl' => max(1, min(3600, $notice->ends_at->getTimestamp() - now()->getTimestamp()))];
    }
}
