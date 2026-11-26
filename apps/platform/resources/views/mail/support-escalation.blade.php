@extends('mail.layout')
@section('title', 'Level 3 support escalation')
@section('preheader', 'A support ticket was escalated to Kernel Hub.')
@section('body')
<p style="color:#12645f;font-size:13px;font-weight:bold;letter-spacing:1px;">POWER SOLUTIONS · SUPPORT OPERATIONS</p>
<h1 class="email-heading" style="color:#12366b;font-size:30px;line-height:1.2;">A ticket needs Level 3 support</h1>
<p style="font-size:15px;line-height:1.7;color:#17243a;">A support ticket was escalated to <strong>Level 3 — Kernel Hub (App Developer)</strong>.</p>
<p style="font-size:15px;line-height:1.7;color:#17243a;">Ticket: <strong>{{ $details['ticket_number'] }}</strong><br>Priority: {{ str($details['priority'])->headline() }}</p>
<p style="margin:28px 0;"><a href="{{ $ticketUrl }}" style="display:inline-block;padding:14px 22px;background:#12366b;color:#ffffff;border-radius:8px;text-decoration:none;font-weight:bold;">Review support ticket</a></p>
<p style="font-size:13px;line-height:1.6;color:#66758a;">Sign in with your Superadmin account to review the latest status, read the conversation and respond. Customer descriptions, images and internal notes remain in the authenticated support portal. Replies to this email are not added to the ticket.</p>
@endsection
@section('email-context', 'You received this operational email because you are an authorized Superadmin for this tenant.')
