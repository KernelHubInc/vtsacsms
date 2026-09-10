@extends('public.layout')

@section('content')
<section class="public-hero {{ $page->template === 'home' ? 'public-hero--home' : '' }}">
    <div class="public-hero__glow" aria-hidden="true"></div>
    <div class="public-shell public-hero__grid">
        <div class="public-hero__copy">
            @if ($page->eyebrow)<p class="eyebrow">{{ $page->eyebrow }}</p>@endif
            <h1>{{ $page->hero_heading ?: $page->title }}</h1>
            @if ($page->hero_copy)<p class="lede">{{ $page->hero_copy }}</p>@endif
            @if ($page->legal_review_required)
                <div class="legal-review" role="note"><strong>Legal review required</strong><span>This placeholder is not approved policy and must not be treated as binding terms.</span></div>
            @endif
            <div class="public-actions">
                @if ($page->primary_action_url && (str_starts_with($page->primary_action_url, '/') || str_starts_with($page->primary_action_url, 'https://')))<a class="button" href="{{ $page->primary_action_url }}">{{ $page->primary_action_label }}</a>@endif
                @if ($page->secondary_action_url && (str_starts_with($page->secondary_action_url, '/') || str_starts_with($page->secondary_action_url, 'https://')))<a class="button button--quiet" href="{{ $page->secondary_action_url }}">{{ $page->secondary_action_label }}</a>@endif
            </div>
        </div>
        <div class="network-orbit" aria-hidden="true"><span class="orbit orbit--one"></span><span class="orbit orbit--two"></span><span class="orbit-pin orbit-pin--one"></span><span class="orbit-pin orbit-pin--two"></span><div class="charge-pulse"><span>83%</span><small>NETWORK SIGNAL</small></div></div>
    </div>
</section>

@if ($page->body)
<section class="public-section"><div class="public-shell prose-panel">{!! nl2br(e($page->body)) !!}</div></section>
@endif

@foreach ($page->sections as $section)
<section class="public-section {{ $loop->even ? 'public-section--tint' : '' }}">
    <div class="public-shell feature-layout">
        <div><p class="eyebrow">{{ $section->eyebrow }}</p><h2>{{ $section->heading }}</h2><p class="section-copy">{{ $section->copy }}</p></div>
        @if (is_array($section->items) && count($section->items))
        <ul class="signal-list">@foreach ($section->items as $item)<li><span aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>{{ $item }}</li>@endforeach</ul>
        @endif
    </div>
</section>
@endforeach

@if ($partners->isNotEmpty())
<section class="public-section"><div class="public-shell"><p class="eyebrow">Network partners</p><h2 class="partner-heading">Built with organizations that care about the stop.</h2><div class="partner-grid">@foreach ($partners as $partner)@php($logoUrl = Storage::disk($partner->image_disk)->url($partner->image_path))<div class="partner-logo">@if ($partner->website_url && str_starts_with($partner->website_url, 'https://'))<a href="{{ $partner->website_url }}" rel="noopener">@endif<img src="{{ $logoUrl }}" alt="{{ $partner->image_alt }}" width="320" height="160" loading="lazy" decoding="async">@if ($partner->website_url && str_starts_with($partner->website_url, 'https://'))</a>@endif</div>@endforeach</div></div></section>
@endif

@if ($testimonials->isNotEmpty())
<section class="public-section public-section--tint"><div class="public-shell testimonial-grid">@foreach ($testimonials as $testimonial)<figure><blockquote>“{{ $testimonial->quote }}”</blockquote><figcaption><strong>{{ $testimonial->person_name }}</strong><span>{{ collect([$testimonial->person_role, $testimonial->organization_name])->filter()->join(' · ') }}</span></figcaption></figure>@endforeach</div></section>
@endif

@if ($appLinks->isNotEmpty())
<section class="public-section public-section--ink"><div class="public-shell app-cta"><div><p class="eyebrow">Mobile companion</p><h2>Keep the useful details close.</h2></div><div class="public-actions">@foreach ($appLinks as $link)@if(str_starts_with($link->url, 'https://'))<a class="button" href="{{ $link->url }}" rel="noopener">{{ $link->label }}</a>@endif @endforeach</div></div></section>
@endif

@if ($articles->isNotEmpty())
<section class="public-section public-section--ink"><div class="public-shell"><p class="eyebrow">Field notes</p><div class="section-heading"><h2>Ideas for a network in motion.</h2><a href="{{ route('public.news-and-resources') }}">View all resources</a></div><div class="article-grid">@foreach ($articles->take(3) as $article)<article><p>{{ strtoupper($article->kind) }}</p><h3><a href="{{ route('public.articles.show', $article->slug) }}">{{ $article->title }}</a></h3><span>{{ $article->published_at?->format('M j, Y') }}</span></article>@endforeach</div></div></section>
@endif

@if ($faqs->isNotEmpty())
<section class="public-section"><div class="public-shell faq-layout"><div><p class="eyebrow">Questions, answered</p><h2>Useful details without the detour.</h2></div><div class="faq-list">@foreach ($faqs as $faq)<details><summary>{{ $faq->question }}<span aria-hidden="true">+</span></summary><p>{{ $faq->answer }}</p></details>@endforeach</div></div></section>
@endif

@if ($contacts->isNotEmpty())
<section class="public-section"><div class="public-shell contact-grid">@foreach ($contacts as $contact)<div class="contact-card"><p>{{ $contact->label }}</p><strong>{{ $contact->value }}</strong></div>@endforeach</div></section>
@elseif (in_array($page->template, ['contact', 'support'], true))
<section class="public-section"><div class="public-shell"><div class="empty-panel"><p class="eyebrow">Contact channel pending</p><h2>Public contact details have not been published yet.</h2><p>Use the support route shown in the mobile app or at the charging site. Do not send account or payment secrets.</p></div></div></section>
@endif
@endsection
