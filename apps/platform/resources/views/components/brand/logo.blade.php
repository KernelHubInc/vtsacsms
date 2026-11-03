@props(['variant' => 'horizontal', 'inverse' => false])

@php
    $source = $variant === 'mark'
        ? 'branding/power-solutions-mark.png'
        : 'branding/power-solutions-logo.png';
@endphp

<span {{ $attributes->class('power-solutions-logo') }}>
    <img src="{{ asset($source) }}" alt="Power Solutions" decoding="async">
</span>
