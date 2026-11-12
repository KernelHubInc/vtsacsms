<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Navigation\SidebarLayout;
use App\Models\User;
use App\Modules\Identity\Application\SidebarPreferences;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

final class CustomizeSidebar extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Customize sidebar';

    protected string $view = 'filament.shared.pages.customize-sidebar';

    public string $section = SidebarLayout::ROOT;

    /** @var array<string, list<string>> */
    #[Locked]
    public array $draft = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentPanel()?->getId();

        return $user instanceof User && $panel !== null && app(SidebarPreferences::class)->canCustomize($user, $panel);
    }

    public function mount(): void
    {
        [$user, $panel] = $this->workspace();
        $this->draft = app(SidebarLayout::class)->order($this->lists(), app(SidebarPreferences::class)->load($user, $panel));
    }

    public function reorder(string $key, int $position, string $section): void
    {
        abort_unless($section === $this->section, 422);
        $this->moveItem($key, $position);
    }

    public function move(string $key, int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $this->moveItem($key, $direction, relative: true);
    }

    public function moveTo(string $key, string $destination): void
    {
        $layout = app(SidebarLayout::class);
        $lists = $this->lists();
        $draft = $layout->order($lists, $this->draft);
        $entry = $layout->entries($lists)[$key] ?? null;
        $current = array_search($key, $draft[$this->section] ?? [], true);
        abort_unless(
            $entry instanceof NavigationItem && $current !== false
            && $destination !== $this->section && $layout->canPlace($lists, $destination, $key, $entry),
            422,
        );

        array_splice($draft[$this->section], $current, 1);
        $draft[$destination][] = $key;
        $this->draft = $draft;
        $this->section = $destination;
    }

    public function save(): void
    {
        [$user, $panel] = $this->workspace();
        $this->draft = app(SidebarLayout::class)->order($this->lists(), $this->draft);
        app(SidebarPreferences::class)->save($user, $panel, $this->draft);
        $this->dispatch('refresh-sidebar');
        Notification::make()->title('Sidebar layout saved')->success()->send();
    }

    public function resetLayout(): void
    {
        [$user, $panel] = $this->workspace();
        app(SidebarPreferences::class)->reset($user, $panel);
        $this->section = SidebarLayout::ROOT;
        $this->draft = app(SidebarLayout::class)->order($this->lists(), []);
        $this->dispatch('refresh-sidebar');
        Notification::make()->title('Default sidebar restored')->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $lists = $this->lists();
        $layout = app(SidebarLayout::class);
        $order = $layout->order($lists, $this->draft);
        $entries = $layout->entries($lists);
        $sections = [SidebarLayout::ROOT => 'Top level (outside sections)'];
        foreach ($order as $keys) {
            foreach ($keys as $key) {
                if (isset($lists[$key])) {
                    $sections[$key] = (string) $entries[$key]->getLabel();
                }
            }
        }
        $section = isset($sections[$this->section]) ? $this->section : SidebarLayout::ROOT;
        $destinations = [];
        foreach ($order[$section] as $key) {
            $entry = $entries[$key];
            if ($entry instanceof NavigationItem) {
                $destinations[$key] = array_filter(
                    $sections,
                    fn (string $container): bool => $container !== $section && $layout->canPlace($lists, $container, $key, $entry),
                    ARRAY_FILTER_USE_KEY,
                );
            }
        }

        return ['sections' => $sections, 'selected' => $section, 'entries' => $entries, 'keys' => $order[$section], 'destinations' => $destinations];
    }

    private function moveItem(string $key, int $position, bool $relative = false): void
    {
        $lists = $this->lists();
        $draft = app(SidebarLayout::class)->order($lists, $this->draft);
        $keys = $draft[$this->section] ?? [];
        $current = array_search($key, $keys, true);
        abort_if($current === false, 422);
        $position = $relative ? $current + $position : $position;
        abort_unless($position >= 0 && $position < count($keys), 422);
        array_splice($keys, $current, 1);
        array_splice($keys, $position, 0, [$key]);
        $draft[$this->section] = $keys;
        $this->draft = $draft;
    }

    /** @return array<string, array<string, NavigationGroup|NavigationItem>> */
    private function lists(): array
    {
        abort_unless(self::canAccess(), 403);

        return app(SidebarLayout::class)->lists(app(NavigationManager::class)->defaults());
    }

    /** @return array{User, string} */
    private function workspace(): array
    {
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentPanel()?->getId();
        abort_unless($user instanceof User && $panel !== null, 403);

        return [$user, $panel];
    }
}
