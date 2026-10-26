<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Charging receipt | Power Solutions</title>
</head>
<body style="margin:0;padding:0;background:#f1f6f8;color:#10243f;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your charging session is complete. Your payment details and PDF receipt are inside.</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f6f8;padding:28px 12px;">
        <tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #d9e6eb;">
                <tr><td style="height:6px;background:#48d4cb;font-size:1px;line-height:1px;">&nbsp;</td></tr>
                <tr><td style="padding:28px 34px 23px;">
                    <img src="{{ asset('branding/power-solutions-email-logo.png') }}" width="230" alt="Power Solutions" style="display:block;max-width:100%;height:auto;border:0;">
                </td></tr>
                <tr><td style="background:#0b2341;padding:30px 34px 32px;color:#ffffff;">
                    <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#80e5df;font-weight:bold;">Payment confirmed</div>
                    <h1 style="font-size:28px;line-height:1.15;margin:10px 0 8px;font-weight:700;">Charging receipt</h1>
                    <p style="font-size:14px;line-height:1.5;margin:0;color:#d8e9f0;">Your charging session is complete. A copy of this receipt is attached as a PDF.</p>
                </td></tr>
                <tr><td style="padding:28px 34px 13px;">
                    <div style="font-size:12px;color:#5d7184;text-transform:uppercase;letter-spacing:1px;font-weight:bold;">Total paid from prepaid wallet</div>
                    <div style="font-size:34px;line-height:1.2;font-weight:bold;color:#0b2341;margin-top:7px;">{{ $receipt['amount'] }}</div>
                    <div style="border-bottom:1px solid #dce7ec;margin-top:24px;"></div>
                </td></tr>
                <tr><td style="padding:2px 34px 29px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.45;">
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
                            <tr>
                                <td style="padding:9px 0;color:#5d7184;vertical-align:top;width:39%;">{{ $label }}</td>
                                <td style="padding:9px 0;color:#10243f;font-weight:600;vertical-align:top;word-break:break-word;">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td></tr>
                <tr><td style="background:#edf7f7;padding:21px 34px;color:#3d5667;font-size:12px;line-height:1.55;">
                    Keep the reference above if you need help with this charge. This document confirms the charging session and prepaid wallet payment; it is not an official tax receipt or tax invoice.
                </td></tr>
            </table>
            <p style="max-width:600px;margin:18px 0 0;font-size:12px;color:#718497;line-height:1.5;">Power Solutions &middot; EV charging in the Philippines</p>
        </td></tr>
    </table>
</body>
</html>
