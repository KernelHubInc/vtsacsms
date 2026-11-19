<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Models;

use App\Modules\Tenancy\Infrastructure\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SupportIntakeRequest extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];
}
