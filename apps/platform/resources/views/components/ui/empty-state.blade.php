@props([
    'description',
    'title',
])

<section {{ $attributes->class('rounded-lg border border-dashed border-border-strong bg-panel-subtle px-6 py-10 text-center') }}>
    <div class="mx-auto grid size-12 place-items-center rounded-xl bg-brand-soft text-brand" aria-hidden="true">
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M4 7.5h16M7.5 4v7M16.5 4v7M5 11h14v8H5z" />
        </svg>
    </div>
    <h2 class="mt-4 text-lg font-semibold text-foreground">{{ $title }}</h2>
    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">{{ $description }}</p>
    @if (! $slot->isEmpty())
        <div class="mt-5 flex justify-center gap-3">{{ $slot }}</div>
    @endif
</section>
