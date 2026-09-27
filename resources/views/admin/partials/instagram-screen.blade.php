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
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'instagram';
  var tabs = null, values = {}, open = null, banner = null, busy = false, seq = 0;
  var moduleOn = false, connection = null, me = null, content = null;
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
       * A REAL LINK AND NOT A FETCH, because an OAuth handshake is a top-level
       * navigation to a third party and an XHR cannot log anybody in to one. It
       * also means an owner whose JavaScript is having a bad day can still
       * finish the connection by clicking it.
       */
      + '<a class="igs-btn is-primary' + (ready ? '' : ' is-off') + '" href="' + esc(base()) + '/instagram/start"'
      + (ready ? '' : ' aria-disabled="true" tabindex="-1"') + '>'
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
      host.innerHTML = '<div class="igs-wrap"><div class="igs-card"><div class="igs-empty">'
        + (banner ? esc(banner.text) : 'Loading…') + '</div></div></div>';
      return;
    }

    var current = null;
    tabs.forEach(function (t) { if (t.key === open) current = t; });

    var strip = tabs.map(function (t) {
      return '<button class="igs-tab" role="tab" aria-selected="' + (t.key === open ? 'true' : 'false') + '"'
        + ' data-igs-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
    }).join('');

    host.innerHTML = '<div class="igs-wrap">'
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

    if (el.type === 'checkbox') { values[key] = el.checked; return; }
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
      return;
    }
    values[key] = el.value;
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-igs-key')) return;
    if (el.tagName === 'SELECT') { values[el.getAttribute('data-igs-key')] = el.value; }
  });

  addNavEntry();
})();
</script>
@endverbatim
