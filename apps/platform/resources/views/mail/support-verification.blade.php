@extends('mail.layout')
@section('title', 'Confirm your support request')
@section('preheader', 'Verify your email to submit your support request.')
@section('body')
<p style="color:#12645f;font-size:13px;font-weight:bold;letter-spacing:1px;">POWER SOLUTIONS · CUSTOMER SUPPORT</p>
<h1 class="email-heading" style="font-size:30px;line-height:1.2;color:#12366b;">Confirm your email</h1>
<p style="font-size:15px;line-height:1.7;color:#66758a;">Enter this code in the support form to submit your request. The code expires in 15 minutes and does not sign you into an account.</p>
<p style="padding:18px;background:#f0f7f7;color:#12366b;font-size:30px;letter-spacing:5px;font-weight:bold;">{{ $code }}</p>
<p style="font-size:13px;line-height:1.7;color:#66758a;">If you did not request help, you can ignore this message. No support ticket is created until the code is confirmed. Do not share this code.</p>
@endsection
@section('email-context', 'This message verifies the email address entered in the Power Solutions support form.')
