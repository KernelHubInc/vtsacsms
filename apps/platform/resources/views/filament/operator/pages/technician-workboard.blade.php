<x-filament-panels::page>
    <section class="mx-auto max-w-3xl space-y-5">
        <header class="rounded-3xl bg-gradient-to-br from-violet-600 to-indigo-700 p-6 text-white shadow-lg shadow-violet-900/15">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-violet-100">Technician workboard</p>
            <h1 class="mt-2 text-2xl font-semibold">Today’s assigned work</h1>
            <p class="mt-2 max-w-xl text-sm leading-6 text-violet-100">
                Evidence, safety steps, parts, and elapsed time remain attached to the server-side work order.
            </p>
        </header>

        <div class="space-y-4">
            @forelse ($assignments as $assignment)
                @php($workOrder = $assignment->workOrder)
                <article class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-violet-600 dark:text-violet-300">
                                    {{ $workOrder->work_order_number }}
                                </p>
                                <h2 class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">{{ $workOrder->title }}</h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $workOrder->site->name }} · {{ str($workOrder->asset_type)->headline() }}
                                </p>
                            </div>
                            <span class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                {{ str($workOrder->state->value)->headline() }}
                            </span>
                        </div>

                        <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-2xl bg-gray-50 p-3 dark:bg-white/5">
                                <dt class="text-xs text-gray-500">Priority</dt>
                                <dd class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $workOrder->priority->name }}</dd>
                            </div>
                            <div class="rounded-2xl bg-gray-50 p-3 dark:bg-white/5">
                                <dt class="text-xs text-gray-500">SLA target</dt>
                                <dd class="mt-1 font-semibold text-gray-900 dark:text-white">
                                    {{ $workOrder->resolve_target_at?->timezone(config('app.timezone'))->format('M j, g:i A') ?? 'Not configured' }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-5">
                            <div class="flex items-center justify-between text-xs font-medium text-gray-500">
                                <span>Required steps</span>
                                <span>{{ $workOrder->checklistItems->whereNotNull('completed_at')->count() }} / {{ $workOrder->checklistItems->where('is_required', true)->count() }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                @php($required = max(1, $workOrder->checklistItems->where('is_required', true)->count()))
                                <div class="h-full rounded-full bg-violet-600" style="width: {{ min(100, $workOrder->checklistItems->whereNotNull('completed_at')->count() / $required * 100) }}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 border-t border-gray-100 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                        @if ($workOrder->state === \App\Modules\Maintenance\Domain\WorkOrderState::Assigned)
                            <button
                                type="button"
                                wire:click="startWork('{{ $workOrder->getKey() }}')"
                                wire:loading.attr="disabled"
                                class="min-h-12 flex-1 rounded-2xl bg-violet-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-500 focus:outline-none focus:ring-4 focus:ring-violet-300 disabled:opacity-50 dark:focus:ring-violet-900"
                            >
                                Start work
                            </button>
                        @elseif (in_array($workOrder->state, [
                            \App\Modules\Maintenance\Domain\WorkOrderState::OnHold,
                            \App\Modules\Maintenance\Domain\WorkOrderState::AwaitingParts,
                            \App\Modules\Maintenance\Domain\WorkOrderState::AwaitingAccess,
                            \App\Modules\Maintenance\Domain\WorkOrderState::AwaitingExternal,
                            \App\Modules\Maintenance\Domain\WorkOrderState::AwaitingSafetyClearance,
                        ], true))
                            <button
                                type="button"
                                wire:click="resumeWork('{{ $workOrder->getKey() }}')"
                                wire:loading.attr="disabled"
                                class="min-h-12 flex-1 rounded-2xl bg-violet-600 px-4 py-3 text-sm font-semibold text-white focus:outline-none focus:ring-4 focus:ring-violet-300"
                            >
                                Resume work
                            </button>
                        @else
                            <div class="min-h-12 flex-1 rounded-2xl border border-gray-200 px-4 py-3 text-center text-sm font-medium text-gray-600 dark:border-white/10 dark:text-gray-300">
                                Continue checklist and evidence through the work-order API
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="rounded-3xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-white/15 dark:bg-gray-900">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
                        <x-heroicon-o-check class="size-6" />
                    </div>
                    <h2 class="mt-4 font-semibold text-gray-950 dark:text-white">No assigned work</h2>
                    <p class="mt-1 text-sm text-gray-500">New assignments will appear here automatically.</p>
                </div>
            @endforelse
        </div>
    </section>
</x-filament-panels::page>
