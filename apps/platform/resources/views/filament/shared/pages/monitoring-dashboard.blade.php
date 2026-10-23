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
            $colors = ['Available' => '#18a978', 'Charging' => '#2589be', 'Faulted' => '#d64555', 'Offline' => '#66758a', 'Reserved' => '#9974bf', 'Maintenance' => '#d5a14a'];
            $attentionTotal = $d['attention_count'] + $d['waiting'] + $d['review'] + $d['payment_exceptions'] + ($d['maintenance_visible'] ? $d['sla_breaches'] : 0);
            $topSitePeak = max(1, $d['top_sites'][0]['energy_wh'] ?? 0);
        @endphp
        <div class="ps-ops" wire:poll.15s>
            <header class="ps-ops-intro">
                <div>
                    <h2>Operations overview</h2>
                    <p>Charging network performance and the items that need your attention.</p>
                </div>
                <div class="ps-ops-refresh">
                    <span>Updated {{ $localTime->format('M j, H:i:s') }} GMT+8</span>
                    <x-filament::button color="gray" size="sm" wire:click="$refresh" wire:loading.attr="disabled">Refresh now</x-filament::button>
                    <span wire:loading role="status">Refreshing…</span>
                </div>
            </header>

            <div class="ps-ops-toolbar">
                <label>Location
                    <select wire:model.live="site">
                        <option value="">All authorized locations</option>
                        @foreach ($d['sites'] as $option)<option value="{{ $option['id'] }}">{{ $option['name'] }}</option>@endforeach
                    </select>
                </label>
                <label>Reporting period
                    <select wire:model.live="days">
                        <option value="7">Last 7 days</option>
                        <option value="30">Last 30 days</option>
                        <option value="90">Last 90 days</option>
                    </select>
                </label>
                <p>Period: {{ $d['from'] }} – {{ $localTime->toDateString() }}<br>Connection and session states are current.</p>
            </div>

            <div class="ps-ops-kpis">
                <article class="ps-ops-kpi ps-ops-kpi--blue"><span>Active stations</span><strong>{{ $d['lifecycle']['active'] }}</strong><small>{{ $d['online'] }} online · {{ $d['not_online'] }} offline or not seen</small></article>
                <article class="ps-ops-kpi ps-ops-kpi--green"><span>Available connectors</span><strong>{{ $d['breakdown']['Available'] }} <em>/ {{ $d['connector_count'] }}</em></strong><small>{{ $d['available_percent'] === null ? 'No equipment configured' : $d['available_percent'].'% of configured connectors' }}</small></article>
                <article class="ps-ops-kpi ps-ops-kpi--cyan"><span>Charging now</span><strong>{{ $states['charging'] ?? 0 }}</strong><small>{{ $paused }} paused · {{ $waitingCount }} waiting to start</small></article>
                <article class="ps-ops-kpi ps-ops-kpi--navy"><span>Energy delivered</span><strong>{{ number_format($d['energy_wh'] / 1000, 2) }} <em>kWh</em></strong><small>{{ $d['completed_sessions'] }} completed sessions in period</small></article>
            </div>

            <div class="ps-ops-main-grid">
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Charging activity</h3><p>Completed sessions and energy delivered · {{ $d['days'] }} days</p></div></div>
                    <div class="ps-ops-chart" role="img" aria-label="Daily completed-session energy. Exact values are available in the daily totals below.">
                        @foreach ($d['trend'] as $point)
                            <div class="ps-ops-chart-day" title="{{ $point['day'] }}: {{ number_format($point['energy_wh'] / 1000, 3) }} kWh; {{ $point['sessions'] }} sessions">
                                <div style="height: {{ $point['energy_wh'] > 0 ? max(2, $point['energy_wh'] / $peak * 100) : 0 }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="ps-ops-axis"><span>{{ $d['from'] }}</span><span>Peak {{ number_format($peak > 1 ? $peak / 1000 : 0, 2) }} kWh / day</span><span>{{ $localTime->toDateString() }}</span></div>
                    @if ($d['completed_sessions'] === 0)<p class="ps-ops-empty">No completed charging sessions in this period.</p>@endif
                    <details class="ps-ops-details"><summary>View daily energy, sessions and charges</summary>
                        <div class="ps-ops-table-wrap"><table><thead><tr><th>Date (GMT+8)</th><th>Sessions</th><th>Energy (kWh)</th><th>Finalized charges</th></tr></thead><tbody>
                            @foreach (array_reverse($d['trend']) as $point)<tr><td>{{ $point['day'] }}</td><td>{{ $point['sessions'] }}</td><td>{{ number_format($point['energy_wh'] / 1000, 3) }}</td><td>
                                @if (! $d['finance_visible'])Restricted @else @forelse ($point['charges'] as $amount)<div>{{ $amount['currency'] }} {{ number_format($amount['amount_minor'] / 100, 2) }}</div>@empty — @endforelse @endif
                            </td></tr>@endforeach
                        </tbody></table></div>
                    </details>
                </section>
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Connector status</h3><p>Live equipment signals and configuration</p></div><strong>{{ $d['connector_count'] }}</strong></div>
                    <div class="ps-ops-health">
                        @foreach (['Available', 'Charging', 'Faulted', 'Offline', 'Unknown', 'Reserved', 'Maintenance', 'Draft', 'Retired'] as $label)
                            @php $count = $d['breakdown'][$label] ?? 0; @endphp
                            @if ($count > 0 || in_array($label, ['Available', 'Charging', 'Faulted', 'Offline']))
                                <div class="ps-ops-health-row"><span>{{ $label }}</span><div class="ps-ops-health-track"><div style="width: {{ $d['connector_count'] ? $count / $d['connector_count'] * 100 : 0 }}%; background: {{ $colors[$label] ?? '#9ba9ba' }}"></div></div><strong>{{ $count }}</strong></div>
                            @endif
                        @endforeach
                    </div>
                    <p class="ps-ops-note">Stale or missing device messages never count as available.</p>
                </section>
            </div>

            <div class="ps-ops-main-grid">
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Needs attention</h3><p>Current issues in your authorized network</p></div><span class="ps-ops-count">{{ $attentionTotal }} items</span></div>
                    <div class="ps-ops-alerts">
                        @forelse ($d['attention'] as $item)
                            <div class="ps-ops-alert"><span class="ps-ops-alert-tag">{{ $item['kind'] }}</span><div><strong>{{ $item['name'] }}</strong><small>{{ $item['kind'] }}</small></div><a href="{{ $stationResource::getUrl('setup', ['record' => $item['station_id']]) }}">Inspect station</a></div>
                        @empty <p class="ps-ops-empty">No current equipment alerts in your authorized scope.</p> @endforelse
                    </div>
                    <div class="ps-ops-alert-links">
                        @if ($sessionResource::canViewAny())<a href="{{ $sessionResource::getUrl() }}"><strong>{{ $d['waiting'] }}</strong> delayed starts</a><a href="{{ $sessionResource::getUrl() }}"><strong>{{ $d['review'] }}</strong> sessions for review</a>@endif
                        @if ($paymentResource::canViewAny())<a href="{{ $paymentResource::getUrl() }}"><strong>{{ $d['payment_exceptions'] }}</strong> payment exceptions</a>@endif
                        @if ($d['maintenance_visible'] && $workResource::canViewAny())<a href="{{ $workResource::getUrl() }}"><strong>{{ $d['sla_breaches'] }}</strong> SLA breaches</a>@endif
                    </div>
                </section>
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Live charging sessions</h3><p>Sessions currently in the charging state</p></div>@if ($sessionResource::canViewAny())<a href="{{ $sessionResource::getUrl() }}">View all →</a>@endif</div>
                    <div class="ps-ops-table-wrap"><table><thead><tr><th>Charge point</th><th>Energy</th><th>Started</th></tr></thead><tbody>
                        @forelse ($d['live_sessions'] as $session)<tr><td>{{ $session['station'] }}</td><td>{{ number_format($session['energy_wh'] / 1000, 2) }} kWh</td><td>{{ $session['started_at'] ? \Carbon\CarbonImmutable::parse($session['started_at'])->setTimezone('Asia/Manila')->format('H:i') : 'Awaiting start' }}</td></tr>
                        @empty<tr><td colspan="3">No active charging sessions right now.</td></tr>@endforelse
                    </tbody></table></div>
                    <p class="ps-ops-note">A session state is not a direct meter reading. Check device status before remote action.</p>
                </section>
            </div>

            <div class="ps-ops-lower-grid">
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Top sites</h3><p>Completed-session energy in period</p></div></div>
                    @forelse ($d['top_sites'] as $siteRow)
                        <div class="ps-ops-site-row"><div><span>{{ $siteRow['name'] }}</span><strong>{{ number_format($siteRow['energy_wh'] / 1000, 2) }} kWh</strong></div><div class="ps-ops-health-track"><div style="width: {{ $siteRow['energy_wh'] / $topSitePeak * 100 }}%"></div></div></div>
                    @empty <p class="ps-ops-empty">No completed sessions to rank yet.</p> @endforelse
                </section>
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Charging & wallet</h3><p>Financial amounts in selected period</p></div></div>
                    <div class="ps-ops-finance"><span>Finalized charging charges</span>
                        @if (! $d['finance_visible'])<strong>Restricted</strong>@else @forelse ($d['charges'] as $amount)<strong>{{ $amount['currency'] }} {{ number_format($amount['amount_minor'] / 100, 2) }}</strong>@empty<strong>—</strong>@endforelse @endif
                    </div>
                    <div class="ps-ops-finance"><span>Confirmed wallet top-ups</span>
                        @if (! $d['wallet_visible'])<strong>Tenant-wide access required</strong>@else @forelse ($d['topups'] as $amount)<strong>{{ $amount['currency'] }} {{ number_format($amount['amount_minor'] / 100, 2) }}</strong>@empty<strong>—</strong>@endforelse @endif
                    </div>
                    <p class="ps-ops-note">Charges are gross session cost, not settled revenue. Top-ups are posted credits, not charging income.</p>
                </section>
                <section class="ps-ops-panel">
                    <div class="ps-ops-heading"><div><h3>Network summary</h3><p>Equipment and workflow at a glance</p></div></div>
                    <dl class="ps-ops-summary"><div><dt>Registered stations</dt><dd>{{ $d['station_count'] }}</dd></div><div><dt>Waiting to start</dt><dd>{{ $waitingCount }}</dd></div><div><dt>Finalizing</dt><dd>{{ $states['finalizing'] ?? 0 }}</dd></div><div><dt>Open work orders</dt><dd>{{ $d['maintenance_visible'] ? $d['open_work_orders'] : 'Restricted' }}</dd></div></dl>
                </section>
            </div>

            <section class="ps-ops-panel">
                <div class="ps-ops-heading"><div><h3>Station management</h3><p>Find a charge point and open its setup checklist</p></div>@if ($stationResource::canViewAny())<x-filament::button tag="a" color="gray" :href="$stationResource::getUrl()">Manage stations</x-filament::button>@endif</div>
                <div class="ps-ops-toolbar ps-ops-toolbar--stations">
                    <label>Search stations<input type="search" wire:model.live.debounce.400ms="search" placeholder="Name, location or charge-point ID" maxlength="100"></label>
                    <label>Connection or lifecycle<select wire:model.live="status"><option value="">All statuses</option><option value="online">Online</option><option value="offline">Offline</option><option value="unknown">Not seen</option><option value="active">Active equipment</option><option value="maintenance">Maintenance</option><option value="draft">Draft</option><option value="retired">Retired</option></select></label>
                </div>
                <div class="ps-ops-stations">
                    @forelse ($d['stations'] as $station)
                        <article class="ps-ops-station" wire:key="station-{{ $station['id'] }}">
                            <div class="ps-ops-station-top"><span class="ps-ops-badge {{ $station['connection'] === 'online' ? 'ps-ops-badge--online' : '' }}">{{ $station['connection'] === 'unknown' ? 'Not seen' : ucfirst($station['connection']) }}</span><span>{{ ucfirst($station['lifecycle']) }}</span></div>
                            <h4>{{ $station['name'] }}</h4><p class="ps-ops-station-id">{{ $station['identity'] }}</p><p>{{ $station['site'] }}</p><p class="ps-ops-note">{{ $station['address'] ?: 'Address not configured' }}</p>
                            <div class="ps-ops-plugs">@forelse ($station['connectors'] as $plug)<div><span>Plug {{ $plug['number'] }} · {{ number_format($plug['power_w'] / 1000, 1) }} kW</span><strong>{{ $plug['status'] }}</strong></div>@empty<p>No connectors configured</p>@endforelse</div>
                            <p class="ps-ops-note">Last message: {{ $station['last_seen'] ? \Carbon\CarbonImmutable::parse($station['last_seen'])->setTimezone('Asia/Manila')->format('M j, H:i:s') : 'Never received' }}</p>
                            <a href="{{ $stationResource::getUrl('setup', ['record' => $station['id']]) }}">Open station and setup →</a>
                        </article>
                    @empty <p class="ps-ops-empty">No stations match your filters, or your account has no asset access for this location.</p> @endforelse
                </div>
                <div class="ps-ops-pagination"><span>{{ $d['matches'] }} stations · page {{ $d['page'] }} of {{ $d['pages'] }}</span><div><x-filament::button color="gray" size="sm" wire:click="previousStations" :disabled="$d['page'] <= 1">Previous</x-filament::button><x-filament::button color="gray" size="sm" wire:click="nextStations" :disabled="$d['page'] >= $d['pages']">Next</x-filament::button></div></div>
            </section>
        </div>
    @endif
</x-filament-panels::page>
