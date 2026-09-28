{{--
 ══════════════════════════════════════════════════════════════════════════════
  DESIGN 2 of 4 — "List". One row per product. (Lane SF)
 ══════════════════════════════════════════════════════════════════════════════

    The information-dense answer, and the one that holds up best when the box
    is full: twelve rows of 62px read as a contents list, where twelve cards
    read as a second catalogue. A shopper scanning for "is the toner in this
    one" finds it here fastest, because every name starts at the same x.

    ── THE GRID IS THREE TRACKS AND FLIPS ITSELF IN ARABIC ────────────────────

    `grid-template-columns: 54px minmax(0,1fr) auto` — picture, words, money.
    Grid lays its columns along the INLINE axis, so in an RTL document the
    picture is on the right and the price on the left with no second rule and
    no `[dir]` selector anywhere in this file. Everything directional below is
    a logical property for the same reason: `text-align:start`/`end`,
    `padding-inline`, `border-block-start`. See resources/views/partials/
    checkout/*.blade.php, which is where this storefront settled that habit.

    `minmax(0,1fr)` and not `1fr`: a grid item's default min-width is `auto`,
    which is the width of its longest unbreakable word, so a member named
    "Heartleaf 77% Soothing Toner Refill Pack" pushes the row wider than the
    page and the whole document scrolls sideways at 390px. That exact defect
    shipped on the Coupons screen once; every track that can hold something
    long carries min-width:0 here.

    ── THE HAIRLINE IS ON THE ROW, NOT BETWEEN THE ROWS ───────────────────────

    `.ksl-r + .ksl-r { border-block-start: ... }` — so a set with one member
    has no stray rule under it and a set with twelve has eleven, without a
    :last-child exception to get wrong.

    ── WHAT IS NOT HERE ───────────────────────────────────────────────────────

    No script. No getBoundingClientRect, no offsetWidth, no ResizeObserver:
    this project sizes with calc() and min(), and two tests forbid those APIs
    by name. Every number below is a constant or a CSS function.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;
    use App\Support\Money;
@endphp
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WHAT IS IN THIS SET — the list. Logical properties only; nothing measured.
   ═══════════════════════════════════════════════════════════════════════════ */
.ksl{display:block;min-width:0}
.ksl-r{display:grid;gap:0 12px;min-width:0;align-items:center;padding:11px 0;
       grid-template-columns:54px minmax(0,1fr) auto}
.ksl-r + .ksl-r{border-block-start:1px solid var(--line,rgba(42,34,40,.10))}

/* The picture. A fixed square so every name in the column starts at the same
   place — which is the whole reason to draw a list rather than a grid. */
.ksl-ph{position:relative;display:block;flex:none;
        width:54px;height:54px;border-radius:10px;overflow:hidden;
        background:var(--line-2,rgba(42,34,40,.06))}
.ksl-ph img{width:100%;height:100%;object-fit:cover;display:block}
.ksl-ph.is-blank{background-size:cover;background-position:center}

.ksl-w{min-width:0;grid-column:2;display:flex;flex-direction:column;gap:2px;text-align:start}
.ksl-br{font-size:11px;letter-spacing:.04em;text-transform:uppercase;font-weight:700;
        color:var(--ink-2,#5E545A);opacity:.72;overflow-wrap:anywhere}
.ksl-nm{font-size:14px;line-height:1.35;font-weight:640;color:var(--ink,#2A2228);
        overflow-wrap:anywhere}
/* A REAL LINK for a published member, and the same words with no <a> for one
   that is not. SetContents::memberIsLive() decides, and it fails closed: a
   href to a draft is a 404 on a page a shopper reached from Google. */
a.ksl-nm{color:inherit;text-decoration:none;border-bottom:1px solid var(--line,rgba(42,34,40,.10))}
a.ksl-nm:hover{border-bottom-color:currentColor}
a.ksl-nm:focus-visible{outline:2px solid currentColor;outline-offset:2px}
.ksl-var{font-size:12px;color:var(--ink-2,#5E545A);overflow-wrap:anywhere}

.ksl-end{grid-column:3;display:flex;flex-direction:column;align-items:flex-end;
         gap:2px;min-width:0;text-align:end}
.ksl-q{font-size:12px;font-weight:700;color:var(--ink,#2A2228);white-space:nowrap}
.ksl-pr{font-size:13px;color:var(--ink-2,#5E545A);white-space:nowrap}

/* 390px: the picture shrinks and the two type sizes come down a step. The row
   stays three tracks — a list that reflows into a card on a phone is two
   designs, and the owner is choosing between designs. */
@media (max-width:480px){
  .ksl-r{grid-template-columns:46px minmax(0,1fr) auto;gap:0 10px;padding:10px 0}
  .ksl-ph{width:46px;height:46px;border-radius:9px}
  .ksl-nm{font-size:13.5px}
}
</style>
@endonce
<div class="ksl">
    @foreach ($kbbSetPage['members'] as $kbbSetPageMember)
        @php
            $kslName = (string) $kbbSetPageMember['name'];
            $kslBrand = (string) ($kbbSetPageMember['brand'] ?? '');
            $kslImage = $kbbSetPageMember['image'] ?? null;
            $kslSrcset = $kslImage ? ImageVariants::srcsetFor($kslImage) : '';
            $kslLink = ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
        @endphp
        <div class="ksl-r">
            @if ($kslImage)
                <span class="ksl-ph"><img src="{{ $kslImage }}" alt="{{ $kslName }}" width="108" height="108" loading="lazy" decoding="async" @if ($kslSrcset !== '') srcset="{{ $kslSrcset }}" sizes="{{ ImageVariants::setListSizesAttribute() }}" @endif></span>
            @else
                <span class="ksl-ph is-blank" style="background:{{ Gradient::for($kslBrand . $kslName) }}"></span>
            @endif
            <span class="ksl-w">
                @if ($kslBrand !== '')<span class="ksl-br">{{ $kslBrand }}</span>@endif
                @if ($kslLink)
                    <a class="ksl-nm" href="{{ $kbbSetPageMember['url'] }}">{{ $kslName }}</a>
                @else
                    <span class="ksl-nm">{{ $kslName }}</span>
                @endif
                @if (($kbbSetPageMember['variant'] ?? '') !== '')<span class="ksl-var">{{ $kbbSetPageMember['variant'] }}</span>@endif
            </span>
            <span class="ksl-end">
                <span class="ksl-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span>
                <span class="ksl-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
            </span>
        </div>
    @endforeach
</div>
@include('partials.set-contents.footing')
