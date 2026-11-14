{{--
    The free gifts strip. Lane GS, GS2 — App\Services\ProductGiftStrip.

      "Free gifts with every order. Limited time only! use this text in one
       line, the text should adjust as per the screen size, must be in one
       line. flowing underline is fine as default"

    ONE element, drawn by store/product.blade.php at the laptop anchor
    ProductGiftStrip::anchor() names (directly above Tabby & Tamara unless he
    moves it). On a phone the DOM place does not matter: it is a Mobile
    sections row and takes its `order` from `--pm-o-giftstrip`.

    ▲ Off → no bytes. Both parts empty → no bytes.
    ▲ The <style> is css(): a constant of ProductGiftStrip (the base, the
      group and the ONE chosen style), never a setting — so the shop's
      render-blocking kbb-product.css carries none of the 27 designs.
    ▲ html() escapes the words with e(); everything else in it is a constant.
      classes() literal names; style() integers, half pixels and #RRGGBB.
    ▲ No link, no script, no image request: the icon is inline SVG with its
      own width/height.
--}}@php
    $kbbGs = app(\App\Services\ProductGiftStrip::class);
    $kbbGs1 = $kbbGs->line(1);
    $kbbGs2 = $kbbGs->line(2);
@endphp
@if ($kbbGs->on() && ($kbbGs1 !== '' || $kbbGs2 !== ''))
<style>{!! $kbbGs->css() !!}</style><div class="pdp-gs pm-sec pm-giftstrip{{ $kbbGs->classes() }}" style="{{ $kbbGs->style($kbbGs1, $kbbGs2) }}">{!! $kbbGs->html($kbbGs1, $kbbGs2) !!}</div><!--/pm-->
@endif
