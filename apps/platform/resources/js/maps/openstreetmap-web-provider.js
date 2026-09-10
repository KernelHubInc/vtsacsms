import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { MapProviderError, stationMarker, validCoordinate } from './map-provider';

export class OpenStreetMapWebProvider {
    constructor(element, configuration, handlers = {}) {
        this.element = element;
        this.configuration = configuration;
        this.handlers = handlers;
        this.markers = new Map();
        this.disposers = [];
        this.initialized = false;
    }

    async initialize() {
        if (this.initialized) return;
        if (!(this.element instanceof HTMLElement) || !this.element.isConnected) {
            throw new MapProviderError('The map container is unavailable.', 'missing_container');
        }
        if (!this.configuration.tileUrlTemplate || !this.configuration.tileAttribution) {
            throw new MapProviderError('OpenStreetMap tile configuration is incomplete.', 'invalid_tile_configuration');
        }

        try {
            this.map = L.map(this.element, {
                zoomControl: true,
                preferCanvas: true,
                minZoom: this.configuration.minimumZoom,
                maxZoom: this.configuration.maximumZoom,
            }).setView(
                [this.configuration.defaultLatitude, this.configuration.defaultLongitude],
                this.configuration.defaultZoom,
            );
            this.tileLayer = L.tileLayer(this.configuration.tileUrlTemplate, {
                attribution: this.configuration.tileAttribution,
                minZoom: this.configuration.minimumZoom,
                maxZoom: this.configuration.maximumZoom,
                maxNativeZoom: this.configuration.tileMaximumNativeZoom,
                subdomains: this.configuration.tileSubdomains || [],
                detectRetina: Boolean(this.configuration.tileRetina),
            }).addTo(this.map);
            this.markerLayer = this.configuration.clusteringEnabled
                ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 52 })
                : L.layerGroup();
            this.map.addLayer(this.markerLayer);
            this.bindEvents();
            this.initialized = true;
            this.element.dataset.mapProvider = 'openstreetmap';
            queueMicrotask(() => this.resize());
        } catch (error) {
            this.destroy();
            throw new MapProviderError('OpenStreetMap could not be initialized.', 'initialization_failed', error);
        }
    }

    bindEvents() {
        let viewportTimer;
        const onViewportChanged = () => {
            clearTimeout(viewportTimer);
            viewportTimer = window.setTimeout(() => {
                if (!this.map) return;
                this.handlers.onViewportChanged?.(this.getVisibleBounds());
            }, 350);
        };
        const onTileError = (event) => this.handlers.onError?.(
            new MapProviderError('A map tile could not be loaded.', 'tile_failed', event?.error),
        );
        this.map.on('moveend', onViewportChanged);
        this.tileLayer.on('tileerror', onTileError);
        this.disposers.push(
            () => clearTimeout(viewportTimer),
            () => this.map?.off('moveend', onViewportChanged),
            () => this.tileLayer?.off('tileerror', onTileError),
        );
    }

    setMarkers(stations) {
        if (!this.map || !this.markerLayer) return;
        const next = new Map(
            stations
                .map(stationMarker)
                .filter((marker) => validCoordinate(marker.latitude, marker.longitude))
                .map((marker) => [marker.id, marker]),
        );

        for (const [id, entry] of this.markers) {
            if (!next.has(id)) {
                this.markerLayer.removeLayer(entry.instance);
                this.markers.delete(id);
            }
        }

        for (const [id, marker] of next) {
            const existing = this.markers.get(id);
            if (existing) {
                existing.instance.setLatLng([marker.latitude, marker.longitude]);
                existing.instance.setIcon(this.icon(marker.status, id === this.selectedId));
                existing.instance.options.title = `${marker.title}: ${marker.status}`;
                existing.data = marker;
                continue;
            }
            const instance = L.marker([marker.latitude, marker.longitude], {
                title: `${marker.title}: ${marker.status}`,
                icon: this.icon(marker.status, id === this.selectedId),
                keyboard: true,
            });
            instance.on('click', () => this.handlers.onMarkerSelect?.(id));
            this.markers.set(id, { instance, data: marker });
            this.markerLayer.addLayer(instance);
        }
    }

    icon(status, selected) {
        return L.divIcon({
            className: 'vtsa-leaflet-marker',
            html: `<span class="map-marker map-marker--${status}${selected ? ' map-marker--selected' : ''}"><span aria-hidden="true">⚡</span></span>`,
            iconSize: [40, 48],
            iconAnchor: [20, 44],
        });
    }

    selectMarker(id, { focus = false } = {}) {
        const previous = this.markers.get(this.selectedId);
        if (previous) previous.instance.setIcon(this.icon(previous.data.status, false));
        this.selectedId = id;
        const selected = this.markers.get(id);
        if (!selected) return;
        selected.instance.setIcon(this.icon(selected.data.status, true));
        if (focus) this.setCenter(selected.data.latitude, selected.data.longitude, Math.max(this.map.getZoom(), 15));
    }

    setCenter(latitude, longitude, zoom = null) {
        if (!this.map || !validCoordinate(latitude, longitude)) return;
        this.map.setView([Number(latitude), Number(longitude)], zoom ?? this.map.getZoom());
    }

    setZoom(zoom) {
        this.map?.setZoom(zoom);
    }

    fitBounds(bounds) {
        if (!this.map || !Array.isArray(bounds) || bounds.length === 0) return;
        const coordinates = bounds.filter(([latitude, longitude]) => validCoordinate(latitude, longitude));
        if (coordinates.length) this.map.fitBounds(coordinates, { padding: [32, 32], maxZoom: 16 });
    }

    getVisibleBounds() {
        const bounds = this.map?.getBounds();
        return bounds ? {
            west: bounds.getWest(),
            south: bounds.getSouth(),
            east: bounds.getEast(),
            north: bounds.getNorth(),
        } : null;
    }

    showUserPosition(latitude, longitude) {
        if (!this.map || !validCoordinate(latitude, longitude)) return;
        this.userMarker?.remove();
        this.userMarker = L.circleMarker([Number(latitude), Number(longitude)], {
            radius: 8,
            weight: 3,
            color: '#ffffff',
            fillColor: '#2563eb',
            fillOpacity: 1,
        }).addTo(this.map).bindTooltip('Your approximate location');
        this.setCenter(latitude, longitude, 13);
    }

    enableLocationSelection({ latitude, longitude, onChange }) {
        if (!this.map) return;
        const initialValid = validCoordinate(latitude, longitude);
        const initial = initialValid
            ? [Number(latitude), Number(longitude)]
            : [this.configuration.defaultLatitude, this.configuration.defaultLongitude];
        this.locationMarker?.remove();
        this.locationMarker = L.marker(initial, {
            draggable: true,
            title: 'Selected site location',
        }).addTo(this.map);
        const emit = ({ lat, lng }) => onChange?.({ latitude: lat, longitude: lng });
        const onMapClick = ({ latlng }) => {
            this.locationMarker.setLatLng(latlng);
            emit(latlng);
        };
        const onDragEnd = () => emit(this.locationMarker.getLatLng());
        this.map.on('click', onMapClick);
        this.locationMarker.on('dragend', onDragEnd);
        this.disposers.push(
            () => this.map?.off('click', onMapClick),
            () => this.locationMarker?.off('dragend', onDragEnd),
        );
        this.setCenter(initial[0], initial[1], initialValid ? Math.max(this.map.getZoom(), 15) : this.configuration.defaultZoom);
    }

    setSelectedLocation(latitude, longitude, { focus = false } = {}) {
        if (!this.locationMarker || !validCoordinate(latitude, longitude)) return;
        this.locationMarker.setLatLng([Number(latitude), Number(longitude)]);
        if (focus) this.setCenter(latitude, longitude, Math.max(this.map.getZoom(), 15));
    }

    resize() {
        this.map?.invalidateSize({ pan: false });
    }

    destroy() {
        this.disposers.splice(0).forEach((dispose) => dispose());
        this.markers.clear();
        this.userMarker = null;
        this.locationMarker = null;
        if (this.map) {
            this.map.off();
            this.map.remove();
        }
        this.map = null;
        this.tileLayer = null;
        this.markerLayer = null;
        this.initialized = false;
        if (this.element) delete this.element.dataset.mapProvider;
    }
}
