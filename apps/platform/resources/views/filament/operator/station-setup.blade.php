<x-filament-panels::page>
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
        <x-filament::section heading="Device connection">
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
