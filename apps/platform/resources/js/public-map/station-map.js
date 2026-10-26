import { MapProviderFactory, parseMapConfiguration } from '../maps/map-provider-factory';
import { directionsUrl } from '../maps/directions-launcher';
import { connectorIcon, statusBadge } from '../maps/interface-assets';
import { shouldFetchStations, stationSearchParameters } from './search-parameters';

const statusLabel = (status) => ({ available: 'available', busy: 'in use', faulted: 'needs attention', offline: 'offline', unavailable: 'unavailable', unknown: 'unknown' }[status] || 'unknown');
const powerLabel = (watts) => watts ? `${Math.round(Number(watts) / 1000)} kW max` : 'Power not published';

class StationFinder {
    constructor(root) {
        this.root = root;
        this.endpoint = root.dataset.endpoint;
        this.form = root.querySelector('[data-map-filters]');
        this.list = root.querySelector('[data-station-list]');
        this.state = root.querySelector('[data-map-state]');
        this.count = root.querySelector('[data-result-count]');
        this.drawer = root.querySelector('[data-station-drawer]');
        this.drawerContent = root.querySelector('[data-drawer-content]');
        this.unavailable = root.querySelector('[data-map-unavailable]');
        this.filterPanel = root.querySelector('[data-filter-panel]');
        this.bounds = null;
        this.stations = [];
        this.abortController = null;
        this.disposers = [];
        this.configuration = parseMapConfiguration(root);
        this.isHomeMap = root.hasAttribute('data-home-map');
        this.lastFittedKey = null;
        this.lastRequestKey = null;
    }

    async start() {
        const initial = new URLSearchParams(window.location.search);
        for (const name of ['query', 'connector', 'availability']) {
            const field = this.form.elements.namedItem(name);
            const value = initial.get(name)?.slice(0, 120);
            if (!field || !value) continue;
            if (field instanceof HTMLSelectElement && ![...field.options].some((option) => option.value === value)) continue;
            field.value = value;
        }
        const bind = (target, event, listener) => {
            target.addEventListener(event, listener);
            this.disposers.push(() => target.removeEventListener(event, listener));
        };
        bind(this.form, 'submit', (event) => { event.preventDefault(); this.fetchStations(true); });
        bind(this.form, 'change', () => this.fetchStations());
        if (this.filterPanel) {
            if (window.matchMedia('(max-width: 900px)').matches && !initial.has('query') && !initial.has('connector') && !initial.has('availability')) {
                this.filterPanel.open = false;
            }
            bind(this.filterPanel, 'toggle', () => window.requestAnimationFrame(() => this.adapter?.resize()));
        }
        bind(this.root.querySelector('[data-drawer-close]'), 'click', () => { this.drawer.hidden = true; });
        bind(this.root.querySelector('[data-use-location]'), 'click', () => this.useLocation());
        bind(window, 'online', () => this.fetchStations(true));
        bind(window, 'offline', () => this.showState('You are offline. Previously loaded station details may be stale.', 'offline'));
        bind(window, 'resize', () => this.adapter?.resize());
        if ('ResizeObserver' in window) {
            this.resizeObserver = new ResizeObserver(() => this.adapter?.resize());
            this.resizeObserver.observe(this.root.querySelector('.map-canvas-wrap'));
        }

        try {
            const result = await new MapProviderFactory().create(
                this.root.querySelector('[data-map-canvas]'),
                this.configuration,
                {
                    onViewportChanged: (bounds) => {
                        if (!bounds) return;
                        this.bounds = bounds;
                        if (!this.isHomeMap) this.fetchStations();
                    },
                    onMarkerSelect: (id) => this.select(id, true),
                    onError: (error) => this.mapError(error),
                    onProviderFailure: ({ provider, error }) => console.warn('Map provider initialization failed.', { provider, code: error?.code }),
                },
            );
            this.adapter = result.instance;
            this.root.dataset.resolvedProvider = result.provider;
            if (!this.isHomeMap) this.bounds = this.adapter.getVisibleBounds();
            if (result.fallbackActive) {
                this.root.querySelector('[data-provider-warning]')?.removeAttribute('hidden');
            }
        } catch (error) {
            this.mapError(error);
        }
        await this.fetchStations();
    }

    parameters() {
        return stationSearchParameters(new FormData(this.form), this.isHomeMap ? null : this.bounds);
    }

    async fetchStations(force = false) {
        const params = this.parameters();
        const key = params.toString();
        if (!shouldFetchStations(this.lastRequestKey, key, force)) return;
        this.lastRequestKey = key;
        this.abortController?.abort();
        this.abortController = new AbortController();
        if (!this.stations.length) this.showState('Loading stations…', 'loading');
        try {
            const response = await fetch(`${this.endpoint}?${params}`, { headers: { Accept: 'application/json' }, signal: this.abortController.signal });
            if (!response.ok) throw new Error('Station search is temporarily unavailable.');
            const payload = await response.json();
            this.stations = payload.data;
            this.render();
            this.populateOperators();
            this.adapter?.setMarkers(this.stations);
            this.fitHomeResults();
        } catch (error) {
            if (error.name === 'AbortError') return;
            this.lastRequestKey = null;
            this.showState(navigator.onLine ? 'We could not refresh stations. Try again shortly.' : 'You are offline. Station information cannot be refreshed.', 'error');
        }
    }

    showState(message, state) {
        this.state.hidden = false;
        this.state.dataset.state = state;
        this.state.textContent = message;
    }

    render() {
        this.list.replaceChildren();
        this.count.textContent = this.stations.length;
        if (!this.stations.length) {
            this.showState(this.isHomeMap
                ? 'No published stations match these filters. Try another search or connector.'
                : 'No public stations match this view. Move the map or clear a filter.', 'empty');
            return;
        }
        this.state.hidden = true;
        this.stations.forEach((station) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'station-card';
            button.dataset.stationId = station.id;
            button.addEventListener('click', () => this.select(station.id, true));
            const top = document.createElement('div'); top.className = 'station-card__top';
            const heading = document.createElement('h3'); heading.textContent = station.site_name;
            top.append(heading, statusBadge(station.is_stale ? 'stale' : station.availability));
            if (station.is_stale && station.availability !== 'stale') {
                const lastStatus = document.createElement('small');
                lastStatus.className = 'status-last-reported';
                lastStatus.textContent = `Last reported ${statusLabel(station.availability)}`;
                top.append(lastStatus);
            }
            const address = document.createElement('p'); address.textContent = station.address_line_1 || 'Address details pending';
            const details = document.createElement('p'); details.textContent = `${station.operator_name} · ${powerLabel(station.maximum_power_w)} · ${station.open_now ? 'Open now' : 'Hours unavailable or closed'}`;
            const tags = document.createElement('div'); tags.className = 'connector-tags';
            station.connectors.forEach((connector) => { const tag = document.createElement('span'); const icon = connectorIcon(connector.standard); if (icon) { const image = document.createElement('img'); image.src = icon; image.alt = ''; image.width = 24; image.height = 24; tag.append(image); } tag.append(document.createTextNode(`${connector.name} · ${connector.current_type} · ${powerLabel(connector.maximum_power_w)}`)); tags.append(tag); });
            button.append(top, address, details, tags); item.append(button); this.list.append(item);
        });
    }

    populateOperators() {
        const select = this.form.querySelector('[data-operator-filter]');
        if (!select) return;
        const selected = select.value;
        const known = new Map([...select.options].map((option) => [option.value, option.textContent]));
        this.stations.forEach((station) => known.set(station.operator_id, station.operator_name));
        select.replaceChildren();
        known.forEach((name, id) => { const option = document.createElement('option'); option.value = id; option.textContent = name; option.selected = id === selected; select.append(option); });
    }

    fitHomeResults() {
        if (!this.isHomeMap || !this.adapter || !this.stations.length) return;
        const key = new URLSearchParams(new FormData(this.form)).toString();
        if (key === this.lastFittedKey) return;
        this.lastFittedKey = key;
        this.adapter.fitBounds(this.stations.map((station) => [Number(station.latitude), Number(station.longitude)]));
    }

    select(id, focusMap) {
        const station = this.stations.find((candidate) => candidate.id === id);
        if (!station) return;
        this.list.querySelectorAll('[data-station-id]').forEach((button) => button.setAttribute('aria-current', String(button.dataset.stationId === id)));
        this.drawerContent.replaceChildren();
        const title = document.createElement('h2'); title.id = 'station-drawer-title'; title.textContent = station.site_name;
        const status = statusBadge(station.is_stale ? 'stale' : station.availability);
        const address = document.createElement('p'); address.textContent = station.address_line_1 || 'Address details pending';
        const meta = document.createElement('div'); meta.className = 'drawer-meta';
        [station.operator_name, powerLabel(station.maximum_power_w), station.open_now ? 'Reported open now' : 'Hours unavailable or currently closed', station.is_stale ? 'Status data is stale' : 'Status freshness checked'].forEach((value) => { const row = document.createElement('span'); row.textContent = value; meta.append(row); });
        const directions = document.createElement('a'); directions.className = 'button'; directions.target = '_blank'; directions.rel = 'noopener'; directions.textContent = 'Get directions'; directions.href = directionsUrl(this.configuration.directionsUrlTemplate, station);
        const detail = document.createElement('a'); detail.className = 'button button--secondary'; detail.textContent = 'Station details'; detail.href = `/stations/${encodeURIComponent(station.public_slug)}`;
        const actions = document.createElement('div'); actions.className = 'station-drawer__actions'; actions.append(directions, detail);
        this.drawerContent.append(title, status, address, meta, actions);
        this.drawer.hidden = false;
        this.drawer.scrollTop = 0;
        this.adapter?.selectMarker(id, { focus: focusMap });
    }

    useLocation() {
        if (!navigator.geolocation) { this.showState('Location is not available in this browser.', 'error'); return; }
        navigator.geolocation.getCurrentPosition(
            ({ coords }) => { this.bounds = null; this.adapter?.showUserPosition(coords.latitude, coords.longitude); const params = new URLSearchParams(new FormData(this.form)); params.set('latitude', coords.latitude); params.set('longitude', coords.longitude); params.set('radius_m', '25000'); params.set('limit', '250'); this.fetchUrl(params); },
            () => this.showState('Location access was not granted. You can still move the map or use filters.', 'error'),
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 },
        );
    }

    async fetchUrl(params) {
        this.lastRequestKey = null;
        this.abortController?.abort(); this.abortController = new AbortController(); this.showState('Finding nearby stations…', 'loading');
        try { const response = await fetch(`${this.endpoint}?${params}`, { headers: { Accept: 'application/json' }, signal: this.abortController.signal }); if (!response.ok) throw new Error(); const payload = await response.json(); this.stations = payload.data; this.render(); this.populateOperators(); this.adapter?.setMarkers(this.stations); }
        catch (error) { if (error.name !== 'AbortError') this.showState('Nearby stations could not be loaded.', 'error'); }
    }

    mapError(error) {
        this.unavailable.hidden = false;
        this.unavailable.querySelector('span').textContent = error?.code === 'tile_failed'
            ? 'Map tiles are unavailable. Filters and the accessible station list remain usable.'
            : 'The map provider is unavailable. Filters and the accessible station list remain usable.';
        console.warn('Map degraded.', { code: error?.code || 'unknown' });
    }

    destroy() {
        this.abortController?.abort();
        this.disposers.splice(0).forEach((dispose) => dispose());
        this.adapter?.destroy();
        this.resizeObserver?.disconnect();
        this.resizeObserver = null;
        this.adapter = null;
        delete this.root.dataset.resolvedProvider;
    }
}

const stationFinders = new Map();

const bootStationFinders = () => {
    document.querySelectorAll('[data-station-map]').forEach((root) => {
        if (stationFinders.has(root)) return;
        const finder = new StationFinder(root);
        stationFinders.set(root, finder);
        finder.start();
    });
};

const destroyStationFinders = () => {
    stationFinders.forEach((finder) => finder.destroy());
    stationFinders.clear();
};

bootStationFinders();
document.addEventListener('livewire:navigated', bootStationFinders);
document.addEventListener('livewire:navigating', destroyStationFinders);
