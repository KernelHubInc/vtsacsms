@props([
    'confirmLabel' => 'Confirm action',
    'description',
    'id',
    'title',
    'tone' => 'standard',
])

<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-title"
    aria-describedby="{{ $id }}-description"
    {{ $attributes->class('w-[min(32rem,calc(100%-2rem))] rounded-xl border border-border bg-panel-raised p-0 text-foreground shadow-elevation-3 backdrop:bg-[#0d1020]/70') }}
>
    <div class="p-6 sm:p-7">
        <div class="flex items-start gap-4">
            <div class="grid size-11 shrink-0 place-items-center rounded-lg {{ $tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-brand-soft text-brand' }}" aria-hidden="true">
                {{ $tone === 'danger' ? '!' : '→' }}
            </div>
            <div>
                <h2 id="{{ $id }}-title" class="text-xl font-semibold text-foreground">{{ $title }}</h2>
                <p id="{{ $id }}-description" class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
            </div>
        </div>

        @if (! $slot->isEmpty())
            <div class="mt-5 rounded-md bg-panel-subtle p-4 text-sm text-muted">{{ $slot }}</div>
        @endif

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <form method="dialog">
                <x-ui.button class="w-full sm:w-auto" variant="secondary">Cancel</x-ui.button>
            </form>
            <x-ui.button
                data-dialog-confirm="{{ $id }}"
                variant="{{ $tone === 'danger' ? 'danger' : 'primary' }}"
            >
                {{ $confirmLabel }}
            </x-ui.button>
        </div>
    </div>
</dialog>
