{{--
    The free gifts strip. Lane GS — App\Services\ProductGiftStrip.

      "KL Confetti + Holographic sheen is fine, please give me patch. to
       control on off, and change position on the product page, keep above
       tabby tamara section by default."

    ONE element, drawn by store/product.blade.php at the laptop anchor
    ProductGiftStrip::anchor() names (directly above Tabby & Tamara unless he
    moves it). On a phone the DOM place does not matter: it is a Mobile
    sections row and takes its `order` from `--pm-o-giftstrip`.

    ▲ Off → no bytes. Both lines empty → no bytes.
    ▲ The words through {{ }}; classes() literal names; style() integers,
      half pixels and #RRGGBB only, and empty while every value is the design.
    ▲ No link, no script, no image request: the icon is inline SVG with its
      own width/height.
--}}@php
    $kbbGs = app(\App\Services\ProductGiftStrip::class);
    $kbbGs1 = $kbbGs->line(1);
    $kbbGs2 = $kbbGs->line(2);
    $kbbGsStyle = $kbbGs->style();
@endphp
@if ($kbbGs->on() && ($kbbGs1 !== '' || $kbbGs2 !== ''))
<div class="pdp-gs pm-sec pm-giftstrip{{ $kbbGs->classes() }}"@if ($kbbGsStyle !== '') style="{{ $kbbGsStyle }}"@endif>@if ($kbbGs->flag('confetti'))<i class="cf c1"></i><i class="cf c2"></i><i class="cf c3"></i><i class="cf c4"></i><i class="cf c5"></i><i class="cf c6"></i><i class="cf c7"></i><i class="cf c8"></i><i class="cf c9"></i><i class="cf c10"></i>@endif
@if ($kbbGs->flag('icon'))<span class="pgs-i"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg></span>@endif<span class="pgs-t">@if ($kbbGs1 !== '')<b>{{ $kbbGs1 }}</b>@endif
@if ($kbbGs2 !== '')<span class="pgs-l">{{ $kbbGs2 }}</span>@endif</span></div><!--/pm-->
@endif
