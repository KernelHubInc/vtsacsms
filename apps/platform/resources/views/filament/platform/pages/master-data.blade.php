<x-filament-panels::page>
    <div class="grid gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <nav class="rounded-2xl border border-gray-200 bg-white p-2 shadow-sm dark:border-white/10 dark:bg-gray-900" aria-label="Master lists">
            @foreach ($lists as $key => $definition)
                <a href="{{ static::getUrl(['list' => $key]) }}" wire:navigate class="block rounded-xl px-3 py-2.5 text-sm font-medium {{ $list === $key ? 'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' }}">
                    {{ $definition['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="space-y-5">
            @if ($canManage)
                <form wire:submit="createRecord" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="font-semibold">Add {{ str($lists[$list]['label'])->singular() }}</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:items-start">
                        <label class="text-sm font-medium">{{ $lists[$list]['code_label'] ?? 'Code' }}
                            <input wire:model="code" class="mt-1 block w-full rounded-xl border-gray-300 uppercase dark:border-white/10 dark:bg-gray-950">
                            @error('code') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        @if (isset($lists[$list]['secondary_code_attribute']))
                            <label class="text-sm font-medium">{{ $lists[$list]['secondary_code_label'] }}
                                <input wire:model="secondaryCode" class="mt-1 block w-full rounded-xl border-gray-300 uppercase dark:border-white/10 dark:bg-gray-950">
                                @error('secondaryCode') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <label class="text-sm font-medium">Name
                            <input wire:model="name" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                            @error('name') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        @if (isset($lists[$list]['parent_label']))
                            <label class="text-sm font-medium">{{ $lists[$list]['parent_label'] }}
                                <select wire:model="parentId" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                                    <option value="">Select {{ str($lists[$list]['parent_label'])->lower() }}</option>
                                    @foreach ($parents as $parentId => $parentName)
                                        <option value="{{ $parentId }}">{{ $parentName }}</option>
                                    @endforeach
                                </select>
                                @error('parentId') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                            </label>
                        @else
                            <div class="hidden xl:block"></div>
                        @endif
                        <button type="submit" class="mt-6 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500">Add record</button>
                    </div>
                </form>
            @endif

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-col gap-4 border-b border-gray-200 px-5 py-4 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="font-semibold">{{ $lists[$list]['label'] }}</h2>
                        <p class="mt-1 text-xs text-gray-500">Referenced records are archived; they are never destructively deleted here.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[minmax(14rem,1fr)_10rem]">
                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Search
                            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Name or code" class="mt-1 block w-full rounded-xl border-gray-300 text-sm normal-case tracking-normal dark:border-white/10 dark:bg-gray-950">
                        </label>
                        <label class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Records
                            <select wire:model.live="archive" class="mt-1 block w-full rounded-xl border-gray-300 text-sm normal-case tracking-normal dark:border-white/10 dark:bg-gray-950">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                                <option value="all">All</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($records as $record)
                        <div class="px-5 py-4" wire:key="master-data-{{ $list }}-{{ $record->getKey() }}">
                            @if ($editingId === (string) $record->getKey())
                                <form wire:submit="updateRecord" class="grid gap-4 rounded-xl bg-gray-50 p-4 dark:bg-white/5 sm:grid-cols-2 xl:grid-cols-4">
                                    <label class="text-sm font-medium">{{ $lists[$list]['code_label'] ?? 'Code' }}
                                        <input wire:model="editCode" class="mt-1 block w-full rounded-xl border-gray-300 uppercase dark:border-white/10 dark:bg-gray-950">
                                        @error('editCode') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                    </label>
                                    @if (isset($lists[$list]['secondary_code_attribute']))
                                        <label class="text-sm font-medium">{{ $lists[$list]['secondary_code_label'] }}
                                            <input wire:model="editSecondaryCode" class="mt-1 block w-full rounded-xl border-gray-300 uppercase dark:border-white/10 dark:bg-gray-950">
                                            @error('editSecondaryCode') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                        </label>
                                    @endif
                                    <label class="text-sm font-medium">Name
                                        <input wire:model="editName" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                                        @error('editName') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                    </label>
                                    @if (isset($lists[$list]['parent_label']))
                                        <label class="text-sm font-medium">{{ $lists[$list]['parent_label'] }}
                                            <select wire:model="editParentId" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                                                <option value="">Select {{ str($lists[$list]['parent_label'])->lower() }}</option>
                                                @foreach ($parents as $parentId => $parentName)
                                                    <option value="{{ $parentId }}">{{ $parentName }}</option>
                                                @endforeach
                                            </select>
                                            @error('editParentId') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                        </label>
                                    @else
                                        <div class="hidden xl:block"></div>
                                    @endif
                                    <div class="flex items-end gap-2">
                                        <button type="submit" class="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500">Save</button>
                                        <button wire:click="cancelEdit" type="button" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10">Cancel</button>
                                    </div>
                                </form>
                            @else
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="font-medium">{{ $record->name }}</p>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $record->getAttribute($lists[$list]['code_attribute'] ?? 'code') }}
                                            @if (isset($lists[$list]['secondary_code_attribute']))
                                                / {{ $record->getAttribute($lists[$list]['secondary_code_attribute']) }}
                                            @endif
                                            @if ($record->archived_at) · Archived @endif
                                        </p>
                                    </div>
                                    @if ($canManage)
                                        <div class="flex flex-wrap items-center gap-3">
                                            @if (! $record->archived_at)
                                                <button wire:click="beginEdit('{{ $record->getKey() }}')" type="button" class="text-sm font-semibold text-primary-600 hover:text-primary-500">Edit</button>
                                                <button wire:click="archiveRecord('{{ $record->getKey() }}')" wire:confirm="Archive this record? It will stop appearing in active selections but historical references remain." type="button" class="text-sm font-semibold text-danger-600 hover:text-danger-500">Archive</button>
                                            @else
                                                <button wire:click="restoreRecord('{{ $record->getKey() }}')" wire:confirm="Restore this record to active master-data selections?" type="button" class="text-sm font-semibold text-success-600 hover:text-success-500">Restore</button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="p-10 text-center text-sm text-gray-500">No records match this master-list view.</p>
                    @endforelse
                </div>

                @if ($records->hasPages())
                    <div class="border-t border-gray-200 px-5 py-4 dark:border-white/10">
                        {{ $records->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
