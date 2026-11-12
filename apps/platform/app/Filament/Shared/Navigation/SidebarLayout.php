<?php

declare(strict_types=1);

namespace App\Filament\Shared\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

final class SidebarLayout
{
    public const ROOT = 'root';

    /**
     * @param  array<NavigationGroup>  $navigation
     * @return array<string, array<string, NavigationGroup|NavigationItem>>
     */
    public function lists(array $navigation): array
    {
        $lists = [self::ROOT => []];
        foreach ($navigation as $group) {
            if (blank($group->getLabel())) {
                $this->addItems($lists, self::ROOT, $group->getItems());

                continue;
            }

            $key = 'section-'.hash('sha256', (string) $group->getLabel());
            $lists[self::ROOT][$key] = $group;
            $this->addItems($lists, $key, $group->getItems());
        }

        return $lists;
    }

    /**
     * @param  array<string, array<string, NavigationGroup|NavigationItem>>  $lists
     * @param  array<string, list<string>>  $preferred
     * @return array<string, list<string>>
     */
    public function order(array $lists, array $preferred): array
    {
        $result = array_fill_keys(array_keys($lists), []);
        $remaining = $this->entries($lists);
        foreach ($preferred as $container => $keys) {
            foreach ($keys as $key) {
                $entry = $remaining[$key] ?? null;
                if ($entry !== null && $this->canPlace($lists, $container, $key, $entry)) {
                    $result[$container][] = $key;
                    unset($remaining[$key]);
                }
            }
        }

        foreach ($lists as $container => $entries) {
            array_push($result[$container], ...array_keys(array_intersect_key($entries, $remaining)));
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, NavigationGroup|NavigationItem>>  $lists
     * @return array<string, NavigationGroup|NavigationItem>
     */
    public function entries(array $lists): array
    {
        return array_merge(...array_values($lists));
    }

    /** @param array<string, array<string, NavigationGroup|NavigationItem>> $lists */
    public function canPlace(array $lists, string $container, string $key, NavigationGroup|NavigationItem $entry): bool
    {
        return isset($lists[$container][$key]) || ($entry instanceof NavigationItem
            && ($container === self::ROOT || ($lists[self::ROOT][$container] ?? null) instanceof NavigationGroup));
    }

    /**
     * @param  array<NavigationGroup>  $navigation
     * @param  array<string, list<string>>  $preferred
     * @return list<NavigationGroup>
     */
    public function apply(array $navigation, array $preferred): array
    {
        $lists = $this->lists($navigation);
        $entries = $this->entries($lists);
        $order = $this->order($lists, $preferred);
        $groups = [];
        $standalone = [];
        foreach ($order[self::ROOT] as $key) {
            $entry = $entries[$key];
            if ($entry instanceof NavigationItem) {
                $standalone[] = $this->withChildren($entry, $key, $entries, $order);

                continue;
            }

            if ($order[$key] === []) {
                continue;
            }

            if ($standalone !== []) {
                $groups[] = NavigationGroup::make()->items($standalone);
                $standalone = [];
            }

            $groups[] = (clone $entry)->items($this->orderedItems($key, $entries, $order));
        }
        if ($standalone !== []) {
            $groups[] = NavigationGroup::make()->items($standalone);
        }

        return $groups;
    }

    /**
     * @param  array<string, array<string, NavigationGroup|NavigationItem>>  $lists
     * @param  array<NavigationItem>|Arrayable<*, *>  $items
     */
    private function addItems(array &$lists, string $container, array|Arrayable $items): void
    {
        $lists[$container] ??= [];
        foreach (Arr::from($items) as $item) {
            if (! $item->isVisible()) {
                continue;
            }

            $key = 'link-'.hash('sha256', $container.'|'.$item->getKey());
            $lists[$container][$key] = $item;
            if (filled($item->getChildItems())) {
                $this->addItems($lists, $key, $item->getChildItems());
            }
        }
    }

    /**
     * @param  array<string, NavigationGroup|NavigationItem>  $entries
     * @param  array<string, list<string>>  $order
     * @return list<NavigationItem>
     */
    private function orderedItems(string $container, array $entries, array $order): array
    {
        $items = [];
        foreach ($order[$container] ?? [] as $key) {
            $item = $entries[$key];
            if ($item instanceof NavigationItem) {
                $items[] = $this->withChildren($item, $key, $entries, $order);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, NavigationGroup|NavigationItem>  $entries
     * @param  array<string, list<string>>  $order
     */
    private function withChildren(NavigationItem $item, string $key, array $entries, array $order): NavigationItem
    {
        return (clone $item)->childItems($this->orderedItems($key, $entries, $order));
    }
}
