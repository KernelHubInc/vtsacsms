@extends('mail.layout')
@section('title', 'Your everyday charging journey')
@section('preheader', 'A coffee. A few errands. A moment to recharge. Find charging that fits your day.')
@section('body')
@include('mail.partials.brand')
<h1 class="email-heading" style="font-size:42px;line-height:1.1;color:#12366b;margin:0 0 18px;">Your day.<br>With charging<br>built in.</h1>
<p style="font-size:16px;line-height:1.6;color:#66758a;margin:0 0 24px;">A coffee. A few errands. A moment to recharge. Look for charging that fits the places you’re already heading.</p>
<img src="{{ rtrim(config('app.url'), '/') }}/branding/email/journey.png" width="544" alt="A little pause. A few essentials. A little more power. Your routine, recharged." style="display:block;border:0;width:100%;max-width:544px;height:auto;border-radius:12px;">
@include('mail.partials.button', ['href' => rtrim(config('app.url'), '/').'/charging-map', 'label' => 'Find a charger for your day'])
@include('mail.partials.app-invitation')
@endsection
@section('email-context', 'Updates and ideas for your everyday charging journeys from Power Solutions.')
@section('preferences')
<p style="font-size:12px;line-height:1.8;margin:0;">
@if ($preferencesUrl !== null)<a href="{{ $preferencesUrl }}" style="color:#66758a;">Manage email preferences</a> &nbsp;&middot;&nbsp; @endif
<a href="{{ $unsubscribeUrl }}" style="color:#66758a;">Unsubscribe</a>
</p>
@endsection
