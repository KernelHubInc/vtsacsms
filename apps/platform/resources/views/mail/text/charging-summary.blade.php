Power Solutions — Charging receipt

Your charging session is complete. Your PDF receipt is attached.

Total paid: {{ $receipt['amount'] }}
Station: {{ $receipt['station'] }}
Connector: {{ $receipt['connector'] }}
Started: {{ $receipt['started'] }}
Stopped: {{ $receipt['stopped'] }}
Duration: {{ $receipt['duration'] }}
Energy delivered: {{ $receipt['energy'] }}
Paid with: {{ $receipt['payment_method'] }}
Session reference: {{ $receipt['reference'] }}

Open Power Solutions: {{ rtrim(config('app.url'), '/') }}/mobile/
Sign in and open Activity to view this session. Match the session reference above.
Support: {{ rtrim(config('app.url'), '/') }}/support

This document confirms your charging session and prepaid wallet payment; it is not an official tax receipt or tax invoice.
