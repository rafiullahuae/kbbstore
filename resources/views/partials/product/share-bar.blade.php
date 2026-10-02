{{--
    The share bar — Lane PW.

      "under this section, i want a nice bar of share it: but more nicely and
       colorful."  …  "each share icon must carry proper url, short
       description, image etc. and other things if you recommend any."

    The page had no share row of any kind before this (checked: nothing under
    store/product.blade.php or partials/ drew one), so this is the only one.

    ▲ EVERY HREF IS BUILT ON THE SERVER, by App\Support\ProductShare, from the
      canonical and the og:image the <head> publishes — so a share and a
      crawler see the same address and the same picture. Each value is
      urlencoded there and the whole href is escaped here by `{{ }}`.
      ProductShare's header lists what each network is given.

    ▲ THE COLOURS ARE CONSTANTS (ProductTrustShare::NETWORKS), the glyphs are
      constants (TrustShareIcons::NETWORK), and the only setting that reaches
      a class name is `share_style` / `share_shape`, read through choice(),
      which answers one of the select's own keys or its default.

    ▲ OFF-SITE LINKS OPEN IN A NEW TAB with rel="noopener noreferrer". Email is
      a mailto: and Copy link is a button; neither opens anything.

    ▲ "More" — navigator.share() — is drawn `hidden` and pdp-trust.js reveals
      it only on a phone-width screen whose browser has a share sheet, so a
      laptop never shows a button that does nothing.
--}}@php
    $kbbPts = app(\App\Services\ProductTrustShare::class);
@endphp
@if ($kbbPts->on('share_on'))
@php
    $kbbPtsFacts = \App\Support\ProductShare::facts($product, $seoCtx ?? [], (int) ($kbbRange[0] ?? $price ?? 0));
    $kbbPtsLinks = \App\Support\ProductShare::links($kbbPtsFacts, $kbbPts);
    $kbbPtsNative = $kbbPts->on('share_native') ? \App\Support\ProductShare::native($kbbPtsFacts, $kbbPts) : null;
@endphp
@if ($kbbPtsLinks !== [] || $kbbPtsNative !== null)
<div class="pts-share pts-s-{{ $kbbPts->choice('share_style') }} pts-s-{{ $kbbPts->choice('share_shape') }}" data-pts="share" style="{{ $kbbPts->vars('share') }}"><span class="pts-share-label">{{ $kbbPts->text('share_label') }}</span><ul class="pts-share-list">@foreach ($kbbPtsLinks as $kbbPtsL)<li>@if ($kbbPtsL['key'] === 'copy')<button type="button" class="pts-sb" data-net="copy" data-pts-copy="{{ $kbbPtsL['href'] }}" style="--pts-c:{{ $kbbPtsL['colour'] }}" aria-label="{{ __('store.product.pts_copy') }}" title="{{ __('store.product.pts_copy') }}">{!! \App\Support\TrustShareIcons::network('copy') !!}</button>@elseif ($kbbPtsL['external'])<a class="pts-sb" data-net="{{ $kbbPtsL['key'] }}" href="{{ $kbbPtsL['href'] }}" target="_blank" rel="noopener noreferrer" style="--pts-c:{{ $kbbPtsL['colour'] }}" aria-label="{{ __('store.product.pts_share_on', ['network' => $kbbPtsL['name']]) }}" title="{{ $kbbPtsL['name'] }}">{!! \App\Support\TrustShareIcons::network($kbbPtsL['key']) !!}</a>@else<a class="pts-sb" data-net="{{ $kbbPtsL['key'] }}" href="{{ $kbbPtsL['href'] }}" style="--pts-c:{{ $kbbPtsL['colour'] }}" aria-label="{{ __('store.product.pts_share_on', ['network' => $kbbPtsL['name']]) }}" title="{{ $kbbPtsL['name'] }}">{!! \App\Support\TrustShareIcons::network($kbbPtsL['key']) !!}</a>@endif</li>@endforeach @if ($kbbPtsNative !== null)<li class="pts-more-li"><button type="button" class="pts-sb pts-more" data-net="more" data-pts-native data-title="{{ $kbbPtsNative['title'] }}" data-text="{{ $kbbPtsNative['text'] }}" data-url="{{ $kbbPtsNative['url'] }}" aria-label="{{ __('store.product.pts_more') }}" title="{{ __('store.product.pts_more') }}" hidden>{!! \App\Support\TrustShareIcons::network('more') !!}</button></li>@endif</ul><span class="pts-copied" role="status" aria-live="polite" data-ok="{{ __('store.product.pts_copied') }}" data-fail="{{ __('store.product.pts_copy_fail') }}"></span></div><!--/pts-share-->
@endif
@endif
