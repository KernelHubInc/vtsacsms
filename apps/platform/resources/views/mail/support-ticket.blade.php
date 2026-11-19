@extends('mail.layout')
@section('title', 'Support update')
@section('preheader', 'An update on your Power Solutions support request.')
@section('body')
<p style="color:#12645f;font-size:13px;font-weight:bold;letter-spacing:1px;">POWER SOLUTIONS · CUSTOMER SUPPORT</p>
<h1 class="email-heading" style="color:#12366b;font-size:30px;line-height:1.2;">An update on your request</h1>
<p style="color:#66758a;font-size:14px;line-height:1.6;">Ticket <strong>{{ $details['ticket_number'] }}</strong> · {{ str($details['status'])->headline() }}</p>
<h2 style="font-size:18px;color:#17243a;">{{ $details['subject'] }}</h2>
<div style="font-size:15px;line-height:1.7;color:#17243a;">{!! nl2br(e($details['body'])) !!}</div>
<p style="margin-top:28px;font-size:13px;line-height:1.6;color:#66758a;">Keep this reference for follow-up. For another update, use the support form and include the ticket reference. Replies to this email are not automatically added to your ticket.</p>
@endsection
@section('email-context', 'You received this service email because you submitted a support request to Power Solutions.')
