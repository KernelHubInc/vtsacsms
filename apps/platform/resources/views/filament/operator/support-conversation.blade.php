<div class="space-y-5">
    <div class="rounded-xl bg-gray-50 p-5 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Original request · {{ $ticket->ticket_number }}</p>
        <h3 class="mt-2 font-semibold">{{ $ticket->subject }}</h3>
        <p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $ticket->description }}</p>
        @if ($ticket->session_id)<p class="mt-3 break-all font-mono text-xs text-gray-500">Session {{ $ticket->session_id }}</p>@endif
    </div>
    @forelse ($messages as $message)
        <article class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                <span class="font-semibold {{ $message->is_internal ? 'text-amber-700' : 'text-teal-700' }}">{{ $message->is_internal ? 'Internal note · staff only' : 'Customer reply' }}</span>
                <span>{{ $message->author?->name ?? 'Support team' }}</span><time>{{ $message->created_at->utc()->format('Y-m-d H:i') }} UTC</time>
            </div>
            <p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $message->body }}</p>
        </article>
    @empty
        <p class="text-sm text-gray-500">No replies yet. Use Respond to email the customer or record a private staff note.</p>
    @endforelse
    @if ($messages->count() === 100)<p class="text-xs text-gray-500">Showing the latest 100 messages.</p>@endif
</div>
