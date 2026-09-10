<?php

declare(strict_types=1);

return [
    'providers' => [
        'default' => env('MAP_PROVIDER_DEFAULT', 'openstreetmap'),
        'admin' => env('MAP_PROVIDER_ADMIN', 'openstreetmap'),
        'operator' => env('MAP_PROVIDER_OPERATOR', 'openstreetmap'),
        'user_web' => env('MAP_PROVIDER_USER_WEB', 'openstreetmap'),
        'public' => env('MAP_PROVIDER_PUBLIC', 'openstreetmap'),
        'mobile' => env('MAP_PROVIDER_MOBILE', 'openstreetmap'),
    ],

    'viewport' => [
        'latitude' => (float) env('MAP_DEFAULT_LATITUDE', 14.5995),
        'longitude' => (float) env('MAP_DEFAULT_LONGITUDE', 120.9842),
        'zoom' => (int) env('MAP_DEFAULT_ZOOM', 11),
        'minimum_zoom' => (int) env('MAP_MIN_ZOOM', 3),
        'maximum_zoom' => (int) env('MAP_MAX_ZOOM', 19),
    ],

    'tiles' => [
        'url_template' => env('MAP_TILE_URL_TEMPLATE', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('MAP_TILE_ATTRIBUTION', '© OpenStreetMap contributors'),
        'subdomains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAP_TILE_SUBDOMAINS', '')),
        ))),
        'maximum_native_zoom' => (int) env('MAP_TILE_MAX_NATIVE_ZOOM', 19),
        'retina' => (bool) env('MAP_TILE_RETINA', false),
        'request_timeout_seconds' => (int) env('MAP_TILE_REQUEST_TIMEOUT_SECONDS', 10),
    ],

    'clustering_enabled' => (bool) env('MAP_MARKER_CLUSTERING', true),

    'directions' => [
        'web_url_template' => env(
            'MAP_DIRECTIONS_URL_TEMPLATE',
            'https://www.google.com/maps/dir/?api=1&destination={latitude}%2C{longitude}',
        ),
    ],

    'google' => [
        'browser_api_key' => env('GOOGLE_MAPS_BROWSER_API_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID'),
        'mobile_ready' => (bool) env('GOOGLE_MAPS_MOBILE_READY', false),
    ],

    'settings_cache_seconds' => (int) env('MAP_SETTINGS_CACHE_SECONDS', 300),
];
