@props([
    'correlationId' => null,
    'description',
    'title' => 'Something went wrong',
])

<section {{ $attributes->class('rounded-lg border border-danger/30 bg-danger-soft p-6') }} role="alert">
    <div class="flex items-start gap-4">
        <div class="grid size-10 shrink-0 place-items-center rounded-md bg-danger text-white" aria-hidden="true">!</div>
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-foreground">{{ $title }}</h2>
            <p class="mt-1 text-sm leading-6 text-muted">{{ $description }}</p>
            @if ($correlationId)
                <p class="mt-3 font-mono text-xs text-muted">Reference: {{ $correlationId }}</p>
            @endif
            @if (! $slot->isEmpty())
                <div class="mt-4 flex flex-wrap gap-3">{{ $slot }}</div>
            @endif
        </div>
    </div>
</section>
