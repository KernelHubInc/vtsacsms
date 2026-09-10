<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PaymentWebhookReceipt extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $receipt): void {
            if ($receipt->getRawOriginal('outcome') !== 'received') {
                throw new \LogicException('Processed webhook evidence is immutable.');
            }
        });
        self::deleting(fn (): never => throw new \LogicException('Webhook evidence cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['normalized_payload' => 'array', 'provider_created_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
