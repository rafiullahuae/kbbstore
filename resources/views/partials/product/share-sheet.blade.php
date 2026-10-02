{{--
    The share sheet — Lane QB.

      "remove the share row completely, and make the share icon only, and bring
       that beside the right side of the product title, need same icon as
       attached the amazon screenshot. and also upon click it will open popup
       from bottom side same as attached fro mamazon with same product image
       carry, title row, and sharing platforms."

    ONE DIALOG, CLOSED. `hidden` until pdp-trust.js opens it from ANY
    `[data-share-open]` button — Lane QA draws that button beside the title
    (partials/product/share-button.blade.php); the script listens on the
    document, so it works wherever the button sits. A page without the script
    never shows the sheet at all.

    ▲ @once, AND PUSHED TO THE END OF <body>. This partial may be included more
      than once — from trust-share-stack and by Lane QA near the foot of the
      product page — and draws one sheet whichever comes first. It is pushed
      onto the layout's `scripts` stack, just before </body>, so no
      transformed or clipped ancestor in the buy column can capture its
      `position: fixed`.

    ▲ EVERY HREF IS BUILT ON THE SERVER by App\Support\ProductShare (urlencoded
      there, escaped here by `{{ }}`); the picture is the same JPEG og:image
      names (App\Support\ShareImage), scheme-checked by SafeUrl; the tile
      pictures are constants (TrustShareIcons::TILE); the heading is a
      setting printed through `{{ }}`. No setting reaches `{!! !!}`.

    ▲ OFF-SITE TILES OPEN IN A NEW TAB with rel="noopener noreferrer". Email
      and Messages are mailto:/sms: links; Copy and More are buttons. "More"
      is drawn `hidden` and revealed only where navigator.share exists.
--}}@php
    $kbbPts = app(\App\Services\ProductTrustShare::class);
@endphp
@if ($kbbPts->on('share_on') && isset($product))
@once
@include('partials.product.trust-share-assets')
@php
    $kbbPsFacts = \App\Support\ProductShare::facts($product, $seoCtx ?? [], (int) ($kbbRange[0] ?? $price ?? 0));
    $kbbPsLinks = [];
    foreach (\App\Support\ProductShare::links($kbbPsFacts, $kbbPts) as $kbbPsL) { $kbbPsLinks[$kbbPsL['key']] = $kbbPsL; }
    $kbbPsNative = $kbbPts->on('share_native') ? \App\Support\ProductShare::native($kbbPsFacts, $kbbPts) : null;
    // The card is one row (owner, 2 October: "the share popup is too
    // heighted... picture + title in same row"): a square thumbnail, so it
    // shows the product's OWN photograph, not the 1.91:1 share card.
    $kbbPsPic = \App\Support\SafeUrl::src((string) ($kbbPsFacts['image'] ?? $kbbPsFacts['share_image'] ?? ''));
    // The price exactly as the buy box states it: the struck was-price only
    // when one price is on sale, never beside a from-to range.
    $kbbPsSpread = isset($kbbRange) && $kbbRange !== null && \App\Services\VariantPricing::isSpread($kbbRange);
    $kbbPsWas = (! $kbbPsSpread && ! empty($onSale) && (int) ($kbbWas ?? 0) > 0) ? \App\Support\Money::format((int) $kbbWas, $kbbSaleDp ?? null) : '';
    $kbbPsNow = ($kbbHeadline ?? null) !== null ? $kbbHeadline : ((int) ($price ?? 0) > 0 ? \App\Support\Money::format((int) $price, $kbbSaleDp ?? null) : '');
    $kbbPsLabels = [
        'sms' => __('store.product.pts_tile_messages'),
        'email' => __('store.product.pts_tile_email'),
        'copy' => __('store.product.pts_tile_copy'),
        'native' => __('store.product.pts_tile_more'),
    ];
@endphp
@push('scripts')<div class="pdp-share" id="pdpShareSheet" role="dialog" aria-modal="true" aria-labelledby="pdpShareTitle" data-share-sheet hidden><div class="pdp-share-scrim" data-share-close></div><div class="pdp-share-panel"><button type="button" class="pdp-share-x" data-share-close aria-label="{{ __('store.product.pts_close') }}">{!! \App\Support\TrustShareIcons::SHEET_CLOSE !!}</button><div class="pdp-share-body"><h2 class="pdp-share-h" id="pdpShareTitle">{{ $kbbPts->text('share_heading') }}</h2><div class="pdp-share-card"><div class="pdp-share-pic">@if ($kbbPsPic !== '')<img src="{{ $kbbPsPic }}" alt="{{ $kbbPsFacts['name'] }}" width="96" height="96" loading="lazy" decoding="async">@endif</div><div class="pdp-share-txt"><p class="pdp-share-name">{{ $kbbPsFacts['name'] }}</p>@if ($kbbPsNow !== '')<p class="pdp-share-price"><span class="now">{!! $kbbPsNow !!}</span>@if ($kbbPsWas !== '')<s>{!! $kbbPsWas !!}</s>@endif</p>@endif</div></div><ul class="pdp-share-grid">@foreach ($kbbPts->shareNetworks() as $kbbPsKey)@if ($kbbPsKey === 'native')@if ($kbbPsNative !== null)<li data-share-more hidden><button type="button" class="pdp-share-tile" data-net="native" data-share-native data-title="{{ $kbbPsNative['title'] }}" data-text="{{ $kbbPsNative['text'] }}" data-url="{{ $kbbPsNative['url'] }}"@if (($kbbPsFacts['share_path'] ?? null) !== null) data-file="{{ $kbbPsFacts['share_path'] }}"@endif aria-label="{{ __('store.product.pts_more') }}"><span class="pdp-share-ico">{!! \App\Support\TrustShareIcons::tile('native') !!}</span><span class="pdp-share-lbl">{{ $kbbPsLabels['native'] }}</span></button></li>@endif
@elseif (isset($kbbPsLinks[$kbbPsKey]))@php $kbbPsL = $kbbPsLinks[$kbbPsKey]; @endphp<li>@if ($kbbPsKey === 'copy')<button type="button" class="pdp-share-tile" data-net="copy" data-share-copy="{{ $kbbPsL['href'] }}" aria-label="{{ __('store.product.pts_copy') }}"><span class="pdp-share-ico">{!! \App\Support\TrustShareIcons::tile('copy') !!}</span><span class="pdp-share-lbl">{{ $kbbPsLabels['copy'] }}</span></button>@else<a class="pdp-share-tile" data-net="{{ $kbbPsKey }}" href="{{ $kbbPsL['href'] }}"@if ($kbbPsL['app'] !== null) data-app-href="{{ $kbbPsL['app'] }}"@endif @if ($kbbPsL['external'])target="_blank" rel="noopener noreferrer" @endif aria-label="{{ __('store.product.pts_share_on', ['network' => $kbbPsLabels[$kbbPsKey] ?? $kbbPsL['name']]) }}"><span class="pdp-share-ico">{!! \App\Support\TrustShareIcons::tile($kbbPsKey) !!}</span><span class="pdp-share-lbl">{{ $kbbPsLabels[$kbbPsKey] ?? $kbbPsL['name'] }}</span></a>@endif</li>
@endif
@endforeach</ul></div><p class="pdp-share-toast" role="status" aria-live="polite" data-ok="{{ __('store.product.pts_copied') }}" data-fail="{{ __('store.product.pts_copy_fail') }}"></p></div></div><!--/pdp-share-->
@endpush
@endonce
@endif
