<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property string $tenant_id
 * @property string $device_id
 * @property int $subject_security_version
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $expires_at
 */
final class MobileAccessToken extends PersonalAccessToken
{
    use HasUlids;

    protected $table = 'personal_access_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'device_id',
        'name',
        'token',
        'abilities',
        'subject_security_version',
        'expires_at',
        'last_used_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'subject_security_version' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
