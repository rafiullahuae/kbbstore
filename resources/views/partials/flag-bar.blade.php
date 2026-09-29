{{--
    THE FLAG BAR — the thin strip above the header. (Lane FB)

    The owner: "i need thin bar as same as attached, having uae flat, then text
    and then korea flag. (This bar is only for mobile, keep this turnef off for
    desktop by default)."

    Settings: Appearance → Header → Flag bar. Eleven of them, all in
    App\Services\HeaderSettings::SCHEMA under `fb_*`, which is the same ONE
    settings row partials/header.blade.php already reads on every page — so
    this strip costs no query. See the note over those keys for why a module of
    its own would have cost one.

    ── NO WORD HERE IS A LITERAL ───────────────────────────────────────────────

    The line is `store.flagbar.text` unless the owner has typed his own, and
    both flags carry a translated accessible name. StorefrontStringsAreKeyedTest
    walks this directory; nothing below is English this file owns.

    ── THE FLAGS ARE DRAWINGS, NOT EMOJI ───────────────────────────────────────

    `🇦🇪` and `🇰🇷` render as the boxed letters AE and KR on every browser on
    Windows — App\Support\FlagArt's header sets that out. They are inline SVG,
    printed raw from a constant with no interpolation in it, which is CLAUDE.md
    rule 5. The `aria-label` is a translated string and therefore a VALUE, so it
    lives on a wrapper Blade escapes and never inside the raw markup; the SVG
    itself is aria-hidden, so each flag has exactly one accessible name.

    ── RTL COMES OUT OF THE MARKUP ORDER, NOT OUT OF A SELECTOR ────────────────

    UAE, words, Korea — in that order in the source. `.kfb-in` is a flex row
    with no `order`, no `left`, no `right` and no `[dir]` rule anywhere, so on
    /ar the browser lays the same three children out from the right and the UAE
    flag is at the reading start in both languages. The only side-aware
    properties in the stylesheet are `padding-inline` and `margin-inline`.

    ── AND IT RESERVES ITS OWN HEIGHT ──────────────────────────────────────────

    `min-height:var(--kfb-h,30px)` is declared in kbb.css, which the <head>
    loads render-blocking, so the strip occupies its full height from the first
    paint and the header below it never moves. The custom property on the
    element only changes what that height IS. Nothing here measures anything in
    JavaScript, and there is no JavaScript here at all.
--}}@php
    $kfb = app(\App\Services\HeaderSettings::class);
    $kfbC = $kfb->all();
    /* Blank means "the line this app ships", which is translated. An owner who
       types his own gets his own, in both languages — the trade every
       operator-typed string on this shop makes. */
    $kfbText = $kfbC['fb_text'] !== '' ? $kfbC['fb_text'] : __('store.flagbar.text');
@endphp
<div class="kfb {{ $kfb->flagBarClass() }}" style="{{ $kfb->flagBarStyle() }}">
  <div class="kfb-in">
@if ($kfbC['fb_flags'])<span class="kfb-fl" role="img" aria-label="{{ __('store.flagbar.uae') }}">{!! \App\Support\FlagArt::UAE !!}</span>@endif
    <span class="kfb-tx">{{ $kfbText }}</span>
@if ($kfbC['fb_flags'])<span class="kfb-fl" role="img" aria-label="{{ __('store.flagbar.korea') }}">{!! \App\Support\FlagArt::KOREA !!}</span>@endif
  </div>
</div>
