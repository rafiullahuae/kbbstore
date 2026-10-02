{{--
    "Authenticity Guaranteed" — Lane PW.

      "under add to cart button, Authenticity guaranteed line with info icon
       and upon open green yes tick icon with same description text as
       attached. and it should nicely open with slide, and will have cross
       small cornerd redish circled icon to close back. need this in desktop +
       mobile."

    ▲ A REAL <button>, aria-expanded + aria-controls, and the whole line is it.
      pdp-trust.js flips aria-expanded; the CSS does everything else from that
      one attribute.

    ▲ THE SLIDE IS CSS, AND NOTHING MEASURES ANYTHING. The panel is a one-row
      grid whose row goes from `0fr` to `1fr`; the browser interpolates the
      track itself, so no script ever asks how tall the text is (CLAUDE.md rule
      4 — two tests forbid the element-measuring APIs by name, and
      ProductTrustShareTest is a third, over this file and its script).
      `visibility` switches on a delay matching the slide, so a CLOSED panel
      is out of the tab order and the accessibility tree, and the × inside it
      cannot be focused while it is invisible. prefers-reduced-motion drops
      the transition.

    ▲ PLAIN TEXT, ESCAPED. The label and both paragraphs are his words from
      Appearance → Product page → Trust · Authenticity, printed through `{{ }}`;
      the icons are App\Support\TrustShareIcons constants.
--}}@php
    $kbbPts = app(\App\Services\ProductTrustShare::class);
@endphp
@if ($kbbPts->on('auth_on'))
<div class="pts-auth" data-pts="auth" data-pts-auth style="{{ $kbbPts->vars('auth') }}"><button type="button" class="pts-auth-btn" id="ptsAuthBtn" aria-expanded="false" aria-controls="ptsAuthPanel">{!! \App\Support\TrustShareIcons::CHECK_SQUARE !!}<span class="pts-auth-label">{{ $kbbPts->text('auth_label') }}</span>{!! \App\Support\TrustShareIcons::INFO !!}</button><div class="pts-auth-panel" id="ptsAuthPanel" role="region" aria-labelledby="ptsAuthBtn"><div class="pts-auth-in"><div class="pts-auth-card">{!! \App\Support\TrustShareIcons::YES !!}<div class="pts-auth-copy">@foreach ($kbbPts->paragraphs() as $kbbPtsP)<p>{{ $kbbPtsP }}</p>@endforeach</div><button type="button" class="pts-auth-x" aria-label="{{ __('store.product.pts_close') }}">{!! \App\Support\TrustShareIcons::CLOSE !!}</button></div></div></div></div><!--/pts-auth-->
@endif
