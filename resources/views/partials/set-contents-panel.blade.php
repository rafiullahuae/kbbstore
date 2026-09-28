{{--
 ═══════════════════════════════════════════════════════════════════════════════
  WHAT IS IN THIS SET — the set's OWN PRODUCT PAGE. (Lane SP)
 ═══════════════════════════════════════════════════════════════════════════════

    Lane SET's report, §8:

      "It was not one of the seven surfaces and you did not name it. The set
       publishes with its own description, gallery and price; what is in the box
       is not repeated there."

    So a shopper who lands on /product/{set-slug}/ from Google saw a price and
    no contents. This is that page's answer.

    ── WHY THIS IS NOT partials/set-row.blade.php AGAIN ───────────────────────

    The fanned row is a BASKET row: circles, a button, a tiny popup. It is right
    where the shopper has already chosen and the row has 42px of height to live
    in. This page is the surface where the shopper is DECIDING, and on it a
    member is a product they may want to open — so each member gets its own
    picture at a size worth looking at, its own name, its own brand, its own
    option label, its own price, and ITS OWN LINK.

    ── AND WHY IT IS STILL App\Support\SetContents ────────────────────────────

    ▲ THERE IS EXACTLY ONE DESCRIPTION OF A SET'S CONTENTS IN THIS APPLICATION
      AND THIS FILE DOES NOT ADD A SECOND. ▲

    Every figure below comes out of SetContents::fromProduct() — the same array
    the cart row, the checkout summary, the invoice and the order email read.
    Nothing here re-reads the pivot, re-adds the parts total or re-derives the
    saving; a second adder is how two surfaces come to disagree about what a set
    costs, which is a defect this shop has already paid for twice.

    Two keys were ADDED to that array rather than computed here — `url` and
    `visible` — because both are facts about a member product and both belong
    beside the name they describe. Neither reaches an order snapshot or /api/*:
    SetContents::snapshot() and ::toApi() build their rows from explicit key
    lists, so a key added to fromProduct() cannot travel.

    ── THE DESIGN SWITCH IS NOT MINE ──────────────────────────────────────────

    set-row.blade.php's `@if (SetDesign::current() === SetDesign::FAN)` picks
    which BASKET ROW is drawn. This panel is not one of those four drawings and
    is deliberately not behind that switch: whichever row the owner ends up
    with, the product page still has to say what is in the box.

    ── NOTHING HERE MEASURES LAYOUT AND NOTHING HERE IS JAVASCRIPT ────────────

    Not one line of script. The grid is `repeat(auto-fill, minmax(min(100%,
    150px), 1fr))`, which is the CSS answer to "as many columns as fit": two at
    390px, seven or eight at 1280px, and no getBoundingClientRect anywhere.
    `min(100%, 150px)` and not `150px` is what keeps the page honest below
    150px of content width -- a bare 150px minimum makes the track wider than
    its container and the whole page scrolls sideways.

    ── ESCAPING ───────────────────────────────────────────────────────────────

    Every interpolation is escaped. A member name, a brand and an option label
    are settings. The only {!! !!} is Money::format(), which returns markup this
    application builds itself. The link's href is Product::url(), built from the
    slug by App\Support\Url — never a value out of the settings table, so there
    is no scheme to check.

    ── THE STYLE BLOCK IS @once AND INLINE ────────────────────────────────────

    Same reasoning as set-row.blade.php: resources/css/kbb/kbb.css is compiled
    by Vite, package.json defines no `build` script, and a rule added there
    would not reach the server until somebody ran `npx vite build` by hand.

    ── AND IT COSTS NO QUERY ──────────────────────────────────────────────────

    Store\ProductController::show() calls SetEagerLoad::on([$product]), which
    runs NOTHING AT ALL when the product is not a set — which is every product
    in this catalogue but the sets — and three batched queries when it is,
    whether the box holds three members or thirty. SetProductPageTest measures
    the flatness rather than asserting it.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;
    use App\Support\Money;
    use App\Support\SetContents;

    $kbbSetPage = $product->isSet() ? SetContents::fromProduct($product) : SetContents::NONE;
@endphp
@if ($kbbSetPage['members'] !== [])
@once
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   WHAT IS IN THIS SET. calc(), min() and auto-fill only — nothing measured.
   ═══════════════════════════════════════════════════════════════════════════ */
.ksp-intro{margin:0 0 14px;font-size:13px;color:var(--ink-2,#5E545A)}
.ksp-grid{display:grid;gap:14px;min-width:0;
          grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr))}
.ksp-m{display:flex;flex-direction:column;gap:7px;min-width:0;text-align:start}

/* The picture. A square box the track decides the width of, so the row of
   photographs lines up whatever shape the originals are. */
.ksp-ph{position:relative;display:block;width:100%;aspect-ratio:1;border-radius:12px;
        overflow:hidden;background:var(--line-2,rgba(42,34,40,.06))}
.ksp-ph img{width:100%;height:100%;object-fit:cover;display:block}
.ksp-ph.is-blank{background-size:cover;background-position:center}

.ksp-br{font-size:11px;letter-spacing:.04em;text-transform:uppercase;font-weight:700;
        color:var(--ink-2,#5E545A);opacity:.72;overflow-wrap:anywhere}
.ksp-nm{font-size:13.5px;line-height:1.35;font-weight:640;color:var(--ink,#2A2228);
        overflow-wrap:anywhere}
/* A REAL LINK, so it is a link for a keyboard and for a crawler as well as for
   a mouse. A member that is not published is the same words without an <a> --
   see SetContents::memberIsLive(): a href to a draft is a 404 on the one page
   a shopper reached from Google. */
a.ksp-nm{color:inherit;text-decoration:none;border-bottom:1px solid var(--line,rgba(42,34,40,.10))}
a.ksp-nm:hover{border-bottom-color:currentColor}
a.ksp-nm:focus-visible{outline:2px solid currentColor;outline-offset:2px}

.ksp-meta{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline;min-width:0;
          font-size:12px;color:var(--ink-2,#5E545A)}
.ksp-q{font-weight:700;color:var(--ink,#2A2228);white-space:nowrap}
.ksp-pr{white-space:nowrap}

/* The footing. Bought separately, the set's own price, and the saving -- the
   same three integers SetContents already computed, printed rather than
   recomputed. */
.ksp-foot{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:baseline;min-width:0;
          margin-top:16px;padding-top:14px;border-top:1px solid var(--line,rgba(42,34,40,.10))}
.ksp-f{display:flex;gap:6px;align-items:baseline;min-width:0;font-size:13px;
       color:var(--ink-2,#5E545A)}
.ksp-f b{font-weight:700;color:var(--ink,#2A2228);white-space:nowrap}
.ksp-was b{font-weight:600;text-decoration:line-through;color:var(--ink-2,#5E545A)}
.ksp-save{font-size:13px;font-weight:700;color:#1c7a4a;white-space:nowrap}
@media (max-width:700px){
  .ksp-grid{gap:12px}
  .ksp-nm{font-size:13px}
}
</style>
@endonce
<section class="sec ksp">
    <div class="eyebrow">{{ __('store.set.page_eyebrow') }}</div>
    <h2>{{ __('store.set.page_heading') }}</h2>
    <p class="ksp-intro">{{ trans_choice('store.set.contents', $kbbSetPage['count'], ['count' => $kbbSetPage['count']]) }}</p>
    <div class="ksp-grid">
        @foreach ($kbbSetPage['members'] as $kbbSetPageMember)
            @php
                $kspName = (string) $kbbSetPageMember['name'];
                $kspBrand = (string) ($kbbSetPageMember['brand'] ?? '');
                $kspImage = $kbbSetPageMember['image'] ?? null;
                $kspSrcset = $kspImage ? ImageVariants::srcsetFor($kspImage) : '';
                $kspLink = ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
            @endphp
            <div class="ksp-m">
                @if ($kspImage)
                    <span class="ksp-ph"><img src="{{ $kspImage }}" alt="{{ $kspName }}" width="300" height="300" loading="lazy" decoding="async" @if ($kspSrcset !== '') srcset="{{ $kspSrcset }}" sizes="{{ ImageVariants::setMemberSizesAttribute() }}" @endif></span>
                @else
                    <span class="ksp-ph is-blank" style="background:{{ Gradient::for($kspBrand . $kspName) }}"></span>
                @endif
                @if ($kspBrand !== '')<span class="ksp-br">{{ $kspBrand }}</span>@endif
                @if ($kspLink)
                    <a class="ksp-nm" href="{{ $kbbSetPageMember['url'] }}">{{ $kspName }}</a>
                @else
                    <span class="ksp-nm">{{ $kspName }}</span>
                @endif
                <span class="ksp-meta">
                    <span class="ksp-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span>
                    @if (($kbbSetPageMember['variant'] ?? '') !== '')<span>{{ $kbbSetPageMember['variant'] }}</span>@endif
                    <span class="ksp-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
                </span>
            </div>
        @endforeach
    </div>
    <div class="ksp-foot">
        <span class="ksp-f ksp-was">{{ __('store.set.page_separately') }} <b>{!! Money::format((int) $kbbSetPage['partsTotal']) !!}</b></span>
        <span class="ksp-f">{{ __('store.set.page_set_price') }} <b>{!! Money::format((int) $kbbSetPage['setPrice']) !!}</b></span>
        @if ($kbbSetPage['saving'] > 0)<span class="ksp-save">{{ __('store.set.saving', ['amount' => Money::plain((int) $kbbSetPage['saving'])]) }}</span>@endif
    </div>
</section>
@endif
