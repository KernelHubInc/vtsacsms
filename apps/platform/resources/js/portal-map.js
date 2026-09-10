import { MapProviderFactory, parseMapConfiguration } from './maps/map-provider-factory';

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const initializePortalMap = async (root) => {
    if (root.dataset.initialized === 'true') return;
    root.dataset.initialized = 'true';

    const stations = JSON.parse(root.dataset.stations || '[]');
    const list = root.querySelector('[data-map-list]');
    const empty = root.querySelector('[data-map-empty]');
    const count = root.querySelector('[data-map-count]');
    const search = root.querySelector('[data-map-search]');
    const status = root.querySelector('[data-map-status]');
    const error = root.querySelector('[data-map-error]');
    const drawer = root.querySelector('[data-map-drawer]');
    let adapter = null;
    let filtered = stations;
    const disposers = [];
    const bind = (target, event, listener) => {
        target.addEventListener(event, listener);
        disposers.push(() => target.removeEventListener(event, listener));
    };

    const showDetails = (stationId) => {
        const station = stations.find((item) => item.id === stationId);
        if (!station) return;
        root.querySelector('[data-drawer-name]').textContent = station.site_name;
        root.querySelector('[data-drawer-address]').textContent = station.address || 'Address not published';
        root.querySelector('[data-drawer-metrics]').innerHTML = [
            ['Chargers', station.chargers],
            ['Active', station.active_sessions],
            ['Faults', station.faults],
        ].map(([label, value]) => `<div class="rounded-xl bg-gray-50 p-2 dark:bg-white/5"><strong class="block">${escapeHtml(value)}</strong><span class="text-xs text-gray-500">${label}</span></div>`).join('');
        drawer.hidden = false;
        adapter?.selectMarker(stationId, { focus: true });
        requestAnimationFrame(() => adapter?.resize());
    };

    const render = () => {
        const term = search.value.trim().toLowerCase();
        filtered = stations.filter((station) => {
            const matchesStatus = !status.value || station.status === status.value;
            const haystack = `${station.site_name} ${station.address}`.toLowerCase();
            return matchesStatus && (!term || haystack.includes(term));
        });
        count.textContent = String(filtered.length);
        empty.hidden = filtered.length !== 0;
        list.innerHTML = filtered.map((station) => `
            <button type="button" data-station-id="${escapeHtml(station.id)}" class="block w-full px-4 py-4 text-left transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500 dark:hover:bg-white/5">
                <span class="flex items-center justify-between gap-3">
                    <strong class="truncate">${escapeHtml(station.site_name)}</strong>
                    <span class="rounded-full px-2 py-1 text-xs font-semibold ${station.status === 'faulted' ? 'bg-danger-50 text-danger-700' : station.status === 'available' ? 'bg-success-50 text-success-700' : 'bg-gray-100 text-gray-700'}">${escapeHtml(station.status)}</span>
                </span>
                <span class="mt-1 block truncate text-xs text-gray-500">${escapeHtml(station.address || 'Address not published')}</span>
                <span class="mt-2 block text-xs text-gray-500">${station.connectors} connectors · ${station.active_sessions} active · ${station.stale} stale</span>
            </button>
        `).join('');
        list.querySelectorAll('[data-station-id]').forEach((button) => {
            button.addEventListener('click', () => showDetails(button.dataset.stationId));
        });
        adapter?.setMarkers(filtered);
    };

    bind(search, 'input', render);
    bind(status, 'change', render);
    bind(root.querySelector('[data-map-close]'), 'click', () => { drawer.hidden = true; });
    bind(root.querySelector('[data-map-refresh]'), 'click', () => window.location.reload());
    bind(window, 'resize', () => adapter?.resize());
    render();

    try {
        const result = await new MapProviderFactory().create(
            root.querySelector('[data-map-canvas]'),
            parseMapConfiguration(root),
            {
                onViewportChanged: () => {},
                onMarkerSelect: showDetails,
                onError: (mapError) => {
                    error.textContent = mapError?.code === 'tile_failed'
                        ? 'Map tiles could not be loaded. The authorized station list remains available.'
                        : 'The map is degraded. The authorized station list remains available.';
                    error.hidden = false;
                },
                onProviderFailure: ({ provider, error: providerError }) => console.warn(
                    'Portal map provider initialization failed.',
                    { provider, code: providerError?.code },
                ),
            },
        );
        adapter = result.instance;
        root.dataset.resolvedProvider = result.provider;
        root.querySelector('[data-provider-warning]').hidden = !result.fallbackActive;
        adapter.setMarkers(filtered);
        const observer = new ResizeObserver(() => adapter?.resize());
        observer.observe(root.querySelector('[data-map-canvas]'));
        disposers.push(() => observer.disconnect());
    } catch (mapError) {
        error.textContent = 'The map could not be loaded. The accessible station list remains available.';
        error.hidden = false;
        console.warn('Portal map unavailable.', { code: mapError?.code || 'unknown' });
    }

    portalMaps.set(root, {
        destroy: () => {
            disposers.splice(0).forEach((dispose) => dispose());
            adapter?.destroy();
            adapter = null;
            delete root.dataset.initialized;
            delete root.dataset.resolvedProvider;
        },
    });
};

const portalMaps = new Map();

const bootPortalMaps = () => {
    document.querySelectorAll('[data-portal-network-map]').forEach((root) => {
        if (!portalMaps.has(root)) initializePortalMap(root);
    });
};

const destroyPortalMaps = () => {
    portalMaps.forEach((map) => map.destroy());
    portalMaps.clear();
};

document.addEventListener('DOMContentLoaded', bootPortalMaps);
document.addEventListener('livewire:navigated', bootPortalMaps);
document.addEventListener('livewire:navigating', destroyPortalMaps);
