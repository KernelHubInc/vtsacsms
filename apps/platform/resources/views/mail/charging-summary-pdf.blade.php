<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Charging receipt | Power Solutions</title>
    <style>
        @page { margin: 34px 42px 38px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #17243a; font-size: 10px; }
        .accent { height: 6px; background: #45cfc7; margin: -34px -42px 27px; }
        .brand { width: 218px; height: auto; }
        .head { margin-top: 24px; color: #12366b; padding: 0 0 24px; }
        .head .status { color: #12645f; font-size: 10px; }
        h1 { margin: 9px 0 5px; font-size: 25px; line-height: 1.2; }
        .head p { color: #66758a; font-size: 10px; margin: 0; }
        .amount { background: #eaf9f7; border-radius: 12px; padding: 20px; }
        .amount .label { color: #12366b; font-size: 10px; }
        .amount .value { color: #12366b; font-size: 30px; font-weight: bold; margin-top: 6px; }
        .section { padding: 24px 0 0; }
        h2 { font-size: 13px; color: #12366b; margin: 0 0 11px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 10px 0; border-bottom: 1px solid #dce7ec; vertical-align: top; line-height: 1.4; }
        td:first-child { width: 35%; color: #5d7184; }
        td:last-child { font-weight: bold; overflow-wrap: break-word; }
        .note { margin: 24px 0 0; padding: 16px; background: #f5f8fb; color: #66758a; font-size: 9px; line-height: 1.6; }
        .footer { position: fixed; bottom: -17px; left: 0; right: 0; border-top: 1px solid #dce7ec; padding-top: 9px; color: #718497; font-size: 8px; }
    </style>
</head>
<body>
    <div class="accent"></div>
    <img class="brand" src="{{ $logo }}" alt="Power Solutions">
    <div class="head">
        <div class="status">Payment complete</div>
        <h1>Charging receipt</h1>
        <p>Thanks for charging with Power Solutions. Your completed session is summarized below.</p>
    </div>
    <div class="amount">
        <div class="label">Total paid from prepaid wallet</div>
        <div class="value">{{ $receipt['amount'] }}</div>
    </div>
    <div class="section">
        <h2>Your charging session</h2>
        <table>
            @foreach ([
                'Reference' => $receipt['reference'],
                'Station' => $receipt['station'],
                'Connector' => $receipt['connector'],
                'Started' => $receipt['started'],
                'Stopped' => $receipt['stopped'],
                'Duration' => $receipt['duration'],
                'Energy delivered' => $receipt['energy'],
                'Payment method' => $receipt['payment_method'],
            ] as $label => $value)
                <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    </div>
    <div class="note">Keep this reference for support. This document confirms a completed charging session and recorded prepaid wallet payment. It is not an official tax receipt or tax invoice.</div>
    <div class="footer">Power Solutions | Charging payment confirmation | Times shown in Philippine time (UTC+8)</div>
</body>
</html>
