<x-filament-panels::page>
    <section class="space-y-5">
        <div class="max-w-2xl">
            <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                This directory is scoped at query time to the active operator and your exact site assignments.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($sites as $site)
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $site->code }}</p>
                            <h2 class="mt-2 font-semibold text-gray-950 dark:text-white">{{ $site->name }}</h2>
                        </div>
                        <span @class([
                            'rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $site->is_active,
                            'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $site->is_active,
                        ])>{{ $site->is_active ? 'Active' : 'Inactive' }}</span>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-sm text-gray-500 dark:border-white/15">
                    No sites are assigned to this account.
                </div>
            @endforelse
        </div>
    </section>
</x-filament-panels::page>
