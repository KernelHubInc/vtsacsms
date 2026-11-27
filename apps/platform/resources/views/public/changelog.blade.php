@extends('public.layout')

@section('content')
<section class="public-section">
    <div class="public-shell grid items-start gap-12 lg:grid-cols-[0.8fr_1.2fr]">
        <aside class="lg:sticky lg:top-28">
            <p class="mb-5 text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Platform updates · {{ ucfirst($environment) }}</p>
            <h1 class="max-w-lg text-4xl font-semibold leading-tight tracking-tight text-[#12366b] sm:text-5xl">{{ $page->hero_heading }}</h1>
            <p class="mt-6 max-w-md text-lg leading-relaxed text-slate-600">{{ $page->summary }}</p>
            <p class="mt-6 max-w-md text-sm leading-relaxed text-slate-600">This page records recent releases for {{ $environment }}. Mobile changes may require an app update.</p>
            <a class="mt-6 inline-flex font-semibold text-teal-700 underline" href="{{ route('public.support') }}">Need help with a change?</a>
        </aside>
        <div class="space-y-6" aria-label="Release history">
            @forelse ($releases as $release)
                <article id="{{ $release['id'] }}" aria-labelledby="{{ $release['id'] }}-title" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
                    <time class="text-sm font-semibold text-teal-700" datetime="{{ $release['date'] }}">{{ \Carbon\CarbonImmutable::parse($release['date'], 'Asia/Manila')->format('F j, Y') }}</time>
                    <h2 id="{{ $release['id'] }}-title" class="mt-3 text-2xl font-semibold tracking-tight text-[#12366b]">{{ $release['title'] }}</h2>
                    <ul class="mt-6 space-y-6">
                        @foreach ($release['changes'] as $change)
                            <li>
                                <span class="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">{{ $change['type'] }}</span>
                                <p class="mt-3 leading-relaxed text-slate-600">{{ $change['text'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <p class="rounded-3xl border border-slate-200 bg-white p-6 text-slate-600">No release notes have been published yet.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
