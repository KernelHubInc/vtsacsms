<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light">
<title>@yield('title') | Power Solutions</title>
<style>
@media only screen and (max-width:600px) {
.email-padding { padding:24px 20px !important; }
.email-heading { font-size:30px !important; }
.email-amount { font-size:36px !important; }
.detail-label,.detail-value { display:block !important;width:auto !important;text-align:left !important; }
.detail-label { padding-bottom:2px !important; }
.detail-value { padding-top:0 !important;padding-bottom:12px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f5f8fb;color:#17243a;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
@php($emailBase = rtrim(config('app.url'), '/'))
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">@yield('preheader')</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f8fb;">
<tr><td align="center" style="padding:24px 8px;">
<!--[if mso]><table role="presentation" width="640" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;table-layout:fixed;">
@hasSection('brand-header')<tr><td>@yield('brand-header')</td></tr>@endif
<tr><td class="email-padding" style="padding:32px;background:#ffffff;border:1px solid #dce5ed;border-radius:12px;overflow-wrap:break-word;word-wrap:break-word;">@yield('body')</td></tr>
<tr><td class="email-padding" style="padding:24px 32px 32px;background:#f5f8fb;">
<p style="margin:0 0 6px;font-size:16px;font-weight:bold;color:#12366b;">A little help along the way?</p>
<a href="{{ $emailBase }}/support" style="color:#12366b;font-size:14px;line-height:1.6;">Visit Power Solutions support &rarr;</a>
<div style="border-top:1px solid #dce5ed;margin:20px 0;"></div>
<p style="font-size:15px;font-weight:bold;color:#12366b;margin:0 0 12px;">Power Solutions</p>
<p style="font-size:12px;line-height:1.7;color:#66758a;margin:0 0 12px;">@yield('email-context')</p>
<p style="font-size:12px;line-height:2;margin:0 0 12px;">
<a href="{{ $emailBase }}" style="color:#12366b;">Website</a> &nbsp;&nbsp;
<a href="{{ $emailBase }}/privacy-policy" style="color:#12366b;">Privacy policy</a> &nbsp;&nbsp;
<a href="{{ $emailBase }}/terms-of-service" style="color:#12366b;">Terms of service</a>
</p>
@yield('preferences')
<p style="font-size:12px;color:#66758a;margin:12px 0 0;">&copy; {{ now('UTC')->year }} Power Solutions</p>
</td></tr></table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body></html>
