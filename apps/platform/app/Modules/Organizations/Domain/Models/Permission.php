<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class Permission extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'context',
        'description',
    ];
}
