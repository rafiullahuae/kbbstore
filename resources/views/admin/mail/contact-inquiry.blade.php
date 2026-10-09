{{-- The owner's alert for a contact-page inquiry (Lane CT). Every value is the visitor's text, so every one is printed escaped and none raw. --}}
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>New inquiry</title></head>
<body style="margin:0;padding:24px;background:#FFF8F5;font-family:Arial,Helvetica,sans-serif;color:#2A2228">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #F3D9E1;border-radius:14px">
<tr><td style="padding:22px 24px 6px">
<p style="margin:0 0 4px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#C6395F;font-weight:bold">New inquiry from the contact page</p>
<h1 style="margin:0 0 14px;font-size:20px;line-height:1.3">{{ $q['name'] }}</h1>
</td></tr>
<tr><td style="padding:0 24px">
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.5">
<tr><td style="padding:3px 14px 3px 0;color:#756C74">Email</td><td style="padding:3px 0"><a href="mailto:{{ $q['email'] }}" style="color:#C6395F">{{ $q['email'] }}</a></td></tr>
@if ($q['phone'] !== '')<tr><td style="padding:3px 14px 3px 0;color:#756C74">Phone</td><td style="padding:3px 0">{{ $q['phone'] }}</td></tr>@endif
@if ($q['topic'] !== '')<tr><td style="padding:3px 14px 3px 0;color:#756C74">Topic</td><td style="padding:3px 0">{{ $q['topic'] }}</td></tr>@endif
<tr><td style="padding:3px 14px 3px 0;color:#756C74">Page</td><td style="padding:3px 0">{{ $q['locale'] === 'ar' ? 'Arabic' : 'English' }}</td></tr>
</table>
</td></tr>
<tr><td style="padding:16px 24px 22px">
<div style="background:#FFF0F4;border-radius:10px;padding:14px 16px;font-size:15px;line-height:1.6;white-space:pre-wrap;word-break:break-word">{{ $q['message'] }}</div>
<p style="margin:14px 0 0;font-size:12.5px;color:#756C74">Reply to this email to answer. Every inquiry is also kept in the admin at Store → Inquiries.</p>
</td></tr>
</table>
</body>
</html>
