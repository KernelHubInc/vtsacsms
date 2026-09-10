@props([
    'label' => 'Loading content',
    'lines' => 3,
])

<div {{ $attributes->class('grid gap-3') }} role="status" aria-label="{{ $label }}">
    <span class="sr-only">{{ $label }}</span>
    <div aria-hidden="true" class="grid gap-3">
        @for ($line = 0; $line < $lines; $line++)
            <span class="relative h-3.5 overflow-hidden rounded-full bg-panel-subtle {{ $line === $lines - 1 ? 'w-2/3' : 'w-full' }}">
                <span class="absolute inset-0 -translate-x-full animate-[vtsa-skeleton_1.5s_ease-in-out_infinite] bg-gradient-to-r from-transparent via-border/70 to-transparent"></span>
            </span>
        @endfor
    </div>
</div>
