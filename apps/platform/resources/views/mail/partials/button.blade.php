<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;">
<tr><td bgcolor="#45cfc7" style="background:#45cfc7;border-radius:8px;">
<a href="{{ $href }}" style="display:block;padding:18px 24px;color:#12366b;font-size:16px;font-weight:bold;line-height:20px;text-decoration:none;">
<img src="{{ ($embedReceiptImages ?? false) ? $message->embed(public_path('branding/email/arrow.png')) : rtrim(config('app.url'), '/').'/branding/email/arrow.png' }}" width="20" height="20" alt="" align="right" style="border:0;float:right;">{{ $label }}
</a></td></tr></table>
