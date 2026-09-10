<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    @php
        $content = $page ?? $article;
        $seo = $content->seo;
        $title = $seo?->meta_title ?? ($content->title.' | '.config('app.name'));
        $description = $seo?->meta_description ?? ($content->summary ?? $content->excerpt ?? 'Find and understand public EV charging with Power Solutions.');
        $canonical = $seo?->canonical_url ?: url()->current();
        $socialImage = $seo?->social_image_url ? url($seo->social_image_url) : asset('branding/power-solutions-social-card.svg');
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $seo?->noindex ? 'noindex, nofollow' : 'index, follow' }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="{{ isset($article) ? 'article' : 'website' }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $socialImage }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $socialImage }}">
    <meta name="theme-color" content="#12366B">
    <link rel="icon" href="{{ asset('branding/power-solutions-favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('branding/power-solutions-apple-touch-icon.png') }}">
    <title>{{ $title }}</title>
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => isset($article) ? 'Article' : 'WebPage',
        'name' => $content->title,
        'description' => $description,
        'url' => $canonical,
        'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.name'), 'url' => url('/')],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @if (isset($faqs) && $faqs->isNotEmpty())
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $faqs->map(fn ($faq) => ['@type' => 'Question', 'name' => $faq->question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq->answer]])->values(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@yield('body-class', 'public-site')">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <header class="site-header">
        <div class="site-header__inner">
            <a class="brand" href="{{ route('home') }}" aria-label="Power Solutions home">
                <x-brand.logo />
            </a>
            <nav class="desktop-nav" aria-label="Primary navigation">
                <a href="{{ route('public.ev-charging-network') }}">Network</a>
                <a href="{{ route('public.charging-map') }}">Map</a>
                <a href="{{ route('public.for-ev-drivers') }}">Drivers</a>
                <a href="{{ route('public.for-operators-and-site-hosts') }}">Partners</a>
                <a href="{{ route('public.news-and-resources') }}">Resources</a>
            </nav>
            <a class="button button--small" href="{{ route('public.charging-map') }}">Find a charger</a>
            <details class="mobile-menu">
                <summary aria-label="Open navigation">Menu</summary>
                <nav aria-label="Mobile navigation">
                    <a href="{{ route('public.ev-charging-network') }}">Network</a><a href="{{ route('public.charging-map') }}">Charging map</a>
                    <a href="{{ route('public.for-ev-drivers') }}">For drivers</a><a href="{{ route('public.for-operators-and-site-hosts') }}">For partners</a>
                    <a href="{{ route('public.news-and-resources') }}">Resources</a><a href="{{ route('public.support') }}">Support</a>
                </nav>
            </details>
        </div>
    </header>
    <main id="main-content" tabindex="-1">@yield('content')</main>
    <footer class="site-footer">
        <div class="site-footer__grid">
            <div><a class="brand brand--footer" href="{{ route('home') }}" aria-label="Power Solutions home"><x-brand.logo inverse /></a><p>A clearer operating foundation for charging networks and the people who use them.</p></div>
            <nav aria-label="Explore"><strong>Explore</strong><a href="{{ route('public.charging-map') }}">Charging map</a><a href="{{ route('public.mobile-app') }}">Mobile app</a><a href="{{ route('public.partner-program') }}">Partner program</a></nav>
            <nav aria-label="Help"><strong>Help</strong><a href="{{ route('public.frequently-asked-questions') }}">FAQs</a><a href="{{ route('public.support') }}">Support</a><a href="{{ route('public.contact') }}">Contact</a></nav>
            <nav aria-label="Legal"><strong>Legal</strong><a href="{{ route('public.privacy-policy') }}">Privacy</a><a href="{{ route('public.terms-of-service') }}">Terms</a><a href="{{ route('public.charging-terms') }}">Charging terms</a><a href="{{ route('public.refund-policy') }}">Refund policy</a></nav>
        </div>
        <nav class="site-footer__compact-nav" aria-label="Map quick links">
            <a href="{{ route('public.support') }}">Support</a>
            <a href="{{ route('public.contact') }}">Contact</a>
            <a href="{{ route('public.privacy-policy') }}">Privacy</a>
        </nav>
        <div class="site-footer__base"><span>&copy; {{ now()->year }} {{ config('app.name') }}</span><span>Times and status signals may be delayed. Check station signage on arrival.</span></div>
    </footer>
</body>
</html>
