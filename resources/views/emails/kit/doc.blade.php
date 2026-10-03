{{--
    Look A's document shell — tools/rj-email-kit.cjs doc(), ported 1:1 (Lane RM).

    The owner approved docs/rj-email-previews/ on 3 October 2026 and asked for
    "100% same stuff as in previews", so this file and its siblings in
    emails/kit/ are transliterations of that kit: the same tables, the same
    inline styles, the same numbers. tests/Feature/MailKitParityTest.php renders
    every ported email and compares it with the approved file.

    WHAT AN EMAIL VIEW SETS before @extends('emails.kit.doc'):
      $k            MailKit::for($brand) — fonts, colours, wordmark, footer data
      $kitTitle     the <title> (plain text, escaped here)
      $kitPreheader the hidden inbox preview line (plain text, escaped here)
    and fills @section('kit') with rows: topbar, card-open … card-close, footer.

    Printed raw in this file and its siblings, and why each is safe:
    $k['fontFace'] and the font stacks are built and pattern-checked by MailKit
    (no quote-closing, bracket or angle bracket can survive), and every colour
    is a MailKit constant or a #rrggbb that MailKit::hex() proved. Nothing typed
    by a person reaches the page except through {{ }}.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locale::direction() === 'rtl' ? 'rtl' : 'ltr' }}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $kitTitle ?? '' }}</title>
@if ($k['fontFace'] !== '')
<style>{!! $k['fontFace'] !!}</style>
@endif
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,th,p,a,span,div{font-family:Arial,sans-serif!important}</style><![endif]-->
<style>
  body{margin:0!important;padding:0!important;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
  img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
  a{text-decoration:none}
  @media only screen and (max-width:480px){
    .px{padding-left:18px!important;padding-right:18px!important}
    .stack{display:block!important;width:100%!important;max-width:100%!important;padding-left:0!important;padding-right:0!important}
    .stack-gap{padding-top:14px!important}
    .h1{font-size:25px!important;line-height:1.22!important}
    .hide-sm{display:none!important;max-height:0!important;overflow:hidden!important}
    .pimg{width:64px!important;height:64px!important}
    .gpad{padding:5px!important}
    .center-sm{text-align:center!important}
    .btn-full{display:block!important;width:100%!important}
  }
  @media (prefers-color-scheme:dark){
    .bg-outer{background:#17111A!important}
    .card{background:#211921!important}
    .soft{background:#2E2229!important}
    .ink{color:#F7EEF2!important}
    .ink2{color:#D7C8CF!important}
    .muted{color:#A898A0!important}
    .line{border-color:#3A2B33!important}
    .wm-ink{color:#F7EEF2!important}
  }
  [data-ogsc] .ink{color:#F7EEF2!important}
  [data-ogsb] .card{background:#211921!important}
</style>
</head>
<body class="bg-outer" style="margin:0;padding:0;background:{{ $k['background'] }};" bgcolor="{{ $k['background'] }}">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{ $k['background'] }};opacity:0;">{{ $kitPreheader ?? '' }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" class="bg-outer" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $k['background'] }}" style="width:100%;background:{{ $k['background'] }};border-collapse:collapse;">
<tr><td align="center" style="padding:22px 10px 30px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:collapse;">
@yield('kit')
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
