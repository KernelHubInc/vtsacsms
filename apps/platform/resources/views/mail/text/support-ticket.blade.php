POWER SOLUTIONS — CUSTOMER SUPPORT

Ticket: {{ $details['ticket_number'] }}
Status: {{ str($details['status'])->headline() }}
Subject: {{ $details['subject'] }}

{{ $details['body'] }}

Keep this reference for follow-up. Use {{ rtrim(config('app.url'), '/') }}/support and include the reference when contacting us again.
Replies to this email are not automatically added to your ticket.
