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

/* ── the opened player ─────────────────────────────────────────────────── */
.ugcp{position:fixed;inset:0;z-index:2000;background:rgba(18,12,16,.92);
  -webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);
  display:none;align-items:center;justify-content:center}
.ugcp.is-on{display:flex}
.ugcp-box{position:relative;width:100%;height:100%;max-width:520px;background:#100c10;overflow:hidden}
.ugcp-v{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#100c10}
.ugcp-x{position:absolute;top:12px;inset-inline-end:12px;z-index:7;width:36px;height:36px;border-radius:99px;
  background:rgba(255,255,255,.18);-webkit-backdrop-filter:blur(7px);backdrop-filter:blur(7px);
  color:#fff;display:grid;place-items:center;font-size:20px;line-height:1}
.ugcp-credit{position:absolute;top:14px;inset-inline-start:14px;z-index:7;color:#fff;font-size:12px;
  font-weight:600;display:flex;align-items:center;gap:6px;text-decoration:none;
  text-shadow:0 1px 3px rgba(0,0,0,.55)}
.ugcp-credit .ugcr-av{width:20px;height:20px}
.ugcp-rail{position:absolute;inset:auto 0 0 0;z-index:6;display:flex;gap:10px;overflow-x:auto;
  scroll-snap-type:x mandatory;padding:26px 14px calc(14px + env(safe-area-inset-bottom));
  background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.6) 55%)}
.ugcp-card{scroll-snap-align:center;flex:0 0 auto;width:min(78%,300px);display:flex;gap:9px;
  align-items:center;background:#fff;border-radius:12px;padding:8px;
  box-shadow:0 10px 28px -14px rgba(0,0,0,.6)}
.ugcp-card > div{min-width:0;flex:1}
.ugcp-card .ugcr-bd{gap:6px}
.ugcp-card .ugcr-nm{font-size:11.5px;-webkit-line-clamp:2;margin-top:1px}
.ugcp-card .ugcr-now{font-size:12.5px}
.ugcp-card .ugcr-was{font-size:10.5px}
.ugcp-card .ugcr-off{font-size:9.5px;padding:1.5px 5px}
.ugcp-card .ugcr-add{font-size:10.5px;padding:7px 11px}
.ugcp-th{width:46px;height:46px;flex:0 0 auto;border-radius:8px;overflow:hidden;background:#FFF0F4}
.ugcp-th img{width:100%;height:100%;object-fit:cover}

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

  function playTeaser(tile) {
    if (exclusive) return;
    if (tile.querySelector('video')) return;

    var max = parseInt(conf(tile, 'max', MAX_DEFAULT), 10);
    if (!(max > 0)) max = MAX_DEFAULT;
    if (teasers.length >= max) return;

    var teaser = tile.getAttribute('data-ugcr-teaser-src');
    var full = tile.getAttribute('data-ugcr-src');
    var src = teaser || full;
    if (!src) return;

    var v = mount(tile, src, true);
    teasers.push(v);

    /* NO TEASER FILE ON THIS CLIP — the ffmpeg-less case, which is every clip
       on a box with no transcoder. The loop is then cut from the full clip at
       playback, which looks identical and costs 12x the bytes. It is a
       deliberate fallback and not the design: the shop should cut teasers. */
    if (!teaser) {
      var ms = parseInt(conf(tile, 'teaser-ms', 2500), 10);
      if (!(ms >= 500)) ms = 2500;
      v.loop = false;
      v.addEventListener('timeupdate', function () {
        if (v.currentTime * 1000 >= ms) v.currentTime = 0;
      });
      v.addEventListener('ended', function () {
        v.currentTime = 0;
        var again = v.play();
        if (again && again.catch) again.catch(function () {});
      });
    }

    tile.classList.add('is-playing');
    v.classList.add('is-on');
    var p = v.play();
    if (p && p.catch) p.catch(function () {});
  }

  /* Called whenever the set of on-screen tiles changes, or the OS motion
     setting moves. It stops what should not run and then promotes waiting tiles
     IN DOCUMENT ORDER until the cap is reached — so a tile refused because the
     cap was full starts the moment a neighbour leaves. */
  function rebalance() {
    var all = tiles();

    Array.prototype.forEach.call(all, function (t) {
      if (t.getAttribute('data-ugcr-vis') !== '1') stopTeaser(t);
    });

    if (exclusive || reduced.matches || saveData()) {
      Array.prototype.forEach.call(all, stopTeaser);
      return;
    }

    Array.prototype.forEach.call(all, function (t) {
      if (t.getAttribute('data-ugcr-vis') === '1' && conf(t, 'teaser', '1') === '1') playTeaser(t);
    });
  }

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      e.target.setAttribute('data-ugcr-vis',
        (e.isIntersecting && e.intersectionRatio > 0.6) ? '1' : '0');
    });
    rebalance();
  }, { threshold: [0, 0.6] });

  /* Live, not read once at boot. */
  if (reduced.addEventListener) reduced.addEventListener('change', rebalance);
  else if (reduced.addListener) reduced.addListener(rebalance);

  /* ── the opened player ────────────────────────────────────────────────── */
  var shell = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function ensureShell() {
    if (shell) return shell;
    shell = document.createElement('div');
    shell.className = 'kbb-ugc ugcp';
    shell.setAttribute('role', 'dialog');
    shell.setAttribute('aria-modal', 'true');
    shell.innerHTML = '<div class="ugcp-box">'
      + '<video class="ugcp-v" playsinline webkit-playsinline></video>'
      + '<a class="ugcp-credit" target="_blank" rel="noopener nofollow" hidden>'
      +   '<i class="ugcr-av" aria-hidden="true"></i><bdi></bdi></a>'
      + '<div class="ugcp-rail"></div>'
      + '<button class="ugcp-x" type="button" data-ugcp-close aria-label="Close">&times;</button>'
      + '</div>';
    document.body.appendChild(shell);

    shell.addEventListener('click', function (e) {
      if (e.target === shell || (e.target.closest && e.target.closest('[data-ugcp-close]'))) close();
    });

    return shell;
  }

  function close() {
    if (!shell) return;

    shell.classList.remove('is-on');

    /* unmount() REMOVES the element, because dropping the src and calling load()
       is the only thing that hands the decoder back and a detached element cannot
       be left holding one. So a fresh <video> is put back in its place, ready for
       the next tap. */
    unmount(shell.querySelector('.ugcp-v'));
    exclusive = null;

    var fresh = document.createElement('video');
    fresh.className = 'ugcp-v';
    fresh.setAttribute('playsinline', '');
    fresh.setAttribute('webkit-playsinline', '');
    var box = shell.querySelector('.ugcp-box');
    box.insertBefore(fresh, box.firstChild);

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
    var v = box.querySelector('.ugcp-v');
    var data = {};
    var json = tile.parentNode.querySelector('script[data-ugcr-data]');
    if (json) { try { data = JSON.parse(json.textContent); } catch (e) { data = {}; } }

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

    box.classList.add('is-on');

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
