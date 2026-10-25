@extends('public.layout')

@section('content')
<section class="map-intro station-detail-intro">
    <div class="public-shell">
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
<section class="public-section station-detail">
    <div class="public-shell feature-layout">
        <div>
            <div class="station-detail__status">
                <h2>Availability</h2>
                @php
                    $availability = $station['is_stale'] ? 'stale' : $station['availability'];
                @endphp
                @if (in_array($availability, ['available', 'busy', 'faulted', 'offline', 'unknown', 'stale'], true))
                    <img class="status-asset" src="{{ asset('branding/interface/status-'.$availability.'.svg') }}" alt="{{ ['busy' => 'In use', 'faulted' => 'Needs attention', 'stale' => 'Stale data'][$availability] ?? ucfirst($availability) }}" width="{{ $availability === 'faulted' ? 178 : 164 }}" height="40">
                @else
                    <span class="station-detail__availability station-detail__availability--{{ $availability }}">{{ ucfirst($availability) }}</span>
                @endif
            </div>
            <p class="section-copy">
                @if ($station['is_stale'])
                    This status may be out of date. Confirm at the station before relying on it.
                @elseif ($station['availability'] === 'unknown')
                    The charger has not reported a confirmed availability status. Check on arrival.
                @else
                    This is the latest reported status. Check the station when you arrive.
                @endif
            </p>
            <div class="connector-tags">
                @foreach ($siteStations->flatMap(fn ($item) => $item['connectors'])->unique(fn ($connector) => $connector['standard'].'-'.$connector['maximum_power_w']) as $connector)
                    @php
                        $standard = strtolower($connector['standard']);
                        $connectorIcon = match (true) {
                            str_contains($standard, 'ccs') => 'ccs2',
                            str_contains($standard, 'chademo') => 'chademo',
                            str_contains($standard, 'type2'), str_contains($standard, 'type 2') => 'type2',
                            default => null,
                        };
                    @endphp
                    <span>
                        @if ($connectorIcon)
                            <img src="{{ asset('branding/interface/icon-'.$connectorIcon.'.svg') }}" alt="" width="24" height="24">
                        @endif
                        {{ $connector['name'] }} · {{ $connector['current_type'] }} · {{ round($connector['maximum_power_w'] / 1000) }} kW
                    </span>
                @endforeach
            </div>
        </div>
        <div>
            <dl class="station-detail__facts">
                <div><dt>Operator</dt><dd>{{ $station['operator_name'] }}</dd></div>
                <div><dt>Site category</dt><dd>{{ str($station['site_type'])->replace('_', ' ')->title() }}</dd></div>
                <div><dt>Operating state</dt><dd>{{ $station['open_now'] ? 'Open now' : 'Closed or hours unavailable' }}</dd></div>
                <div><dt>Amenities</dt><dd class="station-detail__amenities">
                    @forelse ($station['amenities'] as $amenity)
                        @php
                            $name = $amenity['name'];
                            $amenityKey = strtolower($amenity['code'] ?? $name);
                            $amenityIcon = match (true) {
                                str_contains($amenityKey, 'wifi'), str_contains($amenityKey, 'wi-fi') => 'wifi',
                                str_contains($amenityKey, 'food'), str_contains($amenityKey, 'drink') => 'food',
                                str_contains($amenityKey, 'shop') => 'shopping',
                                str_contains($amenityKey, 'restroom'), str_contains($amenityKey, 'toilet') => 'restroom',
                                str_contains($amenityKey, 'accessible') => 'accessible',
                                str_contains($amenityKey, 'parking') => 'parking',
                                default => null,
                            };
                        @endphp
                        <span class="amenity-tag">
                            @if ($amenityIcon)
                                <img src="{{ asset('branding/interface/icon-'.$amenityIcon.'.svg') }}" alt="" width="24" height="24">
                            @endif
                            {{ $name }}
                        </span>
                    @empty
                        No amenities published
                    @endforelse
                </dd></div>
            </dl>
            <div class="public-actions">
                <a class="button" target="_blank" rel="noopener" href="{{ $directionsUrl }}">Directions</a>
                <a class="button button--quiet" href="{{ route('public.charging-map') }}">Back to locator</a>
            </div>
        </div>
    </div>
</section>
@endsection
