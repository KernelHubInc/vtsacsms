@extends('public.layout')

@section('body-class', 'public-site public-site--map')

@section('content')
<section class="map-intro"><div class="public-shell"><p class="eyebrow">{{ $page->eyebrow }}</p><h1>{{ $page->hero_heading }}</h1><p>{{ $page->hero_copy }}</p></div></section>
<section class="station-finder" data-station-map data-endpoint="{{ route('api.v1.public.stations.index') }}" data-map-config='@json($mapConfig)'>
    <div class="demo-data-ribbon">Demo environment · Fictional test locations · Not real chargers</div>
    <div data-provider-warning hidden class="map-provider-warning" role="status">Google Maps is not ready, so OpenStreetMap is active.</div>
    <div class="station-finder__filters">
        <details class="station-filter-panel" data-filter-panel open>
            <summary><span>Filter stations</span><small>Show or hide search options</small></summary>
            <form data-map-filters aria-label="Filter charging stations">
                <label class="filter-field--query">Search<input name="query" type="search" placeholder="Station name"></label>
                <label>City<input name="city" type="search" placeholder="City"></label>
                <label>Connector<select name="connector"><option value="">All connectors</option><option value="ccs2">CCS2</option><option value="chademo">CHAdeMO</option><option value="type2">Type 2</option></select></label>
                <label>Current<select name="current"><option value="">AC or DC</option><option value="AC">AC</option><option value="DC">DC</option></select></label>
                <label>Minimum power<select name="min_power_w"><option value="">Any power</option><option value="22000">22 kW+</option><option value="50000">50 kW+</option><option value="150000">150 kW+</option></select></label>
                <label>Availability<select name="availability"><option value="">Any status</option><option value="available">Available</option><option value="busy">In use</option><option value="faulted">Needs attention</option><option value="offline">Offline</option><option value="stale">Stale data</option></select></label>
                <label>Operator<select name="operator_id" data-operator-filter><option value="">All operators</option></select></label>
                <label>Site type<select name="site_type"><option value="">All site types</option><option value="public_parking">Public parking</option><option value="retail">Retail</option><option value="workplace">Workplace</option><option value="fleet">Fleet</option><option value="highway">Highway</option><option value="hospitality">Hospitality</option></select></label>
                <label>Amenity<select name="amenity"><option value="">Any amenity</option><option value="restroom">Restroom</option><option value="food">Food</option><option value="wifi">Wi-Fi</option><option value="shopping">Shopping</option><option value="accessible-parking">Accessible parking</option></select></label>
                <label class="checkbox-label"><input type="checkbox" name="open_now" value="1"> Open now</label>
                <button class="button button--small" type="submit">Apply filters</button>
            </form>
        </details>
    </div>
    <div class="station-finder__workspace">
        <aside class="station-list-panel" aria-label="Charging station results">
            <div class="result-summary"><div><p class="eyebrow">Network view</p><h2><span data-result-count>0</span> stations</h2></div><button type="button" class="text-button" data-use-location>Near me</button></div>
            <div class="map-state" data-map-state role="status" aria-live="polite"><span class="spinner" aria-hidden="true"></span>Loading stations…</div>
            <ol class="station-list" data-station-list></ol>
        </aside>
        <div class="map-canvas-wrap">
            <div class="map-canvas" data-map-canvas aria-label="Interactive charging station map"></div>
            <div class="map-unavailable" data-map-unavailable hidden><strong>Interactive map unavailable</strong><span>The accessible station list remains available. Check the selected map provider and browser network access.</span></div>
            <aside class="station-drawer" data-station-drawer hidden role="dialog" aria-labelledby="station-drawer-title" tabindex="-1">
                <button type="button" data-drawer-close aria-label="Close station details">×</button><div data-drawer-content></div>
            </aside>
        </div>
    </div>
</section>
<noscript><div class="public-shell"><div class="legal-review"><strong>JavaScript is off</strong><span>Use the station search API or enable JavaScript for the synchronized accessible list.</span></div></div></noscript>
@endsection
