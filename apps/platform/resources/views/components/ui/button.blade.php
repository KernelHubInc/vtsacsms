@props([
    'href' => null,
    'loading' => false,
    'size' => 'md',
    'type' => 'button',
    'variant' => 'primary',
])

@php
    $variantClasses = match ($variant) {
        'secondary' => 'border-border-strong bg-panel text-foreground hover:border-brand hover:text-brand-strong',
        'quiet' => 'border-transparent bg-transparent text-muted hover:bg-panel-subtle hover:text-foreground',
        'danger' => 'border-danger bg-danger text-white hover:brightness-90',
        default => 'border-brand bg-brand text-white hover:bg-brand-strong',
    };

    $sizeClasses = match ($size) {
        'sm' => 'min-h-9 px-3 py-1.5 text-xs',
        'lg' => 'min-h-12 px-5 py-3 text-base',
        default => 'min-h-11 px-4 py-2.5 text-sm',
    };

    $classes = "inline-flex items-center justify-center gap-2 rounded-md border font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-50 {$variantClasses} {$sizeClasses}";
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->class($classes) }}
        @if ($loading) aria-busy="true" aria-disabled="true" tabindex="-1" @endif
    >
        @if ($loading)
            <span class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class($classes) }}
        @disabled($loading || $attributes->has('disabled'))
        @if ($loading) aria-busy="true" @endif
    >
        @if ($loading)
            <span class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </button>
@endif
