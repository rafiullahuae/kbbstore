{{--
 ══════════════════════════════════════════════════════════════════════════════
  DESIGN 4 of 4 — "The stack". The box as a sum. (Lane SF)
 ══════════════════════════════════════════════════════════════════════════════

    ── WHY THIS ONE, AND WHY IT IS NOT A FOURTH ARRANGEMENT OF THE SAME CARD ──

    The other three answer "what is in the box". This one answers "why is the
    box worth buying", and it answers it in a grammar this storefront already
    speaks: resources/views/partials/fbt.blade.php -- Frequently Bought
    Together, which sits FURTHER DOWN THIS SAME PAGE -- draws products joined
    by `+` and footed by a total. A shopper who scrolls past a set's contents
    and then meets FBT reads the two as one idea rather than as two modules.

    It is also the one design where the arithmetic is the layout. A set's whole
    proposition is that the parts add up to more than the box costs, and in the
    other three that fact is a line under a picture gallery. Here it is the
    shape of the block: chips, plus signs, an equals, and the three figures.

    The circles are borrowed on purpose too. App\Support\SetDesign::FAN -- the
    drawing the owner picked for the basket row, in the cart panel, the cart
    page and the checkout -- is a row of overlapping circular member
    thumbnails. A shopper who buys this set meets those circles three more
    times before the order is placed; starting them on the product page means
    the set looks like the same object all the way through.

    ── WHAT IT COSTS TO BE HONEST HERE ────────────────────────────────────────

    The equals sign resolves to @include('partials.set-contents.footing'), the
    same partial the other three end with, in its `result` arrangement. So an
    UNPRICED set does not claim a saving in this design either, and it does not
    because of a guard written here -- there is no guard written here. One
    file decides that, for all four.

    ── TWELVE MEMBERS AND TWO ────────────────────────────────────────────────

    `flex-wrap` with `flex:1 1 var(--kss-b)`: the chips share the line they are
    on and spill onto the next. Two members make two wide chips; twelve make
    three or four rows of narrow ones, at 390px and at 1280px alike. Nothing is
    measured to decide that -- flex does it, which is the CSS answer this
    project prefers to a scripted one.

    ── ARABIC ─────────────────────────────────────────────────────────────────

    `+` and `=` are direction-neutral glyphs and the flex row runs along the
    inline axis, so the chain reads right-to-left in Arabic with no rule of its
    own. Both operators are aria-hidden: they are punctuation between named
    products, and a screen reader announcing "plus" eleven times is noise. The
    names, quantities and prices are all real text in reading order.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;
    use App\Support\Money;
@endphp
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WHAT IS IN THIS SET — the stack. flex-wrap and calc(); nothing measured.
   ═══════════════════════════════════════════════════════════════════════════ */
.kss{display:block;min-width:0}
/* --kss-b is the chip's flex-basis, and the ONE number this design is tuned
   with: raise it for fewer, wider chips per row and lower it for more. */
.kss-chain{--kss-b:190px;display:flex;flex-wrap:wrap;align-items:stretch;
           gap:9px 8px;min-width:0}
/* padding-inline rather than a four-value shorthand: the tighter side is the
   one the circle sits on, and in Arabic that is the other edge of the pill.
   One logical declaration, no [dir] selector. */
.kss-i{flex:1 1 var(--kss-b);min-width:0;max-width:100%;display:flex;align-items:center;
       gap:10px;padding-block:9px;padding-inline:9px 12px;text-align:start;
       border:1px solid var(--line,rgba(42,34,40,.10));border-radius:999px;
       background:var(--surface,#fff)}

/* THE CIRCLE. The same shape the basket row's fanned stack uses, at a size a
   product page can afford. */
.kss-ph{position:relative;flex:none;display:block;width:42px;height:42px;
        border-radius:50%;overflow:hidden;background:var(--line-2,rgba(42,34,40,.06))}
.kss-ph img{width:100%;height:100%;object-fit:cover;display:block}
.kss-ph.is-blank{background-size:cover;background-position:center}

.kss-w{display:flex;flex-direction:column;gap:1px;min-width:0}
.kss-br{font-size:10px;letter-spacing:.05em;text-transform:uppercase;font-weight:700;
        color:var(--ink-2,#5E545A);opacity:.7;overflow-wrap:anywhere}
.kss-nm{font-size:13px;line-height:1.3;font-weight:640;color:var(--ink,#2A2228);
        overflow-wrap:anywhere}
a.kss-nm{color:inherit;text-decoration:none}
a.kss-nm:hover{text-decoration:underline;text-underline-offset:3px}
a.kss-nm:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.kss-sub{display:flex;flex-wrap:wrap;gap:2px 7px;align-items:baseline;min-width:0;
         font-size:11.5px;color:var(--ink-2,#5E545A)}
.kss-q{font-weight:700;color:var(--ink,#2A2228);white-space:nowrap}
.kss-pr{white-space:nowrap}

/* The operators. `flex:none` so they never take a share of the line, and
   align-self:center so a `+` sits on the chips' midline whatever height the
   names push them to. */
.kss-op{flex:none;align-self:center;font-size:17px;line-height:1;font-weight:400;
        color:var(--ink-2,#5E545A);opacity:.55;padding:0 1px}

/* The right-hand side. `=` beside the three figures, on a tinted panel, so the
   equation resolves into something that reads as an answer rather than as one
   more row of the list. */
.kss-res{display:flex;align-items:center;gap:10px;min-width:0;margin-top:14px}
.kss-res .kss-op{font-size:20px;opacity:.45}
.kss-res .ksp-foot.is-result{flex:1 1 auto;min-width:0;margin-top:0;padding:12px 14px;
        border-top:0;border:1px solid var(--line,rgba(42,34,40,.10));border-radius:14px;
        background:var(--line-2,rgba(42,34,40,.045));gap:6px 16px}
.kss-res .ksp-foot.is-result .ksp-f{font-size:13px}
.kss-res .ksp-foot.is-result .ksp-save{font-size:13.5px}

@media (max-width:480px){
  .kss-chain{--kss-b:150px;gap:8px 7px}
  .kss-i{gap:9px;padding-inline:8px 11px}
  .kss-ph{width:38px;height:38px}
  .kss-res{gap:8px}
  .kss-res .ksp-foot.is-result{padding:11px 12px}
}
</style>
@endonce
<div class="kss">
    <div class="kss-chain">
        @foreach ($kbbSetPage['members'] as $kbbSetPageMember)
            @php
                $kssName = (string) $kbbSetPageMember['name'];
                $kssBrand = (string) ($kbbSetPageMember['brand'] ?? '');
                $kssImage = $kbbSetPageMember['image'] ?? null;
                $kssSrcset = $kssImage ? ImageVariants::srcsetFor($kssImage) : '';
                $kssLink = ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
            @endphp
            <span class="kss-i">
                @if ($kssImage)
                    <span class="kss-ph"><img src="{{ $kssImage }}" alt="{{ $kssName }}" width="84" height="84" loading="lazy" decoding="async" @if ($kssSrcset !== '') srcset="{{ $kssSrcset }}" sizes="{{ ImageVariants::setListSizesAttribute() }}" @endif></span>
                @else
                    <span class="kss-ph is-blank" style="background:{{ Gradient::for($kssBrand . $kssName) }}"></span>
                @endif
                <span class="kss-w">
                    @if ($kssBrand !== '')<span class="kss-br">{{ $kssBrand }}</span>@endif
                    @if ($kssLink)
                        <a class="kss-nm" href="{{ $kbbSetPageMember['url'] }}">{{ $kssName }}</a>
                    @else
                        <span class="kss-nm">{{ $kssName }}</span>
                    @endif
                    <span class="kss-sub">
                        <span class="kss-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span>
                        @if (($kbbSetPageMember['variant'] ?? '') !== '')<span>{{ $kbbSetPageMember['variant'] }}</span>@endif
                        <span class="kss-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
                    </span>
                </span>
            </span>
            @unless ($loop->last)
                <span class="kss-op" aria-hidden="true">+</span>
            @endunless
        @endforeach
    </div>
    <div class="kss-res">
        <span class="kss-op" aria-hidden="true">=</span>
        @include('partials.set-contents.footing', ['kspFoot' => 'result'])
    </div>
</div>
