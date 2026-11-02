<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        {{ $enabled ? 'Push sending is enabled.' : 'Push sending is disabled on this environment.' }}
        Accepted means Firebase accepted the message; it does not confirm the phone displayed or read it.
    </p>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Only active, published notices with push requested are sent. Unknown deliveries are not automatically resent because Firebase may already have accepted them.
    </p>
    <div class="flex flex-wrap gap-3">
        @foreach ($report['counts'] as $status => $count)
            <span class="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">{{ ucfirst($status) }}: {{ $count }}</span>
        @endforeach
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr><th class="p-2">Status</th><th class="p-2">Attempts</th><th class="p-2">Last attempt (UTC)</th><th class="p-2">Result</th></tr></thead>
            <tbody>
            @forelse ($report['recent'] as $delivery)
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="p-2">{{ ucfirst($delivery['status']) }}</td><td class="p-2">{{ $delivery['attempts'] }}</td>
                    <td class="p-2">{{ $delivery['attempted_at'] ?? 'Not attempted' }}</td><td class="p-2">{{ $delivery['error_code'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4">No deliveries yet. Check publication dates, push selection, and signed-in Android devices with notification permission.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-xs text-gray-500">Latest 100 deliveries. Device identifiers and private credentials are not displayed.</p>
</div>
