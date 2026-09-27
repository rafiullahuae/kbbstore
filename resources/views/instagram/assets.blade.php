{{--
    Instagram Profile — the stylesheet and the one script, once per page.

    Phase 21, Lane IG. Pushed by instagram/section.blade.php on the FIRST section on
    a page only; App\Support\Shortcodes::instagram() decides which one that is, for
    the reason its docblock gives at length (Blade's @once is per render cycle and
    every section is its own render call).

    ── FIVE LAYOUTS, AND NOT ONE LINE OF JAVASCRIPT MEASURES ANYTHING ───────

    Rule 4 forbids the element-measuring APIs by name. CheckoutFloatingBarGateTest
    and CartPageSqueezeTest each hold the list for their own files, and
    InstagramSectionShapeTest holds it for this one — greping THIS FILE for each of
    them, which is why not one of those names is spelled out anywhere below, in the
    code or in the prose. A comment naming the API it promises not to use is a file
    that fails its own guard, and this file did exactly that on the first run.

    Every layout is therefore a CLASS plus arithmetic the browser does once:

      .is-grid     grid, repeat(auto-fit) with a minmax floor
      .is-mosaic   the same grid, with the first cell spanning 2 x 2
      .is-masonry  the same grid, with a 4/5 aspect-ratio on the tile
      .is-rail     a scrolling track, tile width from --ig-w (a calc())
      .is-strip    the same track, smaller tiles

    `aspect-ratio` is what keeps every tile square without knowing the width, and it
    is why there is nothing to measure. `grid-template-columns` does the dividing.

    ── AND WHY THE CSS IS HERE RATHER THAN IN resources/css ─────────────────

    The storefront serves BUILT css: the Vite directive resolves to a hashed file
    under a web root that is a different directory from the application, and
    `package.json` defines no build script (CLAUDE.md), so building it is a manual
    step nobody runs during an update. (The directive's own name is not written here
    either — InstagramSectionShapeTest greps this file for it, to prove the
    stylesheet below is not quietly replaced by a bundle reference one day.) A rule added to kbb.css therefore ships INERT until somebody
    rebuilds the bundle. Emitted here it is part of the page and cannot be stale —
    the same argument HomepageSections::orderStyle() makes for its own element, and
    the one resources/views/ugc/assets.blade.php already made for the video rail.

    It also means the cost lands only on pages that actually carry a section.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included —
    with the next closing one.

    EVERY CLASS IS PREFIXED igp- so nothing here can reach another lane's markup.
--}}
@verbatim
<style id="kbb-ig-style">
.igp{--ig-w:auto;--ig-gap:8px;--ig-r:10px;margin:0}
.igp *{box-sizing:border-box;min-width:0}
.igp-h{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 12px}
.igp-h h2{margin:0;font-size:clamp(17px,4.2vw,22px);font-weight:700;line-height:1.25}
.igp-h a{font-size:13px;text-decoration:none;color:#15a85a;font-weight:600}

/* ── the profile box ───────────────────────────────────────────────────────
   `card`, `bar` and `inline` are three classes on one markup block rather than
   three blocks: one set of escaping, one set of alt text, one place a change lands. */
.igp-p{display:flex;align-items:center;gap:12px;margin:0 0 14px}
.igp-p img{border-radius:50%;object-fit:cover;flex:0 0 auto;background:#f3f4f6}
.igp-p.is-card{padding:13px 14px;border:1px solid #e6e6e6;border-radius:calc(var(--ig-r) + 4px);background:#fff}
.igp-p.is-card img{width:64px;height:64px}
.igp-p.is-bar img{width:36px;height:36px}
.igp-pn{display:grid;gap:2px;min-width:0}
.igp-pn b{font-size:15px;font-weight:700;line-height:1.2;overflow-wrap:anywhere}
.igp-pn span{font-size:12.5px;color:#6b7280;line-height:1.35;overflow-wrap:anywhere}
.igp-pf{margin-inline-start:auto;flex:0 0 auto;padding:8px 14px;border-radius:999px;background:#15a85a;color:#fff;
        font-size:13px;font-weight:650;text-decoration:none;white-space:nowrap}
.igp-p.is-bar{gap:9px;margin-bottom:10px}
.igp-p.is-bar .igp-pn b{font-size:13.5px}
.igp-p.is-bar .igp-pn span{font-size:11.5px}

/* ── the tiles ──────────────────────────────────────────────────────────── */
.igp-t{display:grid;gap:var(--ig-gap)}
.igp-t.is-grid,.igp-t.is-mosaic{grid-template-columns:repeat(2,minmax(0,1fr))}
.igp-t.is-masonry{grid-template-columns:repeat(2,minmax(0,1fr))}

/* A TRACK, NOT A GRID. --ig-w is the only property the two scrolling layouts
   need, and InstagramSettings::cssVariables() computes it with the gap subtracted
   exactly — n tiles carry (n-1) whole gaps plus the fraction of one belonging to
   the partly visible tile. Approximating that is how a rail overflows by 24px. */
.igp-t.is-rail,.igp-t.is-strip{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;
  -webkit-overflow-scrolling:touch;scrollbar-width:none;padding-bottom:2px}
.igp-t.is-rail::-webkit-scrollbar,.igp-t.is-strip::-webkit-scrollbar{display:none}
.igp-t.is-rail > *,.igp-t.is-strip > *{flex:0 0 var(--ig-w);scroll-snap-align:start}

.igp-c{position:relative;display:block;overflow:hidden;border-radius:var(--ig-r);
       background:#f3f4f6;aspect-ratio:1/1;text-decoration:none;color:inherit}

/* THE STRETCHED LINK. The cell is always a <div>; when the post has somewhere to
   go the template puts this empty <a> in it, last, covering the whole tile. That
   is what lets the counts and the caption sit OUTSIDE the link — a screen reader
   then reads the tile's own label rather than the whole overlay as the link name —
   while a tap anywhere on the picture still opens the post. z-index over the
   scrim and the caption, both of which are decoration. */
.igp-c > .igp-lk{position:absolute;inset:0;z-index:2;border-radius:inherit;
       -webkit-tap-highlight-color:transparent}
.igp-c > .igp-lk:focus-visible{outline:2px solid #15a85a;outline-offset:-3px}
.igp-t.is-masonry .igp-c{aspect-ratio:4/5}
.igp-c > img{display:block;width:100%;height:100%;object-fit:cover}

/* THE MOSAIC'S FIRST CELL, and it is one rule rather than a layout of its own.
   `grid-row: span 2` needs square rows to span, which repeat(2,1fr) plus the
   tile's own aspect-ratio already gives. */
.igp-t.is-mosaic > :first-child{grid-column:span 2;grid-row:span 2}

/* The play triangle: a pseudo-element, so a video tile costs no extra node and
   the markup cannot forget to close it. */
.igp-c.is-video::after{content:"";position:absolute;inset-block-start:50%;inset-inline-start:50%;
  translate:-50% -50%;width:44px;height:44px;border-radius:50%;background:rgba(0,0,0,.42);
  backdrop-filter:blur(2px);
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23fff'%3E%3Cpath d='M9 6.5v11l9-5.5z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:54% 50%;background-size:22px}
.igp-t.is-strip .igp-c.is-video::after{width:30px;height:30px;background-size:15px}

/* The carousel mark, same technique, opposite corner so the two never collide. */
.igp-c.is-album > .igp-alb{position:absolute;inset-block-start:7px;inset-inline-end:7px;width:17px;height:17px;
  opacity:.94;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45))}

/* ── the counts, and the scrim that makes them readable ───────────────────
   One gradient, drawn only when there is something to put on it — the element is
   absent for a post whose numbers we do not have, which is the whole of
   docs/UGC-ENGAGEMENT.md's rule in CSS. */
.igp-m{position:absolute;inset-inline:0;inset-block-end:0;display:flex;gap:11px;align-items:center;
  padding:16px 9px 7px;color:#fff;font-size:12px;font-weight:600;line-height:1;
  background:linear-gradient(to top,rgba(0,0,0,.56),rgba(0,0,0,0))}
.igp-m span{display:inline-flex;gap:4px;align-items:center;font-variant-numeric:tabular-nums}
.igp-m svg{width:13px;height:13px;flex:0 0 auto}
.igp-cap{position:absolute;inset:0;display:flex;align-items:flex-end;padding:9px;color:#fff;font-size:11.5px;
  line-height:1.4;background:rgba(0,0,0,.44);opacity:0;transition:opacity .18s ease}
/* `:focus-within` and not `:focus-visible` on the cell: the focusable element is
   the stretched <a> INSIDE it now, so the cell itself never takes focus. */
.igp-c:hover .igp-cap,.igp-c:focus-within .igp-cap{opacity:1}
.igp-cap span{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}

/* The word "likes" beside the number, for a screen reader only. A heart icon plus a
   figure reads as "14523" to somebody who cannot see the heart, which is a number
   with no unit. Clipped rather than display:none, because display:none is removed
   from the accessibility tree too and would take the word with it. */
.igp-sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;
  clip-path:inset(50%);white-space:nowrap;border:0}

/* ── the lightbox ────────────────────────────────────────────────────────
   Hidden with `display:none` in the stylesheet and shown by a class, so a page
   whose script never runs shows nothing rather than a black overlay over the shop. */
.igp-box{display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.82);
  padding:16px;align-items:center;justify-content:center}
.igp-box.is-open{display:flex}
.igp-box iframe{width:min(100%,420px);height:min(86vh,720px);border:0;border-radius:12px;background:#fff}
.igp-x{position:absolute;inset-block-start:10px;inset-inline-end:12px;width:38px;height:38px;border-radius:50%;
  border:0;background:rgba(255,255,255,.16);color:#fff;font-size:21px;line-height:1;cursor:pointer}

@media (min-width:640px){
  .igp-t.is-grid,.igp-t.is-mosaic,.igp-t.is-masonry{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media (min-width:900px){
  .igp-t.is-masonry{grid-template-columns:repeat(4,minmax(0,1fr))}
  .igp-t.is-rail > *{flex-basis:calc((100% - (var(--ig-gap) * 4.3)) / 5.3)}
  .igp-t.is-strip > *{flex-basis:calc((100% - (var(--ig-gap) * 7.5)) / 8.5)}
  .igp-p.is-card img{width:76px;height:76px}
}
@media (prefers-reduced-motion:reduce){
  .igp-cap{transition:none}
}
</style>
<script id="kbb-ig-script">
(function () {
  'use strict';

  /*
   * ── WHAT THIS SCRIPT IS ALLOWED TO DO, AND WHAT IT IS NOT ────────────────
   *
   * It opens one lightbox holding Instagram's own embed iframe, and closes it.
   * That is all. IT MEASURES NOTHING — not one of the element-geometry or computed-
   * style readers appears anywhere in this file, because rule 4 forbids them and
   * every size on this section is a calc(), a clamp() or an aspect-ratio in the
   * stylesheet above. InstagramSectionShapeTest greps this file for each of the
   * eight by name, so it is enforced rather than promised — and the names are
   * deliberately not repeated here, because a comment that lists them is a file
   * that fails that grep. (It did, on the first run.)
   *
   * ── AND THE IFRAME'S src IS SET ON TAP, NEVER AT PAGE LOAD ───────────────
   *
   * Which is the whole performance decision. An <iframe src> in the markup is a
   * request to instagram.com for every tile the moment the page paints — nine
   * third-party requests behind a homepage a shopper is waiting for, which is
   * exactly what docs/UGC-ENGAGEMENT.md's "nothing on the read path" rule exists to
   * prevent. Created on demand, a shop whose visitors never tap a tile makes ZERO
   * requests to Instagram, ever.
   *
   * The URL comes from a data attribute the template wrote from
   * InstagramPost::toTile()'s `embed`, which is a constant with a shortcode in it
   * that has matched InstagramPost::SHORTCODE_RE. Not one byte of it is a remote
   * string this script trusts.
   */

  var box = null, frame = null;

  function open(url) {
    if (!url) return;

    if (!box) {
      box = document.createElement('div');
      box.className = 'igp-box';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', 'Instagram post');

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'igp-x';
      close.setAttribute('aria-label', 'Close');
      /* textContent and not innerHTML. Nothing in this script ever assigns markup
         from a string, so there is no place for one to arrive from. */
      close.textContent = '×';

      frame = document.createElement('iframe');
      frame.setAttribute('allowtransparency', 'true');
      frame.setAttribute('allow', 'encrypted-media');
      frame.setAttribute('loading', 'lazy');
      frame.setAttribute('title', 'Instagram post');
      /* A SANDBOX WOULD BREAK IT, and this is worth a line so nobody adds one
         "for safety": Instagram's embed runs its own script and needs
         allow-scripts, and it needs allow-same-origin to reach its own CDN — which
         together are no sandbox at all for a same-site frame. The real containment
         is that this is a THIRD-PARTY ORIGIN, so it is already in its own process
         with no access to this page's DOM, cookies or storage. `referrerpolicy`
         keeps the shopper's exact page out of Instagram's logs. */
      frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

      box.appendChild(frame);
      box.appendChild(close);
      document.body.appendChild(box);
    }

    frame.src = url;
    box.classList.add('is-open');
  }

  function shut() {
    if (!box) return;
    box.classList.remove('is-open');
    /* Emptied, not just hidden: a hidden iframe carrying a video is a video still
       decoding, and on a phone that is a battery nobody agreed to spend. */
    if (frame) { frame.removeAttribute('src'); }
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    if (box && (t === box || t.closest('.igp-x'))) { e.preventDefault(); shut(); return; }

    var tile = t.closest('[data-ig-embed]');
    if (!tile) return;

    e.preventDefault();
    open(tile.getAttribute('data-ig-embed'));
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { shut(); }
  });
})();
</script>
@endverbatim
