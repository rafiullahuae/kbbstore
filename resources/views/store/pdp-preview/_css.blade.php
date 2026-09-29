{{--
    ═══════════════════════════════════════════════════════════════════════════
    THE FIVE DRAWINGS, IN ONE SHEET, INLINE.                          (Lane PDP)
    ═══════════════════════════════════════════════════════════════════════════

    ▲ WHY THIS IS NOT A FILE UNDER resources/css/kbb/.
      Four of these five are going to be deleted. A sheet under resources/css
      needs a Vite entry, `npx vite build` by hand (this project has no `build`
      script and CI does not build assets), and public/build committed — which
      means a new manifest key on the live shop for a page the live shop cannot
      reach, and a second commit to take it away again. Inline, the whole cost
      of throwing four candidates away is deleting their blocks from this file,
      and the cost of throwing ALL FIVE away is deleting one directory.
      resources/css/kbb/kbb-product.css is not touched by this lane at all, so
      the shipped product page's stylesheet — and therefore its manifest hash,
      and therefore every page that references it — does not move.

    ▲ IT IS A CONSTANT, WHICH IS WHY IT MAY BE PRINTED UNESCAPED. CLAUDE.md
      rule 5: "Anything printed unescaped is a constant, never a setting." There
      is no interpolation in this sheet except the @for loop below, whose only
      variable is its own integer counter.

    ▲ LOGICAL PROPERTIES THROUGHOUT, so /ar is the same sheet. `inline-size`,
      `margin-inline`, `inset-inline-start`, `border-inline-start`,
      `padding-inline` — the tab row scrolls the other way on Arabic because the
      writing mode says so, not because a [dir] rule says so. THE ONE
      UNAVOIDABLE EXCEPTION is `mask-image`, whose gradients take physical
      directions only and have no logical form; candidate A's edge fade
      therefore carries a single [dir="rtl"] override, and it is the only one in
      the sheet.

    ▲ NOTHING IN HERE IS MEASURED BY A SCRIPT. Every position that looks
      computed — the segmented fill in C, the progress hairline in E, the rating
      bar's fill — is arithmetic on a number the server already knew: the tab's
      index, the tab count, the average rating. `calc()` does the rest. See the
      note at the head of parts/tabs.blade.php.
--}}
@php
    /* Ten is more tabs than Catalog → Product tabs has ever produced on one
       product (three built-ins plus the owner's globals), and the rules are two
       selectors each, so the whole loop is under a kilobyte. A product with an
       eleventh tab shows it in the row and opens the first panel — it degrades
       to "tab 1 is open", never to a blank panel. */
    $pvTabMax = 10;
@endphp
<style>
/* ── shared vocabulary ──────────────────────────────────────────────────── */
.pv{--pv-gut:var(--site-gutter,22px);--pv-ink:var(--ink);--pv-mut:var(--muted);
    --pv-line:var(--line);--pv-faint:var(--line-2);--pv-accent:var(--pink);
    --pv-deep:var(--pink-deep);--pv-soft:var(--pink-soft);--pv-cream:var(--cream);
    --pv-sale:var(--sale);--pv-r:14px;padding-block:0 56px}
.pv *{box-sizing:border-box}
.pv-bleed{margin-inline:calc(var(--pv-gut) * -1)}

/* brand · title + price · rating ------------------------------------------ */
.pv-brand{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.pv-brand a{color:var(--pv-deep);text-decoration:none;border-block-end:1px solid color-mix(in srgb,var(--pv-deep) 35%,transparent);padding-block-end:1px}
.pv-head{display:grid;grid-template-columns:minmax(0,1fr) auto;column-gap:16px;align-items:start;margin-block:8px 0}
.pv-title{font-size:20px;font-weight:600;line-height:1.3;letter-spacing:-.01em;margin:0}
.pv-money{text-align:end;line-height:1.15;white-space:nowrap}
.pv-money s{display:block;font-size:12.5px;font-weight:500;color:var(--pv-mut)}
.pv-money b{display:block;font-size:21px;font-weight:800;letter-spacing:-.02em}
.pv-off{display:inline-block;margin-block-start:4px;font-size:10px;font-weight:700;color:#fff;background:var(--pv-sale);padding:2px 6px;border-radius:6px}
.pv-vat{font-size:11px;color:var(--pv-mut);margin-block-start:4px}

.pv-rate{display:flex;align-items:center;gap:8px;margin-block-start:10px;font-size:12px;color:var(--pv-mut)}
.pv-stars{font-size:11px;letter-spacing:1px;opacity:.45}
.pv-stars .f{opacity:1}
.pv-avg{font-weight:700;color:var(--pv-ink);font-size:12.5px}
.pv-ratebar{flex:0 1 96px;min-inline-size:44px;block-size:3px;border-radius:2px;background:var(--pv-faint);overflow:hidden}
.pv-ratebar i{display:block;block-size:100%;background:var(--pv-accent);border-start-end-radius:2px;border-end-end-radius:2px}
.pv-rcount{color:var(--pv-mut);text-decoration:underline;text-underline-offset:2px}

/* blurb with a fade, and one tap to the rest ------------------------------ */
.pv-blurb{font-size:13.5px;line-height:1.62;color:var(--ink-2);margin-block:14px 0;
    max-block-size:calc(3 * 1.62em);overflow:hidden;
    -webkit-mask-image:linear-gradient(to bottom,#000 calc(100% - 1.05em),transparent);
    mask-image:linear-gradient(to bottom,#000 calc(100% - 1.05em),transparent)}
.pv-more{display:inline-flex;align-items:center;gap:4px;margin-block-start:6px;font-size:12px;font-weight:700;color:var(--pv-deep);cursor:pointer}
.pv-more svg{inline-size:13px;block-size:13px}
.pv-morebox:checked ~ .pv-blurb{max-block-size:none;-webkit-mask-image:none;mask-image:none}
.pv-morebox:checked ~ .pv-more{display:none}

/* options / bundle bars --------------------------------------------------- */
.pv-optlabel{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--pv-mut);margin-block:20px 9px;display:flex;justify-content:space-between;gap:10px}
.pv-optlabel span{color:var(--pv-deep);text-transform:none;letter-spacing:0}
.pv .pv-variants{margin-block-end:0}
.pv .pv-variants .variant{border-radius:12px;padding-block:11px}

/* stock · quantity + add to cart ------------------------------------------ */
.pv-stock{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--green);margin-block:18px 10px}
.pv-stock .dot{inline-size:7px;block-size:7px;border-radius:50%;background:currentColor}
.pv-stock.out{color:var(--pv-sale)}
.pv-buyrow{display:flex;gap:10px}
.pv-qty{display:inline-flex;align-items:center;border:1.5px solid var(--pv-line);border-radius:12px;flex:0 0 auto}
.pv-qty button{inline-size:42px;block-size:50px;font-size:19px;color:var(--ink-2);background:none;border:0;cursor:pointer}
.pv-qty span{min-inline-size:30px;text-align:center;font-size:15px;font-weight:700}
.pv-add{flex:1;min-block-size:50px;border:0;border-radius:12px;background:var(--pv-accent);color:#fff;font:700 15px/1 var(--sans);display:flex;align-items:center;justify-content:center;gap:9px;cursor:pointer}
.pv-add svg{inline-size:18px;block-size:18px}
.pv-add[disabled]{background:var(--pv-mut);cursor:not-allowed}

/* authenticity · delivery · payment --------------------------------------- */
.pv-assure{margin-block-start:26px;display:grid;gap:10px}
.pv-as{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--ink-2)}
.pv-as svg{inline-size:17px;block-size:17px;color:var(--pv-deep);flex:0 0 auto}
.pv-pay{display:flex;flex-wrap:wrap;gap:6px;margin-block-start:2px}
.pv-pay span{font-size:10px;font-weight:700;color:var(--ink-2);border:1px solid var(--pv-line);border-radius:6px;padding:4px 8px}

/* the gallery, restated once and then bent per candidate ------------------ */
.pv .gallery{position:static}
.pv .gmain{aspect-ratio:1}            /* SQUARE IN ANY CASE — his words. */
.pv .gthumbs{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;scroll-snap-type:x mandatory;padding-block-end:2px}
.pv .gthumbs::-webkit-scrollbar{display:none}
.pv .gthumb{flex:0 0 auto;scroll-snap-align:start}

/* ═══════════════════════════════════════════════════════════════════════════
   THE TABS — one radio group, five presentations.
   ═══════════════════════════════════════════════════════════════════════════ */
.pv-tabs{position:relative;margin-block-start:30px}
/* Off-canvas, NOT display:none: a display:none radio cannot be reached by
   keyboard, which would make the whole strip mouse-only. */
.pv-tabin{position:absolute;inline-size:1px;block-size:1px;opacity:0;pointer-events:none}
.pv-tabrow{display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;scroll-snap-type:x proximity;position:relative}
.pv-tabrow::-webkit-scrollbar{display:none}
.pv-tab{flex:0 0 auto;scroll-snap-align:start;cursor:pointer;white-space:nowrap;
    font-size:13px;font-weight:600;color:var(--pv-ink);opacity:.38;transition:opacity .15s,color .15s}
.pv-tab:hover{opacity:.7}
.pv-panels{margin-block-start:16px}
.pv-panel{display:none}
.pv-segfill,.pv-progress{display:none}
.pv .dcontent{font-size:13.5px;line-height:1.72;color:var(--ink-2)}
.pv .dcontent p{margin-block-end:.8em}
.pv .dcontent p:last-child{margin-block-end:0}
.pv .dcontent ul,.pv .dcontent ol{margin-inline-start:1.1em;margin-block-end:.8em}

/* which tab is open, and where the moving parts sit. The only variables are
   this loop's own integer and the tab count the server counted. */
@for ($k = 0; $k < $pvTabMax; $k++)
.pv-tabin-{{ $k }}:checked ~ .pv-tabrow .pv-tab:nth-of-type({{ $k + 1 }}){opacity:1}
.pv-tabin-{{ $k }}:checked ~ .pv-panels .pv-panel:nth-of-type({{ $k + 1 }}){display:block}
.pv-tabin-{{ $k }}:checked ~ .pv-deck .pv-card:nth-of-type({{ $k + 1 }}){flex:0 0 76%;opacity:1}
.pv-tabin-{{ $k }}:checked ~ .pv-deck .pv-card:nth-of-type({{ $k + 1 }}) .pv-spine{display:none}
.pv-tabin-{{ $k }}:checked ~ .pv-deck .pv-card:nth-of-type({{ $k + 1 }}) .pv-face{display:block}
.pv-tabin-{{ $k }}:checked ~ .pv-tabrow .pv-segfill{inset-inline-start:calc({{ $k }} * var(--pv-segw))}
.pv-tabin-{{ $k }}:checked ~ .pv-progress i{inset-inline-start:calc({{ $k }} * 100% / var(--pv-n))}
@endfor

/* A · underline row, panel inline beneath, the row dissolving at the edge --- */
.pv-tabs-underline .pv-tabrow{gap:22px;border-block-end:1px solid var(--pv-faint);
    /* mask-image has no logical form: this pair is the sheet's only [dir] rule. */
    -webkit-mask-image:linear-gradient(to right,#000 calc(100% - 34px),transparent);
    mask-image:linear-gradient(to right,#000 calc(100% - 34px),transparent)}
[dir="rtl"] .pv-tabs-underline .pv-tabrow{
    -webkit-mask-image:linear-gradient(to left,#000 calc(100% - 34px),transparent);
    mask-image:linear-gradient(to left,#000 calc(100% - 34px),transparent)}
.pv-tabs-underline .pv-tab{padding-block:0 12px;border-block-end:2px solid transparent;margin-block-end:-1px;font-size:13.5px}
.pv-tabs-underline .pv-tabin:checked ~ .pv-tabrow .pv-tab{border-color:transparent}
@for ($k = 0; $k < $pvTabMax; $k++)
.pv-tabs-underline .pv-tabin-{{ $k }}:checked ~ .pv-tabrow .pv-tab:nth-of-type({{ $k + 1 }}){border-block-end-color:var(--pv-ink);font-weight:700}
@endfor
.pv-tabs-underline .pv-panels{padding-block-start:4px}

/* B · pinned pill row, the next pill peeking past the edge ----------------- */
.pv-tabs-pill .pv-tabrow{position:sticky;top:76px;z-index:6;background:#fff;
    padding-block:10px;gap:7px;
    /* the peek: the track ends 40px short so a pill is always cut off. */
    padding-inline-end:40px;
    box-shadow:0 1px 0 var(--pv-faint)}
.pv-tabs-pill .pv-tab{padding:9px 15px;border-radius:99px;background:var(--pv-cream);opacity:.5;font-size:12.5px}
@for ($k = 0; $k < $pvTabMax; $k++)
.pv-tabs-pill .pv-tabin-{{ $k }}:checked ~ .pv-tabrow .pv-tab:nth-of-type({{ $k + 1 }}){background:var(--pv-ink);color:#fff;opacity:1}
@endfor
.pv-tabs-pill .pv-panels{padding-block-start:6px}

/* C · a segmented control in a tinted well, the fill sliding under it ------ */
.pv-tabs-seg .pv-tabrow{--pv-segw:124px;background:var(--pv-cream);border-radius:12px;padding:4px;gap:0;
    scroll-padding-inline:4px;overflow-x:auto}
.pv-tabs-seg .pv-tab{inline-size:var(--pv-segw);flex:0 0 var(--pv-segw);text-align:center;
    padding-block:10px;font-size:12.5px;opacity:.45;position:relative;z-index:2;
    overflow:hidden;text-overflow:ellipsis}
.pv-tabs-seg .pv-segfill{display:block;position:absolute;inset-block:4px;inline-size:var(--pv-segw);
    inset-inline-start:0;background:#fff;border-radius:9px;box-shadow:var(--sh-s);
    transition:inset-inline-start .22s var(--ease);z-index:1;margin-inline:4px}
@for ($k = 0; $k < $pvTabMax; $k++)
.pv-tabs-seg .pv-tabin-{{ $k }}:checked ~ .pv-tabrow .pv-tab:nth-of-type({{ $k + 1 }}){opacity:1;font-weight:700}
@endfor
.pv-tabs-seg .pv-panels{background:var(--pv-cream);border-radius:14px;padding:18px;margin-block-start:10px}

/* D · the deck: the open tab IS the panel, its neighbours are spines ------- */
.pv-tabs-deck .pv-deck{display:flex;gap:8px;align-items:flex-start;overflow-x:auto;scrollbar-width:none;
    scroll-snap-type:x proximity;padding-block-end:4px}
.pv-tabs-deck .pv-deck::-webkit-scrollbar{display:none}
/* A SPINE IS 44px AND AN OPEN CARD IS 86% OF THE STRIP, deliberately adding up
   to more than the screen: the deck scrolls, which is requirement 3, and a
   spine is therefore always standing at the edge, which is the affordance. An
   open card sized `flex:1 1 auto` instead came out 130px wide with the body set
   one word per line — measured, first run. */
.pv-card{flex:0 0 44px;scroll-snap-align:start;cursor:pointer;opacity:.5;
    border:1px solid var(--pv-line);border-radius:var(--pv-r);background:#fff;overflow:hidden;
    transition:flex-basis .24s var(--ease),opacity .18s}
.pv-card:hover{opacity:.85}
/* ▲ PHYSICAL width/height HERE, ON PURPOSE, AND IT IS THE ONE PLACE IN THIS
     SHEET WHERE LOGICAL PROPERTIES WOULD BE THE BUG. `writing-mode:vertical-rl`
     SWAPS what block and inline mean ON THE ELEMENT THAT CARRIES IT, so
     `min-block-size:150px` on a vertical spine asks for 150px of WIDTH — inside
     a 44px card with overflow:hidden, which clipped every spine's title to
     nothing. Five empty white boxes, first run. */
.pv-spine{display:flex;align-items:center;justify-content:center;
    width:100%;height:210px;
    writing-mode:vertical-rl;font-size:12px;font-weight:700;letter-spacing:.03em;
    color:var(--pv-ink);padding:14px 0;white-space:nowrap;overflow:hidden}
.pv-face{display:none;padding:18px}
.pv-facetitle{display:block;font-size:14px;font-weight:700;margin-block-end:10px}
.pv-body{display:block}

/* E · a dark full-bleed band, a white sheet under it, a proportional rule --- */
.pv-tabs-band{margin-block-start:34px}
.pv-tabs-band .pv-tabrow{background:var(--pv-ink);padding:14px var(--pv-gut);gap:20px;
    margin-inline:calc(var(--pv-gut) * -1)}
.pv-tabs-band .pv-tab{color:#fff;opacity:.45;font-size:13px}
@for ($k = 0; $k < $pvTabMax; $k++)
.pv-tabs-band .pv-tabin-{{ $k }}:checked ~ .pv-tabrow .pv-tab:nth-of-type({{ $k + 1 }}){opacity:1;font-weight:700}
@endfor
.pv-tabs-band .pv-progress{display:block;position:relative;block-size:2px;background:rgba(42,34,40,.9);
    margin-inline:calc(var(--pv-gut) * -1)}
.pv-tabs-band .pv-progress i{position:absolute;inset-block:0;inline-size:calc(100% / var(--pv-n));
    inset-inline-start:0;background:var(--pv-accent);transition:inset-inline-start .22s var(--ease)}
.pv-tabs-band .pv-panels{background:#fff;padding:22px var(--pv-gut) 6px;margin-inline:calc(var(--pv-gut) * -1)}

/* ═══════════════════════════════════════════════════════════════════════════
   A · LEDGER — no boxes anywhere. A full-bleed photograph and hairline rules.
   ═══════════════════════════════════════════════════════════════════════════ */
.pv-a .pv-gal{margin-inline:calc(var(--pv-gut) * -1)}
.pv-a .gmain{border-radius:0;border:0}
.pv-a .gthumbs{margin-block-start:-30px;padding-inline:var(--pv-gut);position:relative;z-index:2;gap:8px}
.pv-a .gthumb{inline-size:56px;block-size:56px;border-radius:10px;box-shadow:var(--sh-s);background-color:#fff}
.pv-a .pv-body-in{padding-block-start:22px}
.pv-a .pv-rule{border-block-start:1px solid var(--pv-faint);margin-block:20px 0;padding-block-start:20px}
.pv-a .pv-title{font-size:21px}
.pv-a .pv-money b{font-size:22px}

/* ═══════════════════════════════════════════════════════════════════════════
   B · DOSSIER — one lifted card, overlapping the photograph, holding the whole
   decision. Everything after it sits on the open page.
   ═══════════════════════════════════════════════════════════════════════════ */
.pv-b .pv-gal{padding-block-start:14px}
.pv-b .gmain{border-radius:20px}
.pv-b .gthumbs{margin-block-start:10px;gap:8px;justify-content:center}
/* ▲ THE TOP PADDING IS THE SAME 34px THE CARD OVERLAPS BY, and that is the fix
     rather than tidiness. At 20px the brand line sat INSIDE the overlap and the
     thumbnail strip painted over it — measured on the set, where the brand is
     long enough to reach the middle of the strip: "B———— of Joseon". A
     z-index alone is the wrong answer to a layout that puts two things in the
     same place; the right one is not to put anything there. */
.pv-b .pv-card-buy{background:#fff;border-radius:22px;box-shadow:var(--sh-m);border:1px solid var(--pv-faint);
    padding:34px 18px 22px;margin-block-start:-34px;position:relative;z-index:3}
.pv-b .pv-assure{margin-block-start:22px;padding-inline:2px}

/* ═══════════════════════════════════════════════════════════════════════════
   C · COUNTER — the price is the loudest thing on the page.
   ═══════════════════════════════════════════════════════════════════════════ */
.pv-c .pv-gal{background:var(--pv-cream);border-radius:20px;padding:14px;margin-block-start:12px}
.pv-c .gmain{border-radius:14px;border:0;background:#fff}
.pv-c .gthumbs{justify-content:center;margin-block-start:12px;gap:8px}
.pv-c .gthumb{background-color:#fff}
.pv-c .pv-head{grid-template-columns:minmax(0,1fr);row-gap:0;margin-block-start:18px}
.pv-c .pv-title{font-size:19px}
.pv-c .pv-band{margin-inline:calc(var(--pv-gut) * -1);margin-block-start:14px;padding:16px var(--pv-gut);
    background:var(--pv-soft)}
.pv-c .pv-band .pv-money{text-align:start;white-space:normal}
.pv-c .pv-band .pv-money s{display:inline;font-size:13px;margin-inline-end:9px}
.pv-c .pv-band .pv-money b{display:inline;font-size:29px}
.pv-c .pv-band .pv-off{margin-inline-start:8px;margin-block-start:0}
.pv-c .pv-blurb{background:var(--pv-cream);border-radius:14px;padding:14px 15px;margin-block-start:16px}
.pv-c .pv-more{margin-inline-start:15px}
.pv-c .pv-buyrow{margin-block-start:4px}

/* ═══════════════════════════════════════════════════════════════════════════
   D · DECK — unboxed page, and the buy row docks to the bottom while you are
   still in the part of the page it belongs to.
   ═══════════════════════════════════════════════════════════════════════════ */
.pv-d .pv-gal{padding-block-start:12px}
.pv-d .gmain{border-radius:18px}
.pv-d .gthumbs{margin-block-start:10px;gap:9px}
.pv-d .pv-title{font-size:21px;font-weight:700}
.pv-d .pv-dock{position:sticky;bottom:10px;z-index:5;background:#fff;
    border-radius:16px;box-shadow:var(--sh-m);border:1px solid var(--pv-faint);padding:10px;margin-block-start:16px}
.pv-d .pv-dock .pv-stock{margin-block:0 8px;padding-inline-start:4px}

/* ═══════════════════════════════════════════════════════════════════════════
   E · MARQUEE — quiet page, loud band. The thumbnails stand up beside the
   photograph the moment there is room for them.
   ═══════════════════════════════════════════════════════════════════════════ */
.pv-e .pv-gal{padding-block-start:12px}
.pv-e .gmain{border-radius:4px;border:1px solid var(--pv-faint)}
.pv-e .gthumbs{margin-block-start:10px;gap:8px}
.pv-e .gthumb{border-radius:4px}
.pv-e .pv-title{font-size:20px;font-weight:500;letter-spacing:0}
.pv-e .pv-brand a{border:0;color:var(--pv-ink);opacity:.65}
/* the stripes. Each group runs to both edges of the phone and is told from its
   neighbours by its ground, which is the whole of this candidate's skeleton. */
.pv-e .pv-strip{margin-inline:calc(var(--pv-gut) * -1);padding:18px var(--pv-gut) 22px}
.pv-e .pv-strip-tint{background:var(--pv-soft)}
.pv-e .pv-strip-plain{background:#fff}
.pv-e .pv-strip-last{margin-block-start:0;padding-block-end:34px}
.pv-e .pv-assure{margin-block-start:0}
.pv-e .pv-optlabel{margin-block-start:0}

/* ═══════════════════════════════════════════════════════════════════════════
   DESKTOP. 881px is the shipped page's own breakpoint (.pdp in
   kbb-product.css), so these five turn at the width the shop already turns at.
   The five differ here as much as they do on the phone: two columns on
   hairlines, two columns with a sticky card, THREE columns with a price rail,
   two columns over a card deck, and a gallery with a standing thumbnail rail
   beside a vertical tab rail.
   ═══════════════════════════════════════════════════════════════════════════ */
@media (min-width:881px){
  .pv-2col{display:grid;grid-template-columns:1.08fr 1fr;gap:46px;align-items:start}
  .pv-title{font-size:27px}
  .pv-money b{font-size:26px}
  .pv-gal{position:sticky;top:88px}

  /* A — the photograph keeps its full-bleed edge on the inline-start side. */
  .pv-a .pv-gal{margin-inline:calc(var(--pv-gut) * -1) 0}
  .pv-a .gthumbs{margin-block-start:14px;padding-inline:var(--pv-gut) 0}
  .pv-a .gthumb{inline-size:66px;block-size:66px;box-shadow:none}
  .pv-a .pv-title{font-size:30px;font-weight:500}

  /* B — the card lifts off the page and follows you down it. */
  .pv-b .pv-card-buy{margin-block-start:0;padding:26px 24px 28px;position:sticky;top:88px}
  .pv-b .gthumbs{justify-content:flex-start}

  /* C — THREE columns, made by PLACEMENT and not by moving any markup:
     photograph, the reading, and a buy rail. The DOM order is still the order
     he asked for, which is what a phone reads.

     ▲ THE BUY GROUP IS THE STICKY ONE, NOT THE PRICE BAND, AND THAT IS A FACT
       ABOUT GRID RATHER THAN A PREFERENCE. A grid item's containing block is
       its own grid AREA, so `position:sticky` on an item sitting in a short,
       auto-sized row has nowhere to travel and does nothing at all — the band
       was declared sticky in the first draft and measured at 1280 as
       `addToCart top=-57, onScreen=false`, i.e. the rail scrolled away with
       everything else. The buy group is in the TALL row (the one the bundle
       bars make), so it has somewhere to stick, and it is also the half of the
       rail worth keeping: a price you have already read does not need to follow
       you, and a button does. */
  .pv-c .pv-3col{display:grid;grid-template-columns:1fr .94fr 290px;
      grid-template-rows:auto 1fr;column-gap:34px;row-gap:0;align-items:start}
  .pv-c .pv-gal{grid-column:1;grid-row:1 / span 2}
  .pv-c .pv-facts{grid-column:2;grid-row:1}
  .pv-c .pv-mid{grid-column:2;grid-row:2}
  .pv-c .pv-band{grid-column:3;grid-row:1;margin-inline:0;margin-block-start:0;
      border-radius:16px 16px 0 0;padding:22px}
  .pv-c .pv-buygroup{grid-column:3;grid-row:2;background:var(--pv-soft);
      border-radius:0 0 16px 16px;padding:4px 22px 22px;
      position:sticky;top:88px;align-self:start}
  .pv-c .pv-band .pv-money s{display:block;margin-inline-end:0}
  .pv-c .pv-band .pv-money b{display:block;font-size:33px}
  .pv-c .pv-buygroup .pv-stock{margin-block:0 12px}
  .pv-c .pv-head{margin-block-start:0}
  /* ▲ RESTATED PER CANDIDATE, BECAUSE THE PHONE SIZES OUTRANK THE DESKTOP ONE.
       `.pv-c .pv-title` is two classes and `.pv-title` inside the media query is
       one, so the 19px phone size WON at 1280 — measured: C and D and E all came
       back with a 19-21px title on a 1280 screen, which is the "21px title on a
       1280 screen is timid" note docs/PP-PRODUCT-PAGE-PROPOSALS.md already
       carries. C stays the smallest of the three on purpose: its whole argument
       is that the PRICE is the loudest thing, and 24 against 33 says that. */
  .pv-c .pv-title{font-size:24px}
  .pv-c .pv-tabs-seg .pv-tabrow{--pv-segw:150px}

  /* D — the dock stops docking: on a desktop the button is never far away. */
  .pv-d .pv-title{font-size:28px}
  .pv-d .pv-dock{position:static;box-shadow:none;border:0;padding:0;background:none}
  .pv-d .pv-card{flex-basis:54px}
  .pv-d .pv-spine{height:300px;font-size:13px}

  /* E — the stripes stop bleeding (there is a gutter to bleed into on a wide
     screen and it looks like an accident), the thumbnails stand up beside the
     photograph, and the band becomes a rail down the panel's inline-start. */
  .pv-e .pv-title{font-size:28px}
  .pv-e .pv-strip{margin-inline:0;padding-inline:20px;border-radius:14px}
  .pv-e .pv-strip-plain{padding-inline:0;border-radius:0}
  .pv-e .pv-gal{display:grid;grid-template-columns:72px minmax(0,1fr);gap:12px;direction:inherit}
  .pv-e .pv-gal .gallery{display:contents}
  .pv-e .pv-gal .gthumbs{flex-direction:column;overflow:visible;margin-block-start:0;
      grid-column:1;grid-row:1;align-self:start}
  .pv-e .pv-gal .gmain{grid-column:2;grid-row:1}
  .pv-e .pv-tabs-band{display:grid;grid-template-columns:210px minmax(0,1fr);gap:0;
      border:1px solid var(--pv-faint);border-radius:16px;overflow:hidden}
  .pv-e .pv-tabs-band .pv-tabrow{flex-direction:column;gap:2px;margin-inline:0;
      padding:18px 16px;align-items:stretch;overflow:visible;position:sticky;top:88px}
  .pv-e .pv-tabs-band .pv-tab{padding-block:9px;white-space:normal}
  .pv-e .pv-tabs-band .pv-progress{display:none}
  .pv-e .pv-tabs-band .pv-panels{margin-inline:0;padding:22px 26px;min-block-size:240px}
}

/* ── the preview's own chrome. Never part of a design; it is how the owner
      moves between the five without going back to a menu. ─────────────────── */
/* ▲ NOT `.pv-bar`, AND NOT STICKY, AND BOTH WERE BUGS I SHIPPED INTO THE FIRST
     CONTACT SHEET. The rating hairline above was also called `.pv-bar`, so every
     rating on every candidate was painted as a solid ink rectangle by this
     rule — an 96px black bar where a 3px pink one belonged, in all ten shots.
     And sticky at top:0 put this chrome UNDER the shop's own sticky header
     (z-index 60, top 0), so the switcher was a dark sliver behind the logo. It
     is chrome for a throwaway page; it scrolls away like anything else. */
.pv-switch{background:var(--ink);color:#fff;
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px var(--site-gutter,22px);font-size:12px}
.pv-switch b{font-size:13px}
.pv-switch a{color:#fff;opacity:.55;text-decoration:none;border:1px solid rgba(255,255,255,.25);
    border-radius:99px;padding:4px 11px;font-weight:600}
.pv-switch a.on{opacity:1;background:#fff;color:var(--ink);border-color:#fff}
.pv-switch .pv-idea{flex:1 1 100%;opacity:.6;line-height:1.5;font-size:11.5px}
.pv-index{padding-block:26px 60px}
.pv-index h1{font-size:22px;margin-block-end:6px}
.pv-index h2{font-size:15px;margin-block:26px 8px}
.pv-index p{font-size:13px;color:var(--ink-2);line-height:1.6;max-inline-size:62ch}
.pv-index ul{list-style:none;display:flex;flex-wrap:wrap;gap:8px;margin-block-start:10px}
.pv-index a{border:1px solid var(--line);border-radius:10px;padding:7px 12px;font-size:12.5px;text-decoration:none}
</style>
