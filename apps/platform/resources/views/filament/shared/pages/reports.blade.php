<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="max-w-3xl">
                <h2 class="text-lg font-semibold">Download a report</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Reports cover only the locations and records you can access. PDF gives a summary and the first 200 records; CSV contains every matching row.</p>
            </div>
            @if ($availableReports !== [])
                <div class="mt-5 flex flex-wrap gap-4">
                    <label class="text-sm font-medium">From (UTC, inclusive)
                        <input type="date" wire:model="from" class="mt-1 block rounded-lg border-gray-300 bg-white text-sm dark:border-white/15 dark:bg-gray-800" />
                    </label>
                    <label class="text-sm font-medium">To (UTC, exclusive)
                        <input type="date" wire:model="to" class="mt-1 block rounded-lg border-gray-300 bg-white text-sm dark:border-white/15 dark:bg-gray-800" />
                    </label>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Leave dates blank for all available history. Dates apply to charging sessions and payments; sites show their current state.</p>
                @error('from') <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                @error('to') <p class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                <div class="mt-6 grid gap-3 lg:grid-cols-3">
                    @foreach ($availableReports as $type => $label)
                        <article class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <h3 class="font-semibold">{{ $label }}</h3>
                            <p class="mt-1 min-h-12 text-sm text-gray-500 dark:text-gray-400">{{ match($type) {
                                'sessions' => 'Start and stop times, energy, duration and final charge.',
                                'assets' => 'Authorized sites, publication status and charging-station counts.',
                                'finance' => 'Session payment intents, captures and refunds.',
                            } }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button wire:click="queueExport('{{ $type }}', 'pdf')" wire:loading.attr="disabled" type="button" class="rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:opacity-50">Create PDF</button>
                                <button wire:click="queueExport('{{ $type }}', 'csv')" wire:loading.attr="disabled" type="button" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:border-white/20 dark:hover:bg-white/5">Export all rows as CSV</button>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <p class="mt-4 rounded-xl bg-warning-50 p-3 text-sm text-warning-800">Your role does not currently allow report exports. Ask an administrator about reporting and the relevant data permission.</p>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-6 py-4 dark:border-white/10">
                <h2 class="font-semibold">Your recent exports</h2>
            </div>
            @if ($exports->isEmpty())
                <div class="p-10 text-center text-sm text-gray-500">No exports have been requested from this account.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5">
                            <tr><th class="px-6 py-3">Type</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Rows</th><th class="px-6 py-3">Requested</th><th class="px-6 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($exports as $export)
                                <tr wire:key="export-{{ $export->getKey() }}">
                                    <td class="px-6 py-4 font-medium">{{ str($export->type)->headline() }} · {{ strtoupper($export->filters['_format'] ?? 'csv') }}</td>
                                    <td class="px-6 py-4"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium dark:bg-white/10">{{ str($export->status)->headline() }}</span></td>
                                    <td class="px-6 py-4 tabular-nums">{{ number_format($export->row_count) }}</td>
                                    <td class="px-6 py-4 text-gray-500">{{ $export->created_at->diffForHumans() }}</td>
                                    <td class="px-6 py-4 text-right">
                                        @if ($export->status === 'completed' && $export->created_at->gte(now('UTC')->subDays(7)) && isset($export->filters['_scope_hash']))
                                            <button wire:click="downloadExport('{{ $export->getKey() }}')" class="font-semibold text-primary-600 hover:text-primary-500">Download</button>
                                        @elseif ($export->status === 'completed' && ! isset($export->filters['_scope_hash']))
                                            <span class="text-gray-400">Recreate report</span>
                                        @elseif ($export->status === 'completed')
                                            <span class="text-gray-400">Expired</span>
                                        @elseif ($export->status === 'failed')
                                            <span class="text-danger-600">{{ str($export->failure_code ?? 'generation_failed')->headline() }}</span>
                                        @else
                                            <span class="text-gray-400">Pending</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
