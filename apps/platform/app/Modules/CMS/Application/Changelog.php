<?php

declare(strict_types=1);

namespace App\Modules\CMS\Application;

use Illuminate\Support\Collection;

final class Changelog
{
    public function environment(): string
    {
        return app()->environment('production') ? 'production' : 'staging';
    }

    /** @return Collection<int, array{id: string, date: string, environments: list<string>, title: string, changes: list<array{type: string, text: string}>}> */
    public function releases(): Collection
    {
        $today = now('Asia/Manila')->toDateString();

        return collect(config()->array('changelog.releases'))
            ->filter(fn (array $release): bool => in_array($this->environment(), $release['environments'], true) && $release['date'] <= $today)
            ->sortByDesc('date')->values();
    }
}
