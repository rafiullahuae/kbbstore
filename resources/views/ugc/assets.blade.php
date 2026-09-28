{{--
    The rail's stylesheet and its script, emitted ONCE per response.

    ── WHY THIS IS INLINE AND NOT A VITE ENTRY ──────────────────────────────

    Three reasons, in order of weight:

      1. resources/css/kbb/** and resources/views/layouts/** belong to Lane W1
         this round, and the vite manifest is loaded from
         layouts/store.blade.php's @vite call. Adding an entry means editing
         another lane's file.
      2. package.json defines no `build` script and CI does not build assets
         (CLAUDE.md, Known gaps), so a new stylesheet under resources/css/ would
         reach the server only if somebody remembered `npx vite build` before
         packaging. A rail whose CSS is missing is a column of unstyled text.
      3. It costs nothing on a page with no rail. The shortcode is what pulls this
         in, so a shop with one rail on the homepage carries it on the homepage and
         nowhere else.

    ONE PER PAGE, GUARDED BY THE CALLER AND NOT BY @once. Blade's @once is scoped
    to a render cycle, and every rail on a page is its own view()->render() call
    from App\Support\Shortcodes::videos() — so the counter is back at zero by the
    time the second rail starts and the directive fires again. Two rails shipped
    this file twice, which matters most for the script: it registers a delegated
    document click listener and an IntersectionObserver, so a second copy opens the
    player twice on one tap. The guard is a per-request flag in the container; see
    that method's own comment for why not a static.

    ▲ THE CONTENT-SECURITY POLICY. App\Services\Security\ContentSecurityPolicy
    ships as Content-Security-Policy-REPORT-ONLY with no 'unsafe-inline' and no
    nonce, and its own header counts the 24 inline <script> and 29 inline <style>
    blocks the storefront already carries. So this adds two more violations to a
    report that is measuring exactly that, and it refuses nothing today. When that
    module grows a nonce, these two tags take it the same way the other 53 do.

    ── EVERY NUMBER IN THE CSS BELOW IS R3's ────────────────────────────────

    Transcribed from docs/UGC-VIDEO-PREVIEWS.html rather than re-chosen, with the
    selectors renamed to this lane's ugcr- prefix. The tokens are R3's literal
    values and NOT var() references into kbb.css: another lane owns that file, and
    a palette change there must not silently redesign a rail the owner signed off.

    The one departure is the rating bar, which R3 draws on the poster and the owner
    moved into the product box. docs/UGC-RAIL-R3.md carries the element-by-element
    comparison and the measured deltas.

    ── NO JAVASCRIPT MEASURES LAYOUT ────────────────────────────────────────

    Rule 4, and two tests in this repo forbid the element-measuring APIs by name
    (getBoundingClientRect, offsetTop, offsetHeight, clientHeight, scrollY). There
    is none of that below. Every position is aspect-ratio, inset-inline, calc(),
    scroll-snap or a container query; what plays is decided by one
    IntersectionObserver, which is the sanctioned answer. There is no scroll
    handler, no resize listener and no requestAnimationFrame.
--}}
<style id="kbb-ugc-style">
/* ── tokens, R3's own, literal ─────────────────────────────────────────── */
.kbb-ugc{
  --ugc-ink:#2A2228; --ugc-ink2:#5E545A; --ugc-muted:#8C828A;
  --ugc-pink:#E0567B; --ugc-pink-deep:#C13E63; --ugc-gold:#BE8E2E;
  --ugc-sale:#E23A4E; --ugc-line:rgba(42,34,40,.10);
  /* The star inside the WHITE card. #FFC53D was chosen for a 78%-alpha scrim
     over video and is 1.7:1 on white, which fails the 3:1 non-text threshold.
     #A8760F is 3.99:1 on white — measured off the rendered pixels, not assumed.
     That is what moving the bar off the poster costs, and it is the only cost:
     the score is 14:1 and the count 7.3:1, so the scrim, the blur, the 1px
     border and the pill all go away. */
  --ugc-star:#A8760F;
  --ugc-sh:0 1px 2px rgba(42,34,40,.05),0 4px 14px rgba(42,34,40,.06);
  --ugc-ease:cubic-bezier(.22,.61,.36,1);
  font-family:"Poppins",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  line-height:1.5;
  color:var(--ugc-ink);
  -webkit-font-smoothing:antialiased;
  display:block;
  margin:0;
  padding:0;
}
/* The preview page's global reset, scoped to this subtree instead. Without it the
   rail inherits whatever the shop's own reset happens to be, and R3's geometry
   stops being R3's geometry.

   ▲ :where() AND IT IS LOAD-BEARING, NOT TIDINESS. :where() contributes ZERO
   specificity, so each rule below weighs exactly as much as `.kbb-ugc` — which is
   the same weight as `.ugcr-add`, so source order decides and the component rule
   wins. Written the obvious way, `.kbb-ugc button{padding:0}` is (0,1,1) and
   BEATS `.ugcr-add{padding:5px 8px}` at (0,1,0).

   THAT WAS NOT HYPOTHETICAL: the first version of this file did exactly that and
   the element-by-element run against R3 came back with the ADD button at padding
   0, background transparent, colour #2A2228 on #2A2228, font-size 16 and weight
   400 — an invisible label on a transparent pill. The preview gets this right by
   accident, because its reset is a bare `*` and a bare `button` at (0,0,0) and
   (0,0,1); scoping a reset to a class is what silently raises it above the
   components it is meant to sit under.

   `font-family` and NOT `font` on the button, for the same reason and found the
   same way: `font:inherit` also sets font-size and line-height, which made the
   42px play disc's box 16px/24px where R3's is the UA default 13.333px/normal. A
   button with no text in it still has a line box. */
.kbb-ugc :where(*),.kbb-ugc :where(*::before),.kbb-ugc :where(*::after){box-sizing:border-box}
.kbb-ugc :where(*){margin:0;padding:0}
.kbb-ugc :where(img),.kbb-ugc :where(video){max-width:100%;display:block}
.kbb-ugc :where(button){font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}

.ugcr-head{padding:0 16px 10px}
.ugcr-h2{font-size:19px;font-weight:800;letter-spacing:-.015em;line-height:1.25}
@media(min-width:900px){.ugcr-h2{font-size:25px}}
.ugcr-sub{font-size:13.5px;color:var(--ugc-ink2);margin-top:6px;max-width:66ch}

/* ── the rail. R3: gap 12, padding 2/16/12, snap x mandatory ───────────── */
.ugcr-rail{display:flex;gap:var(--ugc-gap,12px);overflow-x:auto;scroll-snap-type:x mandatory;
  padding:2px 16px 12px;-webkit-overflow-scrolling:touch;scrollbar-width:thin}
.ugcr-cell{scroll-snap-align:start;flex:0 0 auto;width:var(--ugc-w,158px)}
@media(min-width:900px){.ugcr-cell{width:var(--ugc-dw,206px)}}

/* The one column option that is not a track: two across, wrapping, no sideways
   scroll at all. --ugc-w is ignored here by design. */
.ugcr-sec.is-grid .ugcr-rail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
  overflow:visible;scroll-snap-type:none}
.ugcr-sec.is-grid .ugcr-cell{width:auto}
@media(min-width:900px){.ugcr-sec.is-grid .ugcr-rail{grid-template-columns:repeat(4,minmax(0,1fr))}}

/* ── the tile ──────────────────────────────────────────────────────────── */
.ugcr-t{position:relative;aspect-ratio:9/16;border-radius:var(--ugc-r,16px);overflow:hidden;
  background:#EDE6E9;box-shadow:var(--ugc-sh);isolation:isolate;
  /* The container the rating bar's count query measures against. */
  container-type:inline-size}
.ugcr-poster,.ugcr-t video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.ugcr-t video{opacity:0;transition:opacity .28s var(--ugc-ease)}
.ugcr-t video.is-on{opacity:1}
/* R3's scrim: 56% of the tile, and 72% once a card sits on it. */
.ugcr-t::after{content:'';position:absolute;inset:auto 0 0 0;height:56%;pointer-events:none;
  background:linear-gradient(180deg,rgba(0,0,0,0) 0%,rgba(0,0,0,.52) 78%,rgba(0,0,0,.66) 100%)}
.ugcr-t.has-card::after{height:72%}

.ugcr-over{position:absolute;inset:auto 0 0 0;padding:10px;z-index:2;color:#fff}
/* R3: the caption has to clear the card. At 56px it sat under it and read
   "Glass skin in 6". */
.ugcr-t.has-card .ugcr-over{bottom:80px}
.ugcr-handle{font-size:11.5px;font-weight:600;text-shadow:0 1px 3px rgba(0,0,0,.5);
  display:flex;align-items:center;gap:5px}
.ugcr-av{width:16px;height:16px;border-radius:99px;
  background:linear-gradient(135deg,var(--ugc-pink),var(--ugc-gold));
  display:block;flex:0 0 auto;border:1.2px solid rgba(255,255,255,.75)}
.ugcr-cap{font-size:12px;font-weight:600;margin-top:3px;text-shadow:0 1px 3px rgba(0,0,0,.55);
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ugcr-t.has-card .ugcr-cap{-webkit-line-clamp:1}

.ugcr-play{position:absolute;inset:0;z-index:3;display:grid;place-items:center;background:none}
.ugcr-play span{width:42px;height:42px;border-radius:99px;background:rgba(255,255,255,.22);
  -webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px);
  border:1.4px solid rgba(255,255,255,.5);display:grid;place-items:center;
  transition:.18s var(--ugc-ease)}
.ugcr-play span::before{content:'';border-inline-start:12px solid #fff;
  border-top:8px solid transparent;border-bottom:8px solid transparent;margin-inline-start:4px}
.ugcr-t.is-playing .ugcr-play span{opacity:0;transform:scale(.7)}
.ugcr-play:hover span{transform:scale(1.08)}
/* A border triangle has no logical form, so RTL flips it by hand — kbb.css
   carries exactly this pattern for the drawer, with a comment explaining the
   sign, and this follows it. */
[dir="rtl"] .ugcr-play span::before{transform:scaleX(-1)}

.ugcr-badge{position:absolute;top:8px;inset-inline-start:8px;z-index:2;font-size:10.5px;font-weight:700;
  color:#fff;background:rgba(20,14,18,.62);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);
  border-radius:99px;padding:3px 8px;display:flex;align-items:center;gap:4px}
.ugcr-badge svg{width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2}

/* ── R3's card, ON the poster ──────────────────────────────────────────── */
.ugcr-card{position:absolute;inset-inline:6px;bottom:6px;z-index:4;background:rgba(255,255,255,.96);
  -webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);border-radius:10px;padding:7px 8px;
  display:block;box-shadow:0 6px 18px -8px rgba(0,0,0,.5)}
/* THE BRAND LINE IS NOW A FLEX ROW, and that is the one structural change to
   R3. The brand yields (flex 0 1 auto + ellipsis) and the rating does not
   (flex 0 0 auto), because the rating is a fixed ~30px and the brand is the
   least load-bearing text in a card whose poster and product name already
   identify the product. */
.ugcr-bd{font-size:8px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
  color:var(--ugc-muted);display:flex;align-items:center;gap:6px;line-height:1.5}
.ugcr-bdn{min-width:0;flex:0 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ugcr-nm{font-size:10px;font-weight:600;line-height:1.3;margin-top:1px;color:var(--ugc-ink);
  text-decoration:none;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.ugcr-row2{display:flex;align-items:center;gap:8px;margin-top:2px}
.ugcr-pr{display:flex;align-items:baseline;gap:5px;margin-top:0;flex-wrap:wrap;flex:1;min-width:0}
.ugcr-now{font-size:11px;font-weight:800;color:var(--ugc-ink)}
.ugcr-was{font-size:9px;color:var(--ugc-muted);text-decoration:line-through}
.ugcr-off{font-size:8.5px;font-weight:800;color:#fff;background:var(--ugc-sale);
  border-radius:5px;padding:1px 4px;letter-spacing:.02em}
.ugcr-add{flex:0 0 auto;font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  background:var(--ugc-ink);color:#fff;border-radius:99px;padding:5px 8px;transition:.15s}
.ugcr-add:hover{background:var(--ugc-pink-deep)}

/* ── THE RATING BAR — thin, type only, and COSTING THE CARD 0px ─────────
   It rides the brand line, which is the only row in this card with spare inline
   space, so the card's height is identical with and without it. That was a
   requirement rather than a preference, and it is measured rather than asserted:
   docs/UGC-RAIL-R3.md carries the pair of numbers.

   line-height:1.5 on the row and on the bar, so both line boxes are
   proportional to their own font size and the tallest one decides the row. The
   9px score in a 12px row is the tallest thing here and it is what the row is
   sized to; the 8px brand rides in the same box.

   NO PILL, NO SCRIM, NO BLUR, NO BORDER. All four existed on the poster bar to
   buy contrast against video, and none of them is needed against white — which
   is the whole gain from moving it. #2A2228 on #FFF is 14:1, the count at
   #5E545A is 7.3:1, and the star at #A8760F is 3.99:1 against the 3:1 non-text
   threshold. */
.ugcr-rate{margin-inline-start:auto;flex:0 0 auto;display:flex;align-items:center;gap:3px;
  /* line-height:1 IS THE WHOLE OF "IT ADDS 0px", and it was measured rather than
     hoped for. The brand's line box is 8px x 1.5 = 12px and it sets the row's
     height. At line-height:1.5 the 9px score's box is 13.5px, it becomes the
     tallest thing in the flex line, and the card grows by exactly 1.5px — which
     the first run of the comparison reported as `rating cost delta: 1.5`. At
     line-height:1 the score's box is 9px, the brand's 12px still decides, and the
     delta is 0. align-items:center on both rows rather than baseline, because two
     different line-heights on one baseline is what put the star a pixel low. */
  line-height:1;letter-spacing:0;text-transform:none;font-variant-numeric:tabular-nums}
.ugcr-sc{font-size:9px;font-weight:800;color:var(--ugc-ink)}
.ugcr-star{font-style:normal;font-size:9px;color:var(--ugc-star)}
.ugcr-rc{font-size:8px;font-weight:600;color:var(--ugc-ink2)}
/* THE COUNT IS THE FIRST THING TO GO ON A NARROW TILE, answered in CSS and in
   one place rather than by a script that measures. At the designed 158px the
   longest brand in this catalogue ("Beauty of Joseon", 79px at 8px uppercase)
   plus a bar with the count does not fit the 130px of content width; without the
   count it does, with room to spare. At the 206px desktop tile both fit. The
   aria-label carries the count either way, so nothing is lost to a screen
   reader. */
@container (max-width:170px){ .ugcr-rate .ugcr-rc{display:none} }

/* ── engagement, and it ships off ──────────────────────────────────────── */
.ugcr-eng{position:absolute;top:8px;inset-inline-end:8px;z-index:4;display:flex;align-items:center;gap:7px;
  font-size:10px;font-weight:700;color:#fff;background:rgba(20,14,18,.62);
  -webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);border-radius:99px;padding:4px 8px;line-height:1}
.ugcr-eng em,.ugcr-like{font-style:normal;display:flex;align-items:center;gap:3px;color:inherit;
  font-size:10px;font-weight:700;line-height:1}
.ugcr-eng svg{width:11px;height:11px;fill:none;stroke:currentColor;stroke-width:2}
.ugcr-like.is-on svg{fill:currentColor}

/* ── the opened player ─────────────────────────────────────────────────────
   ── THE FRAME IS THE VIDEO, AND THAT IS THE WHOLE OF THIS BLOCK ───────────

   The owner, in his own words: *"the products boxes should come over the video
   at the bottom, not outside the video frame! and there should not top and
   bottom black weird space!!! only the video will popup, along with products
   box(es), and other credit etc will also come on the video frame."*

   What produced the black space was two lines that read as harmless:

       .ugcp-box{width:100%;height:100%;max-width:520px}
       .ugcp-v  {object-fit:contain}

   The BOX was sized to the VIEWPORT and the picture was then fitted inside it,
   so the box and the picture were different rectangles — and every overlay in
   here (`.ugcp-credit`, `.ugcp-rail`, `.ugcp-x`) is positioned against the BOX.
   Measured in Chromium against this repo, on the 9:16 clips this feature is for:

     390 x 844   box 390x844, picture 390x693  ->  75px of black above AND below;
                 the credit sat at y=14, ON the top band, and 76 of the product
                 rail's 163px hung below the picture's bottom edge at y=768.
     1280 x 800  box 520x800, picture 450x800  ->  35px of black each side; the
                 rail spanned the full 520 and overhung the picture at both ends.

   Those are the three complaints, in one cause.

   THE FIX IS ARITHMETIC, NOT MEASUREMENT. `--ugcp-ar` is the clip's own
   width÷height, printed by the server onto the tile from the same two columns
   the tile reserves its box from (`data-ugcr-ar` in ugc/rail.blade.php), and
   handed to this box by open(). The frame is then the largest rectangle of that
   ratio that fits — which is precisely the rectangle `object-fit:contain` would
   have drawn the picture in, computed in CSS instead of left to the video
   element. Box ratio and picture ratio are now the same number, so there is
   nothing to letterbox: `object-fit:cover` fills the frame edge to edge, and
   every overlay is positioned against a rectangle that IS the picture.

   Rule 4 holds: no element is measured. `min()` and `calc()` do the whole job,
   once, at layout — there is no resize listener and nothing reads a rect. */
/* `overflow:hidden` AND `overscroll-behavior:contain` TOGETHER, and neither
   works without the other. A swipe anywhere on this overlay that is not the
   product rail has no scrollable ancestor inside the dialog, so it chains to the
   page — and the shop scrolls behind a modal that is covering it, which is the
   opposite of "only the video will popup". `overscroll-behavior` only applies to
   a scroll container, and `overflow:hidden` is what makes this one (its
   scrollable overflow is zero, so nothing is clipped and nothing can scroll).
   Pure CSS: no body-scroll lock, so no reflow of the page behind and no
   scrollbar width to measure. */
.ugcp{position:fixed;inset:0;z-index:2000;background:rgba(18,12,16,.92);
  -webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);
  overflow:hidden;overscroll-behavior:contain;
  display:none;align-items:center;justify-content:center}
.ugcp.is-on{display:flex}
/* THE THREE CUSTOM PROPERTIES MOVED UP ONTO .ugcp, and that is the only reason
   they are not here any more:
     --ugcp-ar  the clip's own width/height, set per open(). The shipped value
                is 9:16 — the shape every clip in this feature is — so a clip
                whose row lost its dimensions frames exactly as it did before.
     --ugcp-w   560px keeps a 9:16 clip from becoming a 1000px-wide column on a
                tall desktop window; on every phone and on 1280x800 the height
                bound is the one that binds.
     --ugcp-h   dvh where it exists, because 100vh on mobile Safari is the tall
                viewport and the frame would run under the toolbar.
   The previous/next arrows have to place themselves against the frame's edge
   and they are NOT inside the frame, so they cannot inherit these from it.
   Declared on the shell, both the frame and the arrows read the same numbers
   and there is still nothing measured anywhere. */
.ugcp-box{
  position:relative;overflow:hidden;background:#100c10;
  width:var(--ugcp-fw);
  height:min(var(--ugcp-h),calc(var(--ugcp-w) / var(--ugcp-ar)))}
/* COVER, not contain, and only because the line above made them identical: the
   frame already carries this clip's ratio, so cover crops nothing. It is also
   the safe failure — a row whose stored dimensions disagree with the file loses
   a few pixels at one edge instead of growing the bands back. The rail's own
   tiles have used cover since R3, so the popup and the tile now agree. */
.ugcp-v{position:absolute;inset:0;z-index:1;width:100%;height:100%;object-fit:cover;background:#100c10}
/* THE LEGIBILITY SCRIM AT THE TOP, measured rather than guessed. The credit is
   white text on whatever the clip's first frames happen to be; over the bright
   clip in the preview seed the frame under it is #F6E7EC, and white on that is
   1.05:1 — invisible, text-shadow or no text-shadow. This gradient puts a
   measured 0.55 alpha of black under the credit and the close button and fades
   to nothing by 100% of its own height, so a dark clip is not darkened twice.
   The bottom half of the frame is the product rail's own gradient, below. */
.ugcp-box::before{content:'';position:absolute;inset:0 0 auto 0;height:21%;z-index:5;pointer-events:none;
  background:linear-gradient(180deg,rgba(0,0,0,.55) 0%,rgba(0,0,0,.26) 48%,rgba(0,0,0,0) 100%)}
.ugcp-x{position:absolute;top:12px;inset-inline-end:12px;z-index:7;width:36px;height:36px;border-radius:99px;
  background:rgba(255,255,255,.18);-webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px);
  color:#fff;display:grid;place-items:center;font-size:20px;line-height:1}
.ugcp-credit{position:absolute;top:14px;inset-inline-start:14px;z-index:7;color:#fff;font-size:12px;
  font-weight:600;display:flex;align-items:center;gap:6px;text-decoration:none;
  /* Room for the close disc: the credit is a creator handle of any length and
     without this it runs under a 36px button at 390. */
  max-width:calc(100% - 66px);min-width:0;
  text-shadow:0 1px 3px rgba(0,0,0,.55)}
.ugcp-credit bdi{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.ugcp-credit .ugcr-av{width:20px;height:20px}
/* A visible focus ring on both, because the frame is dark glass and the UA
   default outline is nearly invisible on it. Keyboard only — :focus-visible —
   so a tap does not draw one. */
.ugcp-x:focus-visible,.ugcp-credit:focus-visible,.ugcp-card :focus-visible{
  outline:2px solid #fff;outline-offset:2px;border-radius:6px}
.ugcp-rail{position:absolute;inset:auto 0 0 0;z-index:6;display:flex;gap:10px;overflow-x:auto;
  scroll-snap-type:x mandatory;padding:26px 14px calc(14px + env(safe-area-inset-bottom));
  background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.62) 55%)}
/* NOTHING AT ALL when the clip has no products, rather than an empty 163px
   gradient strip across the bottom of the video. */
.ugcp-rail:empty{display:none}
.ugcp-card{scroll-snap-align:center;flex:0 0 auto;width:min(78%,300px);display:flex;gap:9px;
  align-items:center;background:#fff;border-radius:12px;padding:8px;
  box-shadow:0 10px 28px -14px rgba(0,0,0,.6)}
/* ── :not(.ugcp-th) IS THE WHOLE OF THE OWNER'S "THUMBNAIL MUST BE SQUARE" ──
 *
 * This read `.ugcp-card > div`, and .ugcp-th IS a div and IS a direct child, so
 * this rule matched it too. `flex:1` is shorthand for `flex:1 1 0%`, and at
 * (0,1,1) it out-specifies .ugcp-th's own `flex:0 0 auto` at (0,1,0) — so the
 * thumbnail stopped being 46px wide and took an equal share of the card
 * instead. MEASURED, before the fix: 129x46 at 390px and 138x46 at 1280px, a
 * 2.8:1 letterbox with the product image cropped to a slot in the middle of it,
 * and the name pushed onto two lines. The owner drew an arrow at it: "the boxes
 * has product thumbnail must be square, not rectangle etc. so we will have more
 * space for the product name".
 *
 * MUTATION NOTE, RUN, and its result is in the evidence rather than guessed at.
 * Drop the `:not(.ugcp-th)` and the thumbnail does NOT go back to a rectangle —
 * `aspect-ratio:1` below survives — it INFLATES: measured 129x129 at 390px
 * (docs/lane-ug2-shots/player-after.json, `en-390-last-mutated`), taking the
 * product name's box from 211px wide back down to 129px, which is the same
 * width the name had before this change and the same harm. UgcPlayerNavTest's
 * "keeps the player's product thumbnail square" goes red. */
.ugcp-card > div:not(.ugcp-th){min-width:0;flex:1}
.ugcp-card .ugcr-bd{gap:6px}
.ugcp-card .ugcr-nm{font-size:11.5px;-webkit-line-clamp:2;margin-top:1px}
.ugcp-card .ugcr-now{font-size:12.5px}
.ugcp-card .ugcr-was{font-size:10.5px}
.ugcp-card .ugcr-off{font-size:9.5px;padding:1.5px 5px}
.ugcp-card .ugcr-add{font-size:10.5px;padding:7px 11px}
/* SQUARE BY CONSTRUCTION, not by two numbers that happen to match. The
   aspect-ratio is what survives a future change to the width; `flex:0 0 46px`
   is the basis restated on the axis that actually decides, so nothing has to
   out-specify anything for this box to stay a square. `cover` then gives a tall
   bottle and a wide carton the same square, which is the point. */
.ugcp-th{flex:0 0 46px;width:46px;aspect-ratio:1;border-radius:8px;overflow:hidden;background:#FFF0F4}
.ugcp-th img{width:100%;height:100%;object-fit:cover;display:block}

/* ── PREVIOUS / NEXT, AND WHY ONE RULE COVERS BOTH WIDTHS ────────────────────
 *
 * The owner drew both arrows in the dimmed area OUTSIDE the frame, which is
 * where a desktop expects them and where they cover none of the clip. On a
 * phone there IS no outside: .ugcp-box is min(100vw,560px) wide and the frame
 * reaches both edges, so a button parked outside it would be off screen.
 *
 * `max(10px, ...)` is the whole answer and it needs no media query and no
 * measurement. The inner term is the gap between the frame's edge and the
 * viewport's — half of (100% - the frame's width) — minus the button's own
 * room. On a desktop that is a positive number and the arrow sits in the dim;
 * on a phone it goes negative, the max() clamps it to 10px, and the arrow lands
 * on the frame's edge instead. One expression, resolved once at layout. Rule 4.
 *
 * --ugcp-fw IS the frame's width — .ugcp-box sets its own `width` from it — so
 * the arrows are placed against the same number the frame is drawn from and the
 * two can never disagree. --ugcp-ar is set per open() on the shell. */
.ugcp{--ugcp-ar:.5625;--ugcp-w:min(100vw,560px);--ugcp-h:100vh;
  --ugcp-fw:min(var(--ugcp-w),calc(var(--ugcp-h) * var(--ugcp-ar)))}
@supports (height:100dvh){ .ugcp{--ugcp-h:100dvh} }
.ugcp-nav{position:absolute;top:50%;translate:0 -50%;z-index:8;
  width:44px;height:44px;border-radius:99px;display:grid;place-items:center;
  background:rgba(255,255,255,.18);-webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px);
  border:0;color:#fff;cursor:pointer}
.ugcp-nav[hidden]{display:none}
.ugcp-nav:disabled{opacity:.3;cursor:default}
.ugcp-nav.is-prev{inset-inline-start:max(10px,calc((100% - var(--ugcp-fw)) / 2 - 54px))}
.ugcp-nav.is-next{inset-inline-end:max(10px,calc((100% - var(--ugcp-fw)) / 2 - 54px))}
.ugcp-nav:focus-visible{outline:2px solid #fff;outline-offset:2px}
/* THE GLYPH MIRRORS ITSELF, with no [dir] rule anywhere.
   A triangle whose ONE solid border is a logical one points toward the opposite
   logical side, in both directions, because `border-inline-end` IS the left
   border on an Arabic page. The play disc on the tile is built the same way —
   and unlike the `transform:scaleX(-1)` it still needs, this needs nothing. */
.ugcp-nav i{display:block;width:0;height:0;border-block:7px solid transparent}
.ugcp-nav.is-prev i{border-inline-end:11px solid #fff;margin-inline-end:3px}
.ugcp-nav.is-next i{border-inline-start:11px solid #fff;margin-inline-start:3px}

/* Nothing moves for a shopper who asked for nothing to move. The script checks
   the same query live through matchMedia, so this is the paint half of one
   decision rather than a second decision. */
@media (prefers-reduced-motion: reduce){
  .ugcr-t video,.ugcr-play span,.ugcr-add{transition:none}
}
</style>
<script>
/* Shoppable video — the teaser loop and the opened player. Phase 20, Lane V3.

   THE TEASER MACHINERY IS ROUND THREE'S, REUSED AND NOT REWRITTEN.
   docs/UGC-VIDEO-PLAN.md §0b is where every decision below was measured:

     - a SEPARATE teaser file, looped, rather than seeking a full clip back to
       zero. 1.01 MB against 12.19 MB for a rail of eight, over real HTTP. A
       media fragment (#t=0,2.5) saves nothing either: it tells the player where
       to start and the network nothing.
     - muted + playsinline + the attribute AND the property, and the play()
       promise caught. Every browser refuses an unmuted autoplay; iOS Safari
       additionally refuses one without playsinline and takes the clip full
       screen, which is worse than not playing.
     - ONE IntersectionObserver at threshold 0.6 records WHICH tiles are on
       screen; what plays is decided afterwards in one place, because the
       decision depends on the whole set (the cap, and whether a player is open)
       and not on the tile that happened to cross the line.
     - leaving the viewport DROPS THE src AND CALLS load(). Pausing alone leaves
       the decoded stream resident and a long page accumulates them.
     - prefers-reduced-motion read live through matchMedia, so changing the OS
       setting with the page open takes effect without a reload.
     - Save-Data strictly === true. navigator.connection does not exist in
       Safari or Firefox, so `undefined` is most of the traffic and treating
       unknown as "save data" would turn the feature off for the people who can
       afford it. ABSENT MEANS NO.

   NO ELEMENT-MEASURING API IS USED ANYWHERE IN THIS FILE. No
   getBoundingClientRect, no offsetHeight, no clientHeight, no scrollY, no scroll
   handler, no resize listener, no requestAnimationFrame. Rule 4, and two tests
   in this repo forbid those names.

   AND THE CLICK. A tap is intent, so it overrides both switches and the cap —
   and an opened player is EXCLUSIVE: every teaser gives its decoder back first.
   The full clip then plays automatically if Appearance → Video rail →
   Motion says so. It is still muted unless the owner asked otherwise, because a
   rail that makes noise on a tap is the thing people leave a page over.

   ▲ AND THERE IS NO THIRD-PARTY EMBED HERE, DELIBERATELY. §3 of the plan
   decided to re-host rather than embed, and UgcVideo::published() requires a
   file_path — so every clip a shopper can open is one this shop serves. That is
   what makes a 2.5-second teaser and an autoplay-on-click possible at all:
   inside somebody else's iframe we would control neither. The source URL is a
   link back to the original post, on the creator's own credit line, and never
   an embed target. */
(function () {
  'use strict';

  if (!('IntersectionObserver' in window)) return;

  var MAX_DEFAULT = 4;
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

  function saveData() {
    return !!(navigator.connection && navigator.connection.saveData === true);
  }

  /* ── the arbiter. Nothing calls play() itself; everything asks here. ──── */
  var teasers = [];         /* the muted loops currently mounted */
  var exclusive = null;     /* the opened player's element, when there is one */

  function tiles() {
    return document.querySelectorAll('.ugcr-t[data-ugcr-tile]');
  }

  function conf(tile, name, fallback) {
    var sec = tile.closest('.ugcr-sec');
    if (!sec) return fallback;
    var v = sec.getAttribute('data-ugcr-' + name);
    return v === null ? fallback : v;
  }

  /* Dropping the src and calling load() is what hands the decoder back.
     Pausing alone leaves the stream decoded and resident. */
  function unmount(v) {
    if (!v) return;
    try { v.pause(); } catch (e) {}
    v.removeAttribute('src');
    try { v.load(); } catch (e) {}
    var i = teasers.indexOf(v);
    if (i > -1) teasers.splice(i, 1);
    if (v.parentNode) v.parentNode.removeChild(v);
  }

  function stopTeaser(tile) {
    var v = tile.querySelector('video');
    if (!v) return;
    tile.classList.remove('is-playing');
    unmount(v);
  }

  function stopAll() {
    Array.prototype.forEach.call(tiles(), stopTeaser);
    teasers.length = 0;
  }

  function mount(tile, src, loop) {
    var v = document.createElement('video');
    /* MUTED AND INLINE OR NOTHING HAPPENS ANYWHERE. The property AND the
       attribute: the property is what the element honours and the attribute is
       what older WebKit reads. */
    v.muted = true;
    v.playsInline = true;
    v.setAttribute('playsinline', '');
    v.setAttribute('webkit-playsinline', '');
    v.setAttribute('muted', '');
    v.loop = !!loop;
    v.preload = 'none';
    v.disableRemotePlayback = true;
    v.setAttribute('aria-hidden', 'true');
    v.src = src;
    tile.appendChild(v);
    return v;
  }

  /* WHY THIS TILE IS NOT MOVING, written where anybody can read it — the DOM.

     The rail used to have exactly one externally visible fact about a tile
     ("is-playing"), and it was not even true: the class went on at MOUNT, so a
     tile whose file 404s looked, to the CSS and to a screenshot, exactly like a
     tile that was playing. That is how a rail with four dud tiles and two good
     ones reads as "four playing, two refused" in a picture, and it is why this
     complaint survived two rounds of somebody measuring a healthy rail.

     `data-ugcr-why` is now set on EVERY tile, every rebalance, and it is the
     same vocabulary Content -> Shoppable video -> Motion prints in words. */
  function why(tile, reason) {
    if (tile.getAttribute('data-ugcr-why') !== reason) tile.setAttribute('data-ugcr-why', reason);
  }

  function playTeaser(tile) {
    if (exclusive) return;
    if (tile.querySelector('video')) return;

    var max = parseInt(conf(tile, 'max', MAX_DEFAULT), 10);
    if (!(max > 0)) max = MAX_DEFAULT;
    /* The hard backstop. rebalance() already slices the candidate list to the
       cap; this stays so that no future caller can exceed it by accident. */
    if (teasers.length >= max) { why(tile, 'cap'); return; }

    var teaser = tile.getAttribute('data-ugcr-teaser-src');
    var full = tile.getAttribute('data-ugcr-src');
    var src = teaser || full;
    if (!src) { why(tile, 'no-media'); return; }

    var v = mount(tile, src, true);
    teasers.push(v);

    /* ── A SLOT IS GIVEN BACK WHEN THE FILE TURNS OUT NOT TO PLAY ──────────
     *
     * There was no error handler at all, and that is a second way the cap
     * starved the rail: a tile whose source 404s, or whose codec this browser
     * cannot decode, mounted, pushed onto `teasers`, took a slot AND KEPT IT
     * FOR THE LIFE OF THE PAGE. Nothing ever removed it, because nothing was
     * listening. On a shop whose first clips were placeholders that is four
     * permanently-held slots and a rail that never moves.
     *
     * The tile is marked rather than silently dropped: `data-ugcr-fault` is
     * what makes a dud clip visible to a screenshot, to a test, and to the
     * owner's own eyes — the play disc comes back, because `is-playing` is now
     * only ever set by the `playing` event below. */
    v.addEventListener('error', function () {
      tile.setAttribute('data-ugcr-fault', 'media');
      why(tile, 'no-media');
      stopTeaser(tile);
      rebalance();
    });

    /* `is-playing` NOW MEANS PLAYING. It used to be set a few lines below this,
       unconditionally, before a single byte of the clip had arrived — and the
       one CSS rule that hides the play disc keys off it. So the disc vanished
       from tiles that were not playing and the owner's screenshot could not be
       read. Both the class and the video's own opacity now wait for the
       `playing` event, which is the browser saying it is rendering frames. A
       tile that never gets there keeps its poster and keeps its disc. */
    v.addEventListener('playing', function () {
      tile.classList.add('is-playing');
      v.classList.add('is-on');
      why(tile, 'playing');
    });

    /* NO TEASER FILE ON THIS CLIP — the ffmpeg-less case, which is every clip
       on a box with no transcoder. The loop is then cut from the full clip at
       playback, which looks identical and costs 12x the bytes. It is a
       deliberate fallback and not the design: the shop should cut teasers. */
    if (!teaser) {
      var ms = parseInt(conf(tile, 'teaser-ms', 2500), 10);
      if (!(ms >= 500)) ms = 2500;
      v.loop = false;

      /*
       * ── THE REWIND STACKED, AND THAT IS WHAT THE HANG WAS ────────────────
       *
       * This read, in full:
       *
       *     v.addEventListener('timeupdate', function () {
       *       if (v.currentTime * 1000 >= ms) v.currentTime = 0;
       *     });
       *
       * `timeupdate` fires about four times a second AND KEEPS FIRING WHILE A
       * SEEK IS IN FLIGHT. A seek to 0 on a long clip is not instant — the
       * decoder flushes and re-seeks to a keyframe — so the handler ran again,
       * saw a currentTime still past the mark, and assigned 0 a second and a
       * third time. Every assignment is another seek, and they queue.
       *
       * The owner: *"on front-end, it's now playing auto, please make sure the
       * auto loop play must be smooth without hanging etc."* This is the hang.
       * It is worst in exactly the case this shop is in — no teaser file,
       * because ffmpeg cannot be started from PHP-FPM on that host, so the loop
       * is cut from the WHOLE clip at playback and every rewind is a real seek
       * through a real file. Up to four tiles do it at once.
       *
       * Two changes, and neither costs a byte:
       *
       *   - ONE seek in flight at a time. `v.seeking` is the browser's own
       *     answer to "are you still doing the last one", and `rewinding`
       *     covers the window before it flips true.
       *   - `fastSeek()` where the browser has it. It lands on the nearest
       *     keyframe instead of decoding forward to an exact frame, which is
       *     the whole cost of an accurate seek and buys nothing when the target
       *     is zero. Chromium does not implement it yet; Firefox and Safari do,
       *     and they are where a long seek hurts most.
       *
       * THE REAL FIX IS STILL A TEASER FILE, and it is a paragraph up: a clip
       * with its own 2.5-second file uses `v.loop = true` and never seeks at
       * all. `php artisan ugc:cut-covers` cuts them over SSH, and the clip
       * editor now has a button that re-cuts one on demand. This branch is the
       * fallback for clips that have not been cut yet, and it should stutter on
       * none of them.
       */
      var rewinding = false;

      v.addEventListener('seeked', function () { rewinding = false; });

      v.addEventListener('timeupdate', function () {
        if (rewinding || v.seeking) return;
        if (v.currentTime * 1000 < ms) return;

        rewinding = true;

        if (typeof v.fastSeek === 'function') {
          try { v.fastSeek(0); return; } catch (e) { /* fall through */ }
        }

        v.currentTime = 0;
      });

      v.addEventListener('ended', function () {
        rewinding = false;
        v.currentTime = 0;
        var again = v.play();
        if (again && again.catch) again.catch(function () {});
      });
    }

    /* NOT `is-playing` HERE ANY MORE — see the `playing` listener above.
       A rejected play() is a refusal (an autoplay policy, a decoder this
       browser will not give us), and it used to be swallowed into a no-op that
       left the tile claiming to play. It now gives the slot back, so a
       neighbour that CAN play gets it. */
    var p = v.play();
    if (p && p.catch) {
      p.catch(function () {
        if (tile.querySelector('video') !== v) return;   /* already torn down */
        tile.setAttribute('data-ugcr-fault', 'blocked');
        why(tile, 'blocked');
        stopTeaser(tile);
        rebalance();
      });
    }
  }

  /* ── WHO GETS A SLOT, AND WHY IT IS NO LONGER "WHOEVER IS FIRST" ─────────
   *
   * THE DEFECT, MEASURED. The owner's homepage rail is six tiles: four
   * `(Demo)` rows that Content -> Demo content wrote, all four pointing at the
   * same 270x480 plum gradient, followed by the two clips he actually
   * uploaded. At 1600x1000 all six report `data-ugcr-vis="1"` and 100% on
   * screen. The four demo tiles mounted, `teasers.length` hit `max_playing`
   * (4), and the loop below returned for tiles 5 and 6 — the only two he
   * cares about — WITHOUT LOOKING AT THEM. docs/lane-ug2-shots/ has the run.
   *
   * Document order is not a policy, it is an accident of what was imported
   * first. So the cap is now spent on a RANKING:
   *
   *   1. a real clip before a placeholder. `data-ugcr-demo` is printed by
   *      ugc/rail.blade.php from the server's own comparison against
   *      UgcDemoMedia's fixed file name — no query, no guess, and the demo
   *      rows are the only thing it can ever match.
   *   2. then the tile the shopper can see MOST of. This is the "promote by
   *      what is actually on screen" half, and it is the reason the ratio is
   *      recorded on the tile rather than thrown away.
   *   3. then document order, so the result is stable and a rail of equals
   *      behaves exactly as it did before this change.
   *
   * Ratios are bucketed to 0.05 before they are compared, because a ratio that
   * wobbles by a thousandth during a smooth scroll must not reorder the rail
   * and restart two decoders.
   */
  function candidates(all) {
    var out = [];

    Array.prototype.forEach.call(all, function (t, i) {
      if (t.getAttribute('data-ugcr-vis') !== '1') { why(t, 'offscreen'); return; }
      if (conf(t, 'teaser', '1') !== '1') { why(t, 'teaser-off'); return; }
      /* A tile that has already failed is out of the running, and it keeps
         saying WHICH failure: 'blocked' is a refused play() (an autoplay policy
         or a phone in low-power mode, which stops every tile and is honest to
         show as a rail full of play buttons), 'media' is a file that is not
         there. They are different answers and the tile gives the right one. */
      var fault = t.getAttribute('data-ugcr-fault');
      if (fault) { why(t, fault === 'blocked' ? 'blocked' : 'no-media'); return; }
      if (!(t.getAttribute('data-ugcr-teaser-src') || t.getAttribute('data-ugcr-src'))) {
        why(t, 'no-media');
        return;
      }

      out.push({
        t: t,
        i: i,
        real: t.getAttribute('data-ugcr-demo') === '1' ? 0 : 1,
        ratio: parseFloat(t.getAttribute('data-ugcr-vis-ratio')) || 0,
      });
    });

    out.sort(function (a, b) {
      if (a.real !== b.real) return b.real - a.real;
      var ra = Math.round(a.ratio / 0.05);
      var rb = Math.round(b.ratio / 0.05);
      if (ra !== rb) return rb - ra;
      return a.i - b.i;
    });

    return out;
  }

  /* Called whenever the set of on-screen tiles changes, or the OS motion
     setting moves. It stops what should not run, ranks what is left, and hands
     the cap to the top of that ranking — so a tile refused because the cap was
     full starts the moment a neighbour leaves OR a better candidate scrolls
     away, and a placeholder can no longer sit on a slot a real clip wants. */
  function rebalance() {
    var all = tiles();

    Array.prototype.forEach.call(all, function (t) {
      if (t.getAttribute('data-ugcr-vis') !== '1') stopTeaser(t);
    });

    /* The two silent switches, now said out loud on the tile. Neither is a
       fault and neither is a setting the owner can reach — they are the
       shopper's phone asking for less — so the admin screen names them as
       "and this can still happen on a shopper's device" rather than as
       something to go and fix. */
    if (exclusive || reduced.matches || saveData()) {
      var reason = exclusive ? 'player-open' : (reduced.matches ? 'reduced-motion' : 'save-data');
      Array.prototype.forEach.call(all, function (t) { stopTeaser(t); why(t, reason); });
      return;
    }

    var want = candidates(all);

    var max = want.length > 0 ? parseInt(conf(want[0].t, 'max', MAX_DEFAULT), 10) : MAX_DEFAULT;
    if (!(max > 0)) max = MAX_DEFAULT;

    /* EVICTION, and it is the half that actually rescues his rail. Ranking the
       waiting list is worthless on its own: the four placeholders had already
       mounted, and playTeaser() returns early for a tile that holds a video, so
       without this the losers would keep the decoders they grabbed first. A
       tile is evicted only when it is NOT in the top `max` of a ranking built
       from static attributes, so the set converges and cannot oscillate. */
    var keep = want.slice(0, max);

    Array.prototype.forEach.call(all, function (t) {
      if (!t.querySelector('video')) return;

      for (var k = 0; k < keep.length; k++) {
        if (keep[k].t === t) return;
      }

      stopTeaser(t);
    });

    keep.forEach(function (e) { playTeaser(e.t); });
    want.slice(max).forEach(function (e) { why(e.t, 'cap'); });
  }

  /* ── 0.6 IS THE WRONG GATE FOR A RAIL THAT PEEKS ON PURPOSE ──────────────
   *
   * The rail scrolls sideways and its whole design is that the next tile sits
   * half off the edge — `cols` even offers "2.3 across" and "1.2" by name. A
   * tile has to be 61% inside the scroller before the old gate let it play, so
   * a tile the shopper is plainly looking at was refused: measured at 1280px,
   * tile 6 was 73% on screen, reported vis="0", and never mounted anything.
   *
   * 0.35 is the new gate and it is chosen, not rounded down to zero: the
   * deliberate 2.3-across peek shows 24% of the third tile at 390px, so that
   * sliver still does NOT spend a slot or a decoder, while anything a shopper
   * would call "on screen" now does. The extra thresholds exist so the ratio
   * the ranking sorts on is reported often enough to be worth sorting on.
   */
  var VIS_MIN = 0.35;

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      var r = e.isIntersecting ? e.intersectionRatio : 0;
      e.target.setAttribute('data-ugcr-vis', r >= VIS_MIN ? '1' : '0');
      e.target.setAttribute('data-ugcr-vis-ratio', r.toFixed(2));
    });
    rebalance();
  }, { threshold: [0, 0.15, 0.35, 0.5, 0.65, 0.8, 1] });

  /* Live, not read once at boot. */
  if (reduced.addEventListener) reduced.addEventListener('change', rebalance);
  else if (reduced.addListener) reduced.addListener(rebalance);

  /* ── the opened player ────────────────────────────────────────────────── */
  var shell = null;
  /* The tile the player is CURRENTLY showing, which stops being the tile it was
     opened from the moment the shopper presses next. close() returns focus to
     this one — see returnFocusTo, which open() now re-points on every step. */
  var openTile = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ── the focus trap ──────────────────────────────────────────────────────
   *
   * An overlay with `aria-modal="true"` that does not hold focus is lying to a
   * screen reader: the rest of the document stays in the tab order, so Tab
   * walks out of the dialog and onto the rail behind it — which is still there,
   * still clickable, and now has the keyboard while a modal covers it.
   *
   * Three parts, and all three are needed:
   *   - focus goes INTO the dialog when it opens (the close button, so the
   *     first Escape-equivalent is one Enter away);
   *   - Tab and Shift+Tab cycle inside it;
   *   - focus goes BACK to the tile that opened it on close, so a keyboard
   *     shopper carries on from where they were rather than at the top of the
   *     document.
   *
   * querySelectorAll over a fixed selector list rather than anything measured.
   * It is re-read on each Tab because the product cards are built per clip.
   */
  var FOCUSABLE = 'a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var returnFocusTo = null;

  function focusables() {
    if (!shell) return [];
    /* `hidden` is on the credit link when a clip has no creator handle, and a
       hidden element is not focusable — walking onto it would look like Tab
       doing nothing. */
    return Array.prototype.filter.call(shell.querySelectorAll(FOCUSABLE), function (el) {
      return !el.hidden && el.getAttribute('aria-hidden') !== 'true';
    });
  }

  function trapTab(e) {
    var list = focusables();
    if (!list.length) return;

    var first = list[0];
    var last = list[list.length - 1];
    var here = document.activeElement;

    if (e.shiftKey && (here === first || !shell.contains(here))) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && (here === last || !shell.contains(here))) {
      e.preventDefault();
      first.focus();
    }
  }

  function ensureShell() {
    if (shell) return shell;
    shell = document.createElement('div');
    shell.className = 'kbb-ugc ugcp';
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');
    /* A NAME, because a dialog without one is announced as "dialog". The clip's
       own title is set per open() in open(); this is the fallback for a clip
       with no title and it is a constant, never a setting. */
    shell.setAttribute('aria-label', 'Video');
    /*
     * THE ORDER OF THESE FOUR IS THE DESIGN, not tidiness. Everything except the
     * video is INSIDE .ugcp-box, which is now exactly the picture — so the
     * credit, the product boxes and the close button are all over the video
     * frame, which is what the owner asked for and what the old viewport-sized
     * box made impossible.
     */
    shell.innerHTML = '<div class="ugcp-box">'
      + '<video class="ugcp-v" playsinline webkit-playsinline></video>'
      + '<a class="ugcp-credit" target="_blank" rel="noopener nofollow" hidden>'
      +   '<i class="ugcr-av" aria-hidden="true"></i><bdi></bdi></a>'
      + '<div class="ugcp-rail"></div>'
      + '<button class="ugcp-x" type="button" data-ugcp-close aria-label="Close">&times;</button>'
      + '</div>'
      /* OUTSIDE .ugcp-box ON PURPOSE. The owner drew both arrows in the dimmed
         area beside the frame, which is where a desktop expects them and where
         they cover none of the clip; the CSS clamps them onto the frame's edge
         on a phone, where there is no outside. They are siblings of the frame
         rather than children of it so that "outside" is expressible at all. */
      + '<button class="ugcp-nav is-prev" type="button" data-ugcp-nav="-1" hidden>'
      +   '<i aria-hidden="true"></i></button>'
      + '<button class="ugcp-nav is-next" type="button" data-ugcp-nav="1" hidden>'
      +   '<i aria-hidden="true"></i></button>';
    document.body.appendChild(shell);

    shell.addEventListener('click', function (e) {
      if (e.target === shell || (e.target.closest && e.target.closest('[data-ugcp-close]'))) close();
    });

    shell.addEventListener('click', function (e) {
      var nav = e.target && e.target.closest ? e.target.closest('[data-ugcp-nav]') : null;
      if (!nav) return;
      /* stopPropagation is belt and braces: the listener above closes only on a
         click whose target IS the shell, and these buttons are children of it.
         It stays because they are the first elements ever added directly to the
         shell, and "a click on the backdrop closes" is one edit away from
         becoming "a click anywhere outside the frame closes". */
      e.preventDefault();
      e.stopPropagation();
      step(parseInt(nav.getAttribute('data-ugcp-nav'), 10));
    });

    /* On the shell rather than on document, so it can only ever fire while the
       dialog is the thing being typed into. */
    shell.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') { trapTab(e); return; }

      /*
       * ── A PHYSICAL KEY, MAPPED TO A LOGICAL DIRECTION ────────────────────
       *
       * ArrowRight means "the next one" on an English page and "the previous
       * one" on an Arabic one, because the rail itself runs the other way. The
       * CSS needs no [dir] rule — every offset in this player is logical — but
       * a keyboard event carries a PHYSICAL key and something has to do the
       * mapping. Reading the document's direction here is that mapping, and it
       * is the only place in this file that reads it.
       */
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;

      var rtl = (document.documentElement.getAttribute('dir') || 'ltr') === 'rtl';
      var forward = rtl ? (e.key === 'ArrowLeft') : (e.key === 'ArrowRight');

      e.preventDefault();
      step(forward ? 1 : -1);
    });

    return shell;
  }

  /* unmount() REMOVES the element, because dropping the src and calling load()
     is the only thing that hands the decoder back and a detached element cannot
     be left holding one. So a fresh <video> is put back in its place, ready for
     the next tap.

     EXTRACTED so that prev/next uses it too. Moving between clips used to be
     impossible and the obvious way to add it was to assign a new `.src` over
     the old one — which is a SECOND teardown path, with its own opinion about
     what a replaced stream leaves behind. There is one path, and both the close
     button and the arrows go through it. */
  function recycleVideo() {
    unmount(shell.querySelector('.ugcp-v'));

    var fresh = document.createElement('video');
    fresh.className = 'ugcp-v';
    fresh.setAttribute('playsinline', '');
    fresh.setAttribute('webkit-playsinline', '');
    var box = shell.querySelector('.ugcp-box');
    box.insertBefore(fresh, box.firstChild);

    return fresh;
  }

  function close() {
    if (!shell) return;

    shell.classList.remove('is-on');
    shell.removeAttribute('data-ugcp-slug');

    recycleVideo();
    exclusive = null;
    openTile = null;

    /* BACK TO THE TILE THAT OPENED IT. Without this the focus is on a button
       that has just been hidden, and the next Tab starts again at the top of
       the document — which on this shop is the header, several screens above
       the rail the shopper was reading. */
    if (returnFocusTo && returnFocusTo.isConnected && returnFocusTo.focus) {
      try { returnFocusTo.focus(); } catch (e) {}
    }
    returnFocusTo = null;

    rebalance();
  }

  function cardHtml(p) {
    var price = '<span class="ugcr-now">' + p.now_html + '</span>'
      /* now_html and was_html are App\Support\Money::format()'s own markup — a
         constant plus digits, produced on the server — so they go in as they
         come. EVERY OTHER value below is esc()'d: a product name is a setting,
         and rule 5 says anything printed unescaped is a constant. */
      + (p.was_html ? '<span class="ugcr-was">' + p.was_html + '</span>'
          + '<span class="ugcr-off"><bdi>' + esc(p.off) + '</bdi></span>' : '');
    var rate = p.reviews > 0
      ? '<span class="ugcr-rate" role="img" aria-label="' + esc(p.rating_aria) + '">'
        + '<b class="ugcr-sc"><bdi>' + esc(p.rating) + '</bdi></b>'
        + '<i class="ugcr-star" aria-hidden="true">★</i>'
        + '<span class="ugcr-rc"><bdi>(' + esc(p.reviews_label) + ')</bdi></span></span>'
      : '';
    return '<div class="ugcp-card">'
      + '<div class="ugcp-th">' + (p.image ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">' : '') + '</div>'
      + '<div>'
      +   '<div class="ugcr-bd"><span class="ugcr-bdn">' + esc(p.brand) + '</span>' + rate + '</div>'
      +   '<a class="ugcr-nm" href="' + esc(p.url) + '">' + esc(p.name) + '</a>'
      +   '<div class="ugcr-row2"><span class="ugcr-pr">' + price + '</span>'
      +     '<button class="ugcr-add" type="button" data-kbb-add="' + esc(p.id) + '"'
      +     ' data-price="' + esc(p.price_plain) + '" data-name="' + esc(p.name) + '">'
      +     esc(p.add_label) + '</button>'
      +   '</div>'
      + '</div></div>';
  }

  /**
   * The clip's own width÷height, as the server printed it onto the tile.
   *
   * NOT MEASURED, and deliberately not taken from the video element's
   * `videoWidth`/`videoHeight` either. Those are only known after metadata
   * loads, so sizing the frame from them would draw one rectangle and then jump
   * to another — a layout shift on top of a modal, on the slowest connection,
   * which is exactly the reader this feature is for. The server has the number
   * from the clip's own columns before the page is sent; this reads it back.
   */
  function frameRatio(tile) {
    var ar = parseFloat(tile.getAttribute('data-ugcr-ar'));

    /* The same band the server clamps to, applied again here because an
       attribute is a string from a page and this one divides. */
    return (ar >= 0.2 && ar <= 5) ? ar : 0.5625;
  }

  /*
   * ── PREVIOUS / NEXT ──────────────────────────────────────────────────────
   *
   * The owner: *"i want a nice arrows to move to next or previous video."*
   *
   * WITHIN ONE SECTION, AND ONLY OVER CLIPS THAT CAN ACTUALLY OPEN. open()
   * returns immediately for a tile with no `data-ugcr-src`, so a rail that
   * carries one of those would have an arrow that visibly did nothing — which
   * is the exact failure this lane is here to stop being possible. They are
   * filtered out of the walk instead, so "next" always means "the next clip
   * that will open".
   */
  function walkable(tile) {
    var sec = tile.closest('.ugcr-sec');
    if (!sec) return [];

    return Array.prototype.filter.call(
      sec.querySelectorAll('.ugcr-t[data-ugcr-tile]'),
      function (t) { return !!t.getAttribute('data-ugcr-src'); }
    );
  }

  /*
   * ── THE ENDS ARE DISABLED, NOT WRAPPED, AND THAT IS A CHOICE ─────────────
   *
   * A rail is an ORDER the owner arranged in Content -> Shoppable video, not a
   * carousel of equals: "the first one" and "the last one" are positions he
   * picked. A control that wrapped silently would make "have I seen all of
   * these?" unanswerable — the shopper would have no way to tell the sixth clip
   * from the first — while a disabled button answers it without a word.
   *
   * DISABLED, AND IT LOOKS DISABLED (opacity .3 in the CSS), because a control
   * that is present, lit and does nothing is the worse answer. `disabled` also
   * takes it out of the focus trap for free: FOCUSABLE is
   * `button:not([disabled])`.
   *
   * BOTH HIDDEN ENTIRELY when there is only one openable clip, rather than two
   * permanently dead buttons on every single-clip rail.
   */
  function updateNav(tile) {
    if (!shell) return;

    var list = walkable(tile);
    var at = Array.prototype.indexOf.call(list, tile);
    var prev = shell.querySelector('[data-ugcp-nav="-1"]');
    var next = shell.querySelector('[data-ugcp-nav="1"]');
    var many = list.length > 1;

    prev.hidden = next.hidden = !many;
    prev.disabled = !many || at <= 0;
    next.disabled = !many || at < 0 || at >= list.length - 1;

    /* The words travel on the section, because this markup is built in the
       browser and an Arabic page needs Arabic labels. */
    prev.setAttribute('aria-label', conf(tile, 'prev', 'Previous video'));
    next.setAttribute('aria-label', conf(tile, 'next', 'Next video'));
  }

  function step(delta) {
    if (!openTile || !(delta === 1 || delta === -1)) return;

    var list = walkable(openTile);
    var at = Array.prototype.indexOf.call(list, openTile);
    var to = list[at + delta];

    /* No wrap: at either end this is undefined and nothing happens, which is
       the same answer the disabled button gives. The two agree on purpose —
       ArrowLeft at the first clip must not do what the greyed-out button
       refuses to do. */
    if (at < 0 || !to) return;

    open(to);
  }

  function open(tile) {
    var src = tile.getAttribute('data-ugcr-src');
    if (!src) return;

    /* EXCLUSIVE. Everything else gives its decoder back BEFORE the player
       mounts, here rather than waiting for each tile's own observer — two tiles
       can be over the threshold at once, and relying on the exit callback leaves
       elements merely resident, which is the kind that accumulates. */
    exclusive = true;
    stopAll();

    var box = ensureShell();

    /* STEPPING INTO A LIVE SHELL. Assigning a new `.src` over a playing stream
       is a second teardown with its own opinion about what the old decoder
       keeps; recycleVideo() is the one close() uses, so there is exactly one.
       Harmless on a first open — the element it replaces has never had a src. */
    recycleVideo();

    var v = box.querySelector('.ugcp-v');
    var data = {};
    var json = tile.parentNode.querySelector('script[data-ugcr-data]');
    if (json) { try { data = JSON.parse(json.textContent); } catch (e) { data = {}; } }

    /*
     * THE FRAME, BEFORE ANYTHING IS PUT IN IT. .ugcp-box's width and height are
     * both min() expressions over this one number, so setting it is the whole of
     * "the popup is the shape of the clip" — see the CSS for the measurements
     * this replaced.
     */
    /* ON THE SHELL, not on .ugcp-box. The frame reads it through inheritance
       exactly as before; the previous/next arrows, which sit OUTSIDE the frame
       in the dimmed area, read the same number to place themselves against its
       edge. One source, so the arrows cannot end up beside a frame of a
       different shape. */
    box.style.setProperty('--ugcp-ar', String(frameRatio(tile)));

    /* The dialog's name, from the clip's own label. `aria-label` on the tile is
       built by the server from the title or the caption and is already escaped
       there; setAttribute writes text, never markup. */
    var label = tile.getAttribute('aria-label');
    if (label) box.setAttribute('aria-label', label);

    var credit = box.querySelector('.ugcp-credit');
    if (data.handle) {
      credit.hidden = false;
      credit.querySelector('bdi').textContent = data.handle;
      if (data.source_url) { credit.href = data.source_url; credit.removeAttribute('aria-disabled'); }
      else { credit.removeAttribute('href'); }
    } else {
      credit.hidden = true;
    }

    box.querySelector('.ugcp-rail').innerHTML =
      (data.products || []).map(cardHtml).join('');

    v.poster = tile.getAttribute('data-ugcr-poster') || '';
    v.controls = conf(tile, 'controls', '1') === '1';
    /* Still muted unless the owner asked otherwise — and muted is also what
       lets autoplay happen at all. */
    var wantsSound = conf(tile, 'sound', '0') === '1';
    v.muted = !wantsSound;
    v.playsInline = true;
    v.loop = false;
    v.preload = 'auto';
    v.src = src;
    exclusive = v;

    var wasOpen = box.classList.contains('is-on');
    box.classList.add('is-on');

    openTile = tile;
    box.setAttribute('data-ugcp-slug', tile.getAttribute('data-ugcr-slug') || '');
    updateNav(tile);

    /* FOCUS IN, and remember where it came from. After the class is added,
       because focus() on a `display:none` subtree does nothing at all.

       RE-POINTED ON EVERY STEP, and that is the thing prev/next quietly breaks
       if nobody thinks about it: a shopper who opens the first clip, presses
       next four times and then Escape must land on the tile they ENDED on. It
       is also the tile the rail has scrolled to, so returning to the opening
       one would put focus somewhere off screen. */
    returnFocusTo = tile;

    /* FOCUS ONLY ON THE FIRST OPEN. Moving it back to the close button on every
       step would take it off the arrow the shopper is pressing, so the next
       press would go nowhere. */
    if (!wasOpen) {
      var closer = box.querySelector('.ugcp-x');
      if (closer) { try { closer.focus(); } catch (e) {} }
    }

    if (conf(tile, 'autoplay', '1') === '1') {
      var p = v.play();
      /* CAUGHT, and then RETRIED MUTED. An unmuted play() on a page the shopper
         has only tapped once is refused by every browser, and the refusal is a
         rejected promise rather than an error — so without this branch
         "unmute on open" would mean "do not play on open". */
      if (p && p.catch) {
        p.catch(function () {
          v.muted = true;
          var again = v.play();
          if (again && again.catch) again.catch(function () {});
        });
      }
    }
  }

  /* ── wiring, delegated on document ────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    if (t.closest('.ugcr-add') || t.closest('.ugcr-nm')) return;   /* cart and product page */

    var like = t.closest('[data-ugcr-like-slug]');
    if (like) { e.preventDefault(); e.stopPropagation(); sendLike(like); return; }

    var tile = t.closest('.ugcr-t[data-ugcr-tile]');
    if (tile) { e.preventDefault(); open(tile); }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && shell && shell.classList.contains('is-on')) { close(); return; }
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var tile = e.target && e.target.closest ? e.target.closest('.ugcr-t[data-ugcr-tile]') : null;
    if (tile) { e.preventDefault(); open(tile); }
  });

  /* ── the like, and it is a public write ──────────────────────────────── */
  function sendLike(button) {
    if (button.getAttribute('data-ugcr-busy') === '1') return;

    var sec = button.closest('.ugcr-sec');
    var url = sec ? sec.getAttribute('data-ugcr-like') : '';
    var slug = button.getAttribute('data-ugcr-like-slug');
    if (!url || !slug) return;

    button.setAttribute('data-ugcr-busy', '1');

    fetch(url.replace('__SLUG__', encodeURIComponent(slug)), {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      /* The one-per-browser token is a cookie this shop minted, so the request
         has to carry it. It is set by the response the first time. */
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (body) {
      if (!body || body.ok !== true) return;
      var out = button.querySelector('[data-ugcr-like-count]');
      /* The SERVER's count, never a local increment: two tabs, or a like
         already spent, and an optimistic +1 would print a number nobody has. */
      if (out && typeof body.likes === 'number') {
        out.textContent = body.likes.toLocaleString();
      }
      button.classList.add('is-on');
    }).catch(function () {}).then(function () {
      button.removeAttribute('data-ugcr-busy');
    });
  }

  function boot() {
    Array.prototype.forEach.call(tiles(), function (t) { io.observe(t); });
    rebalance();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
