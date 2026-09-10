export class MapProviderError extends Error {
    constructor(message, code, cause = null) {
        super(message, cause ? { cause } : undefined);
        this.name = 'MapProviderError';
        this.code = code;
    }
}

export const validCoordinate = (latitude, longitude) => (
    Number.isFinite(Number(latitude))
    && Number.isFinite(Number(longitude))
    && Number(latitude) >= -90
    && Number(latitude) <= 90
    && Number(longitude) >= -180
    && Number(longitude) <= 180
);

export const normalizeMarkerStatus = (status) => (
    ['available', 'busy', 'faulted', 'offline', 'stale'].includes(status) ? status : 'unknown'
);

export const stationMarker = (station) => ({
    id: String(station.id),
    latitude: Number(station.latitude),
    longitude: Number(station.longitude),
    title: String(station.site_name || station.name || 'Charging station'),
    status: normalizeMarkerStatus(station.availability || station.status),
    source: station,
});
