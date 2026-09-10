import assert from 'node:assert/strict';
import test from 'node:test';
import { MapProviderFactoryCore, assertMapProviderContract } from '../map-provider-factory-core.js';
import { stationMarker, validCoordinate } from '../map-provider.js';

class ContractAdapter {
    constructor(element, configuration) {
        this.element = element;
        this.configuration = configuration;
        this.markers = new Map();
    }

    async initialize() { this.initialized = true; }
    destroy() { this.destroyed = true; }
    resize() {}
    setCenter() {}
    setZoom() {}
    fitBounds() {}
    setMarkers(markers) { markers.forEach((marker) => this.markers.set(marker.id, marker)); }
    selectMarker(id) { this.selected = id; }
    getVisibleBounds() { return { west: 120, south: 14, east: 121, north: 15 }; }
    showUserPosition() {}
    enableLocationSelection() {}
    setSelectedLocation() {}
}

class FailingAdapter extends ContractAdapter {
    async initialize() { throw new Error('mocked SDK failure'); }
}

test('provider contract covers lifecycle, markers, bounds, and location selection', () => {
    assert.equal(assertMapProviderContract(new ContractAdapter({}, {})), true);
    assert.throws(
        () => assertMapProviderContract({ initialize() {} }),
        (error) => error.code === 'contract_incomplete',
    );
});

test('Google initialization failure is cleaned up and falls back once', async () => {
    const failures = [];
    const factory = new MapProviderFactoryCore({
        google: FailingAdapter,
        openstreetmap: ContractAdapter,
    });
    const result = await factory.create({}, { provider: 'google' }, {
        onProviderFailure: ({ provider }) => failures.push(provider),
    });

    assert.equal(result.provider, 'openstreetmap');
    assert.equal(result.fallbackActive, true);
    assert.deepEqual(failures, ['google']);
    assert.equal(result.instance.initialized, true);
});

test('unknown provider is normalized to OpenStreetMap', async () => {
    const result = await new MapProviderFactoryCore({
        openstreetmap: ContractAdapter,
        google: ContractAdapter,
    }).create({}, { provider: 'unknown' });

    assert.equal(result.provider, 'openstreetmap');
});

test('station markers preserve exact WGS84 coordinates and stable IDs', () => {
    const marker = stationMarker({
        id: '01TEST',
        latitude: '14.599500',
        longitude: '120.984200',
        site_name: '<unsafe>',
        availability: 'available',
    });

    assert.equal(marker.id, '01TEST');
    assert.equal(marker.latitude, 14.5995);
    assert.equal(marker.longitude, 120.9842);
    assert.equal(marker.title, '<unsafe>');
    assert.equal(validCoordinate(marker.latitude, marker.longitude), true);
    assert.equal(validCoordinate(91, marker.longitude), false);
});
