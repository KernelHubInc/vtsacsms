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

export const isPowerSolutionsOperator = (name) =>
    ['power solutions', 'evcs power solutions'].includes(String(name || '').trim().toLowerCase());

export const markerAsset = ({ status, branded }, selected = false) => {
    if (!branded) return '/branding/interface/pin-other-operator.svg';
    if (selected && status === 'available') return '/branding/interface/pin-selected.svg';
    return `/branding/interface/pin-${normalizeMarkerStatus(status)}.svg`;
};

export const stationMarker = (station) => ({
    id: String(station.id),
    latitude: Number(station.latitude),
    longitude: Number(station.longitude),
    title: String(station.site_name || station.name || 'Charging station'),
    status: station.is_stale === true ? 'stale' : normalizeMarkerStatus(station.availability || station.status),
    branded: isPowerSolutionsOperator(station.operator_name),
    source: station,
});
