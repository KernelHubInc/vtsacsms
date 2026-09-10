<?php

declare(strict_types=1);

namespace App\Modules\CMS\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class CmsModel extends Model
{
    use HasUlids;

    protected $guarded = [];
}
