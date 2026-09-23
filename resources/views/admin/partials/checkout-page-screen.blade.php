{{--
    Appearance → Checkout page. (Lane: checkout-page)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, window.kbbAddNavEntry and
    toast(). Its own file rather than more lines inside a 20,000-line Blade, for
    the reason cart-page-screen.blade.php states: several lanes edit that file
    at once. The cost is the same — it cannot reach app.blade.php's
    module-scoped constants, so it appends its own sidebar entry and wraps
    window.go.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    "the same options as we built for cart page, like squeezing, spacings,
    paddings, control for every section of the checkout page ... please don't
    disturb any number of sections on desktop checkout and mobile checkout
    page."

    So: spacing, and only spacing. Nothing on this screen can add, remove,
    reorder or rename a section, and there is no layout switch to get wrong.
    Every control writes App\Services\CheckoutPage::SCHEMA, and every default
    in that schema is the number the page already had — a shop that never opens
    this screen renders the checkout byte for byte as before.

    ── TWO TABS, TWO SETS OF VALUES ─────────────────────────────────────────

    Desktop and Mobile are separate stored values, not one value seen through a
    breakpoint: 16px of padding on a 594px column and 16px on a 350px one are
    not the same decision. The service emits both sets as custom properties and
    the STYLESHEET chooses between them inside its media query — it has to be
    that way round, because an inline style attribute beats a media query and
    the Mobile tab would otherwise save and move nothing.

    ── THE PREVIEW IS BELOW THE CONTROLS ────────────────────────────────────

    "bring the preview to downwards, so i can see better, but put heading
    'Preview'" — said of the cart page's desktop tab, and true of both tabs
    here for the same reason: a checkout drawn in a 336px side column is a
    two-column layout with two columns too narrow to judge.

    The mock draws the four numbered sections at whatever spacing the sliders
    say, in the page's own proportions. It is a DRAWING and not the real
    checkout: rendering the storefront in here would mean an authenticated
    fetch per keystroke, and the checkout needs a basket to render at all.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment
    included — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto and that exact
    defect shipped on the Coupons screen.

    EVERY CLASS IS PREFIXED chp- OR chv- AND APPEARS NOWHERE ELSE IN THE
    CONSOLE, and so is every data- attribute anything clicks: app.blade.php
    binds delegated listeners to `document` itself, each claiming a bare
    attribute name, and a click on any element carrying one is handled by that
    listener whichever screen it belongs to.
--}}
@verbatim
<style>
/* Controls, then the preview under them. One column at every width: the
   preview is a page mock and a page mock in a side rail shows nothing. */
.chp-wrap{display:grid;gap:14px;min-width:0}
.chp-wrap > *{min-width:0}

/* ── THE MOBILE TABS PUT THE PREVIEW BESIDE THE CONTROLS ──────────────────
   "in all mobile tabs for checkout page, i want the preview on the right
   side, only in the mobile tabs."

   ONLY THE MOBILE ONES, and that is not a preference. The phone mock is a
   320px frame, which sits happily in a side column; the desktop mock stands
   for a 1040px page with two columns in it, and drawn 380px wide its tracks
   are a few dozen pixels each and show the owner nothing they can judge. So
   the desktop tabs -- and Fields & attention, which previews the desktop page
   -- keep the full-width preview below the controls.

   Below 1180px it folds back to one column: a 300px control column beside a
   320px phone is two things nobody can use, which is the same reason the cart
   page's screen folds at 1100. */
.chp-wrap.chp-side{grid-template-columns:minmax(0,1fr) 372px;align-items:start}
.chp-wrap.chp-side > .chp-col{display:grid;gap:14px;min-width:0}
/* The controls are the long column, so the preview follows the scroll rather
   than scrolling off the top while a slider is still being dragged. */
.chp-wrap.chp-side [data-chp-preview]{position:sticky;top:16px}
.chp-wrap.chp-side .chv-h{margin-top:0}
@media (max-width:1180px){
  .chp-wrap.chp-side{grid-template-columns:minmax(0,1fr)}
  .chp-wrap.chp-side [data-chp-preview]{position:static}
}

.chp-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.chp-title{font-weight:650;font-size:15px}
.chp-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.chp-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.chp-tab{padding:8px 12px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
         background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.chp-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.chp-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.chp-f{display:grid;gap:5px;min-width:0}
.chp-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.chp-fh label{font-size:12.5px;font-weight:650;min-width:0;overflow-wrap:anywhere}
.chp-val{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;
         font-variant-numeric:tabular-nums}
.chp-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.chp-f input[type=range]{width:100%;accent-color:var(--accent,#15a85a);margin:0;min-width:0}
/* A select and a text box on this screen, because until now there were none
   and three selects had already shipped rendering as sliders. Sized off the
   same 13px the rest of the card uses, and full width so a long option label
   is not cut off at 372px in the side-by-side Mobile tabs. */
.chp-f select.chp-sel,.chp-f input.chp-text{
  width:100%;min-width:0;box-sizing:border-box;font:inherit;font-size:13px;
  padding:7px 9px;border:1px solid var(--line,#e5e7eb);border-radius:7px;
  background:var(--card,#fff);color:inherit
}
.chp-f select.chp-sel:focus-visible,.chp-f input.chp-text:focus-visible{
  outline:2px solid var(--accent,#15a85a);outline-offset:1px;border-color:transparent
}
.chp-f input.chp-text::placeholder{color:var(--ink-soft,#6b7280);opacity:.7}
.chp-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.chp-check input{margin-top:3px;flex:none;width:16px;height:16px}
.chp-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.chp-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.chp-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.chp-btn[disabled]{opacity:.45;cursor:default}
.chp-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.chp-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
@media (max-width:640px){ .chp-card{padding:13px} }

/* ── the preview ────────────────────────────────────────────────────────── */
.chv-h{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin:4px 0 10px}
.chv-h b{font-size:15px;font-weight:700}
.chv-h span{font-size:11px;color:var(--ink-soft,#6b7280)}

/* The desktop mock stands for a window wider than 900px. The proportions are
   what matter, not the pixels, so every measurement below is a share of the
   owner's own page width — drag "Page width" and the two tracks re-proportion
   exactly as the shop's would. */
.chv-desk{border:1px solid var(--border,#e6e6e6);border-radius:12px;overflow:hidden;
  background:#fbf5f4;box-shadow:0 8px 26px -18px rgba(0,0,0,.4)}
.chv-bar{display:flex;align-items:center;gap:6px;padding:7px 11px;background:#f6f7f9;
  border-bottom:1px solid var(--border,#e6e6e6);font-size:10px;color:#6b7280}
.chv-bar i{width:6px;height:6px;border-radius:50%;background:#d8dbe0;display:block}
.chv-page{background:#fff;border-bottom:1px solid #ebe3e6;
  padding:var(--chv-headpady,7px) var(--chv-headpadx,10px);
  display:flex;align-items:center;justify-content:space-between;
  font-size:var(--chv-headbadge,9px);color:#1f7a4d;font-weight:600}
.chv-page b{font-size:var(--chv-headlogo,11px);color:#c13a5e;font-weight:800}
/* The bar's width is its own control, so the mock draws the band rather than
   letting it fill -- that is the whole of what the slider does. */
.chv-page > span{display:flex;align-items:center;gap:5px}
.chv-headband{max-width:var(--chv-headmax,100%);margin:0 auto;width:100%;
  display:flex;align-items:center;justify-content:space-between}
.chv-pin{font-size:7.5px;color:#94a3b8;font-weight:500;padding:2px 11px;background:#f6f7f9;
  border-bottom:1px solid #ebe3e6}
.chv-cols{display:grid;grid-template-columns:minmax(0,1fr) var(--chv-aside,31%);
  column-gap:var(--chv-gap,2%);align-items:start;
  padding:var(--chv-pady,2%) var(--chv-padx,2%) 4%}
.chv-cols > div{min-width:0}

/* The phone mock. Drawn at real proportions too: the script scales every
   stored pixel by the frame's width over 390, so a 16px section reads here at
   the size it reads on the owner's own phone. */
.chv-phone{width:320px;max-width:100%;margin:0 auto;border:1px solid var(--border,#e6e6e6);
  border-radius:20px;overflow:hidden;background:#fbf5f4;box-shadow:0 8px 26px -18px rgba(0,0,0,.4)}
.chv-stack{display:grid;gap:var(--chv-mgap,11px);padding:var(--chv-pady,17px) var(--chv-padx,16px) 18px}
.chv-stack > *{min-width:0}

/* Shared pieces. Both mocks draw the same checkout; only the frame differs. */
.chv-lead{margin:0 0 var(--chv-block,13px)}
.chv-lead b{display:block;font-size:calc(13px * var(--chv-ttitle,1));font-weight:800;color:#17181c}
.chv-lead i{display:block;font-style:normal;font-size:calc(9px * var(--chv-tlead,1));
  color:#6b7280;margin-top:1px}
.chv-coupon{background:linear-gradient(135deg,#eef8f1,#fff);border:1.4px dashed #bfe0cd;
  border-radius:10px;padding:8px 9px;margin-bottom:var(--chv-block,13px);font-size:9px;
  font-weight:700;color:#1f7d52}
.chv-coupon u{display:block;text-decoration:none;background:#fff;border:1.2px solid #c9e7d5;
  border-radius:6px;margin-top:5px;padding:5px 6px;color:#9aa0aa;font-weight:500}
.chv-box{background:#fff;border:1px solid #ebe3e6;border-radius:10px;overflow:hidden}
/* A numbered section. Its padding is the owner's slider; the heading bar's
   negative margin is the SAME number, which is what pulls the bar out to the
   card edge — exactly the arithmetic kbb-checkout.css performs, so the drawing
   cannot disagree with the page. */
.chv-sec{padding:var(--chv-secpad,16px);border-bottom:1px solid #ebe3e6}
.chv-sec:last-child{border-bottom:0}
.chv-sec > h6{margin:calc(var(--chv-secpad,16px) * -1) calc(var(--chv-secpad,16px) * -1) 9px;
  padding:6px var(--chv-secpad,16px) 6px calc(var(--chv-secpad,16px) - 3px);
  background:#fbf5f4;border-bottom:1px solid #f0eaec;border-inline-start:3px solid #c13a5e;
  display:flex;align-items:center;gap:6px;font-size:calc(9.5px * var(--chv-th2,1));
  font-weight:700;color:#17181c}
.chv-sec > h6 em{flex:0 0 auto;width:calc(14px * var(--chv-th2,1));height:calc(14px * var(--chv-th2,1));
  border-radius:50%;background:#c13a5e;color:#fff;
  font-style:normal;font-size:calc(8px * var(--chv-th2,1));font-weight:800;display:grid;place-items:center}
/* The mock's field text is a placeholder, so it takes BOTH factors -- the
   field size and the placeholder share of it -- exactly as the page does. */
.chv-fi{border:1px solid #ebe3e6;border-radius:6px;padding:5px 6px;
  font-size:calc(9px * var(--chv-tinput,1) * var(--chv-tph,1));
  font-weight:var(--chv-phw,400);font-style:var(--chv-phi,normal);color:var(--chv-phc,#9aa0aa);
  background:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chv-lb{display:block;font-size:calc(7.5px * var(--chv-tlabel,1));font-weight:600;
  color:#6b7280;margin-bottom:2px}
.chv-fg{display:grid;gap:5px}
.chv-fg.two{grid-template-columns:1fr 1fr}
.chv-ad{display:flex;align-items:center;gap:6px;border:1px solid #1e9e5a;background:#f4fbf7;
  border-radius:8px;padding:6px 7px}
.chv-ad .ic{flex:0 0 auto;width:18px;height:18px;border-radius:6px;background:#dff1e7;color:#1f7a4d;
  display:grid;place-items:center;font-size:9px}
.chv-ad .tx{flex:1;min-width:0}
.chv-ad .tx b{display:block;font-size:9.5px;font-weight:700;color:#17181c}
.chv-ad .tx i{display:block;font-style:normal;font-size:8.5px;color:#6b7280;white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis}
.chv-ad .go{flex:0 0 auto;font-size:8.5px;font-weight:700;color:#1f7a4d}
.chv-pick{display:flex;align-items:center;justify-content:space-between;gap:8px;
  border:1px solid #1e9e5a;background:#f4fbf7;border-radius:8px;padding:6px 7px;
  font-size:9.5px;font-weight:700;color:#17181c}

/* The summary column. --chv-asidepad is its own slider, separate from the
   sections', because it is its own card on both surfaces. */
.chv-sum{background:#fff;border:1px solid #ebe3e6;border-radius:10px;padding:var(--chv-asidepad,17px);
  font-size:9.5px}
.chv-sum .sr{display:flex;justify-content:space-between;gap:8px;padding:2px 0;color:#3c3a40}
.chv-sum .tot{display:flex;justify-content:space-between;align-items:center;gap:8px;background:#e6f5ed;
  border-radius:7px;padding:6px 8px;margin-top:6px;font-weight:800;font-size:11px}
.chv-sum .go{display:grid;place-items:center;background:#c13a5e;color:#fff;border-radius:99px;
  padding:7px;margin-top:7px;font-size:10px;font-weight:700}
/* The order-summary lines. Drawn at the SAME px the owner is choosing on the
   Desktop tab, because that tab's frame stands for a 1040px-plus page and is
   about that wide; scaled by the phone frame's 320/390 on the Mobile tab, for
   the same reason everything else in that frame is. Both are stated on the
   ruler under the mock so neither can be misread. */
.chv-ci{display:flex;align-items:flex-start;gap:var(--chv-rowgap,11px);
  padding:var(--chv-rowp-t,10px) var(--chv-rowp-r,0px) var(--chv-rowp-b,10px) var(--chv-rowp-l,0px);
  border-bottom:1px solid #f0eaec}
.chv-ci:first-child{padding-top:0}
.chv-ci:last-child{border-bottom:0}
.chv-ci .th{flex:0 0 auto;position:relative;width:var(--chv-rowh,54px);aspect-ratio:1;
  border-radius:calc(var(--chv-rowh,54px) * .2)}
.chv-ci .th i{position:absolute;top:-5px;inset-inline-end:-5px;min-width:14px;height:14px;
  border-radius:50%;background:#17181c;color:#fff;font-style:normal;font-size:8px;font-weight:700;
  display:grid;place-items:center;border:1.5px solid #fff}
.chv-ci .info{flex:1;min-width:0}
.chv-ci .info b{display:block;font-size:calc(10px * var(--chv-rowf,1));
  font-weight:var(--chv-rowb,600);line-height:1.3;margin:1px 0 4px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* The stepper takes the multiplier on its BOX AND ITS GLYPH together, which is
   what the shop's own rule does. Scaling one of the two would draw a control
   the page never renders. */
.chv-ci .qty{display:inline-flex;align-items:center;border:1px solid #ebe3e6;border-radius:6px;
  overflow:hidden}
.chv-ci .qty u{text-decoration:none;display:grid;place-items:center;color:#6b7280;
  width:calc(17px * var(--chv-qtys,1));height:calc(17px * var(--chv-qtys,1));
  font-size:calc(10px * var(--chv-qtys,1));line-height:1}
.chv-ci .qty em{font-style:normal;text-align:center;min-width:calc(16px * var(--chv-qtys,1));
  font-size:calc(9px * var(--chv-rowf,1));font-weight:var(--chv-rowb,600)}
.chv-ci .pr{flex:0 0 auto;margin-inline-start:auto;white-space:nowrap;
  font-size:calc(9.5px * var(--chv-rowf,1));font-weight:var(--chv-rowpb,700)}
.chv-items{margin-bottom:6px}

/* The trust block under Payment, and the tick that is the point of it. Same
   keyframe shape as the shop's -- the stroke is DRAWN from its own length to
   zero and the shield fills behind it -- so what the owner times here is what
   they get. */
.chv-trust{background:#fff;border:1px solid #ebe3e6;border-radius:10px;
  padding:var(--chv-secpad,16px);margin-top:var(--chv-block,13px);
  display:flex;flex-direction:column;gap:6px}
.chv-tl{display:flex;align-items:center;gap:7px;font-size:calc(9px * var(--chv-ttrust,1));
  font-weight:600;color:#5b6472}
.chv-tl svg{flex:none;width:calc(12px * var(--chv-ttrust,1));height:calc(12px * var(--chv-ttrust,1));
  color:#1e9e5a}
.chv-tl .st{color:#f4b740;letter-spacing:.5px}
.chv-tick{stroke-dasharray:9;stroke-dashoffset:0;
  animation:chvTick calc(3.2s * var(--chv-tickt,1)) ease-in-out infinite}
.chv-shield{animation:chvShield calc(3.2s * var(--chv-tickt,1)) ease-in-out infinite}
@keyframes chvTick{0%,6%{stroke-dashoffset:9}26%,80%{stroke-dashoffset:0}94%,100%{stroke-dashoffset:9}}
@keyframes chvShield{0%,6%{fill:rgba(30,158,90,0)}30%,80%{fill:rgba(30,158,90,.15)}96%,100%{fill:rgba(30,158,90,0)}}
.chv-notick .chv-tick,.chv-notick .chv-shield{animation:none}

/* The address cue, at the mock's scale and on the mock's own timings -- the
   same three parts and the same two numbers driving them. */
.chv-cue{display:flex;align-items:center;gap:7px;border:1px solid #ebe3e6;border-radius:8px;
  padding:7px 8px;background:#fff}
.chv-cueic{position:relative;flex:none;
  width:calc(26px * var(--chv-cues,1));height:calc(22px * var(--chv-cues,1))}
.chv-cueic i{position:absolute;display:grid;place-items:center;border-radius:50%;
  width:calc(16px * var(--chv-cues,1));height:calc(16px * var(--chv-cues,1));
  border:1.2px solid #fff;background:#edf1f5;color:#5b6472}
.chv-cueic svg{width:calc(8px * var(--chv-cues,1));height:calc(8px * var(--chv-cues,1))}
.chv-cueic .o{right:0;bottom:0}
.chv-cueic .h{left:0;top:0;z-index:2;background:#dff1e7;color:#1f7a4d;
  animation:chvBob calc(3.4s * var(--chv-cuet,1)) ease-in-out infinite}
@keyframes chvBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}
.chv-cue .pr{flex:1;min-width:0;font-size:calc(9px * var(--chv-tinput,1));color:#6b7280;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chv-ar{flex:none;display:flex;align-items:center;gap:1px;color:#1f7a4d;
  width:calc(22px * var(--chv-cues,1));
  animation:chvNudge calc(1.5s * var(--chv-cuet,1)) ease-in-out infinite}
.chv-ar em{flex:1;height:1.6px;border-radius:2px;
  background:linear-gradient(90deg,rgba(31,122,77,0),currentColor)}
.chv-ar svg{flex:none;width:calc(10px * var(--chv-cues,1));height:calc(10px * var(--chv-cues,1))}
@keyframes chvNudge{0%,100%{transform:translateX(0);opacity:.5}50%{transform:translateX(4px);opacity:1}}
.chv-cta{position:relative;flex:none;font-size:calc(8.5px * var(--chv-tinput,1));font-weight:700;
  color:#1f7a4d;white-space:nowrap}
.chv-cta::before{content:"";position:absolute;inset:-4px -7px;border-radius:99px;
  border:1.3px solid #1f7a4d;opacity:0;
  animation:chvPulse calc(3s * var(--chv-cuet,1)) ease-out infinite}
@keyframes chvPulse{0%{transform:scale(.88);opacity:.5}60%{transform:scale(1.14);opacity:0}100%{opacity:0}}
.chv-nocue .chv-cueic,.chv-nocue .chv-ar{display:none}
.chv-nocue .chv-cta::before{content:none}
.chv-nocue-ic .chv-cueic{display:none}
.chv-nocue-ar .chv-ar{display:none}
.chv-nocue-pu .chv-cta::before{content:none}
@media (prefers-reduced-motion:reduce){
  .chv-cueic .h,.chv-ar,.chv-tick,.chv-shield{animation:none}
  .chv-ar{opacity:1}
  .chv-cta::before{animation:none;opacity:0}
}
.chv-stick{border:1px dashed #cbd5e1;border-radius:10px;padding:4px;position:relative}
.chv-stick::after{content:'follows the scroll';position:absolute;top:-7px;right:6px;background:#fff;
  padding:0 4px;font-size:7.5px;color:#94a3b8}
.chv-mob-order{background:#fff;border:1px solid #ebe3e6;border-radius:10px;
  padding:var(--chv-secpad,16px);margin-top:var(--chv-block,13px)}
.chv-mob-order .go{display:grid;place-items:center;background:#c13a5e;color:#fff;border-radius:99px;
  padding:8px;margin-top:8px;font-size:10px;font-weight:700}
/* The measured width of the phone mock, stated on it. The owner's report was
   "few sections are showing wide, and few not" — a number on the frame is the
   quickest way to see that they are not, any more. */
.chv-ruler{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px 8px;
  padding:7px 11px;margin-top:8px;background:#f6f7f9;border:1px solid var(--border,#e6e6e6);
  border-radius:8px;font-size:11px;color:#6b7280;text-align:center}
.chv-ruler b{font-weight:700;color:var(--ink,#16181d);font-variant-numeric:tabular-nums}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'checkoutpage';

  /* ---------------------------------------------------------------- state */
  var tabs = null;        // GET /admin-api/checkout-page -> tabs
  var values = {};        // key -> current value, edited in place
  var mobileMax = 900;
  var squeezeKeys = [];  // which controls "Squeeze everything" drives to their minimum
  var open = null;        // which tab is showing
  var banner = null;
  var busy = false;
  var seq = 0;

  /* ------------------------------------------------------------- plumbing */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  /* A 404 from these endpoints almost always means the package shipped without
     its clear_caches migration having run, so the compiled route table does not
     know these paths. Said plainly rather than drawing an empty screen. */
  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Checkout page endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  /* -------------------------------------------------------- sidebar entry */
  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Checkout page',
      icon: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>',
      group: 'Appearance',
      after: ['cartpage', 'cartpanel', 'dividers']
    });
  }

  /* ------------------------------------------------------------ the route */
  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Checkout page';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  /* ----------------------------------------------------------------- data */
  async function load() {
    var mine = ++seq;
    busy = true;
    banner = null;
    render();

    try {
      var body = await api('/checkout-page');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      mobileMax = body.mobileMax || 900;
      squeezeKeys = body.squeeze || [];
      values = {};
      tabs.forEach(function (t) {
        t.fields.forEach(function (f) { values[f.key] = f.value; });
      });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Checkout page settings could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function save() {
    if (busy) return;
    busy = true;
    render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      await api('/checkout-page', { settings: payload });
      say('Checkout page saved.');
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false;
      render();
    }
  }

  /* ------------------------------------------------------------- controls */
  function shown(f) {
    var o = f.options || {};
    return String(values[f.key]) + (o.unit || '');
  }

  /* `d_sticky_top` is read only while `d_sticky` is on, so it leaves the
     screen when the switch is off rather than sitting there doing nothing. */
  function hidden(f) {
    if (f.key === 'd_sticky_top') return !pvOn('d_sticky');
    /* Both only mean anything while the line above them is drawn at all. */
    if (f.key === 'rating_text' || f.key === 'rating_min') return !pvOn('rating_on');

    return false;
  }

  /** The schema row for a key, across every tab. */
  function fieldFor(key) {
    var out = null;

    (tabs || []).forEach(function (t) {
      t.fields.forEach(function (f) { if (f.key === key) out = f; });
    });

    return out;
  }

  function fieldHTML(f) {
    if (hidden(f)) return '';

    var id = 'chp-' + f.key;
    var help = f.help ? '<p class="chp-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="chp-f"><div class="chp-check">'
        + '<input type="checkbox" id="' + id + '" data-chp-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    /*
     * SELECT AND TEXT, AND WHY THEY ARE HERE RATHER THAN NOT.
     *
     * Everything that was not a checkbox used to fall through to the range
     * branch below. `ph_tone` and `ph_weight` shipped in 2.60.252 and `m_float`
     * in 2.60.253, all three of them selects, and every one of them rendered as
     * `<input type="range" min="undefined" max="undefined" value="muted">` — a
     * slider with no scale showing a value it cannot represent — and then saved
     * as NaN, because the input handler read `Number(el.value)`. A control that
     * cannot be read and cannot be stored is worse than a missing one: the
     * screen said the setting existed.
     *
     * The handler now branches on the field's own type rather than on the DOM
     * element's, which is what made a select indistinguishable from a range in
     * the first place.
     */
    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '') + '>'
          + esc(f.options[k]) + '</option>';
      }).join('');

      return '<div class="chp-f"><div class="chp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select class="chp-sel" id="' + id + '" data-chp-key="' + esc(f.key) + '">' + opts + '</select>'
        + help + '</div>';
    }

    if (f.type === 'text') {
      return '<div class="chp-f"><div class="chp-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<input class="chp-text" type="text" id="' + id + '" data-chp-key="' + esc(f.key) + '"'
        + ' value="' + esc(values[f.key] == null ? '' : values[f.key]) + '"'
        + ' placeholder="' + esc(f['default'] == null ? '' : f['default']) + '">'
        + help + '</div>';
    }

    var o = f.options || {};
    return '<div class="chp-f"><div class="chp-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
      + '<span class="chp-val" data-chp-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
      + '<input type="range" id="' + id + '" data-chp-key="' + esc(f.key) + '"'
      + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
      + help + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Checkout page') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="chp-wrap"><div class="chp-card"><div class="chp-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="chp-wrap"><div class="chp-card">'
        + '<div class="chp-title">Checkout page</div>'
        + '<p class="chp-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="chp-actions"><button class="chp-btn" data-chp-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = tabs.map(function (t) {
      return '<button type="button" class="chp-tab" data-chp-tab="' + esc(t.key) + '"'
        + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
    }).join('');

    var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];

    /* Two columns on the mobile tabs, one everywhere else. The controls and
       the notes go in a column of their own so grid auto-placement cannot put
       a note beside the preview and the card under it. */
    var side = /^mobile/.test(String(open));

    host.innerHTML = '<div class="chp-wrap' + (side ? ' chp-side' : '') + '">'
      + (side ? '<div class="chp-col">' : '')
      + (banner ? '<div class="chp-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + '<div class="chp-note">Spacing only. Nothing on this screen adds, removes or reorders a '
      + 'section — the checkout has the same four numbered sections at every value of every '
      + 'control below, and the words in them are translated copy that lives in the language files.</div>'
      + '<div class="chp-card">'
      + '<div class="chp-tabs">' + strip + '</div>'
      + '<p class="chp-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
      + '<div class="chp-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
      + '<div class="chp-actions">'
      + '<button class="chp-btn is-primary" data-chp-save' + (busy ? ' disabled' : '') + '>'
      + (busy ? 'Saving…' : 'Save') + '</button>'
      + '<button class="chp-btn" data-chp-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
      /* THE PRESETS WRITE THE SLIDERS AND NOTHING ELSE. They move the values
         on screen; nothing is stored until Save, so both are undone by
         Reload and either can be nudged afterwards. A stored "squeezed" mode
         would leave every slider showing a number the page was not using. */
      + '<button class="chp-btn" data-chp-squeeze' + (busy ? ' disabled' : '') + '>Squeeze everything</button>'
      + '<button class="chp-btn" data-chp-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
      + '</div>'
      + '<p class="chp-help" style="margin-top:9px">Both presets move the sliders in front of you across '
      + '<b>every tab</b>, on desktop and mobile. Nothing is stored until you press Save, and Reload puts '
      + 'them back.</p>'
      + '</div>'
      + (side ? '</div>' : '')
      + previewHTML()
      + '</div>';
  }

  /* --------------------------------------------------------------- presets */
  /*
   * `min` drives the keys the server named to the bottom of their own range;
   * `default` puts EVERY key back to the value the schema ships. The two are
   * deliberately not symmetrical: squeezing is a look, applied to the controls
   * that make a page denser, while "back to defaults" has to be able to undo
   * anything at all or it is not a way out.
   */
  function preset(which) {
    if (!tabs) return;

    tabs.forEach(function (t) {
      t.fields.forEach(function (f) {
        if (which === 'default') { values[f.key] = f['default']; return; }
        if (f.type !== 'range') return;
        if (squeezeKeys.indexOf(f.key) === -1) return;
        values[f.key] = Number((f.options || {}).min);
      });
    });

    render();
    say(which === 'min'
      ? 'Squeezed. Nothing is saved until you press Save.'
      : 'Back to the shipped values. Nothing is saved until you press Save.');
  }

  /* -------------------------------------------------------------- preview */
  function pvNum(key, fallback) {
    var v = Number(values[key]);
    return isFinite(v) ? v : fallback;
  }
  function pvOn(key) { return values[key] === true || values[key] === 1 || values[key] === '1'; }

  /* A desktop measurement as a share of the owner's own page width, so the
     mock holds the shop's proportions whatever size it is drawn at. Clamped:
     `d_max` cannot be zero from the schema, but a settings row hand-edited to
     0 would divide by it, and a preview that throws is a blank screen where
     the controls used to be. */
  function pvPct(key, fallback) {
    var max = pvNum('d_max', 1040) || 1040;
    return ((pvNum(key, fallback) / max) * 100).toFixed(2) + '%';
  }

  /* The phone mock is 320px of frame standing for a 390px screen, so every
     stored pixel is drawn at this share of itself. Rounded to one decimal:
     the point of the preview is proportion, and a 0.05px difference is not
     one the owner can see. */
  var PHONE = 320, PHONE_REAL = 390;
  function pvPx(key, fallback) {
    return (pvNum(key, fallback) * (PHONE / PHONE_REAL)).toFixed(1) + 'px';
  }

  /* The four sections, drawn once and used by both mocks. Section 2 is the
     address box, because that is what replaced Shipping address. */
  function pvSections() {
    return '<div class="chv-box">'
      + '<div class="chv-sec"><h6><em>1</em>Contact</h6>'
      + '<div class="chv-fg"><span><b class="chv-lb">Full name</b>'
      + '<span class="chv-fi" style="display:block">First and last name</span></span>'
      /* Phone then email, which is the order the page posts them in. */
      + '<span class="chv-fg two" style="display:grid">'
      + '<span><b class="chv-lb">Phone</b><span class="chv-fi" style="display:block">+971 5x xxx xxxx</span></span>'
      + '<span><b class="chv-lb">Email address</b><span class="chv-fi" style="display:block">you@email.com</span></span>'
      + '</span>'
      + (pvOn('optin_on')
          ? '<span style="display:flex;gap:6px;align-items:center;margin-top:2px">'
            + '<span style="flex:none;width:11px;height:11px;border-radius:3px;border:1.4px solid '
            + (pvOn('optin_checked') ? '#c13a5e;background:#c13a5e' : '#cbd5e1') + '"></span>'
            + '<span class="chv-lb" style="margin:0">Send me order updates and new offers</span></span>'
          : '')
      + '</div></div>'
      + '<div class="chv-sec"><h6><em>2</em>Shipping address</h6>' + pvAddress() + '</div>'
      + '<div class="chv-sec"><h6><em>3</em>Delivery</h6>'
      + '<div class="chv-pick"><span>Standard</span><span>AED 20</span></div>'
      + (pvOn('notes_on')
          ? '<div style="margin-top:6px"><b class="chv-lb">Delivery notes</b>'
            + '<span class="chv-fi" style="display:block">Delivery instructions, a landmark</span></div>'
          : '')
      + '</div>'
      + '<div class="chv-sec"><h6><em>4</em>Payment</h6>'
      + '<div class="chv-pick"><span>Cash on delivery</span><span>&#9673;</span></div></div>'
      + '</div>'
      + pvTrust();
  }

  /* THE ADDRESS SECTION HAS TWO STATES AND THE CUE LIVES ON ONLY ONE OF THEM.
     The Attention & trust tab is the tab where that state is what is being
     set, so it is the one that draws it. Every other tab draws the chosen row,
     which is what a shopper sees for the rest of the page's life. */
  function pvAddress() {
    if (open !== 'cues') {
      return '<div class="chv-ad"><span class="ic">&#9750;</span>'
        + '<span class="tx"><b>Home</b><i>Al-Thumama - area 46, street 912 - Doha</i></span>'
        + '<span class="go">Change</span></div>';
    }

    var home = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 10.5 12 3.5l8.5 7"/>'
      + '<path d="M5.5 9.7V20h13V9.7"/><path d="M10 20v-5.2h4V20"/></svg>';
    var office = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7.5" width="18" height="12" rx="2"/>'
      + '<path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/></svg>';

    return '<div class="chv-cue">'
      + '<span class="chv-cueic"><i class="h">' + home + '</i><i class="o">' + office + '</i></span>'
      + '<span class="pr">Please choose your delivery address</span>'
      + '<span class="chv-ar"><em></em><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"'
      + ' stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg></span>'
      + '<span class="chv-cta">+ Address</span>'
      + '</div>';
  }

  /* The block under Payment. Drawn on every tab, because the tick is the thing
     the owner is timing and a preview that hides it on seven tabs out of nine
     is a preview of the wrong page. */
  function pvTrust() {
    return '<div class="chv-trust">'
      + '<div class="chv-tl"><span class="st">&#9733;&#9733;&#9733;&#9733;&#9733;</span>'
      + '<span>4.8 · loved by UAE customers</span></div>'
      + '<div class="chv-tl">'
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round">'
      + '<path class="chv-shield" d="M12 3l7 3v6c0 4-3 7-7 8-4-1-7-4-7-8V6z"/>'
      + '<path class="chv-tick" d="M9 12l2 2 4-4"/></svg>'
      + '<span>100% authentic K-beauty</span></div>'
      + '</div>';
  }

  function pvLead() {
    return '<div class="chv-lead"><b>Checkout</b><i>Almost glowing — just a few details.</i></div>'
      + '<div class="chv-coupon">&#127873; Have a discount code?<u>Enter promo code</u></div>';
  }

  /* Three lines, because two hides what the row spacing does to a list and
     four does not fit the phone frame's peek. The names are long enough to
     reach the ellipsis, which is the state the owner has to be able to see. */
  var PV_ITEMS = [
    {n: 'Hyaluronic Acid Watery Sun Gel', q: 1, p: 'AED 221', c: '#f6c98a,#efab72'},
    {n: 'Barrier Repair Cream', q: 2, p: 'AED 182', c: '#f5a8b8,#e98598'},
    {n: 'Collagen Night Mask', q: 1, p: 'AED 40', c: '#bfa6f2,#a387e8'}
  ];

  function pvRows() {
    return '<div class="chv-items">' + PV_ITEMS.map(function (it) {
      return '<div class="chv-ci">'
        + '<span class="th" style="background:linear-gradient(135deg,' + it.c + ')"><i>' + it.q + '</i></span>'
        + '<span class="info"><b>' + esc(it.n) + '</b>'
        + '<span class="qty"><u>&minus;</u><em>' + it.q + '</em><u>+</u></span></span>'
        + '<span class="pr">' + esc(it.p) + '</span>'
        + '</div>';
    }).join('') + '</div>';
  }

  function pvSummary() {
    return '<div class="chv-sum">'
      + pvRows()
      + '<div class="sr"><span>Subtotal</span><span>AED 443</span></div>'
      + '<div class="sr"><span>Delivery</span><span>AED 20</span></div>'
      + '<div class="tot"><span>Total</span><span>AED 463</span></div>'
      + '<div class="go">Place order</div>'
      + '</div>';
  }

  /* The header and the six type factors, for whichever surface is showing.
     `px` is the scaler the surface uses -- 1:1 on the desktop mock, the phone
     frame's 320/390 on the other -- so one function serves both. */
  function pvChrome(p, px) {
    return ';--chv-headpady:' + px(p + 'head_pad_y', 14)
      + ';--chv-headpadx:' + px(p + 'head_pad_x', 20)
      + ';--chv-headlogo:' + px(p + 'head_logo', 20)
      + ';--chv-headbadge:' + px(p + 'head_badge', 12)
      + ';--chv-ttitle:' + (pvNum(p + 't_title', 100) / 100)
      + ';--chv-tlead:' + (pvNum(p + 't_lead', 100) / 100)
      + ';--chv-th2:' + (pvNum(p + 't_h2', 100) / 100)
      + ';--chv-tlabel:' + (pvNum(p + 't_label', 100) / 100)
      + ';--chv-tinput:' + (pvNum(p + 't_input', 100) / 100)
      + ';--chv-tph:' + (pvNum(p + 't_ph', 100) / 100)
      /* The placeholder's look is SHARED, so it is read without the surface
         prefix -- the same one value on both mocks, which is what the page
         does. */
      + ';--chv-phw:' + esc(String(values.ph_weight || 400))
      + ';--chv-phc:' + ({muted:'#9aa0aa',faint:'#bdb6ba',ink:'#6b6469',pink:'#d4789a'}[String(values.ph_tone || 'muted')] || '#9aa0aa')
      + ';--chv-phi:' + (pvOn('ph_italic') ? 'italic' : 'normal')
      + ';--chv-ttrust:' + (pvNum(p + 't_trust', 100) / 100)
      + ';--chv-cues:' + (pvNum('addr_cue_size', 100) / 100)
      /* Speed inverted into a duration, exactly as CheckoutPage::inverse()
         does it, so the preview and the page cannot disagree about which way
         the slider runs. */
      + ';--chv-cuet:' + (100 / Math.max(1, pvNum('addr_cue_speed', 100))).toFixed(3)
      + ';--chv-tickt:' + (100 / Math.max(1, pvNum('trust_tick_speed', 100))).toFixed(3);
  }

  /* The cue's off switches, as classes on the mock -- same names, same shape
     as the storefront's, so one reads as the other. */
  function pvCueClass() {
    return (pvOn('addr_cue') ? '' : ' chv-nocue')
      + (pvOn('addr_cue_icons') ? '' : ' chv-nocue-ic')
      + (pvOn('addr_cue_arrow') ? '' : ' chv-nocue-ar')
      + (pvOn('addr_cue_pulse') ? '' : ' chv-nocue-pu')
      + (pvOn('trust_tick') ? '' : ' chv-notick');
  }

  function previewDesktop() {
    var vars = '--chv-aside:' + pvPct('d_aside', 380)
      + ';--chv-gap:' + pvPct('d_gap', 26)
      + ';--chv-padx:' + pvPct('d_pad_x', 20)
      + ';--chv-pady:' + pvPct('d_pad_y', 22)
      + ';--chv-block:' + pvPct('d_block_gap', 16)
      + ';--chv-secpad:' + pvPct('d_sec_pad', 16)
      + ';--chv-asidepad:' + pvPct('d_aside_pad', 17)
      // The rows, at the size they are actually set to -- see the note by
      // .chv-ci. A percentage would be wrong here whatever it said: these boxes
      // sit inside the summary column, so a percentage resolves against THAT
      // and not against the page the rest of the mock is proportioned to.
      + ';--chv-rowh:' + pvNum('d_row_h', 54) + 'px'
      + ';--chv-rowp-t:' + pvNum('d_row_pt', 10) + 'px'
      + ';--chv-rowp-r:' + pvNum('d_row_pr', 0) + 'px'
      + ';--chv-rowp-b:' + pvNum('d_row_pb', 10) + 'px'
      + ';--chv-rowp-l:' + pvNum('d_row_pl', 0) + 'px'
      + ';--chv-rowgap:' + pvNum('d_row_gap', 11) + 'px'
      + ';--chv-rowf:' + (pvNum('d_row_font', 100) / 100)
      + ';--chv-qtys:' + (pvNum('d_qty_size', 100) / 100)
      + ';--chv-rowb:' + (pvOn('d_row_bold') ? 600 : 400)
      + ';--chv-rowpb:' + (pvOn('d_row_bold') ? 700 : 500)
      + ';--chv-headmax:' + pvPct('d_head_max', 1040)
      + pvChrome('d_', function (k, d) { return pvNum(k, d) + 'px'; });

    var side = pvOn('d_sticky')
      ? '<div class="chv-stick">' + pvSummary() + '</div>'
      : pvSummary();

    return '<div class="chv-desk' + pvCueClass() + '" style="' + vars + '">'
      + '<div class="chv-bar"><i></i><i></i><i></i><span>Wider than ' + mobileMax + 'px</span></div>'
      + '<div class="chv-page"><span class="chv-headband"><b>K-BeautyBliss</b>'
      + '<span>&#128274; Secure checkout</span></span></div>'
      + (pvOn('d_head_sticky') ? '<div class="chv-pin">stays at the top while scrolling</div>' : '')
      + '<div class="chv-cols"><div>' + pvLead() + pvSections() + '</div><div>' + side + '</div></div>'
      + '</div>'
      + '<div class="chv-ruler">Page width <b>' + pvNum('d_max', 1040) + 'px</b>'
      + '<span>·</span>summary column <b>' + pvNum('d_aside', 380) + 'px</b>'
      + '<span>·</span>the form takes what is left'
      + '<span>·</span>header and summary rows drawn at <b>1:1</b></div>';
  }

  function previewMobile() {
    var vars = '--chv-padx:' + pvPx('m_pad_x', 20)
      + ';--chv-pady:' + pvPx('m_pad_y', 22)
      + ';--chv-mgap:' + pvPx('m_gap', 14)
      + ';--chv-block:' + pvPx('m_block_gap', 16)
      + ';--chv-secpad:' + pvPx('m_sec_pad', 16)
      + ';--chv-asidepad:' + pvPx('m_aside_pad', 14)
      + ';--chv-rowh:' + pvPx('m_row_h', 54)
      + ';--chv-rowp-t:' + pvPx('m_row_pt', 10)
      + ';--chv-rowp-r:' + pvPx('m_row_pr', 0)
      + ';--chv-rowp-b:' + pvPx('m_row_pb', 10)
      + ';--chv-rowp-l:' + pvPx('m_row_pl', 0)
      + ';--chv-rowgap:' + pvPx('m_row_gap', 11)
      + ';--chv-rowf:' + (pvNum('m_row_font', 100) / 100)
      + ';--chv-qtys:' + (pvNum('m_qty_size', 100) / 100)
      + ';--chv-rowb:' + (pvOn('m_row_bold') ? 600 : 400)
      + ';--chv-rowpb:' + (pvOn('m_row_bold') ? 700 : 500)
      + ';--chv-headmax:100%'
      + pvChrome('m_', pvPx);

    /* The summary sits FIRST on a phone — it is `order:-1` in the stylesheet —
       and the Place order box is a block of its own at the foot. Drawn in that
       order here so the mock is the page and not a rearrangement of it. */
    return '<div class="chv-phone' + pvCueClass() + '" style="' + vars + '">'
      + '<div class="chv-bar"><i></i><i></i><i></i><span>' + mobileMax + 'px and below</span></div>'
      + '<div class="chv-page"><span class="chv-headband"><b>K-BeautyBliss</b>'
      + '<span>&#128274;</span></span></div>'
      + (pvOn('m_head_sticky') ? '<div class="chv-pin">stays at the top while scrolling</div>' : '')
      + '<div class="chv-stack">'
      + pvSummary()
      + '<div>' + pvLead() + pvSections()
      + '<div class="chv-mob-order"><div class="sr" style="display:flex;justify-content:space-between;'
      + 'font-size:9.5px"><span>Total</span><b>AED 463</b></div><div class="go">Place order</div></div>'
      + '</div>'
      + '</div>'
      + '</div>'
      /* Outside the frame. Inside a 320px one this line wrapped to four
         ragged rows and read as a layout fault in the thing it is measuring. */
      + '<div class="chv-ruler">Drawn at <b>' + PHONE + 'px</b> for a <b>' + PHONE_REAL + 'px</b> phone'
      + '<span>·</span>page padding <b>' + pvNum('m_pad_x', 20) + 'px</b> each side'
      + '<span>·</span>section padding <b>' + pvNum('m_sec_pad', 16) + 'px</b>'
      + '<span>·</span>header and summary rows scaled to match the frame</div>';
  }

  function previewHTML() {
    /* Two of the four tabs are the phone. Matched on the prefix rather than
       listed, so a tab added later previews the surface its name claims
       instead of silently falling through to the desktop mock. */
    var body = /^mobile/.test(String(open)) ? previewMobile() : previewDesktop();

    return '<div class="chp-card" data-chp-preview>'
      + '<div class="chv-h"><b>Preview</b><span>Redraws as you drag. A drawing, not the live page.</span></div>'
      + body + '</div>';
  }

  /* Repaint without rebuilding the controls — rebuilding them mid-drag drops
     the pointer capture and the slider stops following the finger. */
  function paintPreview() {
    var node = document.querySelector('[data-chp-preview]');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
  }

  /* --------------------------------------------------------------- events */
  document.addEventListener('input', function (e) {
    var el = e.target.closest('[data-chp-key]');
    if (!el) return;

    var key = el.getAttribute('data-chp-key');
    var fld = fieldFor(key);
    var kind = fld ? fld.type : (el.type === 'checkbox' ? 'bool' : 'range');

    /* BY THE FIELD'S TYPE, NOT THE ELEMENT'S. `Number(el.value)` on a select
       stored NaN for every select on this screen — see fieldHTML. */
    if (kind === 'bool') values[key] = el.checked;
    else if (kind === 'select' || kind === 'text') values[key] = String(el.value);
    else values[key] = Number(el.value);

    /* A checkbox can decide whether another control belongs on the screen --
       `d_sticky` does -- so a checkbox redraws rather than only repainting.
       The two bold switches do not need it, but paying a full redraw on a
       click nobody is dragging costs nothing and one rule is one rule. */
    /* A checkbox or a select can decide whether another control belongs on the
       screen, so both redraw rather than only repainting. A text field must
       NOT: a redraw on every keystroke would take the caret to the end of the
       line on the second character. */
    if (el.type === 'checkbox' || el.tagName === 'SELECT') { render(); return; }

    var out = document.querySelector('[data-chp-val="' + key + '"]');
    if (out) {
      var f = null;
      tabs.forEach(function (t) {
        t.fields.forEach(function (x) { if (x.key === key) f = x; });
      });
      if (f) out.textContent = shown(f);
    }
    paintPreview();
  });

  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-chp-tab]');
    if (tab) { open = tab.getAttribute('data-chp-tab'); render(); return; }

    if (e.target.closest('[data-chp-squeeze]')) { preset('min'); return; }
    if (e.target.closest('[data-chp-defaults]')) { preset('default'); return; }
    if (e.target.closest('[data-chp-save]')) { save(); return; }
    if (e.target.closest('[data-chp-reload]')) { load(); return; }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
</script>
@endverbatim
