<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Notifications\Application\DriverNoticePush;
use App\Modules\Notifications\Application\FirebaseNoticeSender;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantStatus;
use Illuminate\Console\Command;
use RuntimeException;

final class SendDriverNotices extends Command
{
    protected $signature = 'notifications:send-driver-notices {--check : Validate authentication without sending}';

    protected $description = 'Deliver opted-in, active driver notices through Firebase.';

    public function handle(DriverNoticePush $push, FirebaseNoticeSender $sender): int
    {
        try {
            if ($this->option('check')) {
                $sender->check();
                $this->info('Firebase authentication and FCM validation succeeded. No notifications sent.');

                return self::SUCCESS;
            }
            foreach (Tenant::query()->select(['id', 'status'])->lazyById(100) as $tenant) {
                $push->prune((string) $tenant->getKey());
                if ($tenant->status === TenantStatus::Active) {
                    $push->deliver((string) $tenant->getKey());
                }
            }
        } catch (RuntimeException $exception) {
            // Do not expose provider payloads, assertions or registration identifiers in command logs.
            $code = $exception->getMessage();
            $this->error(str_starts_with($code, 'firebase_') ? $code : 'notice_push_failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
