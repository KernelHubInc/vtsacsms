@extends('public.layout')

@section('content')
<section class="map-intro">
    <div class="public-shell">
        <p class="eyebrow">Demo station profile</p>
        <h1>{{ $station['site_name'] }}</h1>
        <p>{{ $station['address_line_1'] }}{{ $station['city_name'] ? ', '.$station['city_name'] : '' }}</p>
    </div>
</section>
<section class="station-detail-map" data-station-detail-map data-map-config='@json($mapConfig)' data-station='@json($station)'>
    <div data-map-canvas class="station-detail-map__canvas" aria-label="Map showing the exact published station coordinates"></div>
    <div data-map-unavailable hidden class="map-unavailable">
        <strong>Interactive map unavailable</strong>
        <span>The station information and directions action remain available.</span>
    </div>
</section>
<section class="public-section">
    <div class="public-shell feature-layout">
        <div>
            <div class="legal-review">
                <strong>Demo · Test data</strong>
                <span>This is a fictional location and is not a real operational EV charger.</span>
            </div>
            <p class="eyebrow">Availability</p>
            <h2>{{ ucfirst($station['availability']) }}</h2>
            <p class="section-copy">Status {{ $station['is_stale'] ? 'is stale and should be confirmed on arrival' : 'was recently observed' }}. No charger command is sent from this Milestone 1 page.</p>
            <div class="connector-tags">
                @foreach ($siteStations->flatMap(fn ($item) => $item['connectors'])->unique(fn ($connector) => $connector['standard'].'-'.$connector['maximum_power_w']) as $connector)
                    <span>{{ $connector['name'] }} · {{ $connector['current_type'] }} · {{ round($connector['maximum_power_w'] / 1000) }} kW</span>
                @endforeach
            </div>
        </div>
        <div>
            <p><strong>Operator</strong><br>{{ $station['operator_name'] }}</p>
            <p><strong>Site category</strong><br>{{ str($station['site_type'])->replace('_', ' ')->title() }}</p>
            <p><strong>Operating state</strong><br>{{ $station['open_now'] ? 'Open now' : 'Closed or hours unavailable' }}</p>
            <p><strong>Amenities</strong><br>{{ collect($station['amenities'])->pluck('name')->join(', ') ?: 'No amenities published' }}</p>
            <div class="public-actions">
                <a class="button" target="_blank" rel="noopener" href="{{ $directionsUrl }}">Directions</a>
                <a class="button button--quiet" href="{{ route('public.charging-map') }}">Back to locator</a>
            </div>
        </div>
    </div>
</section>
@endsection
