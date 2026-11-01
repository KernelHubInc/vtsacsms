<form wire:submit="saveChargingBudgets" class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <h2 class="font-semibold">Charging reservation settings</h2>
    <p class="mt-1 text-sm text-gray-500">The charging budget is a temporary wallet hold, including any platform fee. Available funds and Business/Fleet limits always apply. Changes affect new quotes only.</p>
    <div class="mt-5 grid gap-4 sm:grid-cols-3">
        @foreach (['minimum' => 'Minimum budget (PHP)', 'default' => 'Default budget (PHP)', 'maximum' => 'Maximum budget (PHP)'] as $field => $label)
            <label class="text-sm font-medium">{{ $label }}
                <input wire:model="budgetSettings.{{ $field }}" inputmode="decimal" @disabled(!$this->canManage()) class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                @error('budgetSettings.'.$field)<span role="alert" class="block text-sm text-danger-600">{{ $message }}</span>@enderror
                @error('budgetSettings.'.$field.'_minor')<span role="alert" class="block text-sm text-danger-600">{{ $message }}</span>@enderror
            </label>
        @endforeach
    </div>
    <div class="mt-5 space-y-3">
        <h3 class="text-sm font-semibold">Budget buttons · PHP</h3>
        @foreach ($budgetSettings['presets'] ?? [] as $index => $amount)
            <div wire:key="budget-preset-{{ $index }}" class="flex flex-wrap items-start gap-3">
                <div>
                    <input aria-label="Budget amount {{ $index + 1 }}" wire:model="budgetSettings.presets.{{ $index }}" inputmode="decimal" @disabled(!$this->canManage()) class="rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                    @error('budgetSettings.presets.'.$index)<span role="alert" class="block text-sm text-danger-600">{{ $message }}</span>@enderror
                </div>
                @if ($this->canManage())<x-filament::button type="button" color="gray" wire:click="removeBudgetPreset({{ $index }})">Remove</x-filament::button>@endif
            </div>
        @endforeach
        @error('budgetSettings.presets')<p role="alert" class="text-sm text-danger-600">{{ $message }}</p>@enderror
        @if ($this->canManage())<x-filament::button type="button" color="gray" wire:click="addBudgetPreset">Add amount</x-filament::button>@endif
        <label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="budgetSettings.allow_custom_amount" @disabled(!$this->canManage())>Allow Other amount</label>
    </div>
    @if ($this->canManage())<div class="mt-5 flex justify-end"><x-filament::button type="submit" wire:loading.attr="disabled">Save charging budgets</x-filament::button></div>@endif
</form>

<form wire:submit="publishPlatformFee" class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="font-semibold">Charging platform fee</h2><p class="mt-1 text-sm text-gray-500">Applies once to a completed session with charging usage. No fee for top-ups or failed starts. Active policy: {{ $activeFee['enabled'] ? 'Enabled' : 'Disabled' }}.</p></div>
        <label class="flex items-center gap-3 text-sm font-medium"><input type="checkbox" wire:model="feeSettings.enabled" @disabled(!$this->canManage())>Enable fee</label>
    </div>
    @error('feeSettings.enabled')<p role="alert" class="mt-3 text-sm text-danger-600">{{ $message }}</p>@enderror
    <div class="mt-5 grid gap-4 sm:grid-cols-2">
        @foreach (['amount' => 'Fee including tax (PHP)', 'waiver_threshold' => 'Waive below charging total (PHP)', 'tax_rate' => 'Confirmed included tax rate (%)', 'beneficiary' => 'Confirmed fee beneficiary'] as $field => $label)
            <label class="text-sm font-medium">{{ $label }}
                <input wire:model="feeSettings.{{ $field }}" @if($field !== 'beneficiary') inputmode="decimal" @endif @disabled(!$this->canManage()) class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
                @error('feeSettings.'.$field)<span role="alert" class="block text-sm text-danger-600">{{ $message }}</span>@enderror
                @error('feeSettings.'.$field.'_minor')<span role="alert" class="block text-sm text-danger-600">{{ $message }}</span>@enderror
            </label>
        @endforeach
        <label class="text-sm font-medium">Effective date and time · Philippines
            <input type="datetime-local" wire:model="feeSettings.effective_at" @disabled(!$this->canManage()) class="mt-1 block w-full rounded-xl border-gray-300 dark:border-white/10 dark:bg-gray-950">
            <span class="mt-1 block text-xs text-gray-500">Leave empty to apply immediately to new quotes.</span>
            @error('feeSettings.effective_at')<span role="alert" class="block text-sm text-danger-600">{{ $message }}</span>@enderror
        </label>
    </div>
    @error('feeSettings.tax_rate_basis_points')<p role="alert" class="text-sm text-danger-600">{{ $message }}</p>@enderror
    <p class="mt-4 text-sm text-gray-500">Keep disabled until accounting confirms the beneficiary and tax treatment. The threshold compares the charging total including its tax, excluding this fee. At the threshold, the fee applies. Zero threshold disables the waiver. Refunds require the existing operator review; publishing does not issue refunds or payouts.</p>
    @foreach ($scheduledFees as $scheduled)
        <p class="mt-3 text-sm">Scheduled: {{ $scheduled['enabled'] ? 'Enabled' : 'Disabled' }} from {{ $scheduled['effective_at']->setTimezone('Asia/Manila')->format('d M Y, g:i A') }} PHT. Publish a replacement for that date to supersede it.</p>
    @endforeach
    @if ($this->canManage())<div class="mt-5 flex justify-end"><x-filament::button type="submit" wire:loading.attr="disabled">Publish fee policy</x-filament::button></div>@endif
</form>
