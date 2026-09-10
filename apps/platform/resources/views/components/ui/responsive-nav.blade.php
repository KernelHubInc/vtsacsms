@props([
    'brand' => 'Power Solutions',
    'items' => [],
])

@php
    $linkClasses = 'flex min-h-11 items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold transition';
@endphp

<nav aria-label="Primary" {{ $attributes }}>
    <div class="border-b border-border bg-panel px-4 py-3 lg:hidden">
        <details>
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between rounded-md px-2 font-semibold text-foreground">
                <span>{{ $brand }}</span>
                <span class="text-muted" aria-hidden="true">Menu</span>
            </summary>
            <div class="mt-2 grid gap-1 border-t border-border pt-3">
                @foreach ($items as $item)
                    <a
                        href="{{ $item['href'] }}"
                        class="{{ $linkClasses }} {{ ($item['current'] ?? false) ? 'bg-brand-soft text-brand-strong' : 'text-muted hover:bg-panel-subtle hover:text-foreground' }}"
                        @if ($item['current'] ?? false) aria-current="page" @endif
                    >
                        <span class="size-2 rounded-full bg-current opacity-70" aria-hidden="true"></span>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </details>
    </div>

    <div class="hidden min-h-screen w-64 flex-col border-r border-border bg-panel px-4 py-6 lg:flex">
        <a href="#main-content" class="mb-8 flex items-center gap-3 rounded-md px-2 text-foreground">
            <span class="grid size-9 place-items-center rounded-md bg-brand font-black text-white">V</span>
            <span class="text-sm font-bold tracking-[0.14em]">{{ $brand }}</span>
        </a>
        <div class="grid gap-1">
            @foreach ($items as $item)
                <a
                    href="{{ $item['href'] }}"
                    class="{{ $linkClasses }} {{ ($item['current'] ?? false) ? 'bg-brand-soft text-brand-strong' : 'text-muted hover:bg-panel-subtle hover:text-foreground' }}"
                    @if ($item['current'] ?? false) aria-current="page" @endif
                >
                    <span class="size-2 rounded-full bg-current opacity-70" aria-hidden="true"></span>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</nav>
