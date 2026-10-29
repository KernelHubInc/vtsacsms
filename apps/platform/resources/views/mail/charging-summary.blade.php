@extends('mail.layout')
@section('title', 'Charging receipt')
@section('preheader', 'Your charging session is complete. Your payment details and PDF receipt are inside.')
@section('body')
@include('mail.partials.brand')
<p style="margin:0 0 12px;color:#12645f;font-size:13px;line-height:20px;"><img src="{{ rtrim(config('app.url'), '/') }}/branding/email/check.png" width="20" height="20" alt="" style="vertical-align:middle;border:0;"> &nbsp; Payment complete</p>
<h1 class="email-heading" style="font-size:36px;line-height:1.1;color:#12366b;margin:0 0 12px;">All charged up.<br>You’re good to go.</h1>
<p style="font-size:15px;line-height:1.6;color:#66758a;margin:0 0 24px;">Thanks for charging with Power Solutions. Here’s a summary of your completed session.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eaf9f7;border-radius:12px;margin-bottom:24px;">
<tr><td style="padding:20px;">
<p style="margin:0;font-size:14px;font-weight:bold;color:#12366b;">Total paid</p>
<p class="email-amount" style="margin:8px 0;font-size:46px;line-height:1.2;font-weight:bold;color:#12366b;overflow-wrap:anywhere;">{{ $receipt['amount'] }}</p>
<p style="margin:0;font-size:12px;line-height:1.5;color:#12645f;">Paid from your prepaid wallet. Your PDF receipt is attached.</p>
</td></tr></table>
<h2 style="font-size:18px;color:#12366b;margin:0 0 12px;">Your charging session</h2>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;font-size:14px;line-height:1.5;">
@foreach (['Station' => $receipt['station'], 'Connector' => $receipt['connector'], 'Started' => $receipt['started'], 'Stopped' => $receipt['stopped'], 'Duration' => $receipt['duration'], 'Energy delivered' => $receipt['energy']] as $label => $value)
<tr><td class="detail-label" style="width:36%;padding:7px 12px 7px 0;color:#66758a;vertical-align:top;">{{ $label }}</td>
<td class="detail-value" style="padding:7px 0;color:#17243a;text-align:right;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;">{{ $value }}</td></tr>
@endforeach
</table>
<div style="border-top:1px solid #dce5ed;margin:24px 0;"></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="table-layout:fixed;background:#f5f8fb;border-radius:8px;font-size:14px;line-height:1.5;">
<tr><td style="padding:20px;">
<p style="margin:0 0 6px;color:#66758a;">Paid with</p><p style="margin:0 0 16px;color:#17243a;">{{ $receipt['payment_method'] }}</p>
<p style="margin:0 0 6px;color:#66758a;">Session reference</p><p style="margin:0;color:#17243a;overflow-wrap:anywhere;word-break:break-all;">{{ $receipt['reference'] }}</p>
</td></tr></table>
@include('mail.partials.button', ['href' => rtrim(config('app.url'), '/').'/mobile/', 'label' => 'Open Power Solutions'])
<p style="font-size:12px;line-height:1.6;color:#66758a;margin:12px 0 0;">Sign in and open Activity to view this session. Match the session reference above.</p>
@endsection
@section('email-context')
Keep the session reference if you need help with this charge. This document confirms your charging session and prepaid wallet payment; it is not an official tax receipt or tax invoice.
@endsection
