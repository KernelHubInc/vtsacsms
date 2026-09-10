<x-filament-panels::page>
    <section class="space-y-5">
        <div class="max-w-2xl">
            <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                Recent immutable security evidence for the active platform tenant. Detailed actions remain governed by
                explicit permissions and tenant-scoped application services.
            </p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($auditEvents as $event)
                    <li class="flex items-start justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="font-medium text-gray-950 dark:text-white">{{ $event->action }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $event->target_type }} · {{ $event->result->value }}</p>
                        </div>
                        <time class="shrink-0 text-xs text-gray-500">{{ $event->occurred_at->toIso8601String() }}</time>
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-sm text-gray-500">No audit evidence has been recorded yet.</li>
                @endforelse
            </ul>
        </div>
    </section>
</x-filament-panels::page>
