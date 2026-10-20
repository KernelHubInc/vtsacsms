<x-filament-panels::page>
    @if (! $authorized)
        <x-filament::section heading="Your workspace">
            Your account can use the tools in the navigation. Ask your administrator for Reporting access to view network monitoring.
        </x-filament::section>
    @else
        @php
            $d = $dashboard;
            $stationResource = \App\Filament\Operator\Resources\ChargingStations\ChargingStationResource::class;
            $sessionResource = \App\Filament\Operator\Resources\ChargingSessions\ChargingSessionResource::class;
            $paymentResource = \App\Filament\Operator\Resources\PaymentIntents\PaymentIntentResource::class;
            $workResource = \App\Filament\Operator\Resources\WorkOrders\WorkOrderResource::class;
            $states = $d['session_states'];
            $waitingCount = array_sum(array_intersect_key($states, array_flip(['requested', 'authorizing', 'authorized', 'starting'])));
            $paused = ($states['suspended_by_ev'] ?? 0) + ($states['suspended_by_evse'] ?? 0);
            $peak = max(1, max(array_column($d['trend'], 'energy_wh')));
            $localTime = \Carbon\CarbonImmutable::parse($d['updated_at'])->setTimezone('Asia/Manila');
            $colors = ['Available' => '#18a978', 'Charging' => '#2589be', 'Faulted' => '#d64555', 'Offline' => '#718097', 'Reserved' => '#9974bf', 'Maintenance' => '#d5a14a'];
            $segments = [];
            $offset = 0;
            foreach ($d['breakdown'] as $label => $count) {
                if ($count > 0) {
                    $end = $offset + $count / max(1, $d['connector_count']) * 100;
                    $segments[] = ($colors[$label] ?? '#9bbac5').' '.$offset.'% '.$end.'%';
                    $offset = $end;
                }
            }
        @endphp
        <div class="ps-monitor" wire:poll.15s>
            <header class="ps-monitor-hero">
                <div>
                    
                    <h2>Charging network</h2>
                    <p>Live equipment signals and completed charging activity. All displayed times are GMT+8.</p>
                </div>
                <div class="ps-refresh">
                    <span>Updated {{ $localTime->format('H:i:s') }}</span>
                    <x-filament::button color="gray" size="sm" wire:click="$refresh" wire:loading.attr="disabled">Refresh now</x-filament::button>
                    <span wire:loading role="status">Refreshing…</span>
                </div>
            </header>

            <div class="ps-monitor-filters">
                <label>Location
                    <select wire:model.live="site">
                        <option value="">All authorized locations</option>
                        @foreach ($d['sites'] as $option)<option value="{{ $option['id'] }}">{{ $option['name'] }}</option>@endforeach
                    </select>
                </label>
                <label>Reporting period
                    <select wire:model.live="days"><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option></select>
                </label>
                <p>{{ $d['from'] }} – {{ $localTime->toDateString() }}<br>Live statuses are independent of this period.</p>
            </div>

            <div class="ps-metric-grid">
                <article class="ps-metric"><span>Active stations</span><strong>{{ $d['lifecycle']['active'] }}</strong><small>{{ $d['online'] }} online · {{ $d['not_online'] }} offline / not seen</small></article>
                <article class="ps-metric"><span>Available connectors</span><strong>{{ $d['breakdown']['Available'] }} <em>/ {{ $d['connector_count'] }}</em></strong><small>Network availability: {{ $d['available_percent'] === null ? 'No equipment' : $d['available_percent'].'%' }} · all configured plugs</small></article>
                <article class="ps-metric"><span>Charging sessions</span><strong>{{ $states['charging'] ?? 0 }}</strong><small>{{ $paused }} paused · {{ $waitingCount }} waiting to start</small></article>
                <article class="ps-metric"><span>Completed-session energy</span><strong>{{ number_format($d['energy_wh'] / 1000, 2) }} <em>kWh</em></strong><small>{{ $d['completed_sessions'] }} completed sessions in period</small></article>
                <article class="ps-metric"><span>Finalized charging charges</span>
                    @if (! $d['finance_visible'])<strong class="ps-metric-text">Restricted</strong>
                    @else @forelse ($d['charges'] as $amount)<strong class="ps-money">{{ $amount['currency'] }} {{ number_format($amount['amount_minor'] / 100, 2) }}</strong>@empty<strong class="ps-metric-text">No charges</strong>@endforelse @endif
                    <small>Gross session cost · not net collections or refunds</small>
                </article>
                <article class="ps-metric"><span>Confirmed wallet top-ups</span>
                    @if (! $d['wallet_visible'])<strong class="ps-metric-text">Tenant scope only</strong>
                    @else @forelse ($d['topups'] as $amount)<strong class="ps-money">{{ $amount['currency'] }} {{ number_format($amount['amount_minor'] / 100, 2) }}</strong>@empty<strong class="ps-metric-text">No credits</strong>@endforelse @endif
                    <small>Live wallet credits · tenant-wide · separate from charging charges</small>
                </article>
                <article class="ps-metric"><span>Needs session review</span><strong>{{ $d['review'] }}</strong><small>{{ $d['waiting'] }} delayed starts · {{ $states['finalizing'] ?? 0 }} finalizing</small></article>
                <article class="ps-metric"><span>Equipment register</span><strong>{{ $d['station_count'] }}</strong><small>{{ $d['lifecycle']['draft'] }} draft · {{ $d['lifecycle']['maintenance'] }} maintenance · {{ $d['lifecycle']['retired'] }} retired</small></article>
            </div>

            <div class="ps-monitor-split">
                <section class="ps-panel">
                    <div class="ps-panel-heading"><div><h3>Energy & transactions</h3></div><span>{{ $d['days'] }} days</span></div>
                    <p class="ps-help">Completed sessions are attributed to their stop date in GMT+8. In-progress energy is excluded.</p>
                    <div class="ps-energy-chart" role="img" aria-label="Daily completed-session energy in kilowatt-hours. Exact values are available in the daily totals table below.">
                        @foreach ($d['trend'] as $point)
                            <div class="ps-chart-column" title="{{ $point['day'] }}: {{ number_format($point['energy_wh'] / 1000, 3) }} kWh, {{ $point['sessions'] }} sessions">
                                <div class="ps-chart-bar" style="height: {{ $point['energy_wh'] > 0 ? max(2, $point['energy_wh'] / $peak * 100) : 0 }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="ps-chart-axis"><span>{{ $d['from'] }}</span><span>Peak {{ number_format($peak > 1 ? $peak / 1000 : 0, 2) }} kWh / day</span><span>{{ $localTime->toDateString() }}</span></div>
                    @if ($d['completed_sessions'] === 0)<p class="ps-empty">No completed charging sessions in this period.</p>@endif
                    <details class="ps-daily"><summary>View daily energy, transactions and charges</summary>
                        <div class="ps-table-scroll"><table><thead><tr><th>Date (GMT+8)</th><th>Sessions</th><th>Energy (kWh)</th><th>Finalized charges</th></tr></thead><tbody>
                        @foreach (array_reverse($d['trend']) as $point)<tr><td>{{ $point['day'] }}</td><td>{{ $point['sessions'] }}</td><td>{{ number_format($point['energy_wh'] / 1000, 3) }}</td><td>
                            @if (! $d['finance_visible'])Restricted @else @forelse ($point['charges'] as $amount)<div>{{ $amount['currency'] }} {{ number_format($amount['amount_minor'] / 100, 2) }}</div>@empty—@endforelse @endif
                        </td></tr>@endforeach
                        </tbody></table></div>
                    </details>
                </section>
                <section class="ps-panel">
                    <h3>Connector status</h3>
                    <p class="ps-help">Connection loss overrides old availability. Draft, retired and maintenance equipment remains visible.</p>
                    <div class="ps-status-ring" style="background: {{ $segments ? 'conic-gradient('.implode(', ', $segments).')' : '#e0e7ef' }}" aria-hidden="true"><div><strong>{{ $d['connector_count'] }}</strong><span>connectors</span></div></div>
                    <p class="ps-help">{{ $d['connector_count'] }} configured connectors</p>
                    <ul class="ps-status-list">
                        @foreach ($d['breakdown'] as $label => $count)
                            @if ($count > 0 || in_array($label, ['Available', 'Charging', 'Offline', 'Faulted']))
                            <li><span class="ps-dot" style="background: {{ $colors[$label] ?? '#9bbac5' }}"></span><span>{{ $label }}</span><strong>{{ $count }}</strong></li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            </div>

            <section class="ps-panel">
                <div class="ps-panel-heading"><div><h3>Stations</h3></div>
                    @if ($stationResource::canViewAny())<x-filament::button tag="a" color="gray" :href="$stationResource::getUrl()">Manage stations</x-filament::button>@endif
                </div>
                <div class="ps-monitor-filters">
                    <label>Search stations<input type="search" wire:model.live.debounce.400ms="search" placeholder="Station, location or charge-point ID" maxlength="100"></label>
                    <label>Connection / lifecycle<select wire:model.live="status"><option value="">All statuses</option><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Not seen</option><option value="active">Active equipment</option><option value="maintenance">Maintenance</option><option value="draft">Draft</option><option value="retired">Retired</option></select></label>
                </div>
                <div class="ps-station-grid">
                    @forelse ($d['stations'] as $station)
                    <article class="ps-station" wire:key="station-{{ $station['id'] }}">
                        <div class="ps-panel-heading"><span class="ps-badge {{ $station['connection'] === 'online' ? 'ps-online' : '' }}">{{ $station['connection'] === 'unknown' ? 'Not seen' : ucfirst($station['connection']) }}</span><span class="ps-lifecycle">{{ ucfirst($station['lifecycle']) }}</span></div>
                        <h4><a href="{{ $stationResource::getUrl('setup', ['record' => $station['id']]) }}">{{ $station['name'] }}</a></h4>
                        <p class="ps-identity">{{ $station['identity'] }}</p><p>{{ $station['site'] }}</p><p class="ps-help">{{ $station['address'] ?: 'Address not configured' }}</p>
                        <div class="ps-plugs">@forelse ($station['connectors'] as $plug)<div><span>Plug {{ $plug['number'] }} · {{ number_format($plug['power_w'] / 1000, 1) }} kW</span><strong>{{ $plug['status'] }}</strong></div>@empty<p>No connectors configured</p>@endforelse</div>
                        <p class="ps-help">Last device message: {{ $station['last_seen'] ? \Carbon\CarbonImmutable::parse($station['last_seen'])->setTimezone('Asia/Manila')->format('M j, H:i:s') : 'Never received' }}</p>
                        <a class="ps-text-link" href="{{ $stationResource::getUrl('setup', ['record' => $station['id']]) }}">Open station & setup checklist →</a>
                    </article>
                    @empty<p class="ps-empty">No stations match your filters, or your account has no asset access for this location.</p>@endforelse
                </div>
                <div class="ps-pagination"><span>{{ $d['matches'] }} stations · page {{ $d['page'] }} of {{ $d['pages'] }}</span><div>
                    <x-filament::button color="gray" size="sm" wire:click="previousStations" :disabled="$d['page'] <= 1">Previous</x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="nextStations" :disabled="$d['page'] >= $d['pages']">Next</x-filament::button>
                </div></div>
            </section>

            <section class="ps-panel">
                <h3>Needs attention</h3>
                <div class="ps-attention-summary">
                    @if ($sessionResource::canViewAny())<a href="{{ $sessionResource::getUrl() }}"><strong>{{ $d['waiting'] }}</strong> Delayed starts <small>Over 5 minutes or past deadline</small></a><a href="{{ $sessionResource::getUrl() }}"><strong>{{ $d['review'] }}</strong> Sessions needing review</a>@endif
                    @if ($paymentResource::canViewAny())<a href="{{ $paymentResource::getUrl() }}"><strong>{{ $d['payment_exceptions'] }}</strong> Failed / expired session payments <small>Selected reporting period</small></a>@endif
                    @if ($d['maintenance_visible'] && $workResource::canViewAny())<a href="{{ $workResource::getUrl() }}"><strong>{{ $d['sla_breaches'] }}</strong> Maintenance SLA breaches <small>{{ $d['open_work_orders'] }} open work orders</small></a>@endif
                </div>
                <div class="ps-table-scroll"><table><thead><tr><th>Equipment</th><th>Attention needed</th><th>Action</th></tr></thead><tbody>
                    @forelse ($d['attention'] as $item)<tr><td>{{ $item['name'] }}</td><td>{{ $item['kind'] }}</td><td><a class="ps-text-link" href="{{ $stationResource::getUrl('setup', ['record' => $item['station_id']]) }}">Inspect station</a></td></tr>@empty<tr><td colspan="3">No current equipment alerts in your authorized scope.</td></tr>@endforelse
                </tbody></table></div>
                @if ($d['attention_count'] > 10)<p class="ps-help">Showing 10 of {{ $d['attention_count'] }} equipment alerts. Use the station filters to investigate the network.</p>@endif
            </section>
        </div>
    @endif
</x-filament-panels::page>
