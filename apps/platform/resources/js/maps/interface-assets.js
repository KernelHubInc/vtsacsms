const assetRoot = '/branding/interface/';

const statusAssets = new Set(['available', 'busy', 'faulted', 'offline', 'unknown', 'stale']);

export const statusBadge = (status) => {
    if (status === 'unavailable') {
        const label = document.createElement('span');
        label.className = 'status-chip status-chip--unavailable';
        label.textContent = 'Unavailable';
        return label;
    }
    const normalized = statusAssets.has(status) ? status : 'unknown';
    const labels = {
        available: 'Available', busy: 'In use', faulted: 'Needs attention',
        offline: 'Offline', unknown: 'Unknown', stale: 'Stale data',
    };
    const image = document.createElement('img');
    image.className = 'status-asset';
    image.src = `${assetRoot}status-${normalized}.svg`;
    image.alt = labels[normalized];
    image.width = normalized === 'faulted' ? 178 : 164;
    image.height = 40;
    return image;
};

export const connectorIcon = (standard) => {
    const value = String(standard || '').toLowerCase();
    if (value.includes('ccs')) return `${assetRoot}icon-ccs2.svg`;
    if (value.includes('chademo')) return `${assetRoot}icon-chademo.svg`;
    if (value.includes('type 2') || value.includes('type2')) return `${assetRoot}icon-type2.svg`;
    return null;
};

export const amenityIcon = (amenity) => {
    const value = String(amenity || '').toLowerCase();
    if (value.includes('wifi') || value.includes('wi-fi')) return `${assetRoot}icon-wifi.svg`;
    if (value.includes('food') || value.includes('drink')) return `${assetRoot}icon-food.svg`;
    if (value.includes('shop')) return `${assetRoot}icon-shopping.svg`;
    if (value.includes('restroom') || value.includes('toilet')) return `${assetRoot}icon-restroom.svg`;
    if (value.includes('accessible')) return `${assetRoot}icon-accessible.svg`;
    if (value.includes('parking')) return `${assetRoot}icon-parking.svg`;
    return null;
};
