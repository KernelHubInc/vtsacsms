@props([
    'error' => null,
    'hint' => null,
    'id' => null,
    'label',
    'name',
    'required' => false,
    'type' => 'text',
    'value' => null,
])

@php
    $fieldId = $id ?: $name;
    $hintId = $hint ? "{$fieldId}-hint" : null;
    $errorId = $error ? "{$fieldId}-error" : null;
    $describedBy = trim("{$hintId} {$errorId}");
@endphp

<div class="grid gap-2">
    <label for="{{ $fieldId }}" class="text-sm font-semibold text-foreground">
        {{ $label }}
        @if ($required)
            <span class="text-danger" aria-hidden="true">*</span>
            <span class="sr-only">required</span>
        @endif
    </label>

    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        @required($required)
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        @if ($error) aria-invalid="true" @endif
        {{ $attributes->class([
            'min-h-11 w-full rounded-md border bg-panel px-3.5 py-2.5 text-base text-foreground shadow-sm transition placeholder:text-muted/70 focus:border-brand focus:outline-2 focus:outline-offset-2 focus:outline-focus disabled:cursor-not-allowed disabled:bg-panel-subtle disabled:text-muted',
            'border-danger' => $error,
            'border-border-strong' => ! $error,
        ]) }}
    >

    @if ($hint)
        <p id="{{ $hintId }}" class="text-sm leading-5 text-muted">{{ $hint }}</p>
    @endif

    @if ($error)
        <p id="{{ $errorId }}" class="flex items-start gap-2 text-sm font-medium text-danger">
            <span aria-hidden="true">●</span>
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
