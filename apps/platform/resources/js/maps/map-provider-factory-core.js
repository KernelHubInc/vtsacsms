import { MapProviderError } from './map-provider.js';

export class MapProviderFactoryCore {
    constructor(adapters) {
        this.adapters = adapters;
    }

    async create(element, configuration, handlers = {}) {
        const requested = ['openstreetmap', 'google'].includes(configuration.provider)
            ? configuration.provider
            : 'openstreetmap';
        const attempts = requested === 'google' ? ['google', 'openstreetmap'] : ['openstreetmap'];
        let lastError;

        for (const provider of attempts) {
            let instance;
            try {
                const Adapter = this.adapters[provider];
                if (!Adapter) throw new MapProviderError(`No adapter is registered for ${provider}.`, 'adapter_missing');
                instance = new Adapter(element, { ...configuration, provider }, handlers);
                await instance.initialize();
                return {
                    instance,
                    provider,
                    fallbackActive: provider !== requested || Boolean(configuration.fallbackActive),
                };
            } catch (error) {
                lastError = error;
                instance?.destroy?.();
                handlers.onProviderFailure?.({ provider, error });
            }
        }

        throw new MapProviderError(
            'No configured map provider could be initialized.',
            'all_providers_failed',
            lastError,
        );
    }
}

export const assertMapProviderContract = (provider) => {
    const required = [
        'initialize',
        'destroy',
        'resize',
        'setCenter',
        'setZoom',
        'fitBounds',
        'setMarkers',
        'selectMarker',
        'getVisibleBounds',
        'showUserPosition',
        'enableLocationSelection',
        'setSelectedLocation',
    ];
    const missing = required.filter((method) => typeof provider?.[method] !== 'function');
    if (missing.length) {
        throw new MapProviderError(`Map adapter contract is incomplete: ${missing.join(', ')}`, 'contract_incomplete');
    }
    return true;
};
