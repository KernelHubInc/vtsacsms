<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Power Solutions design-system catalog">
        <title>Currentline design system · Power Solutions</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        @php
            $navigation = [
                ['label' => 'Foundations', 'href' => '#foundations', 'current' => true],
                ['label' => 'Controls', 'href' => '#controls'],
                ['label' => 'Operational state', 'href' => '#status'],
                ['label' => 'Recovery states', 'href' => '#recovery'],
                ['label' => 'Overlays', 'href' => '#overlays'],
            ];
        @endphp

        <x-ui.page-shell
            eyebrow="Power Solutions Currentline · visual catalog"
            title="Designed for clear decisions."
            description="A theme-aware inventory of reusable foundations. Catalog content is illustrative and creates no product or domain records."
        >
            <x-slot:navigation>
                <x-ui.responsive-nav :items="$navigation" />
            </x-slot:navigation>

            <x-slot:actions>
                <x-ui.button data-theme-toggle aria-pressed="false" variant="secondary">Toggle theme</x-ui.button>
                <x-ui.button href="/" variant="quiet">Back to foundation</x-ui.button>
            </x-slot:actions>

            <div class="grid gap-10">
                <section id="foundations" aria-labelledby="foundations-title" class="scroll-mt-6">
                    <div class="mb-5 flex items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">01 · Foundations</p>
                            <h2 id="foundations-title" class="mt-2 text-2xl font-semibold text-foreground">Color and type</h2>
                        </div>
                        <span class="font-mono text-xs text-muted">4 px grid · AA contrast intent</span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ([
                            ['name' => 'Current violet', 'class' => 'bg-brand', 'value' => 'Primary action'],
                            ['name' => 'Sea glass', 'class' => 'bg-accent', 'value' => 'Identity accent'],
                            ['name' => 'Available', 'class' => 'bg-state-available', 'value' => 'Operational success'],
                            ['name' => 'Faulted', 'class' => 'bg-state-faulted', 'value' => 'Needs attention'],
                        ] as $swatch)
                            <x-ui.card>
                                <div class="mb-4 h-20 rounded-md {{ $swatch['class'] }}"></div>
                                <p class="font-semibold text-foreground">{{ $swatch['name'] }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $swatch['value'] }}</p>
                            </x-ui.card>
                        @endforeach
                    </div>

                    <x-ui.card class="mt-4 overflow-hidden">
                        <div class="grid gap-6 lg:grid-cols-[1.1fr_.9fr] lg:items-end">
                            <div>
                                <p class="text-4xl font-semibold leading-[1.05] tracking-[-0.04em] text-foreground sm:text-5xl">Operational clarity without visual noise.</p>
                            </div>
                            <div class="grid gap-3 border-l border-border pl-5">
                                <p class="text-lg font-semibold text-foreground">Section title · 18/24</p>
                                <p class="text-base leading-6 text-muted">Body copy keeps an easy reading rhythm while preserving room for identifiers, units, and evidence.</p>
                                <p class="font-mono text-xs text-muted">01K0M0JJ5X0M0JJ5X0M0JJ5X0M · UTC</p>
                            </div>
                        </div>
                    </x-ui.card>
                </section>

                <section id="controls" aria-labelledby="controls-title" class="scroll-mt-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">02 · Controls</p>
                    <h2 id="controls-title" class="mt-2 text-2xl font-semibold text-foreground">Actions and fields</h2>

                    <div class="mt-5 grid gap-4 xl:grid-cols-2">
                        <x-ui.card>
                            <x-slot:title>Buttons</x-slot:title>
                            <div class="flex flex-wrap gap-3">
                                <x-ui.button>Primary action</x-ui.button>
                                <x-ui.button variant="secondary">Secondary</x-ui.button>
                                <x-ui.button variant="quiet">Quiet action</x-ui.button>
                                <x-ui.button variant="danger">Destructive</x-ui.button>
                                <x-ui.button loading>Submitting</x-ui.button>
                                <x-ui.button disabled variant="secondary">Unavailable</x-ui.button>
                            </div>
                        </x-ui.card>

                        <x-ui.card>
                            <x-slot:title>Form fields</x-slot:title>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <x-ui.form-field
                                    name="catalog-label"
                                    label="Display label"
                                    hint="Labels stay visible while typing."
                                    placeholder="Example value"
                                    required
                                />
                                <x-ui.form-field
                                    name="catalog-error"
                                    label="Connector reference"
                                    error="Use the identifier printed on the connector."
                                    value="Unknown"
                                />
                            </div>
                        </x-ui.card>
                    </div>
                </section>

                <section id="status" aria-labelledby="status-title" class="scroll-mt-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">03 · Operational state</p>
                    <h2 id="status-title" class="mt-2 text-2xl font-semibold text-foreground">Labeled, shaped, and colored</h2>

                    <x-ui.card class="mt-5">
                        <div class="flex flex-wrap gap-2.5">
                            <x-ui.status-chip label="Available" tone="available" />
                            <x-ui.status-chip label="Preparing" tone="preparing" />
                            <x-ui.status-chip label="Charging" tone="charging" />
                            <x-ui.status-chip label="Suspended" tone="suspended" />
                            <x-ui.status-chip label="Finishing" tone="finishing" />
                            <x-ui.status-chip label="Reserved" tone="reserved" />
                            <x-ui.status-chip label="Unavailable" />
                            <x-ui.status-chip label="Faulted" tone="faulted" />
                            <x-ui.status-chip label="Offline · 8 min" tone="offline" />
                        </div>
                        <div class="mt-6 grid gap-4 md:grid-cols-3">
                            <x-ui.card subtle>
                                <x-slot:eyebrow>Connectivity</x-slot:eyebrow>
                                <x-slot:title>Charger C-104</x-slot:title>
                                <div class="flex items-center justify-between gap-4">
                                    <span>Last evidence 24 s ago</span>
                                    <x-ui.status-chip label="Connected" tone="success" />
                                </div>
                            </x-ui.card>
                            <x-ui.card selected>
                                <x-slot:eyebrow>Connector</x-slot:eyebrow>
                                <x-slot:title>CCS2 · 01</x-slot:title>
                                <div class="flex items-center justify-between gap-4">
                                    <span>Selected state</span>
                                    <x-ui.status-chip label="Charging" tone="charging" />
                                </div>
                            </x-ui.card>
                            <x-ui.card subtle>
                                <x-slot:eyebrow>Data confidence</x-slot:eyebrow>
                                <x-slot:title>Projection delayed</x-slot:title>
                                <div class="flex items-center justify-between gap-4">
                                    <span>Updated 8 min ago</span>
                                    <x-ui.status-chip label="Stale" tone="warning" />
                                </div>
                            </x-ui.card>
                        </div>
                    </x-ui.card>
                </section>

                <section id="recovery" aria-labelledby="recovery-title" class="scroll-mt-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">04 · Recovery states</p>
                    <h2 id="recovery-title" class="mt-2 text-2xl font-semibold text-foreground">Loading, empty, and error</h2>

                    <div class="mt-5 grid gap-4 xl:grid-cols-3">
                        <x-ui.card>
                            <x-slot:title>Loading evidence</x-slot:title>
                            <x-ui.skeleton :lines="5" />
                        </x-ui.card>

                        <x-ui.empty-state
                            title="No matching records"
                            description="The current filters exclude every result. Clear them without losing your place."
                        >
                            <x-ui.button size="sm" variant="secondary">Clear filters</x-ui.button>
                        </x-ui.empty-state>

                        <x-ui.error-state
                            title="Evidence could not refresh"
                            description="Last known values remain visible but may be stale. Retry safely or use the reference when contacting support."
                            correlation-id="01K0M0JJ5X0M0JJ5X0M0JJ5X0M"
                        >
                            <x-ui.button size="sm" variant="secondary">Retry refresh</x-ui.button>
                        </x-ui.error-state>
                    </div>
                </section>

                <section id="overlays" aria-labelledby="overlays-title" class="scroll-mt-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand">05 · Overlays</p>
                    <h2 id="overlays-title" class="mt-2 text-2xl font-semibold text-foreground">Confirmation and feedback</h2>

                    <div class="mt-5 grid gap-4 lg:grid-cols-[.8fr_1.2fr]">
                        <x-ui.card>
                            <x-slot:title>Risk follows friction</x-slot:title>
                            <p>Consequential actions state the target and effect before submission.</p>
                            <x-slot:footer>
                                <x-ui.button data-dialog-open="catalog-confirmation" variant="danger">Open confirmation</x-ui.button>
                            </x-slot:footer>
                        </x-ui.card>

                        <div class="grid content-start gap-3" aria-label="Toast examples">
                            <x-ui.toast title="View updated" description="Fresh evidence was retrieved without changing your selection." />
                            <x-ui.toast title="Draft preserved" description="Your local input remains available." tone="success" />
                        </div>
                    </div>
                </section>
            </div>

            <x-ui.confirmation-dialog
                id="catalog-confirmation"
                title="Confirm consequential action"
                description="This visual example names a target and effect. It does not submit a command or alter product data."
                confirm-label="Confirm example"
                tone="danger"
            >
                Target: catalog-only charger · Effect: no operation will be performed.
            </x-ui.confirmation-dialog>
        </x-ui.page-shell>
    </body>
</html>
