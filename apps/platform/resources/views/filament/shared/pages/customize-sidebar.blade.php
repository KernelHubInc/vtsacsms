<x-filament-panels::page>
    <x-filament::section>
        <div class="space-y-5">
            <p class="max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Choose which part of the sidebar to arrange. Drag by the handle or use the arrow buttons, then save your layout for this workspace.
            </p>

            <div class="max-w-sm">
                <label for="sidebar-layout-section" class="mb-2 block text-sm font-semibold">Arrange</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="sidebar-layout-section" wire:model.live="section">
                        @foreach ($sections as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <ul
                wire:key="sidebar-list-{{ $selected }}"
                wire:sort="reorder"
                wire:sort:group-id="{{ $selected }}"
                aria-label="{{ $sections[$selected] }}"
                class="max-w-3xl space-y-2"
            >
                @foreach ($keys as $key)
                    @php $entry = $entries[$key]; @endphp
                    <li wire:key="sidebar-entry-{{ $key }}" wire:sort:item="{{ $key }}"
                        class="flex min-w-0 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 dark:border-gray-700 dark:bg-gray-800 sm:gap-3 sm:px-3">
                        <span wire:sort:handle aria-hidden="true" class="flex min-h-10 min-w-10 touch-none cursor-grab items-center justify-center rounded-md text-gray-500 hover:bg-gray-200 active:cursor-grabbing dark:text-gray-300 dark:hover:bg-gray-700">
                            <x-filament::icon icon="heroicon-o-bars-3" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1 text-sm font-medium break-words">{{ $entry->getLabel() }}</span>
                        <x-filament::icon-button icon="heroicon-o-arrow-up" color="gray"
                            :label="'Move '.$entry->getLabel().' up'" :disabled="$loop->first"
                            wire:click="move('{{ $key }}', -1)" wire:loading.attr="disabled" />
                        <x-filament::icon-button icon="heroicon-o-arrow-down" color="gray"
                            :label="'Move '.$entry->getLabel().' down'" :disabled="$loop->last"
                            wire:click="move('{{ $key }}', 1)" wire:loading.attr="disabled" />
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-filament::button wire:click="save" wire:loading.attr="disabled">Save layout</x-filament::button>
                <x-filament::button color="gray" wire:click="resetLayout" wire:loading.attr="disabled">Reset to default</x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
