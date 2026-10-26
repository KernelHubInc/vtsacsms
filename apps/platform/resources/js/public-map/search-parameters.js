export function stationSearchParameters(formData, bounds) {
    const params = new URLSearchParams(formData);
    params.set('limit', '250');
    if (bounds && !params.get('query')?.trim()) {
        Object.entries(bounds).forEach(([key, value]) => params.set(key, String(value)));
    }
    return params;
}

export function shouldFetchStations(previousKey, nextKey, force = false) {
    return force || previousKey !== nextKey;
}
