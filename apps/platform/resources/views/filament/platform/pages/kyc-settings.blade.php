<x-filament-panels::page>
    <form wire:submit="save" class="max-w-3xl space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-semibold uppercase tracking-widest text-primary-600">Verification policy</p>
            <h2 class="mt-2 text-xl font-semibold">Who makes the final decision?</h2>
            <p class="mt-3 text-sm text-gray-500">Checks run after each submission. This setting applies to new submissions in the current tenant; existing applications keep their recorded policy.</p>
            @if (config('kyc.assurance_profile') === 'optical_v1')
                <p class="mt-3 text-sm text-gray-500">This deployment uses optical ID checks, matching personal details, face comparison and a live camera challenge. It does not verify government issuance.</p>
            @endif
            <fieldset class="mt-6 space-y-4">
                <legend class="sr-only">Review mode</legend>
                <label class="flex cursor-pointer gap-3 rounded-xl border p-4 focus-within:ring-2 focus-within:ring-primary-500">
                    <input type="radio" wire:model="mode" value="manual" class="mt-1" />
                    <span><strong class="block">Manual review</strong><span class="mt-1 block text-sm text-gray-500">An authorized administrator decides after reviewing the checks and evidence.</span></span>
                </label>
                <label class="flex cursor-pointer gap-3 rounded-xl border p-4 focus-within:ring-2 focus-within:ring-primary-500">
                    <input type="radio" wire:model="mode" value="automatic" class="mt-1" @disabled(!config('kyc.automatic_verification_enabled')) />
                    <span><strong class="block">Automatic verification</strong><span class="mt-1 block text-sm text-gray-500">Approve only when document, identity extraction, face matching and liveness checks all pass. Inconclusive results go to review.</span></span>
                </label>
            </fieldset>
            @unless (config('kyc.automatic_verification_enabled'))
                <p class="mt-4 text-sm text-warning-600">Automatic approval is unavailable until the self-hosted assurance checks have been deployed and accepted.</p>
            @endunless
            @error('mode')<p role="alert" class="mt-3 text-sm text-danger-600">{{ $message }}</p>@enderror
        </section>
        <x-filament::button type="submit" wire:loading.attr="disabled">Save verification policy</x-filament::button>
    </form>
</x-filament-panels::page>
