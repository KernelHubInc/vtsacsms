import { MapProviderFactory, parseMapConfiguration } from './maps/map-provider-factory';
import { validCoordinate } from './maps/map-provider';

const pickers = new Map();

const boundInput = (form, suffix) => [...form.querySelectorAll('input')].find((input) => (
    [...input.attributes].some((attribute) => (
        attribute.name.startsWith('wire:model') && attribute.value.endsWith(`.${suffix}`)
    ))
));

const bootLocationPickers = () => {
    document.querySelectorAll('[data-location-picker]').forEach(async (root) => {
        if (pickers.has(root)) return;
        const state = { provider: null, disposers: [] };
        pickers.set(root, state);
        const form = root.closest('form');
        const latitudeInput = form ? boundInput(form, 'latitude') : null;
        const longitudeInput = form ? boundInput(form, 'longitude') : null;
        const readout = root.querySelector('[data-location-readout]');
        const error = root.querySelector('[data-map-error]');

        if (!latitudeInput || !longitudeInput) {
            error.hidden = false;
            return;
        }

        const updateReadout = (latitude, longitude) => {
            readout.textContent = validCoordinate(latitude, longitude)
                ? `${Number(latitude).toFixed(6)}, ${Number(longitude).toFixed(6)}`
                : 'No valid coordinate selected';
        };
        const updateFields = ({ latitude, longitude }) => {
            latitudeInput.value = Number(latitude).toFixed(6);
            longitudeInput.value = Number(longitude).toFixed(6);
            for (const input of [latitudeInput, longitudeInput]) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            updateReadout(latitude, longitude);
        };
        const onManualInput = () => {
            const latitude = Number(latitudeInput.value);
            const longitude = Number(longitudeInput.value);
            updateReadout(latitude, longitude);
            state.provider?.setSelectedLocation(latitude, longitude);
        };
        latitudeInput.addEventListener('change', onManualInput);
        longitudeInput.addEventListener('change', onManualInput);
        state.disposers.push(
            () => latitudeInput.removeEventListener('change', onManualInput),
            () => longitudeInput.removeEventListener('change', onManualInput),
        );

        try {
            const result = await new MapProviderFactory().create(
                root.querySelector('[data-map-canvas]'),
                parseMapConfiguration(root),
                {
                    onViewportChanged: () => {},
                    onMarkerSelect: () => {},
                    onError: () => { error.hidden = false; },
                },
            );
            state.provider = result.instance;
            const latitude = latitudeInput.value || root.dataset.initialLatitude;
            const longitude = longitudeInput.value || root.dataset.initialLongitude;
            state.provider.enableLocationSelection({ latitude, longitude, onChange: updateFields });
            updateReadout(latitude, longitude);
            root.querySelector('[data-location-provider]').textContent = result.provider === 'google'
                ? 'Google Maps'
                : result.fallbackActive ? 'OpenStreetMap · fallback active' : 'OpenStreetMap';
            const observer = new ResizeObserver(() => state.provider?.resize());
            observer.observe(root.querySelector('[data-map-canvas]'));
            state.disposers.push(() => observer.disconnect());
        } catch (providerError) {
            error.hidden = false;
            root.querySelector('[data-location-provider]').textContent = 'Manual coordinates active';
            console.warn('Location picker map unavailable.', { code: providerError?.code || 'unknown' });
        }
    });
};

const destroyLocationPickers = () => {
    pickers.forEach(({ provider, disposers }) => {
        disposers.forEach((dispose) => dispose());
        provider?.destroy();
    });
    pickers.clear();
};

bootLocationPickers();
document.addEventListener('livewire:navigated', bootLocationPickers);
document.addEventListener('livewire:navigating', destroyLocationPickers);
