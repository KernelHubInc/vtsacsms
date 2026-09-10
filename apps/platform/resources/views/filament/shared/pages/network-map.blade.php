<x-filament-panels::page>
    <div
        class="space-y-4"
        data-portal-network-map
        data-map-config='@json($mapConfig)'
        data-stations='@json($stations)'
    >
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-3">
                <label class="min-w-56 flex-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    Search authorized sites
                    <input data-map-search type="search" class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950" placeholder="Name or address">
                </label>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Status
                    <select data-map-status class="mt-1 block rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                        <option value="">All states</option>
                        <option value="available">Available</option>
                        <option value="busy">Active session</option>
                        <option value="faulted">Faulted</option>
                        <option value="offline">Offline</option>
                        <option value="stale">Stale</option>
                    </select>
                </label>
                <button data-map-refresh type="button" class="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    Refresh snapshot
                </button>
            </div>
            <p class="mt-2 text-xs text-gray-500">Status is a tenant-scoped snapshot cached for up to 30 seconds. Stale connectors are highlighted before offline state.</p>
        </div>

        <div data-map-error hidden role="alert" class="rounded-2xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800"></div>
        <div data-provider-warning @if (! $mapConfig['fallbackActive']) hidden @endif role="status" class="rounded-2xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
            Google Maps is not ready for this surface. OpenStreetMap is active and the station data is unchanged.
        </div>
        <div class="grid min-h-[36rem] gap-4 lg:grid-cols-[minmax(19rem,0.34fr)_minmax(0,1fr)]">
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900" aria-label="Authorized charging sites">
                <div class="border-b border-gray-200 px-4 py-3 text-sm font-semibold dark:border-white/10">
                    <span data-map-count>{{ count($stations) }}</span> authorized sites
                </div>
                <div data-map-empty hidden class="p-8 text-center text-sm text-gray-500">No sites match these filters.</div>
                <div data-map-list class="max-h-[32rem] divide-y divide-gray-100 overflow-y-auto dark:divide-white/5"></div>
            </section>
            <section class="relative overflow-hidden rounded-2xl border border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-gray-950" aria-label="Charging network map">
                <div data-map-canvas class="h-full min-h-[36rem] w-full"></div>
                <aside data-map-drawer hidden class="absolute bottom-4 left-4 right-4 rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-900 lg:left-auto lg:w-96">
                    <button data-map-close type="button" class="float-right rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="Close site details">✕</button>
                    <h2 data-drawer-name class="pr-8 text-lg font-semibold"></h2>
                    <p data-drawer-address class="mt-1 text-sm text-gray-500"></p>
                    <div data-drawer-metrics class="mt-4 grid grid-cols-3 gap-2 text-center text-sm"></div>
                </aside>
            </section>
        </div>
    </div>
    @vite('resources/js/app.js')
</x-filament-panels::page>
