<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Qr;

use App\Modules\Tenancy\Application\CurrentTenant;

final readonly class QrAvailability
{
    public function __construct(private CurrentTenant $tenant) {}

    public function mode(): string
    {
        $mode = config('wallet.mode');
        if ($mode === 'simulated' && app()->environment(['local', 'testing', 'staging', 'demo']) && config('features.simulated_payments')) {
            return 'simulated';
        }
        if ($mode === 'live' && config('features.real_payments') && config('wallet.aub.approved')
            && $this->tenant->get()->tenantId === config('wallet.aub.tenant_id')) {
            foreach (['merchant_id', 'signing_key', 'server_ip', 'notify_url'] as $key) {
                if (! is_string(config('wallet.aub.'.$key)) || config('wallet.aub.'.$key) === '') {
                    return 'disabled';
                }
            }
            if (! str_starts_with((string) config('wallet.aub.notify_url'), 'https://')) {
                return 'disabled';
            }

            return 'live';
        }

        return 'disabled';
    }

    public function requireCollection(): string
    {
        $mode = $this->mode();
        if ($mode === 'disabled') {
            throw new WalletException('TOPUPS_DISABLED', 'Top-ups are not available yet.', 503);
        }

        return $mode;
    }
}
