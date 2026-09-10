@props([
    'label',
    'tone' => 'neutral',
])

@php
    $toneClasses = match ($tone) {
        'success', 'available' => 'bg-success-soft text-success',
        'warning', 'suspended' => 'bg-warning-soft text-warning',
        'danger', 'faulted' => 'bg-danger-soft text-danger',
        'information', 'preparing', 'finishing' => 'bg-information-soft text-information',
        'charging' => 'bg-brand-soft text-state-charging',
        'reserved' => 'bg-brand-soft text-state-reserved',
        'offline' => 'bg-panel-subtle text-state-offline',
        default => 'bg-panel-subtle text-muted',
    };

    $shapeClasses = match ($tone) {
        'danger', 'faulted' => 'rounded-[2px] rotate-45',
        'warning', 'suspended' => 'rounded-[1px] rotate-45',
        'offline' => 'rounded-full border border-current bg-transparent',
        default => 'rounded-full bg-current',
    };
@endphp

<span {{ $attributes->class("inline-flex min-h-7 items-center gap-2 rounded-full px-2.5 py-1 text-xs font-semibold {$toneClasses}") }}>
    <span class="size-2 shrink-0 {{ $shapeClasses }}" aria-hidden="true"></span>
    {{ $label }}
</span>
