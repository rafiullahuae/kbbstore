{{--
    Content → Instagram. (Lane IG — Phase 21, Instagram Profile)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry and toast(). It wraps window.go,
    exactly as the screens beside it do, so ONE include is the whole of the change
    to that file:

        @@include('admin.partials.instagram-screen')

    ── WHAT THIS SCREEN IS ──────────────────────────────────────────────────

    Two halves, and they are deliberately not two screens.

    CONNECTION is the wizard: the six things docs/IG-PROFILE.md §7 says the owner
    must do himself, the exact redirect URI to paste into Meta with a Copy button,
    the app id and secret boxes, and the Configure now button that does the OAuth
    handshake. LOOK and WHAT A TILE SHOWS are the ordinary schema tabs, drawn from
    App\Services\InstagramSettings::SCHEMA by the same renderer every other
    migrated module uses.

    They share a screen because they are one job with one failure mode: an owner
    who picks a layout before connecting sees nothing and cannot tell which half is
    wrong. On one screen the connection's state is visible above the layout picker
    at all times, and the empty-grid case explains itself.

    ── "CONFIGURE NOW" IS HONEST ABOUT WHAT IT CANNOT DO ────────────────────

    The owner asked for a button that "should reach to instagram, take permission
    and configure". Step 3 is exactly that and all of it is automated. But a button
    cannot create a Meta app or switch an Instagram account to professional, so
    this screen prints those steps FIRST, with ticks derived from what is actually
    saved rather than from a box the owner ticked — and marks the two this server
    genuinely cannot observe as unobservable rather than drawing an empty tick that
    implies it looked and found them undone. docs/IG-PROFILE.md §8 is the table.

    ── NO CREDENTIAL IS EVER DRAWN ──────────────────────────────────────────

    The payload carries `secret_saved` as a BOOLEAN and no secret, and no token at
    all. The secret box is therefore drawn EMPTY with a placeholder saying whether
    one is stored — never a row of asterisks, which discloses the length — and an
    empty box on save means "leave the stored one alone", which is
    InstagramCredentials::saveApp()'s documented third state. `app_id` IS shown,
    because a Meta app id is public by construction: it travels in the
    authorisation URL in the owner's own address bar, and showing it is how he
    checks he pasted the right one.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included —
    with the next closing one, so writing the word in prose swallows everything
    between them and serves the whole docblock to the browser as visible text.

    EVERY CLASS IS PREFIXED igs- AND EVERY data- ATTRIBUTE data-igs-, and both
    appear nowhere else in the console: app.blade.php binds delegated listeners to
    `document` itself, each claiming a bare attribute name, so a click on any
    element carrying one is handled by that listener whichever screen it belongs to.
--}}
@verbatim
<style>
.igs-wrap{display:grid;gap:14px;min-width:0}
.igs-wrap > *{min-width:0}
.igs-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.igs-title{font-weight:650;font-size:15px}
.igs-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.igs-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;margin-top:12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.igs-note b{color:var(--ink,#16181d)}
.igs-note.is-warm{border-style:solid;border-color:#e9d5a1;background:#fdf9ef}
.igs-note.is-bad{border-style:solid;border-color:#d9534f;color:#b4443c}
.igs-note.is-good{border-style:solid;border-color:#9ed8b6;background:#f2fbf6;color:#1d7145}
.igs-tabs{display:flex;flex-wrap:wrap;gap:6px;min-width:0}
.igs-tab{padding:7px 12px;border:1px solid var(--border,#e6e6e6);border-radius:999px;background:transparent;
         color:inherit;font:inherit;font-size:12.5px;cursor:pointer}
.igs-tab[aria-selected="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.igs-fields{display:grid;gap:15px;margin-top:14px;min-width:0}
.igs-f{display:grid;gap:5px;min-width:0}
.igs-fh{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.igs-f label{font-size:12.5px;font-weight:650;overflow-wrap:anywhere}
.igs-val{font-size:12px;color:var(--ink-soft,#6b7280);font-variant-numeric:tabular-nums;white-space:nowrap}
.igs-f select,.igs-f input[type=text],.igs-f input[type=password]{width:100%;min-width:0;padding:8px 10px;
  font:inherit;font-size:13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
  color:inherit}
.igs-f input[type=range]{width:100%;min-width:0}
.igs-check{display:flex;gap:9px;align-items:flex-start;min-width:0}
.igs-check input{margin-top:3px;flex:0 0 auto}
.igs-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.igs-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px;min-width:0}
.igs-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%;text-decoration:none;
         display:inline-block}
.igs-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.igs-btn.is-bad{border-color:#d9534f;color:#b4443c}
.igs-btn[disabled],.igs-btn.is-off{opacity:.45;cursor:default;pointer-events:none}

/* ── the six-step checklist ───────────────────────────────────────────────
   A list and not a table: on a phone a four-column table of instructions is a
   horizontal scrollbar, and this screen is one the owner will read on a phone
   because half of it tells him what to do IN the Instagram app. */
.igs-steps{list-style:none;margin:12px 0 0;padding:0;display:grid;gap:9px;counter-reset:igs}
.igs-step{display:grid;grid-template-columns:22px 1fr;gap:10px;align-items:start;font-size:12.5px;
          line-height:1.55;color:var(--ink-soft,#6b7280)}
.igs-mark{width:20px;height:20px;border-radius:50%;display:grid;place-items:center;font-size:11px;
          font-weight:700;border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280)}
.igs-step.is-done .igs-mark{background:#15a85a;border-color:#15a85a;color:#fff}
.igs-step.is-done{color:var(--ink,#16181d)}
.igs-step.is-unknown .igs-mark{border-style:dashed}

/* The redirect URI. `word-break:break-all` and not `anywhere`: this string must
   be selectable and copyable in one gesture, and it has no spaces to wrap at. */
.igs-uri{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:9px}
.igs-uri code{flex:1 1 320px;min-width:0;padding:8px 10px;border:1px solid var(--border,#e6e6e6);
  border-radius:8px;font-size:12px;word-break:break-all;background:rgba(0,0,0,.02)}

/* The profile readback — proof the connection works, in the owner's own face. */
.igs-me{display:flex;gap:12px;align-items:center;margin-top:12px;flex-wrap:wrap}
.igs-me img{width:56px;height:56px;border-radius:50%;object-fit:cover;background:#f3f4f6;flex:0 0 auto}
.igs-me div{min-width:0}
.igs-me b{font-size:14px;display:block;overflow-wrap:anywhere}
.igs-me span{font-size:12px;color:var(--ink-soft,#6b7280);display:block;margin-top:2px}
.igs-empty{color:var(--ink-soft,#6b7280);font-size:13px;padding:6px 0}

/* ── THE PREVIEW ──────────────────────────────────────────────────────────
   A DRAWING OF THE SECTION, NOT THE SECTION. Every other Appearance screen in
   this console previews what it controls and this one used to ask the owner to
   save and go look at the shop.

   It is not an iframe of the storefront, and that is a cost decision rather than
   a convenience: the section is behind the shop's own routes, so a live preview
   is an authenticated fetch and a full page render per keystroke — and the
   controls above it move on INPUT, which for a slider is thirty events a drag.
   So the storefront's arrangement is restated here in CSS, and the browser lays
   it out once per repaint from the stylesheet.

   THE RULES BELOW ARE THE SAME ARITHMETIC AS resources/views/instagram/
   assets.blade.php, at the same two breakpoints (640 and 900), including
   InstagramSettings::cssVariables()'s peeking-rail fractions -- 2.3 tiles and 1.3
   gaps on a phone, 5.3 and 4.3 wide. A preview whose columns are its own opinion
   is a preview that lies, and the way to keep the two honest is for both to be
   the same expressions rather than two sets of numbers that look similar.

   ── AND THEY ARE @container RULES, NOT @media ──────────────────────────
   The preview is a BOX INSIDE a console, so the width that decides its
   arrangement is the box's, never the window's. @media here would draw the
   desktop grid inside a 340px-wide preview on a 1280px screen, which is the
   exact wrong answer -- and it is also what makes the Phone/Desktop switch below
   work without JavaScript measuring anything: the switch sets a max-width, the
   container query notices, and the browser redoes the layout. A browser with no
   container-query support ignores every @container block and keeps the phone
   arrangement, which is a smaller lie than the other way round.

   NOTHING HERE MEASURES ANYTHING -- rule 4, and InstagramSectionShapeTest holds
   this file to it by scanning for the eight element-measuring APIs BY NAME. Which
   is why they are not written out in this comment either: that test is a
   str_contains over the source, so naming one in prose is as red as calling it,
   and this paragraph went red exactly once for saying so. Every size above is a
   calc(), a repeat() or an aspect-ratio, and there is nothing left to ask the
   browser for. */
.igs-pvh{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;min-width:0}
.igs-pvh b{font-weight:650;font-size:15px}
.igs-pvh span{font-size:12px;color:var(--ink-soft,#6b7280)}
.igs-pvw{display:flex;gap:6px;flex:0 0 auto}
.igs-pvw button{padding:5px 10px;border:1px solid var(--border,#e6e6e6);border-radius:999px;background:transparent;
  color:inherit;font:inherit;font-size:11.5px;cursor:pointer}
.igs-pvw button[aria-pressed="true"]{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}

/* The frame. `container-type:inline-size` is what the @container rules below
   resolve against, and `overflow:hidden` is the thing that keeps a peeking rail
   inside this card instead of widening the whole console -- the fault rule 4's
   scrollWidth number exists to catch. */
.igs-pvf{margin-top:12px;border:1px solid var(--border,#e6e6e6);border-radius:12px;padding:12px;
  background:var(--surface,#fff);container-type:inline-size;overflow:hidden;min-width:0}
.igs-pvf.is-phone{max-width:390px}

.igs-pv{--igsp-w:auto;--igsp-gap:8px;--igsp-r:10px;min-width:0}
.igs-pv *{box-sizing:border-box;min-width:0}
.igs-pvhd{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 12px}
.igs-pvhd h3{margin:0;font-size:17px;font-weight:700;line-height:1.25;overflow-wrap:anywhere}
.igs-pvhd i{font-style:normal;font-size:13px;color:#15a85a;font-weight:600;flex:0 0 auto}

/* The profile box, in the two styles that draw one. */
.igs-pvp{display:flex;align-items:center;gap:12px;margin:0 0 14px}
.igs-pvp img,.igs-pvp .igs-pvav{border-radius:50%;object-fit:cover;flex:0 0 auto;background:#f3f4f6}
.igs-pvp.is-card{padding:13px 14px;border:1px solid var(--border,#e6e6e6);border-radius:calc(var(--igsp-r) + 4px)}
.igs-pvp.is-card img,.igs-pvp.is-card .igs-pvav{width:64px;height:64px}
.igs-pvp.is-bar{gap:9px;margin-bottom:10px}
.igs-pvp.is-bar img,.igs-pvp.is-bar .igs-pvav{width:36px;height:36px}
.igs-pvn{display:grid;gap:2px;min-width:0}
.igs-pvn b{font-size:15px;font-weight:700;line-height:1.2;overflow-wrap:anywhere}
.igs-pvn span{font-size:12.5px;color:var(--ink-soft,#6b7280);line-height:1.35;overflow-wrap:anywhere}
.igs-pvp.is-bar .igs-pvn b{font-size:13.5px}
.igs-pvp.is-bar .igs-pvn span{font-size:11.5px}
.igs-pvfl{margin-inline-start:auto;flex:0 0 auto;padding:8px 14px;border-radius:999px;background:#15a85a;color:#fff;
  font-size:12.5px;font-weight:650}

/* The track. Two grids, two flex scrollers, the same as the shop. */
.igs-pvt{display:grid;gap:var(--igsp-gap)}
.igs-pvt.is-grid,.igs-pvt.is-mosaic,.igs-pvt.is-masonry{grid-template-columns:repeat(2,minmax(0,1fr))}
.igs-pvt.is-rail,.igs-pvt.is-strip{display:flex;overflow-x:auto;scrollbar-width:none}
.igs-pvt.is-rail::-webkit-scrollbar,.igs-pvt.is-strip::-webkit-scrollbar{display:none}
.igs-pvt.is-rail > *,.igs-pvt.is-strip > *{flex:0 0 var(--igsp-w)}
.igs-pvt.is-mosaic > :first-child{grid-column:span 2;grid-row:span 2}

/* The cell. `aspect-ratio` is what keeps a tile square without anybody knowing
   its width, which is the whole reason there is nothing here to measure. */
.igs-pvc{position:relative;display:block;overflow:hidden;border-radius:var(--igsp-r);aspect-ratio:1/1;
  background:#f3f4f6}
.igs-pvt.is-masonry .igs-pvc{aspect-ratio:4/5}
.igs-pvc > img{display:block;width:100%;height:100%;object-fit:cover}

/* A PLACEHOLDER IS DRAWN AS A PLACEHOLDER. Not an empty grey box that reads as a
   broken picture: a hairline border and a diagonal wash, so "nothing has been
   fetched yet" is legible at a glance and the note under the frame says it in
   words. */
/* TWO BACKGROUND LAYERS AND NOT A PSEUDO-ELEMENT, because `::before` and
   `::after` on this cell are already spoken for by the album mark and the play
   triangle -- and a ghost tile that borrowed one would be a ghost tile whose
   badge disappeared the day somebody drew a placeholder for a video. */
.igs-pvc.is-ghost{box-shadow:inset 0 0 0 1px var(--border,#e6e6e6);
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23b9bec7' stroke-width='1.6'%3E%3Crect x='3' y='3' width='18' height='18' rx='5'/%3E%3Ccircle cx='12' cy='12' r='4'/%3E%3Ccircle cx='17.2' cy='6.8' r='1'/%3E%3C/svg%3E"),
    repeating-linear-gradient(135deg,rgba(0,0,0,.045) 0 8px,transparent 8px 16px);
  background-repeat:no-repeat,repeat;background-position:50% 50%,0 0;background-size:30% auto,auto}

/* The play triangle and the album mark, as pseudo-elements on the same classes
   the shop uses, so a tile costs no extra node here either. */
.igs-pvc.is-video::after{content:"";position:absolute;inset-block-start:50%;inset-inline-start:50%;
  translate:-50% -50%;width:38px;height:38px;border-radius:50%;background:rgba(0,0,0,.42);
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23fff'%3E%3Cpath d='M9 6.5v11l9-5.5z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:54% 50%;background-size:19px}
.igs-pvt.is-strip .igs-pvc.is-video::after{width:26px;height:26px;background-size:13px}
.igs-pvc.is-album::before{content:"";position:absolute;inset-block-start:6px;inset-inline-end:6px;width:14px;height:14px;
  border:2px solid #fff;border-radius:3px;box-shadow:-3px 3px 0 -1px rgba(255,255,255,.55);opacity:.95}

/* The counts, on the same gradient, and drawn only when there is a number. */
.igs-pvm{position:absolute;inset-inline:0;inset-block-end:0;display:flex;gap:10px;align-items:center;
  padding:15px 8px 6px;color:#fff;font-size:11.5px;font-weight:600;line-height:1;
  background:linear-gradient(to top,rgba(0,0,0,.56),rgba(0,0,0,0))}
.igs-pvm span{display:inline-flex;gap:4px;align-items:center;font-variant-numeric:tabular-nums}
.igs-pvm svg{width:12px;height:12px;flex:0 0 auto}

/* The caption. ALWAYS VISIBLE IN THE PREVIEW and hover-only on the shop, which
   is a deliberate difference and is said in the note under the frame: a preview
   of a thing that only appears on hover shows the owner nothing when he turns the
   switch on, which is the one moment he is looking. */
.igs-pvcap{position:absolute;inset:0;display:flex;align-items:flex-end;padding:8px;color:#fff;font-size:11px;
  line-height:1.4;background:rgba(0,0,0,.44)}
.igs-pvcap span{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}

@container (min-width:640px){
  .igs-pvt.is-grid,.igs-pvt.is-mosaic,.igs-pvt.is-masonry{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@container (min-width:900px){
  .igs-pvt.is-masonry{grid-template-columns:repeat(4,minmax(0,1fr))}
  .igs-pvt.is-rail > *{flex-basis:calc((100% - (var(--igsp-gap) * 4.3)) / 5.3)}
  .igs-pvt.is-strip > *{flex-basis:calc((100% - (var(--igsp-gap) * 7.5)) / 8.5)}
  .igs-pvp.is-card img,.igs-pvp.is-card .igs-pvav{width:76px;height:76px}
}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'instagram';
  var tabs = null, values = {}, open = null, banner = null, busy = false, seq = 0;
  var moduleOn = false, connection = null, me = null, content = null;
  /* Which width the preview frame is drawn at. A view of the drawing and not a
     setting, so it is never posted and never saved -- and it is why the frame's
     arrangement is decided by @container rules rather than @media ones. */
  var pvPhone = false;
  /* The OAuth popup and the watchdog that notices it was closed by hand. Both
     nulled the moment either finishes; see watchPopup(). */
  var popup = null, popupWatch = null;
  /* The sentence the OAuth callback redirected back with, read once from the
     query string and then kept in a variable — see landed() for why it is
     stripped out of the URL rather than left in it. */
  var landed = null;

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(m) { try { window.toast(m); } catch (e) {} }

  /*
   * ── THE TOKEN THIS CONSOLE ACTUALLY USES ────────────────────────────────
   *
   * `X-XSRF-TOKEN`, read from the `XSRF-TOKEN` COOKIE that Laravel sets on every
   * response. That is what app.blade.php's own api() has always sent.
   *
   * WRITTEN FROM THE SIBLING SCREEN'S SCAR RATHER THAN FROM SCRATCH: the
   * shoppable-video screens read a <meta name="csrf-token"> tag, which this admin
   * does not have, so the token was '' on every call and every write was refused
   * with 419 "CSRF token mismatch" — reported to the owner as "that could not be
   * saved", a sentence that names the symptom and hides the cause. Nobody could
   * create a section on any shop. The cookie is the one that works.
   */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body, method) {
    var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (method || body !== undefined) {
      options.method = method || 'POST';
      options.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    }
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    var response = await fetch(base() + path, options);
    var payload = null;
    try { payload = await response.json(); } catch (e) {}
    if (!response.ok) { throw { status: response.status, body: payload }; }
    return payload || {};
  }

  function explain(e, fallback) {
    /* A 404 here means ONE thing and it is worth naming, because the symptom
       reads as "the feature does not work": the routes are in the source and not
       in this server's compiled route table. A package that adds a route ships a
       clear_caches_ migration beside it for exactly this reason, and a shop whose
       cache survived it gets a screen that draws and answers 404 — including on
       the OAuth callback, which fails AFTER Instagram has granted access. */
    if (e && e.status === 404) {
      return 'The Instagram endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache (php artisan route:clear) and reload this page. '
           + 'Do not press Configure now until that is done — the callback would 404 after '
           + 'Instagram had already granted access.';
    }
    if (e && e.status === 403) { return 'Your account does not hold the Instagram permission.'; }
    var body = e && e.body ? e.body : null;
    var text = body && body.error ? body.error : fallback;
    /* Meta's own sentence is offered BESIDE ours, never instead of it and never
       concatenated inside it — InstagramClient::fail() keeps the two apart so a
       screen can do exactly this. It has already been scrubbed of the app secret
       and the access token by that class. */
    if (body && body.detail) { text += ' — ' + body.detail; }
    return text;
  }

  /*
   * The sentence the OAuth round trip came back with.
   *
   * ── AND IT IS TAKEN OUT OF THE ADDRESS BAR IMMEDIATELY ──────────────────
   *
   * `?ig_done=...` left in the URL is a banner that comes back every time the
   * owner reloads, long after it stopped being true — and one he cannot get rid
   * of without editing the address bar. replaceState() drops it without adding a
   * history entry, so Back still goes where it went before.
   *
   * The text is read as a STRING and printed through esc(). It is our own
   * sentence rather than Meta's, chosen in InstagramController::callback() from a
   * fixed set — but it arrives via a query string either way, so it is escaped
   * like anything else that does.
   */
  function readLanding() {
    var params = new URLSearchParams(window.location.search);
    var done = params.get('ig_done');
    var bad = params.get('ig_error');
    var detail = params.get('ig_detail');

    if (!done && !bad) return;

    landed = { ok: !!done && !bad, text: (done || bad || ''), detail: detail || '' };

    params.delete('ig_done'); params.delete('ig_error'); params.delete('ig_detail');
    var query = params.toString();
    try {
      window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + '#instagram');
    } catch (e) {}
  }

  readLanding();

  /* ------------------------------------------------------------------- the popup */

  /*
   * ── THE ONE WORD THAT CROSSES BETWEEN THE WINDOWS ───────────────────────
   *
   * A CONSTANT, AND IT CARRIES NOTHING. Not the token, not the app secret, not the
   * profile, not even a success flag — this exact string and no other shape is
   * accepted by the listener below. What the popup is saying is "I have finished,
   * go and ask the server", and the screen then does exactly that with its own
   * authenticated read of /admin-api/instagram.
   *
   * That is not caution for its own sake. A message is a value from ANOTHER WINDOW,
   * and a handler that believed a payload would be a handler that could be told the
   * shop is connected, or handed a sentence to print, by any page that got a handle
   * on this one. Carrying nothing is the only version of this that cannot be lied
   * to about anything — and the token never needed to travel anyway: it is
   * exchanged and stored server-side by App\Services\Instagram\InstagramSync,
   * which is the whole reason a popup is safe here.
   */
  var OAUTH_DONE = 'kbb-instagram-oauth-done';

  /*
   * ── THIS SAME SCRIPT, RUNNING INSIDE THE POPUP ──────────────────────────
   *
   * The callback lands the popup back on this console's own URL with `?ig_done=` or
   * `?ig_error=` on it (InstagramController::screenUrl()), which means the admin page
   * — and this file with it — loads in the popup. So the popup half of the handshake
   * needs NO new route, NO new view and NO new endpoint: it is these ten lines,
   * running in a window that has an opener and a landing parameter.
   *
   * `window.location.origin` AS THE TARGET ORIGIN, never '*'. A '*' here would
   * broadcast to whatever the opener has since navigated to, and the whole point of
   * the origin argument is that the browser refuses to deliver when the opener is
   * not who we think it is. The word itself is harmless; the habit is not.
   *
   * `window.opener !== window` is belt and braces — `opener` is null in an ordinary
   * tab, which is the fallback path, and that path is left exactly as it was: it
   * prints the banner readLanding() just took out of the address bar.
   */
  if (landed && window.opener && window.opener !== window) {
    try { window.opener.postMessage(OAUTH_DONE, window.location.origin); } catch (e) {}
    /* CLOSED BY THE POPUP ITSELF, not by the opener. A popup that waits to be
       closed is a popup left open forever whenever the opener's listener is
       broken, and the owner is looking at a 620px-wide admin console wondering
       what to do with it. */
    try { window.close(); } catch (e) {}
  }

  /* ------------------------------------------------------------------ drawing */

  function stepsHTML() {
    var steps = (connection && connection.steps) || [];
    if (!steps.length) return '';

    return '<ol class="igs-steps">' + steps.map(function (s, i) {
      /* THREE STATES AND NOT TWO. `done === null` means this server cannot see
         the answer — whether the redirect URI is registered in Meta's dashboard,
         whether a tester invitation was accepted. A dashed ring says "we cannot
         check this one"; an empty solid ring would claim we looked. */
      var cls = s.done === true ? ' is-done' : (s.done === null || s.observable === false ? ' is-unknown' : '');
      var mark = s.done === true ? '&#10003;' : (s.done === null || s.observable === false ? '?' : String(i + 1));
      return '<li class="igs-step' + cls + '"><span class="igs-mark" aria-hidden="true">' + mark + '</span>'
        + '<span>' + esc(s.text) + '</span></li>';
    }).join('') + '</ol>'
      + '<p class="igs-help" style="margin-top:10px">A tick is read from what is actually saved on this '
      + 'server, not from a box you ticked. The two marked <b>?</b> are in your Meta dashboard and your '
      + 'phone, which this server cannot see — so it says so rather than guessing.</p>';
  }

  /* When the connection expires, in the owner's words, coloured by urgency. */
  function expiryHTML() {
    if (!connection || !connection.connected) return '';

    var days = connection.days_left;
    var when = connection.expires_at ? new Date(connection.expires_at) : null;
    var date = when && !isNaN(when.getTime())
      ? when.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })
      : null;

    if (connection.expired) {
      return '<div class="igs-note is-bad"><b>The connection has expired.</b> Instagram tokens last 60 days '
        + 'and can only be renewed while they are still valid, so this one needs the full authorisation '
        + 'again — press <b>Reconnect</b>. Your posts and their pictures are still here and the section on '
        + 'the shop is still showing them; only new posts have stopped arriving.</div>';
    }

    var soon = connection.needs_refresh;
    return '<div class="igs-note' + (soon ? ' is-warm' : ' is-good') + '">'
      + '<b>Connection valid' + (date ? ' until ' + esc(date) : '') + '</b>'
      + (days === null ? '' : ' — ' + esc(String(days)) + (days === 1 ? ' day' : ' days') + ' left')
      + '. ' + (soon
          ? 'It renews itself the next time you open this screen or press Refresh posts, so opening this '
            + 'page once now is enough. It is amber because this shop has no scheduled task to renew it '
            + 'for you — there is no cron on this server.'
          : 'It renews itself automatically whenever you open this screen inside the last week, so '
            + 'opening this page about once a month is all it needs. There is no cron on this server to '
            + 'do it for you.')
      + '</div>';
  }

  function profileHTML() {
    if (!me || !me.username) return '';

    var bits = [];
    if (me.followers !== null && me.followers !== undefined) { bits.push(Number(me.followers).toLocaleString() + ' followers'); }
    if (me.posts !== null && me.posts !== undefined) { bits.push(Number(me.posts).toLocaleString() + ' posts'); }
    if (me.account_type) { bits.push(me.account_type === 'MEDIA_CREATOR' ? 'Creator account' : me.account_type.charAt(0) + me.account_type.slice(1).toLowerCase() + ' account'); }

    return '<div class="igs-me">'
      + (me.avatar ? '<img src="' + esc(me.avatar) + '" alt="" width="56" height="56">' : '')
      + '<div><b>' + esc(me.name || ('@' + me.username)) + '</b>'
      + '<span>@' + esc(me.username) + (bits.length ? ' · ' + esc(bits.join(' · ')) : '') + '</span>'
      + (content ? '<span>' + esc(String(content.posts)) + ' posts fetched, '
          + esc(String(content.drawable)) + ' with a picture stored on this shop</span>' : '')
      + '</div></div>'
      /* THE ONE CASE THAT LOOKS LIKE A BUG AND IS NOT. Rows fetched but no
         pictures means the thumbnail downloads are failing — a disk, a
         permission, a CDN — and the grid is empty with a successful fetch behind
         it. Two numbers rather than one is what makes this sayable at all. */
      + (content && content.posts > 0 && content.drawable === 0
          ? '<div class="igs-note is-bad"><b>' + esc(String(content.posts)) + ' posts were fetched but not '
            + 'one picture could be stored</b>, so the section draws nothing. That is a disk or permissions '
            + 'problem on <b>storage/app/public/uploads/instagram/</b> rather than an Instagram one — a '
            + 'post with no picture is never drawn, because a tile with no picture is a hole in the page.'
            + '</div>'
          : '')
      + (me.account_type === 'PERSONAL'
          ? '<div class="igs-note is-bad"><b>This is a personal account.</b> Instagram returns no media and '
            + 'no counts for one, at any price. In the Instagram app: Settings → Account type and tools → '
            + 'Switch to professional account → Business or Creator, then press Refresh posts. You do not '
            + 'have to reconnect.</div>'
          : '');
  }

  function connectionHTML() {
    var c = connection || {};
    var ready = c.app_id && c.secret_saved;

    return '<div class="igs-card">'
      + '<div class="igs-title">Connection</div>'
      + '<p class="igs-sub">This shop reads <b>our own</b> Instagram account through Meta\'s Graph API, '
      + 'which is what makes the real like and comment counts available at all — they are ordinary fields '
      + 'on our own posts. Nothing here is sent to Instagram until you press Configure now.</p>'

      + stepsHTML()

      + '<div class="igs-note"><b>Step 4 — the redirect URI.</b> Paste this into your Meta app under '
      + '<b>Instagram → API setup with Instagram login → Business login settings → OAuth redirect URIs</b>, '
      + 'exactly as it reads. One character of difference is the error Meta reports as a redirect_uri '
      + 'mismatch, and it is the commonest way this whole thing fails.'
      + '<div class="igs-uri"><code data-igs-uri>' + esc(c.redirect_uri || '') + '</code>'
      + '<button class="igs-btn" data-igs-copy>Copy</button></div></div>'

      + '<div class="igs-fields">'
      + '<div class="igs-f"><div class="igs-fh"><label for="igs-appid">Instagram app ID</label></div>'
      + '<input id="igs-appid" type="text" inputmode="numeric" autocomplete="off" spellcheck="false"'
      + ' value="' + esc(c.app_id || '') + '" data-igs-appid>'
      + '<p class="igs-help">A number, usually 15 or 16 digits, on the “API setup with Instagram login” '
      + 'panel. Not a secret — it travels in the authorisation URL in your own address bar, which is why '
      + 'it is shown here and the secret below is not.</p></div>'

      + '<div class="igs-f"><div class="igs-fh"><label for="igs-secret">Instagram app secret</label></div>'
      /* type=password so a shoulder does not read it while it is being pasted,
         and autocomplete off so no browser offers to remember it. Drawn EMPTY
         whether or not one is stored: asterisks the length of the value disclose
         the length, and an empty box already means "unchanged". */
      + '<input id="igs-secret" type="password" autocomplete="off" spellcheck="false"'
      + ' placeholder="' + (c.secret_saved ? 'A secret is stored — leave empty to keep it' : 'Required the first time') + '"'
      + ' data-igs-secret>'
      + '<p class="igs-help">Stored encrypted and never shown again, not even here. Leaving this empty '
      + 'keeps the one already saved, so you can correct the app ID on its own.</p></div>'
      + '</div>'

      + '<div class="igs-actions">'
      + '<button class="igs-btn" data-igs-savekeys' + (busy ? ' disabled' : '') + '>Save app ID and secret</button>'
      /*
       * ── A REAL LINK AND NOT A FETCH, AND NOW A POPUP OVER THE TOP ─────────
       *
       * The original reasoning is kept because it is right and because the anchor
       * survives on it: an OAuth handshake is a top-level navigation to a third
       * party and AN XHR CANNOT LOG ANYBODY IN TO ONE. It also means an owner
       * whose JavaScript is having a bad day can still finish the connection by
       * clicking it.
       *
       * What that reasoning rules out is an XHR. It does NOT rule out a popup: a
       * popup IS a top-level navigation, in a window of its own, which is exactly
       * why it is the standard shape for this handshake — the opener's page stays
       * alive, so the screen does not have to be rebuilt from a redirect and the
       * owner does not lose the tab he was working in.
       *
       * So the click handler opens `/instagram/start` with window.open and, only
       * if that returns a window, calls preventDefault(). A blocked popup returns
       * null and the anchor's own navigation happens instead — which is the
       * fallback the sentence above describes, reached by doing nothing rather
       * than by a second code path that has to be kept working. The popup posts
       * one constant word back when it is finished and the screen then ASKS THIS
       * SERVER what the connection's state is; see the `message` listener.
       *
       * Still an <a> with a real href for the third reason too: middle-click and
       * the context menu's "open in new tab" keep working, a modified click is
       * handed straight back to the browser by the handler, and a control that is
       * not a link cannot be any of those.
       */
      + '<a class="igs-btn is-primary' + (ready ? '' : ' is-off') + '" href="' + esc(base()) + '/instagram/start"'
      + (ready ? ' data-igs-oauth' : ' aria-disabled="true" tabindex="-1"') + '>'
      + (c.connected ? 'Reconnect' : 'Configure now') + '</a>'
      + (c.connected
          ? '<button class="igs-btn" data-igs-refresh' + (busy ? ' disabled' : '') + '>Refresh posts</button>'
            + '<button class="igs-btn is-bad" data-igs-disconnect' + (busy ? ' disabled' : '') + '>Disconnect</button>'
          : '')
      + ((content && content.posts > 0)
          ? '<button class="igs-btn is-bad" data-igs-clear' + (busy ? ' disabled' : '') + '>Delete the ' + esc(String(content.posts)) + ' stored posts</button>'
          : '')
      + '</div>'

      /* WHAT THE BUTTON IS ABOUT TO DO, SAID BEFORE IT DOES IT. The owner asked
         for one click; one click is not honestly enough, and a button that
         implies it is is a button that gets blamed for Meta's refusals. */
      /* The LABEL and not the words "Configure now": the button beside this
         sentence reads "Reconnect" once a token is stored, and a paragraph naming
         a button that is not on the screen is a paragraph the owner reads twice
         looking for it. Caught in the 1280px screenshot. */
      + (ready
          ? '<p class="igs-help" style="margin-top:10px"><b>' + (c.connected ? 'Reconnect' : 'Configure now')
            + '</b> will send you to Instagram\'s '
            + 'own permission screen, asking only for <b>' + esc(c.scope || '') + '</b> — permission to read '
            + 'our own posts. Nothing else. When you come back, this shop exchanges the authorisation for a '
            + '60-day token, stores it encrypted, and fetches the profile and the most recent 25 posts with '
            + 'their pictures. Every step after you press Allow is automatic.</p>'
          : '<p class="igs-help" style="margin-top:10px"><b>Configure now is greyed out</b> until the app ID '
            + 'and secret above are saved — steps 2 and 3. Without them there is no app to authorise '
            + 'against.</p>')

      + expiryHTML()
      + profileHTML()
      + '</div>';
  }

  /* ------------------------------------------------------------------ preview */

  /*
   * ── THE PREVIEW IS A DRAWING AND IT MOVES ON INPUT ──────────────────────
   *
   * Everything below reads `values`, which is what the CONTROLS currently say --
   * not what is saved. So the drawing is the unsaved state, which is the only
   * state worth previewing: a preview of the saved settings is a picture of what
   * the owner can already go and look at.
   *
   * It restates the storefront's arrangement rather than embedding it. The
   * alternative -- an iframe of the shop -- costs an authenticated request and a
   * full page render on every keystroke and every slider tick, which the brief
   * rules out in as many words, and it cannot show UNSAVED settings at all
   * without the shop reading them out of a query string (which is rule 5's "a
   * select stores one of its own options or the default" pointed straight at the
   * thing that decides what to render -- tools/ig-shots.cjs's header records that
   * exact idea being rejected for this feature's camera).
   *
   * WHERE IT IS HONESTLY DIFFERENT FROM THE SHOP, IT SAYS SO under the frame
   * rather than quietly differing: the caption overlay is always visible here and
   * hover-only there, and a count is drawn on a real post only because this shop
   * does not invent a number it has not fetched.
   */

  /** One of the schema's own options, or its default — never whatever is in `values`. */
  function pick(key, allowed, fallback) {
    var v = String(values[key] == null ? '' : values[key]);
    return allowed.indexOf(v) >= 0 ? v : fallback;
  }

  function pvNum(key, fallback, low, high) {
    var n = Number(values[key]);
    if (!isFinite(n)) n = fallback;
    return Math.max(low, Math.min(high, Math.round(n)));
  }

  /* The heart and the speech bubble, literals in this file — which is what makes
     printing them unescaped correct (rule 5: anything printed unescaped is a
     constant, never a setting). The same two paths the section draws. */
  var PV_HEART = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
    + '<path d="M12 21s-7.5-4.6-9.3-9A5.3 5.3 0 0 1 12 6.5 5.3 5.3 0 0 1 21.3 12c-1.8 4.4-9.3 9-9.3 9z"/></svg>';
  var PV_BUBBLE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" '
    + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
    + '<path d="M21 11.5A8.4 8.4 0 0 1 12 20a9 9 0 0 1-4-.9L3 20l1.3-3.8A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg>';

  function pvCount(n) {
    /* `!== null` AND NOT A TRUTHINESS TEST, which is the whole of
       docs/UGC-ENGAGEMENT.md's rule in one line: a reel posted an hour ago
       genuinely has zero comments, and hiding an honest zero is the same defect
       as inventing a number. 0 draws "0"; null draws nothing at all. */
    return (n === null || n === undefined) ? null : Number(n).toLocaleString();
  }

  /** One cell. The same markup for all five layouts, so there is one place the escaping lives. */
  function pvTile(tile, opt) {
    var ghost = tile === null;
    var cls = 'igs-pvc'
      + (ghost ? ' is-ghost' : '')
      + (!ghost && opt.play && tile.video ? ' is-video' : '')
      + (!ghost && tile.carousel ? ' is-album' : '');

    var inner = ghost ? '' : '<img src="' + esc(tile.image) + '" alt="" loading="lazy" decoding="async">';

    if (!ghost && opt.caption && tile.caption) {
      inner += '<span class="igs-pvcap"><span>' + esc(tile.caption) + '</span></span>';
    }

    if (!ghost && opt.counts) {
      var likes = pvCount(tile.likes), comments = pvCount(tile.comments);
      if (likes !== null || comments !== null) {
        inner += '<span class="igs-pvm">'
          + (likes !== null ? '<span>' + PV_HEART + esc(likes) + '</span>' : '')
          + (comments !== null ? '<span>' + PV_BUBBLE + esc(comments) + '</span>' : '')
          + '</span>';
      }
    }

    return '<div class="' + cls + '">' + inner + '</div>';
  }

  /** The profile box, or a ghost of it, or nothing at all. */
  function pvProfile(style) {
    if (style === 'off' || style === 'inline') return '';

    var real = me && me.username;
    var bits = [];
    if (real) {
      if (me.followers !== null && me.followers !== undefined) { bits.push(Number(me.followers).toLocaleString() + ' followers'); }
      if (me.posts !== null && me.posts !== undefined) { bits.push(Number(me.posts).toLocaleString() + ' posts'); }
    }

    return '<div class="igs-pvp is-' + esc(style) + '">'
      + (real && me.avatar ? '<img src="' + esc(me.avatar) + '" alt="">' : '<span class="igs-pvav"></span>')
      + '<div class="igs-pvn"><b>' + esc(real ? (me.name || ('@' + me.username)) : 'Your account name') + '</b>'
      /* NO INVENTED NUMBER BEFORE THE CONNECTION EXISTS. A placeholder that read
         "48,219 followers" would be this screen telling the owner something
         false, which is the one thing the section's own count rule forbids. */
      + '<span>' + esc(real
          ? ('@' + me.username + (bits.length ? ' · ' + bits.join(' · ') : ''))
          : 'Followers and post count appear once the connection has fetched them') + '</span></div>'
      + '<span class="igs-pvfl">Follow</span>'
      + '</div>';
  }

  /*
   * What the preview is showing and where it came from, in words.
   *
   * THE HONEST DIFFERENCES ARE LISTED HERE rather than left for the owner to
   * discover on the shop. A preview that quietly differs from the thing it
   * previews is worse than no preview, because it is believed.
   */
  function pvNote(drawn, stored, cap, tap) {
    var lines = [];

    if (!(me && me.username)) {
      lines.push('<b>The profile box is a placeholder.</b> Your avatar, name and follower count are drawn '
        + 'from what the connection fetches, so they appear here the moment it has — and no number is '
        + 'invented in the meantime.');
    }

    if (stored === 0) {
      lines.push('<b>These are placeholders.</b> No Instagram post with a stored picture has been fetched '
        + 'yet, so the frame above shows ' + esc(String(drawn)) + ' empty tiles at the size and spacing '
        + 'your settings ask for. Connect above and press Refresh posts and the real pictures appear here.');
    } else {
      /* STORED AND DRAWN ARE DIFFERENT NUMBERS and the sentence says which is
         which. Written with one number it read "drawn from the 6 posts this shop
         has already stored" on a shop holding nine with the slider at six — a
         sentence that tells the owner he has lost three posts. Caught in the
         390px picture. */
      lines.push('<b>Drawn from the ' + esc(String(stored)) + ' post' + (stored === 1 ? '' : 's')
        + ' this shop has already stored</b>, newest first — the same rows, the same order and the same '
        + 'arrangement the section uses on the shop.');

      if (drawn < stored) {
        lines.push('Your <b>How many posts</b> is ' + esc(String(cap)) + ', so the newest '
          + esc(String(drawn)) + ' of them are drawn and the rest are kept — here and on the shop alike.');
      }

      if (stored < cap) {
        lines.push('Your <b>How many posts</b> is ' + esc(String(cap)) + ', and there are only '
          + esc(String(stored)) + ' with a picture stored, so the section draws ' + esc(String(stored))
          + ' — here and on the shop alike. Press <b>Refresh posts</b> to fetch more.');
      }
    }

    lines.push('Two things are deliberately different from the shop and both are about being able to see '
      + 'what you just switched on: the <b>caption</b> overlay sits open here and only appears on hover or '
      + 'tap there, and a <b>like or comment count is drawn on a real post only</b> — this shop never '
      + 'invents a number it has not fetched, so a placeholder carries none.');

    lines.push('<b>What a tap does</b> is not something a drawing can show. Yours is set to: '
      + esc(tap === 'embed' ? 'play the post here, in a box over the page'
          : (tap === 'nothing' ? 'nothing — the tiles are pictures, not links'
            : 'open the post on Instagram, in a new tab')) + '.');

    return '<p class="igs-help" style="margin-top:11px">' + lines.join(' ') + '</p>';
  }

  function previewHTML() {
    var layout = pick('layout', ['grid', 'rail', 'mosaic', 'masonry', 'strip'], 'grid');
    var style = pick('profile_style', ['card', 'bar', 'inline', 'off'], 'card');
    var tap = pick('tap', ['permalink', 'embed', 'nothing'], 'permalink');
    var cap = pvNum('posts', 9, 3, 24);
    var gap = pvNum('gap', 8, 0, 24);
    var radius = pvNum('radius', 10, 0, 28);
    var heading = String(values.heading == null ? '' : values.heading);

    var opt = { counts: values.counts === true, caption: values.caption === true, play: values.play_badge === true };

    var real = (content && content.tiles) ? content.tiles : [];
    /* The shop draws what it HAS, never a padded row of blanks — so when some
       posts are stored the preview draws exactly those and the note explains the
       shortfall. Only a shop with nothing at all gets ghosts. */
    var cells = real.length
      ? real.slice(0, cap).map(function (t) { return pvTile(t, opt); })
      : (function () {
          var out = [];
          for (var i = 0; i < cap; i++) out.push(pvTile(null, opt));
          return out;
        })();

    /* `inline` puts the avatar in the grid instead of in a box. A ghost cell when
       there is no avatar yet, rather than the nothing the shop draws: the control
       has to be visibly doing something on the one screen where it is chosen, and
       the note under the frame says which cells are placeholders. */
    var inlineCell = style === 'inline'
      ? (me && me.avatar
          ? '<div class="igs-pvc"><img src="' + esc(me.avatar) + '" alt=""></div>'
          : '<div class="igs-pvc is-ghost"></div>')
      : '';

    /*
     * THE SAME EXPRESSIONS App\Services\InstagramSettings::cssVariables() WRITES,
     * and the comment there is the reason they are not rounder numbers: n tiles
     * carry (n-1) whole gaps plus the fraction of one belonging to the partly
     * visible tile, and getting that wrong is how a rail overflows by 24px. Two
     * copies of one piece of arithmetic is a real cost; two DIFFERENT pieces of
     * arithmetic in a preview and the thing it previews is a worse one.
     */
    var width = layout === 'rail' ? 'calc((100% - ' + (gap * 1.3) + 'px) / 2.3)'
      : (layout === 'strip' ? 'calc((100% - ' + (gap * 3.5) + 'px) / 4.5)' : 'auto');

    var handle = me && me.username ? '@' + me.username : '';

    return '<div class="igs-card" data-igs-preview>'
      + '<div class="igs-pvh"><b>Preview</b>'
      + '<span>Redraws as you type or drag. A drawing of the section, not the live page.</span>'
      /* The two widths rule 2 asks for pictures at, switchable without resizing
         the window — and the reason the frame is a container query rather than a
         media one, because a max-width on the frame is all this has to do. */
      + '<span class="igs-pvw">'
      + '<button type="button" data-igs-pvwidth="phone" aria-pressed="' + (pvPhone ? 'true' : 'false') + '">Phone</button>'
      + '<button type="button" data-igs-pvwidth="wide" aria-pressed="' + (pvPhone ? 'false' : 'true') + '">Desktop</button>'
      + '</span></div>'

      + '<div class="igs-pvf' + (pvPhone ? ' is-phone' : '') + '">'
      + '<div class="igs-pv" style="--igsp-w:' + esc(width) + ';--igsp-gap:' + esc(String(gap))
      + 'px;--igsp-r:' + esc(String(radius)) + 'px">'
      /* The shop's own condition: the header row exists when there is a heading or
         a handle to put in it, and nothing at all when there is neither. */
      + ((heading !== '' || handle !== '')
          ? '<div class="igs-pvhd">' + (heading !== '' ? '<h3>' + esc(heading) + '</h3>' : '')
            + (handle !== '' ? '<i>' + esc(handle) + '</i>' : '') + '</div>'
          : '')
      + pvProfile(style)
      + '<div class="igs-pvt is-' + esc(layout) + '">' + inlineCell + cells.join('') + '</div>'
      + '</div></div>'

      + pvNote(cells.length, real.length, cap, tap)
      + '</div>';
  }

  /*
   * Repaint the drawing WITHOUT rebuilding the controls.
   *
   * render() replaces #content wholesale, and doing that on every `input` event
   * takes the slider out from under the finger dragging it and puts the caret at
   * the end of the heading box on the second character. Both faults are already
   * recorded in this console — the checkout screen's paintPreview() carries the
   * same note — so the preview is swapped in place instead.
   */
  function paintPreview() {
    var node = document.querySelector('[data-igs-preview]');
    if (!node) return;
    var holder = document.createElement('div');
    holder.innerHTML = previewHTML();
    node.replaceWith(holder.firstChild);
  }

  function unitOf(field) {
    return (field && field.options && field.options.unit) ? String(field.options.unit) : '';
  }

  function fieldHTML(f) {
    var id = 'igs-' + f.key;
    var help = f.help ? '<p class="igs-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="igs-f"><div class="igs-check">'
        + '<input id="' + esc(id) + '" type="checkbox" data-igs-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + esc(id) + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="igs-f"><div class="igs-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + esc(id) + '" data-igs-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="igs-f"><div class="igs-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label>'
        + '<span class="igs-val" data-igs-val="' + esc(f.key) + '">' + esc(String(values[f.key])) + esc(unitOf(f)) + '</span></div>'
        + '<input id="' + esc(id) + '" type="range" data-igs-key="' + esc(f.key) + '"'
        + ' min="' + esc(String(o.min)) + '" max="' + esc(String(o.max)) + '" step="' + esc(String(o.step || 1)) + '"'
        + ' value="' + esc(String(values[f.key])) + '">' + help + '</div>';
    }

    return '<div class="igs-f"><div class="igs-fh"><label for="' + esc(id) + '">' + esc(f.label) + '</label></div>'
      + '<input id="' + esc(id) + '" type="text" data-igs-key="' + esc(f.key) + '"'
      + ' value="' + esc(values[f.key] == null ? '' : values[f.key]) + '">' + help + '</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host) return;

    if (tabs === null) {
      host.innerHTML = '<div class="igs-wrap" data-igs-screen><div class="igs-card"><div class="igs-empty">'
        + (banner ? esc(banner.text) : 'Loading…') + '</div></div></div>';
      return;
    }

    var current = null;
    tabs.forEach(function (t) { if (t.key === open) current = t; });

    var strip = tabs.map(function (t) {
      return '<button class="igs-tab" role="tab" aria-selected="' + (t.key === open ? 'true' : 'false') + '"'
        + ' data-igs-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
    }).join('');

    /* `data-igs-screen` on the wrapper in BOTH render paths: it is how onScreen()
       knows this module is still the one on display when a popup finishes minutes
       after the owner walked off to another screen. */
    host.innerHTML = '<div class="igs-wrap" data-igs-screen>'
      + (banner ? '<div class="igs-note ' + (banner.ok ? 'is-good' : 'is-bad') + '">' + esc(banner.text)
          + (banner.detail ? ' <b>Instagram said:</b> ' + esc(banner.detail) : '') + '</div>' : '')

      + (moduleOn ? '' : '<div class="igs-note is-warm"><b>This module is switched off, so nothing on this '
          + 'screen changes the shop yet.</b> Turn it on in <b>Store → Modules → Instagram Profile</b>. Even '
          + 'then the section appears only where you have put it: the <b>Instagram Profile</b> row on '
          + '<b>Appearance → Homepage</b>, or a <b>[kbb_instagram]</b> shortcode in any page, post or HTML '
          + 'block.</div>')

      + connectionHTML()

      + (current
          ? '<div class="igs-card">'
            + '<div class="igs-tabs">' + strip + '</div>'
            + '<p class="igs-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
            + '<div class="igs-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
            + '<div class="igs-actions">'
            + '<button class="igs-btn is-primary" data-igs-save' + (busy ? ' disabled' : '') + '>'
            + (busy ? 'Saving…' : 'Save') + '</button>'
            + '<button class="igs-btn" data-igs-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
            + '</div>'
            + '<div class="igs-note"><b>Where this shows up.</b> The homepage draws it in its own '
            + '<b>Instagram Profile</b> row — switch that row on, off, or move it up and down in '
            + '<b>Appearance → Homepage</b>. Anywhere else, write <b>[kbb_instagram]</b>; the shortcode takes '
            + 'its own <b>layout</b>, <b>limit</b>, <b>profile</b> and <b>title</b> if you want one place to '
            + 'differ from another, for example '
            + '<b>[kbb_instagram layout="strip" profile="off" limit="6"]</b> in a footer.</div>'
            + '</div>'
          : '')

      /* THE PREVIEW SITS UNDER THE CONTROLS AND NOT BESIDE THEM. One column at
         every width, which is the answer the checkout screen's own header
         records the owner asking for: "bring the preview to downwards, so i can
         see better". A section mock in a side rail shows nothing, and this one
         has to be wide enough that three columns read as three columns.

         Drawn whenever there are controls to draw, regardless of which tab is
         open: BOTH tabs change it. Tabs are where a control lives; the section is
         one thing. */
      + (current ? previewHTML() : '')
      + '</div>';
  }

  /* ------------------------------------------------------------------ loading */

  async function load() {
    var mine = ++seq;
    busy = true;
    /* The landing banner survives the load it triggered — it is the answer to the
       thing the owner just did, and throwing it away on the refresh that follows
       is how "Connected to Instagram" never gets read. Cleared on the NEXT
       action, not by the load it caused. */
    if (landed) { banner = landed; landed = null; }
    render();

    try {
      var body = await api('/instagram');
      if (mine !== seq) return;
      tabs = body.tabs || [];
      moduleOn = body.module_on === true;
      connection = body.connection || null;
      me = body.profile || null;
      content = body.content || null;
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (!open || !tabs.some(function (t) { return t.key === open; })) {
        open = tabs.length ? tabs[0].key : null;
      }
    } catch (e) {
      tabs = null;
      banner = { ok: false, text: explain(e, 'Content → Instagram could not be loaded.') };
    } finally {
      busy = false;
      render();
    }
  }

  async function save() {
    if (busy) return;
    busy = true; banner = null; render();
    try {
      var body = await api('/instagram', { settings: values });
      var rejected = Object.keys(body.rejected || {});
      banner = rejected.length
        ? { ok: false, text: 'Saved, except: ' + rejected.map(function (k) { return body.rejected[k]; }).join(', ') + '.' }
        : { ok: true, text: 'Saved.' };
      say(rejected.length ? 'Saved with ' + rejected.length + ' refused' : 'Saved');
    } catch (e) {
      banner = { ok: false, text: explain(e, 'That could not be saved.') };
    } finally {
      busy = false; render();
    }
  }

  async function saveKeys() {
    if (busy) return;
    var idEl = document.querySelector('[data-igs-appid]');
    var secretEl = document.querySelector('[data-igs-secret]');
    busy = true; banner = null; render();
    try {
      var body = await api('/instagram/app', {
        app_id: idEl ? idEl.value : '',
        app_secret: secretEl ? secretEl.value : ''
      });
      connection = body.connection || connection;
      banner = { ok: true, text: 'App ID and secret saved. Press Configure now to authorise this shop.' };
      say('Saved');
    } catch (e) {
      banner = { ok: false, text: explain(e, 'Those could not be saved.') };
    } finally {
      busy = false; render();
    }
  }

  async function refresh() {
    if (busy) return;
    busy = true; banner = null; render();
    try {
      var body = await api('/instagram/refresh', {});
      connection = body.connection || connection;
      me = body.profile || me;
      content = body.content || content;
      banner = { ok: true, text: body.stored + ' posts fetched, ' + body.pictures + ' pictures stored'
        + (body.failed ? ', ' + body.failed + ' without a picture' : '')
        + (body.pruned ? ', ' + body.pruned + ' removed because they are gone from Instagram' : '') + '.' };
      say('Refreshed');
    } catch (e) {
      if (e && e.body && e.body.connection) { connection = e.body.connection; }
      if (e && e.body && e.body.content) { content = e.body.content; }
      banner = { ok: false, text: explain(e, 'Instagram could not be reached.') };
    } finally {
      busy = false; render();
    }
  }

  async function disconnect(alsoPosts) {
    if (busy) return;
    /*
     * CONFIRMED, AND THE TWO SENTENCES ARE DIFFERENT because the two acts are.
     * Disconnecting revokes this shop's ACCESS and leaves the section rendering
     * what it rendered yesterday. Deleting the posts throws away CONTENT and
     * empties it. Rolling them into one button is how an owner who wanted to
     * change Meta apps finds his homepage section blank.
     */
    var ask = alsoPosts
      ? 'Delete every stored Instagram post and the pictures downloaded for them? The section will be '
        + 'empty until you fetch again. This does not touch your Instagram account.'
      : 'Disconnect this shop from Instagram? The posts already fetched stay, and the section keeps '
        + 'showing them — only new posts stop arriving. Your app ID and secret are kept so reconnecting '
        + 'is one press.';

    if (!window.confirm(ask)) return;

    busy = true; banner = null; render();
    try {
      var body = await api('/instagram' + (alsoPosts ? '?posts=1' : ''), undefined, 'DELETE');
      connection = body.connection || connection;
      me = body.profile || null;
      content = body.content || content;
      banner = { ok: true, text: alsoPosts
        ? body.removed + ' posts deleted, and the connection closed.'
        : 'Disconnected. The posts already fetched are still here.' };
      say('Done');
    } catch (e) {
      banner = { ok: false, text: explain(e, 'That could not be done.') };
    } finally {
      busy = false; render();
    }
  }

  /* ---------------------------------------------------------- the opener's half */

  /*
   * Is this screen still the one on display?
   *
   * A popup can be open while the owner wanders off to Orders. The handshake
   * finishes, the listener fires, and load() would then paint an Instagram screen
   * into a #content that belongs to somebody else's module. `data-igs-screen` is on
   * this screen's own wrapper in every render path, so its absence is the answer —
   * and it is a presence test rather than anything that asks the browser for a size,
   * because rule 4 forbids the measuring APIs by name in this file.
   */
  function onScreen() {
    return document.querySelector('[data-igs-screen]') !== null;
  }

  function endWatch() {
    if (popupWatch !== null) { window.clearInterval(popupWatch); popupWatch = null; }
  }

  /*
   * ── THE ONE TIMER, AND WHY IT HAS TO EXIST ──────────────────────────────
   *
   * There is no event for "the owner closed the popup", so the only way to notice an
   * abandoned handshake is to look. That makes this the single piece of polling in
   * the feature, and the brief's "no polling loop left running after the popup
   * closes" is met by it having THREE ways to stop and no way to survive:
   *
   *   · the popup reports `closed` — cleared, then the state is re-read once, which
   *     also covers a completion whose message never arrived;
   *   · the message arrives — the listener calls endWatch() first;
   *   · the state's own lifetime runs out — InstagramAuth::STATE_TTL_SECONDS is 900,
   *     after which the callback refuses anyway, so a window left open past it has
   *     nothing left to come back with.
   *
   * endWatch() is also the FIRST thing start() does, so pressing Configure twice
   * leaves one timer and not two.
   */
  function watchPopup() {
    endWatch();

    var waited = 0;

    popupWatch = window.setInterval(function () {
      waited += 700;

      var gone;
      try { gone = !popup || popup.closed; } catch (e) { gone = true; }

      if (gone) {
        endWatch();
        popup = null;
        afterOauth();
        return;
      }

      if (waited >= 900 * 1000) { endWatch(); popup = null; }
    }, 700);
  }

  /*
   * Open the handshake in its own window, or say it could not be.
   *
   * `location=yes` is deliberate: an OAuth window with no address bar is a window in
   * which the owner cannot check he is really on instagram.com, which is the thing
   * every phishing guide tells him to check.
   */
  function openPopup(url) {
    var win = null;

    try {
      win = window.open(url, 'kbb-instagram-oauth',
        'popup=yes,width=620,height=780,location=yes,menubar=no,toolbar=no,status=no,resizable=yes,scrollbars=yes');
    } catch (e) { win = null; }

    if (!win) return null;

    popup = win;
    try { win.focus(); } catch (e) {}
    watchPopup();

    return win;
  }

  /*
   * The popup is finished (or was abandoned): ASK THE SERVER, then say what it said.
   *
   * ── THE STATE COMES FROM /admin-api/instagram AND FROM NOWHERE ELSE ─────
   *
   * Not from the message, which carries one constant word, and not from the popup's
   * URL. So "connected" on this screen means this server, over an authenticated
   * request, said a token is stored — which is the only thing worth believing.
   *
   * A REFUSAL IS REPORTED WITHOUT ITS SENTENCE, and that is a real and deliberate
   * loss. The exact reason Instagram gave went into the popup's own address bar and
   * closed with it, and carrying it back in the message is precisely what the rule
   * above forbids. So the popup path says what it honestly knows — the connection
   * did not complete — and points at the one path that still prints Meta's own
   * words: opening the step in this tab, where readLanding() reads it off the URL
   * exactly as it always did. Better a short true sentence and a way to get the long
   * one than a long sentence arriving through a channel this screen cannot vouch for.
   */
  var oauthPending = false;

  async function afterOauth() {
    if (oauthPending) return;
    if (!onScreen()) return;

    oauthPending = true;

    try {
      await load();

      if (!onScreen()) return;

      banner = (connection && connection.connected)
        ? { ok: true, text: 'Connected to Instagram. '
            + (content && content.drawable
                ? content.drawable + ' post' + (content.drawable === 1 ? '' : 's') + ' with a picture are '
                  + 'stored and the preview below is drawing them.'
                : 'Press Refresh posts to fetch the profile and the most recent posts.') }
        : { ok: false, text: 'The Instagram window closed without the connection being completed, so nothing '
            + 'was changed. Press Configure now again. If it keeps failing, Instagram’s own reason is printed '
            + 'in full when the step runs in this tab instead — block this site’s popups in your browser and '
            + 'press Configure now once more.' };

      render();
    } finally {
      oauthPending = false;
    }
  }

  /*
   * ── EVERY MESSAGE IS CHECKED FOR WHERE IT CAME FROM, FIRST ──────────────
   *
   * `event.origin !== window.location.origin` is the whole gate and it is the first
   * line rather than the last. A handler that reads `event.data` before it has
   * established who sent it is a handler any page with a handle on this one can talk
   * to — and what it would be telling this admin is that the shop is connected to
   * Instagram when it is not, which is a lie the owner acts on.
   *
   * Then the data must be the ONE CONSTANT. Not "starts with", not a parsed object,
   * not a shape with fields: equality against a literal, so there is no payload to
   * get wrong and nothing to validate. Anything else is dropped in silence, because
   * this window shares an origin with every other screen in this console and some of
   * them will one day post messages of their own.
   */
  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) return;
    if (e.data !== OAUTH_DONE) return;

    endWatch();

    try { if (popup && !popup.closed) popup.close(); } catch (err) {}
    popup = null;

    afterOauth();
  });

  /* ------------------------------------------------------------------ the wiring */

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Instagram',
      /* A literal in this file. The camera outline, drawn with the console's own
         stroke so it matches the rows beside it rather than being a brand mark —
         a brand asset in a sidebar is a licence question nobody asked for. */
      icon: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1"/>',
      group: 'Content',
      /* Anchored behind the Content rows that really exist. 'ugcsections' is the
         shoppable-video row's own id and this section is its nearest neighbour —
         both are "creator content placed with a shortcode". A dead anchor here is
         the mistake ugc-sections-screen.blade.php records having made. */
      after: ['ugcsections', 'media', 'htmlblocks', 'posts']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Content"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Content';
    if (title) title.textContent = 'Instagram';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    var tab = t.closest('[data-igs-tab]');
    if (tab) { e.preventDefault(); open = tab.getAttribute('data-igs-tab'); render(); return; }

    /*
     * ── THE POPUP, AND preventDefault() ONLY IF THERE IS ONE ──────────────
     *
     * The order of these three lines is the whole of the fallback. window.open
     * first; if it returned a window, and only then, stop the anchor. A blocked
     * popup returns null, nothing is prevented, and the browser follows the href
     * as it would have before this handler existed — so the owner finishes the
     * connection in this tab and reads Instagram's own sentence off the landing
     * banner.
     *
     * Written the other way round — preventDefault() first, then try to open —
     * a blocked popup is a button that does nothing at all, with no way for the
     * owner to tell that from a broken one. That is the failure this ordering
     * exists to make impossible rather than to handle.
     */
    var oauth = t.closest('[data-igs-oauth]');
    if (oauth) {
      /* A MODIFIED CLICK BELONGS TO THE BROWSER. Ctrl, Cmd, Shift and Alt on a link
         mean "open it somewhere else", and a handler that swallowed them would make
         this the one control in the console that ignores them. Nothing is prevented
         here, so the browser does what it always does with the href. */
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || (e.button !== undefined && e.button !== 0)) return;

      var win = openPopup(oauth.getAttribute('href'));
      if (!win) return;

      e.preventDefault();
      banner = { ok: true, text: 'Instagram is open in a separate window. Grant access there and it will '
        + 'close itself — this screen picks the connection up on its own, with nothing to reload.' };
      render();
      return;
    }

    var pvw = t.closest('[data-igs-pvwidth]');
    if (pvw) {
      e.preventDefault();
      pvPhone = pvw.getAttribute('data-igs-pvwidth') === 'phone';
      /* Repainted, not re-rendered: there is nothing above the preview that this
         changes, and a full render would scroll the card out from under the
         button that was just pressed. */
      paintPreview();
      return;
    }

    if (t.closest('[data-igs-save]')) { e.preventDefault(); save(); return; }
    if (t.closest('[data-igs-savekeys]')) { e.preventDefault(); saveKeys(); return; }
    if (t.closest('[data-igs-refresh]')) { e.preventDefault(); refresh(); return; }
    if (t.closest('[data-igs-disconnect]')) { e.preventDefault(); disconnect(false); return; }
    if (t.closest('[data-igs-clear]')) { e.preventDefault(); disconnect(true); return; }
    if (t.closest('[data-igs-reload]')) { e.preventDefault(); load(); return; }

    if (t.closest('[data-igs-copy]')) {
      e.preventDefault();
      var code = document.querySelector('[data-igs-uri]');
      if (!code) return;
      var text = code.textContent || '';
      /* The clipboard API needs a secure context and a permission the admin may
         not have. A selection is the fallback that always works: the owner
         presses his own copy shortcut and the right thing is already selected. */
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { say('Redirect URI copied'); },
          function () { say('Could not copy — select it and copy by hand'); });
      } else {
        try {
          var range = document.createRange();
          range.selectNodeContents(code);
          var sel = window.getSelection();
          sel.removeAllRanges(); sel.addRange(range);
          say('Selected — press your copy shortcut');
        } catch (err) { say('Select the address and copy it by hand'); }
      }
      return;
    }
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-igs-key')) return;
    var key = el.getAttribute('data-igs-key');

    /*
     * ── ON INPUT, NOT ON SAVE, AND paintPreview() RATHER THAN render() ──────
     *
     * Every branch below ends in the same repaint, which is what "the preview
     * moves as you type" means in code. It is paintPreview() and not render()
     * for the two faults this console has already paid for and the checkout
     * screen's own handler records: a full render replaces #content, which takes
     * the slider out from under the finger dragging it, and puts the caret at the
     * end of the heading box on the second character typed.
     */
    if (el.type === 'checkbox') { values[key] = el.checked; paintPreview(); return; }
    if (el.type === 'range') {
      values[key] = Number(el.value);
      /* Updated IN PLACE rather than by re-rendering: render() replaces #content
         wholesale, so a full repaint on every input event would take the slider
         out from under the finger dragging it. */
      var out = document.querySelector('[data-igs-val="' + key + '"]');
      if (out) {
        var field = null;
        (tabs || []).forEach(function (t) { t.fields.forEach(function (f) { if (f.key === key) field = f; }); });
        out.textContent = String(el.value) + (field ? unitOf(field) : '');
      }
      paintPreview();
      return;
    }
    values[key] = el.value;
    paintPreview();
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-igs-key')) return;
    /* A select fires `input` in every browser this console supports, so the line
       above has already stored it and repainted. This is the belt: `change` is the
       event a select is historically driven by, and the repaint is idempotent. */
    if (el.tagName === 'SELECT') { values[el.getAttribute('data-igs-key')] = el.value; paintPreview(); }
  });

  addNavEntry();
})();
</script>
@endverbatim
