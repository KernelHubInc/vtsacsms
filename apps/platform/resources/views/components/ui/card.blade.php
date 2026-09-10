@props([
    'interactive' => false,
    'selected' => false,
    'subtle' => false,
])

<section {{ $attributes->class([
    'rounded-lg border p-5 transition sm:p-6',
    'border-brand ring-2 ring-brand/15' => $selected,
    'border-border bg-panel-subtle' => $subtle && ! $selected,
    'border-border bg-panel shadow-elevation-1' => ! $subtle && ! $selected,
    'hover:-translate-y-0.5 hover:border-brand/60 hover:shadow-elevation-2' => $interactive,
]) }}>
    @isset($eyebrow)
        <div class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-brand">{{ $eyebrow }}</div>
    @endisset

    @if (isset($title) || isset($actions))
        <header class="mb-4 flex items-start justify-between gap-4">
            @isset($title)
                <div class="text-lg font-semibold leading-6 text-foreground">{{ $title }}</div>
            @endisset
            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="text-sm leading-6 text-muted">{{ $slot }}</div>

    @isset($footer)
        <footer class="mt-5 border-t border-border pt-4">{{ $footer }}</footer>
    @endisset
</section>
