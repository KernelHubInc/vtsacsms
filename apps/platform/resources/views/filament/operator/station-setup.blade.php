<x-filament-panels::page>
    @php
        $wizardSteps = [
            ['title' => 'Prepare the site', 'copy' => 'Confirm the operator, address, map pin and location timezone. Publish the site only after its public details are checked.', 'link' => $links['1. Location & publication'] ?? null, 'action' => 'Open site settings'],
            ['title' => 'Register the charging station', 'copy' => 'Use the exact charge point identity saved on the charger. Check the OCPP version, serial number, station QR identifier and publication settings.', 'link' => $links['2. Station settings'] ?? null, 'action' => 'Open station settings'],
            ['title' => 'Add the EVSE', 'copy' => 'An EVSE is an independently controlled outlet. Enter the number reported by the device and connect it to this station.', 'link' => $links['3. EVSEs'] ?? null, 'action' => 'Open EVSEs'],
            ['title' => 'Add the physical connector', 'copy' => 'Choose the EVSE, connector number, physical plug standard, AC or DC current type, and maximum output in watts. Match the manufacturer label.', 'link' => $links['4. Connectors'] ?? null, 'action' => 'Open connectors'],
            ['title' => 'Set operating hours', 'copy' => 'Enter each day’s local opening and closing times, or mark the site open 24 hours or closed. Missing hours mean unknown.', 'link' => $links['5. Operating hours'] ?? null, 'action' => 'Open operating hours'],
            ['title' => 'Publish the right tariff', 'copy' => 'Apply the tariff to the intended connector. Review energy, session or energy-flow-time pricing, currency, tax and effective dates before publishing.', 'link' => $links['6. Pricing'] ?? null, 'action' => 'Open pricing'],
            ['title' => 'Connect and verify the device', 'copy' => 'Save the charger settings, reconnect while idle, then confirm Online and a fresh physical connector status here. A heartbeat alone does not prove the plug is available.', 'link' => null, 'action' => null],
        ];
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ $setup['name'] }}</x-slot>
        <x-slot name="description">{{ $setup['site_name'] }} · {{ $setup['identity'] }} · OCPP {{ $setup['protocol'] }}</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">Complete the steps in order. One site can contain several stations. Each station needs its own identity, EVSEs and physical connectors. No gateway restart is required for a newly registered active station in dynamic enrollment mode.</p>
        <nav aria-label="Station setup steps" class="mt-4 flex flex-wrap gap-3">
            @foreach ($links as $label => $url)
                <x-filament::button tag="a" :href="$url" color="gray" size="sm">{{ $label }}</x-filament::button>
            @endforeach
        </nav>
    </x-filament::section>

    <x-filament::section heading="Guided station setup" description="Work through the setup in order. Each step points to the matching admin page when your role has access." icon="heroicon-o-clipboard-document-check">
        <div x-data="{ activeStep: 0, count: {{ count($wizardSteps) }} }" class="space-y-5">
            <div class="flex items-center justify-between gap-4">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200" aria-live="polite">
                    Step <span x-text="activeStep + 1"></span> of {{ count($wizardSteps) }}
                </p>
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="progressbar" aria-label="Setup progress" aria-valuemin="1" aria-valuemax="{{ count($wizardSteps) }}" :aria-valuenow="activeStep + 1">
                    <div class="h-full rounded-full bg-primary-600" :style="`width: ${((activeStep + 1) / count) * 100}%; transition: width 300ms ease-out`"></div>
                </div>
            </div>

            <nav aria-label="Guided setup steps" class="flex flex-wrap gap-2">
                @foreach ($wizardSteps as $index => $step)
                    <button type="button" @click="activeStep = {{ $index }}" :aria-current="activeStep === {{ $index }} ? 'step' : null" :class="activeStep === {{ $index }} ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'" class="rounded-full px-3 py-1.5 text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600" style="transition: background-color 200ms, color 200ms">
                        {{ $index + 1 }}. {{ $step['title'] }}
                    </button>
                @endforeach
            </nav>

            @foreach ($wizardSteps as $index => $step)
                <section x-cloak x-show="activeStep === {{ $index }}" x-transition.opacity.duration.200ms aria-labelledby="setup-step-{{ $index }}" class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-900/40" style="min-height: 9rem">
                    <h3 id="setup-step-{{ $index }}" class="text-base font-semibold text-gray-950 dark:text-white">{{ $step['title'] }}</h3>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $step['copy'] }}</p>
                    @if ($step['link'])
                        <x-filament::button tag="a" :href="$step['link']" size="sm" class="mt-4">{{ $step['action'] }}</x-filament::button>
                    @elseif ($step['action'])
                        <p class="mt-4 text-sm text-gray-500">Ask an administrator for access to this setup step.</p>
                    @else
                        <a href="#device-connection" class="mt-4 inline-flex text-sm font-semibold text-primary-600 hover:underline">Check device connection below</a>
                    @endif
                </section>
            @endforeach

            <div class="flex justify-between gap-3">
                <x-filament::button color="gray" icon="heroicon-o-arrow-left" x-on:click="activeStep = Math.max(0, activeStep - 1)" x-bind:disabled="activeStep === 0">Previous</x-filament::button>
                <x-filament::button icon="heroicon-o-arrow-right" icon-position="after" x-on:click="activeStep = Math.min(count - 1, activeStep + 1)" x-bind:disabled="activeStep === count - 1">Next step</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Operator instructions" collapsible collapsed>
        @include('filament.operator.partials.station-setup-guide')
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Configuration checklist">
            <ul class="space-y-3">
                @foreach ($setup['checks'] as $label => $complete)
                    <li class="flex items-start gap-3 text-sm">
                        <x-filament::badge :color="$complete ? 'success' : 'warning'">{{ $complete ? 'Done' : 'To do' }}</x-filament::badge>
                        <span>{{ $label }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-sm text-gray-500">Unknown opening hours do not mean closed. Pricing and setup checks do not prove a successful physical charge.</p>
        </x-filament::section>
        <x-filament::section heading="Device connection" id="device-connection">
            <div class="space-y-4" wire:poll.15s>
                <x-filament::badge :color="$setup['connection'] === 'online' ? 'success' : 'gray'">{{ ucfirst($setup['connection']) }}</x-filament::badge>
                <p class="text-sm">Last device message (UTC): {{ $setup['last_seen'] }}</p>
                <p class="text-sm">Required final WebSocket path: <code class="break-all">/ocpp/{{ $setup['identity'] }}</code></p>
                <p class="text-sm text-gray-500">Use your deployment's configured WebSocket host. If the charger appends its identifier, enter the base URL ending in /ocpp and the identity separately. Include the identity only once.</p>
                <p class="text-sm text-gray-500">Online is connection evidence. Available, Charging and Faulted come from device reports. Reconnect the idle device after configuring its connectors to receive fresh status.</p>
                @if ($setup['connection'] === 'online' && collect($setup['outlets'])->contains('status', 'Unknown'))
                    <div role="status" class="rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-700">
                        <p class="font-semibold">Connected, waiting for a plug status</p>
                        <p class="mt-2">Check the EVSE and connector numbers below, then reconnect the charger while idle. A fresh StatusNotification must identify the physical connector. Heartbeats confirm the connection but do not confirm that a plug is available.</p>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Physical connectors" description="Match these numbers and ratings to the charger. QR identifiers below are for the mobile charging flow, not the WebSocket identity.">
        @forelse ($setup['outlets'] as $outlet)
            <div class="border-b border-gray-200 py-4 last:border-0 dark:border-gray-700">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="font-semibold">EVSE {{ $outlet['evse'] }} / Connector {{ $outlet['number'] }}</h3>
                    <x-filament::badge color="gray">{{ ucfirst($outlet['lifecycle']) }}</x-filament::badge>
                    <x-filament::badge :color="$outlet['status'] === 'Available' ? 'success' : 'gray'">{{ $outlet['status'] }}</x-filament::badge>
                </div>
                <p class="mt-2 text-sm">{{ $outlet['standard'] }} · {{ $outlet['current'] }} · {{ number_format($outlet['power_w'] / 1000, 1) }} kW</p>
                <p class="mt-2 text-sm">Pricing: {{ $outlet['pricing'] }}</p>
                <p class="mt-2 break-all text-sm">QR identifier: <code>{{ $outlet['qr'] }}</code></p>
            </div>
        @empty
            <p class="text-sm text-gray-600 dark:text-gray-300">No connectors yet. Add an EVSE for this station, then add its connector with the correct plug standard, current type and power in watts. Set both to Active when ready for service.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
