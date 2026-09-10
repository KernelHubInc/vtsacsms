@props(['variant' => 'horizontal', 'inverse' => false])

@php
    $source = match ($variant) {
        'mark' => 'branding/power-solutions-mark.svg',
        'stacked' => 'branding/power-solutions-logo-stacked.svg',
        'monochrome' => 'branding/power-solutions-logo-monochrome.svg',
        default => $inverse
            ? 'branding/power-solutions-logo-horizontal-dark.svg'
            : 'branding/power-solutions-logo-horizontal.svg',
    };
@endphp

<span {{ $attributes->class('power-solutions-logo') }}>
    <img src="{{ asset($source) }}" alt="Power Solutions" decoding="async">
</span>
