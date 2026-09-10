<?php

declare(strict_types=1);

namespace App\Foundation\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class ArchivableMasterModel extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @param Builder<static> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function archive(): bool
    {
        return $this->forceFill(['archived_at' => now('UTC')])->save();
    }
}
