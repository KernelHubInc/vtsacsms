<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Charging receipt | Power Solutions</title>
    <style>
        @page { margin: 34px 42px 38px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #10243f; font-size: 10px; }
        .accent { height: 6px; background: #48d4cb; margin: -34px -42px 27px; }
        .brand { width: 218px; height: auto; }
        .head { margin-top: 29px; background: #0b2341; color: #fff; padding: 27px 29px 29px; }
        .head .status { color: #80e5df; font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        h1 { margin: 9px 0 5px; font-size: 25px; line-height: 1.2; }
        .head p { color: #d8e9f0; font-size: 10px; margin: 0; }
        .amount { background: #edf7f7; padding: 24px 29px 22px; }
        .amount .label { color: #547084; font-size: 9px; text-transform: uppercase; letter-spacing: .7px; }
        .amount .value { color: #0b2341; font-size: 26px; font-weight: bold; margin-top: 6px; }
        .section { padding: 25px 29px 0; }
        h2 { font-size: 11px; color: #0b2341; margin: 0 0 11px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 10px 0; border-bottom: 1px solid #dce7ec; vertical-align: top; line-height: 1.4; }
        td:first-child { width: 35%; color: #5d7184; }
        td:last-child { font-weight: bold; overflow-wrap: break-word; }
        .note { margin: 26px 29px 0; padding: 13px 15px; background: #f1f6f8; color: #4c6274; font-size: 9px; line-height: 1.6; }
        .footer { position: fixed; bottom: -17px; left: 0; right: 0; border-top: 1px solid #dce7ec; padding-top: 9px; color: #718497; font-size: 8px; }
    </style>
</head>
<body>
    <div class="accent"></div>
    <img class="brand" src="{{ $logo }}" alt="Power Solutions">
    <div class="head">
        <div class="status">Payment confirmed</div>
        <h1>Charging receipt</h1>
        <p>Session and prepaid wallet payment summary</p>
    </div>
    <div class="amount">
        <div class="label">Total paid from prepaid wallet</div>
        <div class="value">{{ $receipt['amount'] }}</div>
    </div>
    <div class="section">
        <h2>Transaction details</h2>
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
