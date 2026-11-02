<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\CMS\Application\PushNoticeQuery;
use App\Modules\Identity\Application\PushRecipientQuery;
use App\Modules\Notifications\Domain\Models\NoticePushDelivery;
use App\Modules\Notifications\Domain\Models\NotificationDevice;

final readonly class DriverNoticePush
{
    public function __construct(private PushNoticeQuery $notices, private PushRecipientQuery $recipients,
        private FirebaseNoticeSender $sender) {}

    public function deliver(string $tenantId, int $limit = 50): int
    {
        if (! config('notice_push.enabled')) {
            return 0;
        }
        // Authentication failures must not consume device delivery attempts.
        $this->sender->authenticate();
        foreach ($this->notices->activeIds($tenantId) as $noticeId) {
            foreach (NotificationDevice::query()->where('tenant_id', $tenantId)->where('platform', 'android')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('notice_push_deliveries')
                    ->whereColumn('notice_push_deliveries.tenant_id', 'notification_devices.tenant_id')
                    ->whereColumn('notice_push_deliveries.installation_hash', 'notification_devices.installation_hash')
                    ->where('notice_push_deliveries.notice_id', $noticeId))
                ->lazyById(200) as $device) {
                if (! $this->recipients->eligible($tenantId, (string) $device->auth_token_id)) {
                    continue;
                }
                NoticePushDelivery::query()->firstOrCreate([
                    'tenant_id' => $tenantId, 'notice_id' => $noticeId,
                    'installation_hash' => $device->installation_hash,
                ], ['device_id' => $device->getKey(), 'available_at' => now()]);
            }
        }
        NoticePushDelivery::query()->where('tenant_id', $tenantId)->where('status', 'sending')
            ->where('attempted_at', '<', now()->subMinutes(10))
            ->update(['status' => 'unknown', 'error_code' => 'worker_interrupted']);
        $count = 0;
        $due = NoticePushDelivery::query()->where('tenant_id', $tenantId)->where('status', 'pending')
            ->where('available_at', '<=', now())->orderBy('available_at')->limit($limit)->get();
        foreach ($due as $delivery) {
            $claimed = NoticePushDelivery::query()->where('tenant_id', $tenantId)->whereKey($delivery->getKey())
                ->where('status', 'pending')->update(['status' => 'sending', 'attempted_at' => now(),
                    'attempts' => (int) $delivery->attempts + 1]);
            if ($claimed !== 1) {
                continue;
            }
            $delivery->refresh();
            $notice = $this->notices->find($tenantId, (string) $delivery->notice_id);
            $device = NotificationDevice::query()->where('tenant_id', $tenantId)
                ->where('installation_hash', $delivery->installation_hash)->find($delivery->device_id);
            if ($notice === null || $device === null || ! $this->recipients->eligible($tenantId, (string) $device->auth_token_id)) {
                $delivery->update(['status' => 'cancelled', 'error_code' => 'notice_or_recipient_inactive']);

                continue;
            }
            $result = $this->sender->send((string) $device->installation_ciphertext, (string) $delivery->notice_id, $notice);
            $status = $result['status'];
            if ($status === 'pending' && (int) $delivery->attempts >= 5) {
                $status = 'failed';
            }
            $delivery->update(['status' => $status, 'error_code' => $result['error'],
                'available_at' => now()->addSeconds($result['delay']),
                'accepted_at' => $status === 'accepted' ? now() : null]);
            if ($status === 'invalid') {
                NotificationDevice::query()->where('tenant_id', $tenantId)->whereKey($device->getKey())
                    ->where('installation_hash', $delivery->installation_hash)->delete();
            }
            $count++;
        }

        return $count;
    }

    public function prune(string $tenantId): void
    {
        foreach ($this->notices->expiredHistoryIds($tenantId) as $noticeId) {
            NoticePushDelivery::query()->where('tenant_id', $tenantId)->where('notice_id', $noticeId)->delete();
        }
    }

    /** @return array{counts: array<string, int>, recent: list<array<string, mixed>>} */
    public function report(string $tenantId, string $noticeId): array
    {
        $query = NoticePushDelivery::query()->where('tenant_id', $tenantId)->where('notice_id', $noticeId);

        return ['counts' => (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status')->map(fn ($value): int => (int) $value)->all(),
            'recent' => array_values($query->latest()->limit(100)->get(['id', 'status', 'attempts', 'attempted_at', 'accepted_at', 'error_code'])
                ->map(fn (NoticePushDelivery $delivery): array => [
                    'id' => (string) $delivery->getKey(), 'status' => (string) $delivery->status,
                    'attempts' => (int) $delivery->attempts, 'attempted_at' => $delivery->attempted_at?->toIso8601String(),
                    'accepted_at' => $delivery->accepted_at?->toIso8601String(), 'error_code' => $delivery->error_code,
                ])->all())];
    }
}
