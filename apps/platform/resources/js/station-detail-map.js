import { MapProviderFactory, parseMapConfiguration } from './maps/map-provider-factory';

const detailMaps = new Map();

const bootDetailMaps = () => {
    document.querySelectorAll('[data-station-detail-map]').forEach(async (root) => {
        if (detailMaps.has(root)) return;
        const state = { provider: null };
        detailMaps.set(root, state);
        try {
            const station = JSON.parse(root.dataset.station || '{}');
            const result = await new MapProviderFactory().create(
                root.querySelector('[data-map-canvas]'),
                parseMapConfiguration(root),
                {
                    onMarkerSelect: () => {},
                    onViewportChanged: () => {},
                    onError: () => root.querySelector('[data-map-unavailable]').removeAttribute('hidden'),
                },
            );
            state.provider = result.instance;
            state.provider.setMarkers([station]);
            state.provider.selectMarker(String(station.id), { focus: true });
            root.dataset.resolvedProvider = result.provider;
        } catch (error) {
            root.querySelector('[data-map-unavailable]').removeAttribute('hidden');
            console.warn('Station detail map unavailable.', { code: error?.code || 'unknown' });
        }
    });
};

const destroyDetailMaps = () => {
    detailMaps.forEach(({ provider }) => provider?.destroy());
    detailMaps.clear();
};

bootDetailMaps();
document.addEventListener('livewire:navigated', bootDetailMaps);
document.addEventListener('livewire:navigating', destroyDetailMaps);
