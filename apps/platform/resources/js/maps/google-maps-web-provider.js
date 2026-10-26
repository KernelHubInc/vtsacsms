import { MarkerClusterer } from '@googlemaps/markerclusterer';
import { MapProviderError, markerAsset, stationMarker, validCoordinate } from './map-provider';

let googleLoaderPromise;

export const loadGoogleMaps = (key, documentObject = document, windowObject = window) => {
    if (windowObject.google?.maps) return Promise.resolve(windowObject.google.maps);
    if (!key) return Promise.reject(new MapProviderError('Google Maps is not configured.', 'provider_not_ready'));
    if (googleLoaderPromise) return googleLoaderPromise;

    googleLoaderPromise = new Promise((resolve, reject) => {
        const callback = `vtsaGoogleMapsReady${Date.now()}`;
        const script = documentObject.createElement('script');
        const cleanup = () => {
            delete windowObject[callback];
            script.onerror = null;
        };
        const timeout = windowObject.setTimeout(() => {
            cleanup();
            googleLoaderPromise = null;
            script.remove();
            reject(new MapProviderError('Google Maps loading timed out.', 'load_timeout'));
        }, 12000);

        windowObject[callback] = () => {
            windowObject.clearTimeout(timeout);
            cleanup();
            resolve(windowObject.google.maps);
        };
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&callback=${callback}&loading=async&v=weekly`;
        script.async = true;
        script.onerror = () => {
            windowObject.clearTimeout(timeout);
            cleanup();
            googleLoaderPromise = null;
            reject(new MapProviderError('Google Maps could not be loaded.', 'load_failed'));
        };
        script.dataset.vtsaGoogleMaps = 'true';
        documentObject.head.append(script);
    });

    return googleLoaderPromise;
};

export class GoogleMapsWebProvider {
    constructor(element, configuration, handlers = {}) {
        this.element = element;
        this.configuration = configuration;
        this.handlers = handlers;
        this.markers = new Map();
        this.listeners = [];
        this.initialized = false;
    }

    async initialize() {
        if (this.initialized) return;
        if (!(this.element instanceof HTMLElement) || !this.element.isConnected) {
            throw new MapProviderError('The map container is unavailable.', 'missing_container');
        }

        try {
            this.maps = await loadGoogleMaps(this.configuration.googleBrowserApiKey);
            this.map = new this.maps.Map(this.element, {
                center: { lat: this.configuration.defaultLatitude, lng: this.configuration.defaultLongitude },
                zoom: this.configuration.defaultZoom,
                minZoom: this.configuration.minimumZoom,
                maxZoom: this.configuration.maximumZoom,
                mapId: this.configuration.googleMapId || undefined,
                streetViewControl: false,
                mapTypeControl: false,
                fullscreenControl: true,
                gestureHandling: 'greedy',
            });
            this.bindEvents();
            this.initialized = true;
            this.element.dataset.mapProvider = 'google';
        } catch (error) {
            this.destroy();
            throw error instanceof MapProviderError
                ? error
                : new MapProviderError('Google Maps could not be initialized.', 'initialization_failed', error);
        }
    }

    bindEvents() {
        let viewportTimer;
        const listener = this.map.addListener('idle', () => {
            clearTimeout(viewportTimer);
            viewportTimer = window.setTimeout(() => this.handlers.onViewportChanged?.(this.getVisibleBounds()), 350);
        });
        this.listeners.push(listener, { remove: () => clearTimeout(viewportTimer) });
    }

    setMarkers(stations) {
        if (!this.map) return;
        const next = new Map(
            stations
                .map(stationMarker)
                .filter((marker) => validCoordinate(marker.latitude, marker.longitude))
                .map((marker) => [marker.id, marker]),
        );

        for (const [id, entry] of this.markers) {
            if (!next.has(id)) {
                entry.instance.setMap(null);
                this.markers.delete(id);
            }
        }

        for (const [id, marker] of next) {
            const existing = this.markers.get(id);
            if (existing) {
                existing.instance.setPosition({ lat: marker.latitude, lng: marker.longitude });
                existing.instance.setIcon(this.icon(marker, id === this.selectedId));
                existing.instance.setTitle(`${marker.title}: ${marker.status}`);
                existing.instance.powerSolutionsBranded = marker.branded;
                existing.data = marker;
                continue;
            }
            const instance = new this.maps.Marker({
                position: { lat: marker.latitude, lng: marker.longitude },
                title: `${marker.title}: ${marker.status}`,
                icon: this.icon(marker, id === this.selectedId),
            });
            instance.powerSolutionsBranded = marker.branded;
            const listener = instance.addListener('click', () => this.handlers.onMarkerSelect?.(id));
            this.listeners.push(listener);
            this.markers.set(id, { instance, data: marker });
        }
        this.clusterer?.clearMarkers();
        this.clusterer = this.configuration.clusteringEnabled
            ? new MarkerClusterer({
                map: this.map,
                markers: [...this.markers.values()].map(({ instance }) => instance),
                renderer: {
                    render: ({ count, position, markers }) => new this.maps.Marker({
                        position,
                        icon: {
                            path: this.maps.SymbolPath.CIRCLE,
                            fillColor: markers.every((marker) => marker.powerSolutionsBranded) ? '#12366b' : '#667891',
                            fillOpacity: 1,
                            strokeColor: '#ffffff',
                            strokeWeight: 3,
                            scale: 28,
                        },
                        label: { text: String(count), color: '#ffffff', fontWeight: '700', fontSize: '14px' },
                    }),
                },
            })
            : null;
        if (!this.clusterer) this.markers.forEach(({ instance }) => instance.setMap(this.map));
    }

    icon(marker, selected) {
        const size = selected && marker.status !== 'available' ? [62, 71] : [56, 64];
        return {
            url: markerAsset(marker, selected),
            scaledSize: new this.maps.Size(...size),
            anchor: new this.maps.Point(size[0] / 2, size[1] * 59 / 64),
        };
    }

    selectMarker(id, { focus = false } = {}) {
        const previous = this.markers.get(this.selectedId);
        if (previous) previous.instance.setIcon(this.icon(previous.data, false));
        this.selectedId = id;
        const selected = this.markers.get(id);
        if (!selected) return;
        selected.instance.setIcon(this.icon(selected.data, true));
        if (focus) this.setCenter(selected.data.latitude, selected.data.longitude, Math.max(this.map.getZoom(), 15));
    }

    setCenter(latitude, longitude, zoom = null) {
        if (!this.map || !validCoordinate(latitude, longitude)) return;
        this.map.panTo({ lat: Number(latitude), lng: Number(longitude) });
        if (zoom !== null) this.map.setZoom(zoom);
    }

    setZoom(zoom) {
        this.map?.setZoom(zoom);
    }

    fitBounds(bounds) {
        if (!this.map || !Array.isArray(bounds) || bounds.length === 0) return;
        const googleBounds = new this.maps.LatLngBounds();
        bounds.filter(([latitude, longitude]) => validCoordinate(latitude, longitude))
            .forEach(([latitude, longitude]) => googleBounds.extend({ lat: Number(latitude), lng: Number(longitude) }));
        if (!googleBounds.isEmpty()) this.map.fitBounds(googleBounds, 32);
    }

    getVisibleBounds() {
        const bounds = this.map?.getBounds();
        if (!bounds) return null;
        const northEast = bounds.getNorthEast();
        const southWest = bounds.getSouthWest();
        return {
            west: southWest.lng(),
            south: southWest.lat(),
            east: northEast.lng(),
            north: northEast.lat(),
        };
    }

    showUserPosition(latitude, longitude) {
        if (!this.map || !validCoordinate(latitude, longitude)) return;
        this.userMarker?.setMap(null);
        this.userMarker = new this.maps.Marker({
            map: this.map,
            position: { lat: Number(latitude), lng: Number(longitude) },
            title: 'Your approximate location',
            icon: {
                path: this.maps.SymbolPath.CIRCLE,
                fillColor: '#2563eb',
                fillOpacity: 1,
                strokeColor: '#ffffff',
                strokeWeight: 3,
                scale: 8,
            },
        });
        this.setCenter(latitude, longitude, 13);
    }

    enableLocationSelection({ latitude, longitude, onChange }) {
        if (!this.map) return;
        const initialValid = validCoordinate(latitude, longitude);
        const initial = initialValid
            ? { lat: Number(latitude), lng: Number(longitude) }
            : { lat: this.configuration.defaultLatitude, lng: this.configuration.defaultLongitude };
        this.locationMarker?.setMap(null);
        this.locationMarker = new this.maps.Marker({
            map: this.map,
            position: initial,
            draggable: true,
            title: 'Selected site location',
        });
        const mapListener = this.map.addListener('click', ({ latLng }) => {
            if (!latLng) return;
            this.locationMarker.setPosition(latLng);
            onChange?.({ latitude: latLng.lat(), longitude: latLng.lng() });
        });
        const dragListener = this.locationMarker.addListener('dragend', ({ latLng }) => {
            if (latLng) onChange?.({ latitude: latLng.lat(), longitude: latLng.lng() });
        });
        this.listeners.push(mapListener, dragListener);
        this.setCenter(initial.lat, initial.lng, initialValid ? Math.max(this.map.getZoom(), 15) : this.configuration.defaultZoom);
    }

    setSelectedLocation(latitude, longitude, { focus = false } = {}) {
        if (!this.locationMarker || !validCoordinate(latitude, longitude)) return;
        this.locationMarker.setPosition({ lat: Number(latitude), lng: Number(longitude) });
        if (focus) this.setCenter(latitude, longitude, Math.max(this.map.getZoom(), 15));
    }

    resize() {
        if (this.map && this.maps?.event) this.maps.event.trigger(this.map, 'resize');
    }

    destroy() {
        this.clusterer?.clearMarkers();
        this.clusterer = null;
        this.markers.forEach(({ instance }) => instance.setMap(null));
        this.markers.clear();
        this.userMarker?.setMap(null);
        this.userMarker = null;
        this.locationMarker?.setMap(null);
        this.locationMarker = null;
        this.listeners.splice(0).forEach((listener) => listener?.remove?.());
        if (this.maps?.event && this.map) this.maps.event.clearInstanceListeners(this.map);
        this.map = null;
        this.maps = null;
        this.initialized = false;
        if (this.element) {
            this.element.replaceChildren();
            delete this.element.dataset.mapProvider;
        }
    }
}
