<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Navigation\SidebarLayout;
use App\Models\User;
use App\Modules\Identity\Application\SidebarPreferences;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
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
        $this->draft = app(SidebarLayout::class)->order($this->lists(), []);
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
        $order = app(SidebarLayout::class)->order($lists, $this->draft);
        $sections = [SidebarLayout::ROOT => 'Sections and standalone links'];
        foreach ($order as $parent => $keys) {
            foreach ($keys as $key) {
                if (! empty($lists[$key])) {
                    $sections[$key] = (string) $lists[$parent][$key]->getLabel();
                }
            }
        }
        $section = isset($sections[$this->section]) ? $this->section : SidebarLayout::ROOT;

        return ['sections' => $sections, 'selected' => $section, 'entries' => $lists[$section], 'keys' => $order[$section]];
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

        return app(SidebarLayout::class)->lists(Filament::getNavigation());
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
