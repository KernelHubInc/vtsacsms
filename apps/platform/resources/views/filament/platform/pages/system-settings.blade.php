<x-filament-panels::page>
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
