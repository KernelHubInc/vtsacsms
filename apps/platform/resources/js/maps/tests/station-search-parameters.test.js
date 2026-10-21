import test from 'node:test';
import assert from 'node:assert/strict';
import { stationSearchParameters } from '../../public-map/search-parameters.js';

test('a named-station search is not limited to the map opening viewport', () => {
    const filters = new URLSearchParams({ query: 'Lumina', connector: 'type2' });
    const params = stationSearchParameters(filters, { north: 15, south: 14, east: 121, west: 120 });

    assert.equal(params.get('query'), 'Lumina');
    assert.equal(params.get('connector'), 'type2');
    assert.equal(params.get('north'), null);
    assert.equal(params.get('limit'), '250');
});

test('browsing without a station name stays within the current map view', () => {
    const params = stationSearchParameters(new URLSearchParams({ connector: 'type2' }), { north: 15, south: 14 });

    assert.equal(params.get('north'), '15');
    assert.equal(params.get('south'), '14');
});

test('the homepage can request published stations across the network', () => {
    const params = stationSearchParameters(new URLSearchParams({ connector: 'type2' }), null);

    assert.equal(params.get('connector'), 'type2');
    assert.equal(params.get('north'), null);
    assert.equal(params.get('limit'), '250');
});
