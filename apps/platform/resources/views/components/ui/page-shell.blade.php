@props([
    'description' => null,
    'eyebrow' => null,
    'title',
])

<div class="min-h-screen bg-canvas text-foreground">
    <a href="#main-content" class="fixed left-4 top-4 z-50 -translate-y-24 rounded-md bg-brand px-4 py-2 font-semibold text-white transition focus:translate-y-0">Skip to main content</a>
    <div class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
        @isset($navigation)
            <div>{{ $navigation }}</div>
        @endisset

        <main id="main-content" class="min-w-0 px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
            <div class="mx-auto max-w-7xl">
                <header class="mb-8 border-b border-border pb-7 sm:flex sm:items-end sm:justify-between sm:gap-8">
                    <div class="max-w-3xl">
                        @if ($eyebrow)
                            <p class="mb-3 text-xs font-bold uppercase tracking-[0.18em] text-brand">{{ $eyebrow }}</p>
                        @endif
                        <h1 class="text-3xl font-semibold tracking-[-0.035em] text-foreground sm:text-4xl">{{ $title }}</h1>
                        @if ($description)
                            <p class="mt-3 text-base leading-7 text-muted">{{ $description }}</p>
                        @endif
                    </div>
                    @isset($actions)
                        <div class="mt-5 flex flex-wrap gap-3 sm:mt-0 sm:justify-end">{{ $actions }}</div>
                    @endisset
                </header>

                {{ $slot }}
            </div>
        </main>
    </div>
</div>
