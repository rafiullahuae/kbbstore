{{--
    Appearance → Footer — the live preview's document.                (Lane FT)

    Rendered by Admin\SlimFooterApiController::preview() into an iframe on each
    of the four Footer pages, from the values the owner has on the screen and
    has not necessarily saved.

    IT INCLUDES THE REAL PARTIALS. `partials.footer` is the file the shop's
    layout includes (it picks the new or the classic design itself) and
    `partials.slim-footer` is the file the cart page and the checkout include.
    This document supplies the surroundings — the shop's stylesheet, its font
    and a few grey lines standing in for the page above — and draws no footer
    markup of its own, so the preview cannot disagree with the shop.

    The frame is sized by the console (1280 wide for a desktop page, 390 for a
    phone), so the shop's own media queries decide which device's rules apply,
    exactly as they do for a shopper. No script is loaded here and the iframe
    is sandboxed without scripts: the pushed `scripts` stack is never printed.
--}}
@section('ftp-body')
@if ($ftpFooter === 'site')
<div class="ftp-ghost" aria-hidden="true"><i></i><i></i><i class="s"></i></div>
@include('partials.footer')
@else
<div class="ftp-ghost ftp-cart" aria-hidden="true"><i class="h"></i><i></i><i></i><i class="s"></i><i class="b"></i></div>
@include('partials.slim-footer')
@endif
@endsection
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<base target="_blank">
<title>Footer preview</title>
<style id="kbb-outfit">{!! \App\Support\WebFonts::faceCss(\App\Support\WebFonts::OUTFIT) !!}</style>
@vite(['resources/css/kbb/kbb.css'])
@include('partials.shop-appearance-css')
@stack('styles')
<style>
html,body{margin:0}
body{overflow-x:hidden}
.ftp-ghost{max-width:1040px;margin:0 auto;padding:22px 20px 26px;display:grid;gap:10px}
.ftp-ghost i{display:block;height:12px;border-radius:6px;background:#EEE7EA}
.ftp-ghost i.s{width:55%}
.ftp-ghost i.h{height:22px;width:40%}
.ftp-ghost i.b{height:44px;width:min(320px,100%);justify-self:end;background:#E5DDE1}
.ftp-cart i:not(.h):not(.s):not(.b){height:58px}
</style>
</head>
<body class="ftp-{{ $ftpDevice === 'm' ? 'phone' : 'desktop' }}">
@yield('ftp-body')
</body>
</html>
