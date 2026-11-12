<x-filament-panels::page>
    <x-filament::section>
        <div class="space-y-5">
            <p class="max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Use Move to on any link to send it to another section or the top level. Drag items or use the arrow buttons to place them in order, then save your layout.
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
                        class="flex min-w-0 flex-col gap-2 rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 dark:border-gray-700 dark:bg-gray-800 sm:flex-row sm:items-center sm:gap-3 sm:px-3">
                        <div class="flex min-w-0 flex-1 items-center gap-2">
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
                        </div>
                        @if (filled($destinations[$key] ?? []))
                            <div class="w-full sm:w-44">
                                <x-filament::input.wrapper>
                                    <x-filament::input.select :aria-label="'Move '.$entry->getLabel().' to'"
                                        wire:change="moveTo('{{ $key }}', $event.target.value)" wire:loading.attr="disabled">
                                        <option value="" selected disabled>Move to…</option>
                                        @foreach ($destinations[$key] as $destination => $label)
                                            <option value="{{ $destination }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($keys === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">This section is empty. Use Move to on a link elsewhere to put it here.</p>
            @endif

            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-filament::button wire:click="save" wire:loading.attr="disabled">Save layout</x-filament::button>
                <x-filament::button color="gray" wire:click="resetLayout" wire:loading.attr="disabled">Reset to default</x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
