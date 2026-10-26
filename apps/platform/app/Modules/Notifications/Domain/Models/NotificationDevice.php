<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class NotificationDevice extends Model
{
    use HasUlids;

    protected $table = 'notification_devices';

    protected $guarded = [];

    protected $hidden = ['installation_ciphertext', 'installation_hash'];

    protected function casts(): array
    {
        return [
            'installation_ciphertext' => 'encrypted',
            'last_registered_at' => 'immutable_datetime',
        ];
    }
}
