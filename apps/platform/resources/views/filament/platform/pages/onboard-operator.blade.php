<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-8">
            @if ($completedOrganization)
                <x-heroicon-o-check-circle class="mb-4 h-12 w-12 text-emerald-600" />
                <h2 class="text-2xl font-semibold">Operator ready for invitation acceptance</h2>
                <p role="status" class="mt-3 text-gray-600 dark:text-gray-300">The organization is saved and the invitation is queued for {{ $email }}. The recipient chooses their own password. The link expires in seven days.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <x-filament::button tag="a" :href="\App\Filament\Platform\Resources\Organizations\OrganizationResource::getUrl('edit', ['record' => $completedOrganization])">View organization</x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ url('/admin/user-invitations') }}">Manage invitations</x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ static::getUrl() }}">Onboard another operator</x-filament::button>
                </div>
            @else
                <ol aria-label="Onboarding progress" class="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach (['Organization', 'Administrator', 'Access', 'Review'] as $label)
                        <li @if($step === $loop->iteration) aria-current="step" @endif class="border-t-2 pt-3 text-sm {{ $step >= $loop->iteration ? 'border-teal-500 font-semibold' : 'border-gray-200 text-gray-500 dark:border-gray-700' }}">{{ $loop->iteration }}. {{ $label }}</li>
                    @endforeach
                </ol>
                <form wire:submit="{{ $step === 4 ? 'finish' : 'next' }}" class="space-y-5">
                    @if ($step === 1)
                        <h2 class="text-xl font-semibold">Which organization are we onboarding?</h2>
                        <label class="block text-sm font-medium" for="operator-mode">Organization</label>
                        <select id="operator-mode" wire:model.live="mode" class="w-full rounded-lg border-gray-300 bg-transparent dark:border-gray-600"><option value="new">Create a new operator</option><option value="existing">Use an existing operator</option></select>
                        @if ($mode === 'new')
                            @foreach (['name' => 'Operator / company name', 'code' => 'Unique operator code'] as $field => $label)
                                <div><label for="operator-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $label }}</label><input id="operator-{{ $field }}" wire:model="{{ $field }}" type="text" required maxlength="{{ $field === 'name' ? 180 : 80 }}" aria-describedby="error-{{ $field }}" @error($field) aria-invalid="true" @enderror class="w-full rounded-lg bg-transparent {{ $errors->has($field) ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">@error($field)<p id="error-{{ $field }}" role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror</div>
                            @endforeach
                            <p class="text-sm text-gray-500">Use letters, numbers, hyphens or underscores for the code. It will be saved in uppercase.</p>
                        @else
                            <label for="operator-organization" class="block text-sm font-medium">Existing organization</label>
                            <select id="operator-organization" wire:model="organization_id" required class="w-full rounded-lg border-gray-300 bg-transparent dark:border-gray-600"><option value="">Select an operator</option>@foreach($organizations as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                            @error('organization_id')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    @elseif ($step === 2)
                        <h2 class="text-xl font-semibold">Invite the person who will manage it</h2>
                        <div><label for="operator-email" class="mb-2 block text-sm font-medium">Work email address</label><input id="operator-email" wire:model="email" type="email" required maxlength="254" autocomplete="email" aria-describedby="email-error" @error('email') aria-invalid="true" @enderror class="w-full rounded-lg bg-transparent {{ $errors->has('email') ? 'border-red-500' : 'border-gray-300 dark:border-gray-600' }}">@error('email')<p id="email-error" role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                        <div><label for="operator-role" class="mb-2 block text-sm font-medium">Operator role</label><select id="operator-role" wire:model="role_id" required class="w-full rounded-lg border-gray-300 bg-transparent dark:border-gray-600"><option value="">Select a role</option>@foreach($roles as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>@error('role_id')<p role="alert" class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                        @if (empty($roles))<p role="alert" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">No eligible operator role is available. Create a role with Operator panel access in Roles, or ask a platform administrator with delegation authority.</p>@endif
                        <p class="text-sm text-gray-500">Only operator roles you can delegate are listed. No shared password is created.</p>
                    @elseif ($step === 3)
                        <h2 class="text-xl font-semibold">Choose their access</h2>
                        <label for="operator-scope" class="block text-sm font-medium">Access scope</label>
                        <select id="operator-scope" wire:model.live="scope" class="w-full rounded-lg border-gray-300 bg-transparent dark:border-gray-600"><option value="organization">This operator's sites</option>@if($mode === 'existing')<option value="site">One existing site</option>@endif</select>
                        <p class="text-sm text-gray-500">Organization access follows sites assigned to this operator, including sites added later. It does not grant access to other operators.</p>
                        @if($scope === 'site')<label for="operator-site" class="block text-sm font-medium">Site</label><select id="operator-site" wire:model="site_id" required class="w-full rounded-lg border-gray-300 bg-transparent dark:border-gray-600"><option value="">Choose a site</option>@foreach($sites as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>@endif
                        @error('site_id')<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @else
                        <h2 class="text-xl font-semibold">Review before inviting</h2>
                        <dl class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach (['Organization' => $mode === 'new' ? $name : ($organizations[$organization_id] ?? 'Select an organization'), 'Code' => $mode === 'new' ? strtoupper($code) : 'Existing organization', 'Invite to' => $email, 'Role' => $roles[$role_id] ?? 'Select a role', 'Access' => $scope === 'organization' ? 'This operator’s sites' : ($sites[$site_id] ?? 'Select a site')] as $label => $value)
                                <div class="py-3"><dt class="text-sm text-gray-500">{{ $label }}</dt><dd class="mt-1 break-words font-medium">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="text-sm text-gray-500">Create and invite saves the organization and queues an email. No stations are published and no billing or payout settings are changed.</p>
                    @endif
                    <div class="flex items-center justify-between border-t border-gray-200 pt-6 dark:border-gray-700">
                        @if ($step > 1)<x-filament::button color="gray" wire:click="back" wire:loading.attr="disabled">Back</x-filament::button>@else<span></span>@endif
                        <x-filament::button type="submit" wire:loading.attr="disabled">{{ $step === 4 ? 'Create and invite' : 'Continue' }}</x-filament::button>
                    </div>
                    <p wire:loading role="status" class="text-sm text-gray-500">Saving your request…</p>
                </form>
            @endif
        </section>
        <aside class="self-start rounded-2xl bg-slate-950 p-6 text-white">
            <x-heroicon-o-building-office-2 class="mb-4 h-8 w-8 text-teal-300" />
            <h2 class="text-lg font-semibold">A clear start for every operator</h2>
            <p class="mt-3 text-sm leading-6 text-slate-300">An operator is the company managing charging locations. Their administrator receives access through a personal email invitation.</p>
            <h3 class="mt-6 font-semibold">After onboarding</h3>
            <ol class="mt-3 list-decimal space-y-3 pl-5 text-sm leading-6 text-slate-300"><li>Confirm they accepted the invitation.</li><li>Create or update a site and select this operator.</li><li>Use the existing station setup guide to configure equipment, hours and pricing.</li><li>Check access and charger readiness before publishing.</li></ol>
        </aside>
    </div>
</x-filament-panels::page>
