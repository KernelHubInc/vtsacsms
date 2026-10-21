@extends('public.layout')

@section('body-class', 'public-site public-site--home')

@section('content')
@php
    $signalSection = $page->sections->first();
    $partnerSection = $page->sections->last();
@endphp

<section class="home-hero" aria-labelledby="home-title">
    <div class="home-wrap home-hero__intro">
        <h1 id="home-title">{{ $page->hero_heading ?: $page->title }}</h1>
        <div class="home-hero__aside">
            @if ($page->hero_copy)<p>{{ $page->hero_copy }}</p>@endif
            <a class="home-button home-button--bright" href="{{ route('public.charging-map') }}">Find a charger <span aria-hidden="true">↗</span></a>
            <a class="home-text-link home-text-link--light" href="{{ route('public.for-operators-and-site-hosts') }}">Have a location in mind? Partner with us <span aria-hidden="true">↗</span></a>
        </div>
    </div>
    <figure class="home-hero__image">
        <picture>
            <source media="(max-width: 640px)" srcset="{{ asset('branding/home/hero-mobile.png') }}">
            <img src="{{ asset('branding/home/hero-desktop.png') }}" alt="An electric car parked at a charging stop beside an everyday destination" width="1312" height="480" fetchpriority="high">
        </picture>
        <figcaption>More of life. Less of the detour.</figcaption>
    </figure>
</section>

<section class="home-network" aria-labelledby="home-network-title">
    <div class="home-wrap home-network__grid">
        <h2 id="home-network-title">One connected network, built place by place.</h2>
        <div>
            <p>Charging belongs where life happens. We connect drivers with useful stops and help the people behind those places deliver a better charging experience.</p>
            <ul class="home-inline-list" aria-label="What matters to us">
                <li>Everyday places</li><li>Clear information</li><li>Connected service</li>
            </ul>
        </div>
    </div>
</section>

<section class="home-discovery" aria-labelledby="home-discovery-title">
    <div class="home-wrap">
        <div class="home-section-heading">
            <h2 id="home-discovery-title">A clearer view of charging.</h2>
            <div><p>Choose your connector. Explore the stop. Know what to check before you go.</p><a class="home-text-link" href="{{ route('public.charging-map') }}">Open the charging map <span aria-hidden="true">↗</span></a></div>
        </div>
        <div class="home-discovery__panel" data-station-map data-home-map data-endpoint="{{ route('api.v1.public.stations.index') }}" data-map-config='@json($mapConfig)'>
            <form class="home-discovery__search" data-map-filters action="{{ route('public.charging-map') }}" method="get" aria-label="Search charging stations">
                <label for="home-station-search">Find a charging stop</label>
                <div class="home-discovery__controls">
                    <input id="home-station-search" name="query" type="search" placeholder="Search by station name" autocomplete="off">
                    <select name="connector" aria-label="Connector type">
                        <option value="">All plugs</option>
                        <option value="ccs2">CCS2</option>
                        <option value="type2">Type 2</option>
                        <option value="chademo">CHAdeMO</option>
                    </select>
                    <select name="availability" aria-label="Station status">
                        <option value="">All statuses</option>
                        <option value="available">Available</option>
                        <option value="busy">In use</option>
                        <option value="offline">Offline</option>
                        <option value="faulted">Needs attention</option>
                        <option value="stale">Stale data</option>
                    </select>
                    <button class="home-button home-button--dark" type="submit">Search stations <span aria-hidden="true">↗</span></button>
                </div>
            </form>
            <div data-provider-warning hidden class="map-provider-warning" role="status">Google Maps is not ready, so OpenStreetMap is active.</div>
            <div class="home-discovery__preview">
                <div class="home-discovery__preview-copy">
                    <h3>See the details before the drive.</h3>
                    <p>Published stations stay on the map when offline. Their status is shown clearly, so you can check before travelling.</p>
                    <div class="home-discovery__results" aria-label="Charging station results">
                        <div class="home-discovery__results-heading"><h4><span data-result-count>0</span> matching stations</h4><button type="button" data-use-location>Near me</button></div>
                        <div class="map-state" data-map-state role="status" aria-live="polite">Loading stations…</div>
                        <ol class="station-list" data-station-list></ol>
                    </div>
                    <a href="{{ route('public.charging-map') }}">Open full charging map <span aria-hidden="true">↗</span></a>
                </div>
                <div class="home-discovery__map map-canvas-wrap">
                    <div class="map-canvas" data-map-canvas aria-label="Interactive charging station map"></div>
                    <div class="map-unavailable" data-map-unavailable hidden><strong>Interactive map unavailable</strong><span>The station list remains available. You can also open the full charging map.</span></div>
                    <aside class="station-drawer" data-station-drawer hidden role="dialog" aria-labelledby="station-drawer-title" tabindex="-1">
                        <button type="button" data-drawer-close aria-label="Close station details">×</button><div data-drawer-content></div>
                    </aside>
                </div>
            </div>
        </div>
        <p class="home-discovery__note">Station status can change. Check the latest details and site signage before relying on a charger.</p>
    </div>
</section>

<section class="home-signals" aria-labelledby="home-signals-title">
    <div class="home-wrap">
        <div class="home-section-heading">
            <h2 id="home-signals-title">{{ $signalSection?->heading ?? 'Know the stop. Not just the pin.' }}</h2>
            <p>{{ $signalSection?->copy ?? 'Useful information should make decisions easier before you set off.' }}</p>
        </div>
        <div class="home-signals__grid">
            @foreach (($signalSection?->items ?? ['Current status', 'Connector details', 'A useful place to stop']) as $item)
                <article>
                    <span class="home-signals__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <h3>{{ $item }}</h3>
                    <p>@switch($loop->iteration)
                        @case(1) See when a status was last reported, including when information is stale or unknown. @break
                        @case(2) Use the synchronized station list when the map is hard to use or unavailable. @break
                        @default Filter by plug, power and availability while keeping the details of each stop close at hand.
                    @endswitch</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="home-journey" aria-labelledby="home-journey-title">
    <div class="home-wrap home-journey__grid">
        <figure class="home-journey__image">
            <picture>
                <source media="(max-width: 640px)" srcset="{{ asset('branding/home/driver-mobile.png') }}">
                <img src="{{ asset('branding/home/driver-desktop.png') }}" alt="A driver connecting an electric car to a charging point" width="540" height="560" loading="lazy" decoding="async">
            </picture>
            <figcaption>Built around real journeys.</figcaption>
        </figure>
        <div class="home-journey__copy">
            <h2 id="home-journey-title">Less uncertainty between here and there.</h2>
            <ol>
                <li><span>01</span><div><h3>Find your fit.</h3><p>Search by place and connector. Check the latest available station details before setting off.</p></div></li>
                <li><span>02</span><div><h3>Check the details.</h3><p>Review the charger, operating information and tariff in the app before you begin.</p></div></li>
                <li><span>03</span><div><h3>Keep the trip moving.</h3><p>Follow the charging session in the app and review the final record when it is complete.</p></div></li>
            </ol>
            <a class="home-button home-button--outline" href="{{ route('public.for-ev-drivers') }}">Explore the driver experience <span aria-hidden="true">↗</span></a>
        </div>
    </div>
</section>

<section class="home-places" aria-labelledby="home-places-title">
    <div class="home-wrap">
        <div class="home-section-heading">
            <h2 id="home-places-title">A good stop. For more than a charge.</h2>
            <p>From a quick errand to a working day, charging can fit the rhythm of the places you already choose.</p>
        </div>
        <div class="home-places__grid">
            @foreach ([
                ['image' => 'destination-1', 'title' => 'While you pick up the everyday.', 'copy' => 'Make charging part of the shopping trip, not another errand.', 'type' => 'Retail and local stops'],
                ['image' => 'destination-2', 'title' => 'While you get down to work.', 'copy' => 'A useful amenity for the places people spend their day.', 'type' => 'Workplaces and offices'],
                ['image' => 'destination-3', 'title' => 'While you take a little time out.', 'copy' => 'Connect at the places worth stopping for, then continue your journey.', 'type' => 'Hospitality and destinations'],
            ] as $place)
                <article>
                    <a href="{{ route('public.charging-map') }}" aria-label="Find charging near {{ strtolower($place['type']) }}">
                        <picture>
                            <source media="(max-width: 640px)" srcset="{{ asset('branding/home/'.$place['image'].'-mobile.png') }}">
                            <img src="{{ asset('branding/home/'.$place['image'].'-desktop.png') }}" alt="" width="420" height="290" loading="lazy" decoding="async">
                        </picture>
                        <span class="home-places__type">{{ $place['type'] }} <span aria-hidden="true">↗</span></span>
                        <h3>{{ $place['title'] }}</h3>
                        <p>{{ $place['copy'] }}</p>
                    </a>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="home-partners" aria-labelledby="home-partners-title">
    <div class="home-wrap">
        <div class="home-section-heading">
            <h2 id="home-partners-title">Turn charging infrastructure into a service people return to.</h2>
            <div><p>{{ $partnerSection?->copy ?? 'Give operators and site hosts a clearer way to manage the charging experience.' }}</p><a class="home-button home-button--bright" href="{{ route('public.for-operators-and-site-hosts') }}">Become a partner <span aria-hidden="true">↗</span></a></div>
        </div>
        <div class="home-partners__grid">
            <ul>
                @foreach (($partnerSection?->items ?? ['One useful record of every asset', 'The right information, reviewed', 'Access that respects the site']) as $item)
                    <li><span aria-hidden="true">✓</span><div><strong>{{ $item }}</strong><p>{{ ['Keep locations, stations and connectors connected in one operating view.', 'See the equipment and service history together when work needs attention.', 'Publish useful charging details with a clear review path.'][$loop->index] ?? '' }}</p></div></li>
                @endforeach
            </ul>
            <div class="home-partners__workspace" aria-label="Charging network setup overview">
                <div class="home-partners__workspace-head"><strong>Network workspace</strong></div>
                <div class="home-partners__workspace-body"><h3>One place for the details that matter.</h3><p>Set up a site, register its charging station, map each EVSE and connector, then publish the information drivers need.</p><div class="home-partners__steps"><span>Site</span><span>Charging station</span><span>EVSE</span><span>Connector</span></div><a href="{{ route('public.for-operators-and-site-hosts') }}">See how the network works <span aria-hidden="true">↗</span></a></div>
            </div>
        </div>
    </div>
</section>

@if ($faqs->isNotEmpty())
<section class="home-faq" aria-labelledby="home-faq-title">
    <div class="home-wrap home-faq__grid">
        <div><h2 id="home-faq-title">Good questions.<br>Clear answers.</h2><p>A few things to know before your next charge.</p><a class="home-text-link" href="{{ route('public.frequently-asked-questions') }}">Visit all FAQs <span aria-hidden="true">↗</span></a></div>
        <div class="home-faq__list">
            @foreach ($faqs->take(4) as $faq)
                <details @if ($loop->first) open @endif><summary>{{ $faq->question }}<span aria-hidden="true"></span></summary><p>{{ $faq->answer }}</p></details>
            @endforeach
        </div>
    </div>
</section>
@endif

@if ($page->body || $partners->isNotEmpty() || $testimonials->isNotEmpty() || $appLinks->isNotEmpty() || $articles->isNotEmpty())
<div class="home-extra">
    @if ($page->body)<section class="public-section"><div class="home-wrap prose-panel">{!! nl2br(e($page->body)) !!}</div></section>@endif
    @if ($partners->isNotEmpty())<section class="public-section"><div class="home-wrap"><h2>Our partners</h2><div class="partner-grid">@foreach ($partners as $partner)@php($logoUrl = Storage::disk($partner->image_disk)->url($partner->image_path))<div class="partner-logo">@if ($partner->website_url && str_starts_with($partner->website_url, 'https://'))<a href="{{ $partner->website_url }}" rel="noopener">@endif<img src="{{ $logoUrl }}" alt="{{ $partner->image_alt }}" width="320" height="160" loading="lazy" decoding="async">@if ($partner->website_url && str_starts_with($partner->website_url, 'https://'))</a>@endif</div>@endforeach</div></div></section>@endif
    @if ($testimonials->isNotEmpty())<section class="public-section"><div class="home-wrap testimonial-grid">@foreach ($testimonials as $testimonial)<figure><blockquote>“{{ $testimonial->quote }}”</blockquote><figcaption><strong>{{ $testimonial->person_name }}</strong><span>{{ collect([$testimonial->person_role, $testimonial->organization_name])->filter()->join(' · ') }}</span></figcaption></figure>@endforeach</div></section>@endif
    @if ($appLinks->isNotEmpty())<section class="public-section public-section--ink"><div class="home-wrap app-cta"><h2>Take Power Solutions with you.</h2><div class="public-actions">@foreach ($appLinks as $link)@if(str_starts_with($link->url, 'https://'))<a class="home-button home-button--bright" href="{{ $link->url }}" rel="noopener">{{ $link->label }}</a>@endif @endforeach</div></div></section>@endif
    @if ($articles->isNotEmpty())<section class="public-section"><div class="home-wrap"><div class="section-heading"><h2>News and resources</h2><a href="{{ route('public.news-and-resources') }}">View all resources</a></div><div class="article-grid">@foreach ($articles->take(3) as $article)<article><h3><a href="{{ route('public.articles.show', $article->slug) }}">{{ $article->title }}</a></h3><span>{{ str($article->kind)->headline() }} · {{ $article->published_at?->format('M j, Y') }}</span></article>@endforeach</div></div></section>@endif
</div>
@endif

<section class="home-next" aria-labelledby="home-next-title">
    <div class="home-wrap home-next__grid">
        <h2 id="home-next-title">Your next stop<br>starts here.</h2>
        <div>
            <p>Find a place to connect. Or make your place part of the network.</p>
            <a class="home-button home-button--dark" href="{{ route('public.charging-map') }}">Find a charger <span aria-hidden="true">↗</span></a>
            <a class="home-text-link" href="{{ route('public.partner-program') }}">Explore the partner program <span aria-hidden="true">↗</span></a>
        </div>
    </div>
</section>
@endsection
