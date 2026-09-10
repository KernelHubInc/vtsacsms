@props([
    'description' => null,
    'title',
    'tone' => 'information',
])

@php
    $toneClasses = match ($tone) {
        'success' => 'border-success/30 bg-success-soft text-success',
        'warning' => 'border-warning/30 bg-warning-soft text-warning',
        'danger' => 'border-danger/30 bg-danger-soft text-danger',
        default => 'border-information/30 bg-information-soft text-information',
    };
@endphp

<div
    {{ $attributes->class("flex w-full max-w-sm items-start gap-3 rounded-lg border p-4 shadow-elevation-2 {$toneClasses}") }}
    role="{{ $tone === 'danger' ? 'alert' : 'status' }}"
>
    <span class="mt-0.5 size-2.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-foreground">{{ $title }}</p>
        @if ($description)
            <p class="mt-1 text-sm leading-5 text-muted">{{ $description }}</p>
        @endif
    </div>
    <button type="button" data-toast-dismiss class="grid size-8 shrink-0 place-items-center rounded-sm text-muted hover:bg-panel/60 hover:text-foreground" aria-label="Dismiss notification">×</button>
</div>
