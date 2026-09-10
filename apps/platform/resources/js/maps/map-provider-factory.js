import { GoogleMapsWebProvider } from './google-maps-web-provider';
import { MapProviderFactoryCore } from './map-provider-factory-core';
import { OpenStreetMapWebProvider } from './openstreetmap-web-provider';

export class MapProviderFactory extends MapProviderFactoryCore {
    constructor(adapters = {}) {
        super({
            openstreetmap: adapters.openstreetmap || OpenStreetMapWebProvider,
            google: adapters.google || GoogleMapsWebProvider,
        });
    }
}

export const parseMapConfiguration = (root) => {
    try {
        const configuration = JSON.parse(root.dataset.mapConfig || '{}');
        return {
            provider: configuration.provider || 'openstreetmap',
            defaultLatitude: Number(configuration.defaultLatitude ?? 14.5995),
            defaultLongitude: Number(configuration.defaultLongitude ?? 120.9842),
            defaultZoom: Number(configuration.defaultZoom ?? 11),
            minimumZoom: Number(configuration.minimumZoom ?? 3),
            maximumZoom: Number(configuration.maximumZoom ?? 19),
            tileUrlTemplate: String(configuration.tileUrlTemplate || ''),
            tileAttribution: String(configuration.tileAttribution || ''),
            tileSubdomains: Array.isArray(configuration.tileSubdomains) ? configuration.tileSubdomains : [],
            tileMaximumNativeZoom: Number(configuration.tileMaximumNativeZoom ?? 19),
            tileRetina: Boolean(configuration.tileRetina),
            tileRequestTimeoutSeconds: Number(configuration.tileRequestTimeoutSeconds ?? 10),
            clusteringEnabled: configuration.clusteringEnabled !== false,
            googleBrowserApiKey: String(configuration.googleBrowserApiKey || ''),
            googleMapId: String(configuration.googleMapId || ''),
            directionsUrlTemplate: String(configuration.directionsUrlTemplate || ''),
            fallbackActive: Boolean(configuration.fallbackActive),
            status: String(configuration.status || 'ready'),
        };
    } catch {
        return {
            provider: 'openstreetmap',
            defaultLatitude: 14.5995,
            defaultLongitude: 120.9842,
            defaultZoom: 11,
            minimumZoom: 3,
            maximumZoom: 19,
            tileUrlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            tileAttribution: '© OpenStreetMap contributors',
            tileSubdomains: [],
            tileMaximumNativeZoom: 19,
            tileRetina: false,
            tileRequestTimeoutSeconds: 10,
            clusteringEnabled: true,
            googleBrowserApiKey: '',
            googleMapId: '',
            directionsUrlTemplate: 'https://www.google.com/maps/dir/?api=1&destination={latitude}%2C{longitude}',
            fallbackActive: true,
            status: 'invalid_client_configuration',
        };
    }
};
