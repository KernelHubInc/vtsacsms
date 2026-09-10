<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($checks as $check)
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="font-semibold">{{ $check['name'] }}</h2>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold dark:bg-white/10">{{ str($check['state'])->headline() }}</span>
                </div>
                <p class="mt-2 text-sm text-gray-500">{{ $check['detail'] }}</p>
            </article>
        @endforeach
        <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 class="font-semibold">Integration outbox</h2>
            <p class="mt-3 text-3xl font-semibold tabular-nums">{{ number_format($outboxPending) }}</p>
            <p class="text-sm text-gray-500">Pending events · {{ number_format($outboxFailed) }} exceeded three attempts</p>
        </article>
    </div>
</x-filament-panels::page>
