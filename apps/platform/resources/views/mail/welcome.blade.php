@extends('mail.layout')
@section('title', 'Welcome to Power Solutions')
@section('preheader', 'Welcome to Power Solutions. Verify your email and get ready for your first charge.')
@section('brand-header')
<div style="border-top:6px solid #45cfc7;background:#12366b;padding:24px 32px;">
<img src="{{ rtrim(config('app.url'), '/') }}/branding/power-solutions-email-logo.png" width="230" alt="Power Solutions" style="display:block;width:230px;max-width:100%;height:auto;border:0;background:#ffffff;border-radius:4px;padding:8px;">
</div>
@endsection
@section('body')
<div style="background:#12366b;border-radius:16px;padding:24px;color:#ffffff;">
<h1 class="email-heading" style="font-size:32px;line-height:1.1;margin:0 0 16px;color:#ffffff;">More of life.<br>Less of the detour.</h1>
<p style="margin:0;font-size:16px;line-height:1.6;color:#ffffff;">Charging that fits the places people already go.</p>
</div>
<h2 style="font-size:22px;line-height:1.3;color:#12366b;margin:24px 0 12px;">Welcome to Power Solutions.</h2>
<p style="font-size:16px;line-height:1.6;color:#66758a;margin:0;">Confirm your email address to secure your account. Then get ready for your first charging session.</p>
@include('mail.partials.button', ['href' => $verificationUrl, 'label' => 'Verify email address'])
<p style="font-size:12px;line-height:1.6;color:#66758a;margin:12px 0 24px;">This link expires in 60 minutes. If you didn’t create this account, you can ignore this email.</p>
@foreach ([
['Find a compatible charger', 'Explore the charging map and choose a location with a connector that works with your vehicle.'],
['Check before you connect', 'Add your vehicle, top up your wallet and review the connector, operating details and tariff before starting.'],
['Follow your session', 'Keep an eye on your charge in the app, then review the final session record when you’re done.'],
] as $step)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f8fb;border-radius:12px;margin-top:16px;">
<tr><td width="40" style="width:40px;padding:16px 0 16px 16px;vertical-align:top;"><div style="background:#eaf9f7;border-radius:40px;width:40px;line-height:40px;text-align:center;font-size:14px;font-weight:bold;color:#12645f;">0{{ $loop->iteration }}</div></td>
<td style="padding:16px;color:#66758a;font-size:14px;line-height:1.6;"><h3 style="margin:0 0 6px;font-size:18px;color:#12366b;">{{ $step[0] }}</h3>{{ $step[1] }}</td></tr></table>
@endforeach
@include('mail.partials.button', ['href' => rtrim(config('app.url'), '/').'/charging-map', 'label' => 'Find a charger'])
@include('mail.partials.app-invitation')
@endsection
@section('email-context', 'You’re receiving this email because a Power Solutions account was created with this address, or an email verification was requested.')
