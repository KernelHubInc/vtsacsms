@props(['variant' => 'horizontal', 'inverse' => false])

@php
    $source = $variant === 'mark' ? 'power-solutions-mark-transparent.png' : 'power-solutions-logo.png';
    $darkSource = $variant === 'mark' ? 'power-solutions-mark-white.png' : 'power-solutions-logo-white.png';
@endphp

<span {{ $attributes->class(['power-solutions-logo', 'power-solutions-logo--inverse' => $inverse]) }}>
    @unless ($inverse)
        <img class="brand-logo-light" src="{{ asset('branding/'.$source.'?v=20261103-theme') }}" alt="Power Solutions" decoding="async">
    @endunless
    <img class="brand-logo-dark" src="{{ asset('branding/'.$darkSource.'?v=20261103-theme') }}" alt="Power Solutions" decoding="async">
</span>
