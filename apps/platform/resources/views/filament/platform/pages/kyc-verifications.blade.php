<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-5">
        <section class="space-y-4 xl:col-span-2" aria-label="Identity verification queue">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-semibold uppercase tracking-widest text-primary-600">Identity review</p>
                <h2 class="mt-2 text-xl font-semibold">Verification queue</h2>
                <p class="mt-2 text-sm text-gray-500">Search by verification or subject reference. Identity numbers never appear in this list.</p>
                <form wire:submit="applyFilters" class="mt-5 space-y-3">
                    <label class="block text-sm">Status
                        <select wire:model="status" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800">
                            <option value="">All states</option>
                            @foreach ($statuses as $state)<option value="{{ $state->value }}">{{ str($state->value)->replace('_', ' ')->title() }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-sm">Reference
                        <input wire:model="search" maxlength="26" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800" />
                    </label>
                    <x-filament::button type="submit">Apply filters</x-filament::button>
                </form>
            </div>
            @forelse ($records as $record)
                <button type="button" wire:click="selectVerification('{{ $record->id }}')" class="w-full rounded-xl border bg-white p-4 text-left transition hover:border-primary-500 focus-visible:outline-2 focus-visible:outline-primary-600 dark:border-gray-700 dark:bg-gray-900">
                    <span class="text-sm font-semibold">{{ str($record->status->value)->replace('_', ' ')->title() }}</span>
                    <span class="mt-2 block break-all font-mono text-xs text-gray-500">{{ $record->id }}</span>
                    <span class="mt-2 block text-xs">{{ $record->document_type }} · {{ $record->created_at->format('d M Y, H:i') }} UTC</span>
                </button>
            @empty
                <p class="rounded-xl border border-dashed p-8 text-sm text-gray-500">No verifications match these filters.</p>
            @endforelse
            {{ $records->links() }}
        </section>
        <section class="space-y-5 xl:col-span-3" aria-label="Verification detail" wire:loading.class="opacity-60">
            @if ($selection)
                <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                    <h2 class="text-2xl font-semibold">{{ $subject?->name ?? 'Identity unavailable' }}</h2>
                    <p class="mt-2 break-all font-mono text-xs">{{ $selection->subject_id }}</p>
                    <p class="mt-3 font-medium">{{ $selection->status->value }}</p>
                    <p class="mt-2 text-sm text-gray-500">Review policy: {{ ucfirst($selection->review_mode) }}</p>
                    <p class="mt-2 text-sm text-gray-500">Assurance: {{ $selection->assurance_profile === 'optical_v1' ? 'Optical ID checks, face match and live camera challenge. Government issuance is not verified.' : 'Issuer authenticity required for automatic approval.' }}</p>
                    <p class="mt-2 text-sm text-gray-500">{{ $selection->reason_code ?? 'Awaiting processing' }}</p>
                    <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                        @foreach (($selection->evidence ?? []) as $item)
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><dt class="font-medium">{{ ucfirst($item['kind']) }}</dt><dd class="text-sm">{{ $item['width'] }} × {{ $item['height'] }} · {{ number_format($item['bytes'] / 1024) }} KB</dd></div>
                        @endforeach
                    </dl>
                    @foreach (($selection->processing_result['checks'] ?? []) as $name => $result)
                        <p class="mt-2 text-sm">{{ $name === 'document' ? 'Issuer authenticity' : str($name)->replace('_', ' ')->title() }}: {{ $result }}</p>
                    @endforeach
                    @if ($selection->evidence_deleted)<p class="mt-4 text-sm text-warning-600">Evidence removed under the retention policy. Approval is unavailable.</p>@endif
                    @if (isset($selection->processing_result['duration_ms']))<p class="mt-3 text-xs text-gray-500">Processing: {{ $selection->processing_result['duration_ms'] }} ms · {{ $selection->processing_result['provider'] }}</p>@endif
                    @if (isset($details['unavailable']))<p role="alert" class="mt-4">Evidence service is temporarily unavailable.</p>
                    @elseif ($details)
                        <div class="mt-4 flex flex-wrap gap-2" aria-label="Audited private evidence previews">
                            @foreach (($selection->evidence ?? []) as $item)
                                <x-filament::button size="sm" color="gray" wire:click="$set('evidenceKind', '{{ $item['kind'] }}')">View {{ $item['kind'] }}</x-filament::button>
                            @endforeach
                        </div>
                        @if ($evidenceImage)<img src="{{ $evidenceImage }}" alt="Private identity evidence: {{ $evidenceKind }}" class="mt-4 max-h-96 w-full rounded-xl object-contain" />@endif
                        @foreach ($details as $section => $fields)
                            <h3 class="mt-6 font-semibold">{{ $section === 'personal' ? 'Submitted information' : 'OCR extraction — review for accuracy' }}</h3>
                            <dl class="mt-3 grid gap-3 sm:grid-cols-2">@foreach ($fields as $label => $value)<div><dt class="text-xs text-gray-500">{{ str($label)->replace('_', ' ')->title() }}</dt><dd class="text-sm">{{ $value ?? 'Unavailable' }}</dd></div>@endforeach</dl>
                        @endforeach
                    @endif
                    @if ($canReview && $selection->status === \App\Modules\Identity\Domain\KycStatus::NeedsReview)
                        <div class="mt-6 border-t pt-5">
                            <label class="block text-sm font-medium" for="review-reason">Decision reason</label>
                            <p class="mt-1 text-xs text-gray-500">Explain the decision. Do not include identity numbers or other unnecessary personal data.</p>
                            <textarea id="review-reason" wire:model="reason" maxlength="500" rows="3" class="mt-3 w-full rounded-xl border-gray-300 dark:bg-gray-800"></textarea>
                            @error('reason')<p role="alert" class="text-sm text-danger-600">{{ $message }}</p>@enderror
                            <div class="mt-4 flex flex-wrap gap-3">
                                <x-filament::button wire:click="decide('APPROVED')" wire:loading.attr="disabled" color="success">Approve</x-filament::button>
                                <x-filament::button wire:click="decide('ACTION_REQUIRED')" wire:loading.attr="disabled" color="warning">Request resubmission</x-filament::button>
                                <x-filament::button wire:click="decide('REJECTED')" wire:loading.attr="disabled" color="danger">Reject</x-filament::button>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="rounded-2xl border p-6 dark:border-gray-700"><h3 class="font-semibold">Timeline</h3>@foreach ($timeline as $event)<p class="mt-3 text-sm">{{ $event->occurred_at }} UTC · {{ $event->status }} · {{ $event->reason_code }}</p>@endforeach</div>
                <div class="rounded-2xl border p-6 dark:border-gray-700"><h3 class="font-semibold">Audit history</h3>@foreach ($audit as $event)<p class="mt-3 text-xs">{{ $event->occurred_at }} · {{ $event->action }} · {{ $event->actor_id ?? 'Service' }}</p>@endforeach</div>
            @else
                <div class="rounded-2xl border border-dashed p-12 text-gray-500">Select a verification to inspect its processing results and review history.</div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
