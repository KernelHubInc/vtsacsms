export const directionsUrl = (template, station) => {
    const latitude = Number(station.latitude);
    const longitude = Number(station.longitude);
    const name = String(station.site_name || station.name || 'Charging station');

    return String(template || 'https://www.google.com/maps/dir/?api=1&destination={latitude}%2C{longitude}')
        .replaceAll('{latitude}', encodeURIComponent(latitude))
        .replaceAll('{longitude}', encodeURIComponent(longitude))
        .replaceAll('{name}', encodeURIComponent(name));
};
