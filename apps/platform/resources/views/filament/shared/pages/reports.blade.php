<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="max-w-2xl">
                <h2 class="text-lg font-semibold">Tenant-safe CSV exports</h2>
                <p class="mt-1 text-sm text-gray-500">Exports run in the queue with the requester’s tenant and site scope rechecked when the job starts. Formula-like cells are escaped.</p>
            </div>
            @if ($canExport)
                <div class="mt-5 flex flex-wrap gap-3">
                    @foreach (['sessions' => 'Session history', 'assets' => 'Sites and assets', 'finance' => 'Finance reconciliation'] as $type => $label)
                        <button wire:click="queueExport('{{ $type }}')" wire:loading.attr="disabled" type="button" class="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 disabled:opacity-50">
                            Queue {{ $label }}
                        </button>
                    @endforeach
                </div>
            @else
                <p class="mt-4 rounded-xl bg-warning-50 p-3 text-sm text-warning-800">You can view reports but do not have export permission.</p>
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
                                    <td class="px-6 py-4 font-medium">{{ str($export->type)->headline() }}</td>
                                    <td class="px-6 py-4"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium dark:bg-white/10">{{ str($export->status)->headline() }}</span></td>
                                    <td class="px-6 py-4 tabular-nums">{{ number_format($export->row_count) }}</td>
                                    <td class="px-6 py-4 text-gray-500">{{ $export->created_at->diffForHumans() }}</td>
                                    <td class="px-6 py-4 text-right">
                                        @if ($export->status === 'completed')
                                            <button wire:click="downloadExport('{{ $export->getKey() }}')" class="font-semibold text-primary-600 hover:text-primary-500">Download</button>
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
