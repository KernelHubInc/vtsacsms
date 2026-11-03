@props(['variant' => 'horizontal', 'inverse' => false])

@php
    $source = $variant === 'mark'
        ? 'branding/power-solutions-mark.png'
        : 'branding/power-solutions-logo.png?v=20261103-padding';
@endphp

<span {{ $attributes->class('power-solutions-logo') }}>
    <img src="{{ asset($source) }}" alt="Power Solutions" decoding="async">
</span>
