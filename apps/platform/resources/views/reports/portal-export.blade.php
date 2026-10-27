<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} | Power Solutions</title>
    <style>
        @page { margin: 30px 34px 34px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #12233c; font-size: 9px; }
        .brand { color: #087d99; font-size: 11px; font-weight: bold; letter-spacing: .7px; }
        h1 { font-size: 22px; margin: 12px 0 4px; color: #0c2645; }
        .meta { color: #60738a; margin-bottom: 19px; }
        .rule { border-bottom: 2px solid #46d3cc; margin-bottom: 15px; }
        .metrics { width: 100%; border-spacing: 8px 0; margin: 0 -8px 16px; }
        .metrics td { background: #edf7f8; padding: 11px 13px; border-radius: 6px; vertical-align: top; }
        .metric-label { color: #526983; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; }
        .metric-value { font-size: 16px; font-weight: bold; margin-top: 5px; }
        h2 { font-size: 12px; margin: 15px 0 7px; }
        .note { color: #5f7185; line-height: 1.5; margin: 6px 0 12px; }
        table.data { border-collapse: collapse; width: 100%; table-layout: fixed; }
        .data th { background: #0c2645; color: white; text-align: left; font-size: 7.5px; padding: 8px 6px; }
        .data td { border-bottom: 1px solid #dbe7ed; padding: 7px 6px; vertical-align: top; overflow-wrap: break-word; white-space: pre-line; }
        .data tr:nth-child(even) td { background: #f5f9fb; }
        .empty { border: 1px solid #dbe7ed; padding: 20px; color: #60738a; }
        .footer { position: fixed; bottom: -18px; left: 0; right: 0; border-top: 1px solid #dbe7ed; padding-top: 6px; color: #60738a; font-size: 7px; }
    </style>
</head>
<body>
    <div class="brand">POWER SOLUTIONS</div>
    <h1>{{ $title }}</h1>
    <div class="meta">Generated {{ $generatedAt }} · Authorized records only
        @if ($title !== 'Sites and charging stations' && (! empty($filters['from']) || ! empty($filters['to'])))
            · Dates: {{ $filters['from'] ?? 'earliest' }} to {{ $filters['to'] ?? 'latest' }} (end exclusive, UTC)
        @endif
    </div>
    <div class="rule"></div>
    <table class="metrics"><tr>
        <td><div class="metric-label">Records in scope</div><div class="metric-value">{{ number_format($count) }}</div></td>
        @if ($hasEnergy)
            <td><div class="metric-label">Energy delivered</div><div class="metric-value">{{ $energyKwh }} kWh</div></td>
        @endif
        @foreach ($money as $currency => $minor)
            <td><div class="metric-label">{{ $title === 'Payment reconciliation' ? 'Captured' : ($vehicleReport ? 'Known final charges' : 'Final charges') }} · {{ $currency }}</div><div class="metric-value">{{ $currency }} {{ (new \App\Modules\Reporting\Application\PortalPdfReport)->minor($minor) }}</div></td>
        @endforeach
    </tr></table>
    @if ($vehicleReport)
        <p class="note">Brand, model and owner reflect the current vehicle register. Missing or deleted vehicles remain unassigned/unknown; no vehicle is inferred. Usage summaries include completed sessions only. Known final charges exclude unpriced sessions and are not proof of payment. Vehicle counts exclude sessions without a recorded vehicle ID.</p>
        @foreach ($filters as $key => $value)
            @if (! str_starts_with($key, '_') && ! in_array($key, ['from', 'to'], true))
                <span class="note">{{ str($key)->replace('_', ' ')->ucfirst() }}: {{ $value }} · </span>
            @endif
        @endforeach
    @endif
    <h2>Record details</h2>
    @if ($count > $limit)
        <p class="note">Showing the first {{ number_format($limit) }} of {{ number_format($count) }} records. Download CSV for complete row-level details.</p>
    @elseif ($title === 'Sites and charging stations')
        <p class="note">This is a snapshot of the current authorized site inventory and charging-station counts.</p>
    @else
        <p class="note">Amounts are in the listed currency. Times are UTC. Figures reflect recorded values when this report was generated.</p>
    @endif
    @if ($details === [])
        <div class="empty">No records are available for the selected dates and your current access.</div>
    @else
        <table class="data">
            <thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($details as $row)
                    <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <div class="footer">Power Solutions · Generated for operational review · Data may change after export</div>
</body>
</html>
