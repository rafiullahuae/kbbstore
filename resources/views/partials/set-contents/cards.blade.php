{{--
 ══════════════════════════════════════════════════════════════════════════════
  DESIGN 3 of 4 — "Cards". A card per product, one column on a phone. (Lane SF)
 ══════════════════════════════════════════════════════════════════════════════

    The shoppable answer. The compact grid says WHAT IS IN THE BOX; this says
    LOOK AT WHAT IS IN THE BOX. Each member gets a photograph at a size worth
    looking at, on its own surface, with its own link — so it reads like the
    shop's own product grid and a member looks like something to click, which
    on a page a shopper reached from Google is the point of naming the members
    at all.

    ── ONE COLUMN ON A PHONE, ON PURPOSE ──────────────────────────────────────

    `repeat(auto-fill, minmax(min(100%, 232px), 1fr))`. At 390px the content
    column is 358px, which is narrower than 232 x 2 + the gap, so it is one
    card across and the photograph is 358px wide. That is the whole difference
    from the compact grid, which puts two 173px squares on the same line: this
    design spends the width on the picture instead of on the count.

    `min(100%, 232px)` and not a bare `232px` is the same guard the compact
    grid documents -- a track minimum wider than its container makes the
    document scroll sideways, and 232px inside a narrow panel would do exactly
    that.

    ── THE QUANTITY IS ON THE PICTURE, AND IT IS THE ONLY THING THERE ─────────

    A "x2" chip in the corner of the photograph rather than a line of text
    under it, because in this design the photograph is the largest thing on the
    card and a quantity printed anywhere else is read after the price. It is
    drawn for every member including the single ones: a chip that appears only
    sometimes reads as a badge on the products that have it, and "x1" is a fact
    about the box, not a promotion.

    ── ARABIC ─────────────────────────────────────────────────────────────────

    The chip is placed with `inset-inline-end`, so it is the top-LEFT corner of
    the picture in an RTL document without a second rule. Text is `start`
    aligned throughout, the grid flows along the inline axis by itself, and
    there is no `[dir=rtl]` selector in this file.

    ── AND NOT ONE LINE OF SCRIPT ─────────────────────────────────────────────

    The card's height comes from `aspect-ratio` and `flex`, not from anything
    measured. The two tests that forbid the element-measuring APIs by name have
    nothing to find here.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;
    use App\Support\Money;
@endphp
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WHAT IS IN THIS SET — the cards. auto-fill, min() and aspect-ratio only.
   ═══════════════════════════════════════════════════════════════════════════ */
.ksc{display:grid;gap:16px;min-width:0;
     grid-template-columns:repeat(auto-fill,minmax(min(100%,232px),1fr))}
.ksc-c{display:flex;flex-direction:column;min-width:0;text-align:start;
       border:1px solid var(--line,rgba(42,34,40,.10));border-radius:14px;
       overflow:hidden;background:var(--surface,#fff)}

/* The picture. 4:5 rather than square: it is the shape a bottle photographs
   into, and it is the shape the shop's own product tiles already use. */
.ksc-ph{position:relative;display:block;width:100%;aspect-ratio:4/5;
        overflow:hidden;background:var(--line-2,rgba(42,34,40,.06))}
.ksc-ph img{width:100%;height:100%;object-fit:cover;display:block}
.ksc-ph.is-blank{background-size:cover;background-position:center}

/* inset-inline-end: the top-right corner in English, the top-left in Arabic,
   from one declaration. */
.ksc-q{position:absolute;top:10px;inset-inline-end:10px;z-index:1;
       padding:3px 8px;border-radius:999px;font-size:11.5px;font-weight:700;
       line-height:1.4;white-space:nowrap;
       background:var(--ink,#2A2228);color:#fff}

.ksc-b{display:flex;flex-direction:column;gap:5px;min-width:0;padding:12px 13px 13px;flex:1 1 auto}
.ksc-br{font-size:11px;letter-spacing:.04em;text-transform:uppercase;font-weight:700;
        color:var(--ink-2,#5E545A);opacity:.72;overflow-wrap:anywhere}
.ksc-nm{font-size:14.5px;line-height:1.35;font-weight:640;color:var(--ink,#2A2228);
        overflow-wrap:anywhere}
/* Published members are links; the rest are the same words without an <a>. */
a.ksc-nm{color:inherit;text-decoration:none}
a.ksc-nm:hover{text-decoration:underline;text-underline-offset:3px}
a.ksc-nm:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.ksc-var{font-size:12px;color:var(--ink-2,#5E545A);overflow-wrap:anywhere}
/* margin-block-start:auto pins the price to the foot of the card, so a row of
   cards whose names run to one, two and three lines still lines its prices up
   -- without measuring anything. */
.ksc-pr{margin-block-start:auto;padding-block-start:8px;font-size:13.5px;font-weight:700;
        color:var(--ink,#2A2228);white-space:nowrap}

@media (max-width:480px){
  .ksc{gap:13px}
  .ksc-ph{aspect-ratio:1}
}
</style>
@endonce
<div class="ksc">
    @foreach ($kbbSetPage['members'] as $kbbSetPageMember)
        @php
            $kscName = (string) $kbbSetPageMember['name'];
            $kscBrand = (string) ($kbbSetPageMember['brand'] ?? '');
            $kscImage = $kbbSetPageMember['image'] ?? null;
            $kscSrcset = $kscImage ? ImageVariants::srcsetFor($kscImage) : '';
            $kscLink = ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
        @endphp
        <div class="ksc-c">
            @if ($kscImage)
                <span class="ksc-ph"><span class="ksc-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span><img src="{{ $kscImage }}" alt="{{ $kscName }}" width="464" height="580" loading="lazy" decoding="async" @if ($kscSrcset !== '') srcset="{{ $kscSrcset }}" sizes="{{ ImageVariants::setCardSizesAttribute() }}" @endif></span>
            @else
                <span class="ksc-ph is-blank" style="background:{{ Gradient::for($kscBrand . $kscName) }}"><span class="ksc-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span></span>
            @endif
            <span class="ksc-b">
                @if ($kscBrand !== '')<span class="ksc-br">{{ $kscBrand }}</span>@endif
                @if ($kscLink)
                    <a class="ksc-nm" href="{{ $kbbSetPageMember['url'] }}">{{ $kscName }}</a>
                @else
                    <span class="ksc-nm">{{ $kscName }}</span>
                @endif
                @if (($kbbSetPageMember['variant'] ?? '') !== '')<span class="ksc-var">{{ $kbbSetPageMember['variant'] }}</span>@endif
                <span class="ksc-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
            </span>
        </div>
    @endforeach
</div>
@include('partials.set-contents.footing')
