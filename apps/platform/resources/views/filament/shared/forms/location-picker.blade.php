<div
    data-location-picker
    data-map-config='@json($mapConfig)'
    data-initial-latitude="{{ $latitude }}"
    data-initial-longitude="{{ $longitude }}"
    class="space-y-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-gray-950"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Site location picker</h3>
            <p class="mt-1 text-xs text-gray-500">Click the map or drag the marker. Manual latitude and longitude fields remain authoritative and available.</p>
        </div>
        <span data-location-provider class="rounded-full bg-white px-3 py-1 text-xs font-medium text-gray-600 shadow-sm dark:bg-gray-900 dark:text-gray-300">Loading map…</span>
    </div>
    <div wire:ignore class="relative overflow-hidden rounded-xl border border-gray-200 bg-gray-200 dark:border-white/10">
        <div data-map-canvas class="h-80 w-full" aria-label="Interactive site coordinate picker"></div>
        <div data-map-error hidden role="alert" class="absolute inset-x-3 bottom-3 rounded-xl border border-warning-200 bg-warning-50 p-3 text-xs text-warning-800 shadow">
            The interactive picker is unavailable. Enter validated coordinates in the manual fields above.
        </div>
    </div>
    <p data-location-readout class="font-mono text-xs text-gray-500">No coordinate selected</p>
</div>
@vite('resources/js/app.js')
