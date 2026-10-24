@php
    $amount = (int) $summary['amount_minor'];
    $money = sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    $energy = sprintf('%d.%03d', intdiv((int) $summary['energy_wh'], 1000), (int) $summary['energy_wh'] % 1000);
@endphp
<p>Your EV charging session is complete and its prepaid wallet charge has been recorded.</p>
<p><strong>Charging reference:</strong> {{ $summary['session_id'] }}</p>
<p><strong>Station:</strong> {{ $summary['station'] }}<br>
<strong>Connector:</strong> {{ $summary['connector'] }}<br>
<strong>Started (UTC):</strong> {{ $summary['started_at'] }}<br>
<strong>Stopped (UTC):</strong> {{ $summary['stopped_at'] }}<br>
<strong>Energy delivered:</strong> {{ $energy }} kWh<br>
<strong>Session duration:</strong> {{ $summary['duration_seconds'] }} seconds<br>
<strong>Amount charged:</strong> {{ $summary['currency'] }} {{ $money }}<br>
<strong>Payment method:</strong> {{ $summary['payment_method'] }}</p>
<p>This is a charging and payment summary. It is not a tax invoice or official receipt.</p>
