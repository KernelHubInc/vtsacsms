<x-filament-panels::page>
    <form wire:submit="saveTopupLimits" class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-gray-200 p-5 dark:border-white/10">
            <h2 class="font-semibold">Wallet top-up settings</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">Choose the amounts available across this tenant's Personal, Business and Fleet wallets. Android and the browser app use the same settings. Existing payment requests keep their original amounts.</p>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                Minimum top-up (PHP)
                <input wire:model.blur="topupMinimum" type="text" inputmode="decimal" autocomplete="off" @disabled(!$this->canManage()) class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                @error('topupMinimum')<span role="alert" class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                Maximum top-up (PHP)
                <input wire:model.blur="topupMaximum" type="text" inputmode="decimal" autocomplete="off" @disabled(!$this->canManage()) class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                @error('topupMaximum')<span role="alert" class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
            </label>
        </div>
        <div class="space-y-4 border-t border-gray-200 p-5 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold">Quick-select amounts</h3>
                    <p class="mt-1 text-sm text-gray-500">Add as many presets as you need, in the order customers should see them. The app shows six first, with More amounts for the rest. All presets must be unique and within your limits.</p>
                </div>
                @if ($this->canManage())
                    <x-filament::button type="button" color="gray" wire:click="addTopupPreset" icon="heroicon-o-plus">Add amount</x-filament::button>
                @endif
            </div>
            @error('topupPresets')<p role="alert" class="text-sm text-danger-600">{{ $message }}</p>@enderror
            @forelse ($topupPresets as $index => $preset)
                <div wire:key="topup-preset-{{ $index }}" class="flex flex-wrap items-start gap-3 rounded-xl border border-gray-200 p-3 dark:border-white/10">
                    <label class="min-w-40 flex-1 text-sm font-medium">
                        Amount {{ $index + 1 }} (PHP)
                        <input wire:model="topupPresets.{{ $index }}.amount" type="text" inputmode="decimal" autocomplete="off" @disabled(!$this->canManage()) class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                        @error('topupPresets.'.$index.'.amount')<span role="alert" class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
                        @error('topupPresets.'.$index.'.amount_minor')<span role="alert" class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex items-center gap-2 py-3 text-sm">
                        <input wire:model="topupPresets.{{ $index }}.enabled" type="checkbox" @disabled(!$this->canManage())>
                        Enabled
                    </label>
                    @if ($this->canManage())
                        <div class="flex flex-wrap gap-2 py-1">
                            <x-filament::button type="button" color="gray" wire:click="moveTopupPreset({{ $index }}, -1)" :disabled="$index === 0" aria-label="Move amount {{ $index + 1 }} up">Up</x-filament::button>
                            <x-filament::button type="button" color="gray" wire:click="moveTopupPreset({{ $index }}, 1)" :disabled="$index === count($topupPresets) - 1" aria-label="Move amount {{ $index + 1 }} down">Down</x-filament::button>
                            <x-filament::button type="button" color="danger" wire:click="removeTopupPreset({{ $index }})" aria-label="Remove amount {{ $index + 1 }}">Remove</x-filament::button>
                        </div>
                    @endif
                </div>
            @empty
                <p class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500 dark:bg-white/5">No preset amounts. Keep Other amount enabled so customers can enter an amount.</p>
            @endforelse
            <label class="flex items-center gap-3 text-sm font-medium">
                <input wire:model="topupAllowCustom" type="checkbox" @disabled(!$this->canManage())>
                Allow Other amount within the minimum and maximum
            </label>
            @error('topupAllowCustom')<p role="alert" class="text-sm text-danger-600">{{ $message }}</p>@enderror
        </div>
        @if ($this->canManage())
            <div class="flex justify-end border-t border-gray-200 px-5 py-4 dark:border-white/10">
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">Save top-up settings</button>
            </div>
        @else
            <div class="border-t border-gray-200 px-5 py-4 text-sm text-gray-500 dark:border-white/10">You can view these limits but do not have permission to change them.</div>
        @endif
    </form>

    <form wire:submit="saveMapSettings" class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-gray-200 p-5 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold">Map providers</h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500">Rendering preferences are provider-independent and contain no credentials. OpenStreetMap remains the safe fallback whenever Google Maps is not ready.</p>
                </div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">Resolved: {{ $mapStatus['resolved'] }}</span>
                    <span class="rounded-full px-3 py-1 font-medium {{ $mapStatus['google'] === 'Configured' ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700' }}">Google: {{ $mapStatus['google'] }}</span>
                    @if ($mapStatus['fallback'] === 'Provider fallback active')
                        <span class="rounded-full bg-warning-50 px-3 py-1 font-medium text-warning-700">{{ $mapStatus['fallback'] }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid gap-6 p-5 xl:grid-cols-2">
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="col-span-full text-sm font-semibold text-gray-900 dark:text-white">Provider by surface</legend>
                @foreach ([
                    'default_provider' => 'Default',
                    'admin_provider' => 'Platform admin',
                    'operator_provider' => 'Operator portal',
                    'user_web_provider' => 'User web',
                    'public_provider' => 'Public locator',
                    'mobile_provider' => 'Mobile recommendation',
                ] as $field => $label)
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ $label }}
                        <select wire:model="{{ 'mapSettings.'.$field }}" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                            @foreach ($providerOptions as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('mapSettings.'.$field)<span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-3">
                <legend class="col-span-full text-sm font-semibold text-gray-900 dark:text-white">Default viewport</legend>
                @foreach ([
                    'default_latitude' => ['Latitude', '-90', '90', '0.000001'],
                    'default_longitude' => ['Longitude', '-180', '180', '0.000001'],
                    'default_zoom' => ['Zoom', '0', '22', '1'],
                    'minimum_zoom' => ['Minimum zoom', '0', '22', '1'],
                    'maximum_zoom' => ['Maximum zoom', '0', '22', '1'],
                    'tile_maximum_native_zoom' => ['Native tile max', '0', '22', '1'],
                ] as $field => [$label, $min, $max, $step])
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ $label }}
                        <input wire:model.blur="{{ 'mapSettings.'.$field }}" type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                        @error('mapSettings.'.$field)<span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </fieldset>

            <fieldset class="grid gap-4 xl:col-span-2">
                <legend class="text-sm font-semibold text-gray-900 dark:text-white">OpenStreetMap-compatible tiles</legend>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Tile URL template
                    <input wire:model.blur="mapSettings.tile_url_template" type="text" autocomplete="off" class="mt-1 block w-full rounded-xl border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-gray-950">
                    <span class="mt-1 block text-xs font-normal text-gray-500">HTTPS only; include the literal placeholders {z}, {x}, and {y}. Tokens may be supplied only through deployment-managed environment configuration.</span>
                    @error('mapSettings.tile_url_template')<span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
                </label>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Required attribution
                    <input wire:model.blur="mapSettings.tile_attribution" type="text" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                    @error('mapSettings.tile_attribution')<span class="mt-1 block text-xs text-danger-600">{{ $message }}</span>@enderror
                </label>
                <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <input wire:model="mapSettings.clustering_enabled" type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                    Cluster overlapping and dense station markers
                </label>
            </fieldset>
        </div>

        @if ($this->canManage())
            <div class="flex justify-end border-t border-gray-200 px-5 py-4 dark:border-white/10">
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveMapSettings">Save map settings</span>
                    <span wire:loading wire:target="saveMapSettings">Saving…</span>
                </button>
            </div>
        @else
            <div class="border-t border-gray-200 px-5 py-4 text-sm text-gray-500 dark:border-white/10">You can view map settings but do not have permission to change them.</div>
        @endif
    </form>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="border-b border-gray-200 p-5 dark:border-white/10">
            <h2 class="font-semibold">Deployment-managed settings</h2>
            <p class="mt-1 text-sm text-gray-500">Sensitive and infrastructure settings are intentionally not editable in the portal. Values show presence or safe identifiers only.</p>
        </div>
        <dl class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($settings as $setting)
                <div class="grid gap-1 px-5 py-4 sm:grid-cols-[1fr_1fr_10rem]">
                    <dt class="font-medium">{{ $setting['name'] }}</dt>
                    <dd class="text-sm text-gray-600 dark:text-gray-300">{{ $setting['value'] }}</dd>
                    <dd class="text-xs uppercase tracking-wide text-gray-400">{{ $setting['source'] }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</x-filament-panels::page>
