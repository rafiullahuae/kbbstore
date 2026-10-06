/*
 * K-Beauty Bliss: register the shop's service worker (Lane PW), and in the
 * INSTALLED app ask once about notifications (Lane NT).
 *
 * Loaded with `defer` by partials/site-app-head only while App -> Site App is
 * on. It waits for the page's load event, so it never competes with the
 * first paint, and registers the worker whose address the tag carries.
 *
 * NOTIFICATIONS (Lane NT). The owner, 5 October: "the site app + owner app,
 * apps should ask by default about to allow notifications, after install when
 * the open the app". iOS lets a web page ask only from a Home Screen app and
 * only inside a tap; Chrome quietens a question nobody tapped for. So:
 *
 *   - in a browser tab: nothing at all, not even a request;
 *   - installed, permission 'default': one GET of /api/site-app/push (the
 *     question's wording in the page's language, and whether App -> Site App
 *     still wants it asked), then the shop's own small sheet, "Allow
 *     notifications" / "Not now". The browser's own question is asked from
 *     the Allow tap and from nowhere else.
 *   - "Not now", or closing the sheet any other way: not asked again on this
 *     phone for ASK_AGAIN_DAYS;
 *   - 'denied': never asked again (the browser forbids it anyway);
 *   - 'granted': at most once a day, makes sure the shop holds this phone's
 *     subscription under the current key (and who is signed in on it), and
 *     on a sold-out product page tells the shop once (interest() below).
 *
 * Never navigator.geolocation: where the phone is comes from the shopper's
 * orders, or the proxy's headers, on the server (SiteAppPush::locate()).
 *
 * No timer, no layout read, no polling; the sheet's CSS is written only when
 * the sheet is shown.
 */
/*
 * THE FOOTER'S APP ROW (Lane FB, 6 October): its Install button, its typing
 * line, and the WhatsApp button stepping aside while it is on screen.
 *
 * Nothing here runs unless the footer printed the row (.kfa[data-kfa]), and
 * nothing here makes a request: the install sheets are a <template> in the
 * page, and the browser's own install box is the browser's.
 *
 *   - Android Chrome / Edge / Samsung: the browser's install offer
 *     (beforeinstallprompt) is held when it arrives and shown from the tap.
 *   - No offer (not arrived yet, refused, Firefox): the "⋮ → Install app" sheet.
 *   - iPhone / iPad: the two-step Share → Add to Home Screen sheet.
 *   - Instagram, Facebook, TikTok and other in-app browsers: "open this page
 *     in your browser first" — they offer neither install nor Add to Home.
 *   - A laptop, only when "Show on laptops" printed the QR: the QR sheet.
 *   - Already installed: CSS hides the row (display-mode); navigator.standalone
 *     covers older iPhones here.
 *
 * ONE IntersectionObserver on the row does two jobs (the owner: "when the
 * install app row appear on screen, the floating whatsapp stuff must hide
 * super instantly"): it sets html.kfa-on, which hides #kbbWa in CSS with no
 * transition, and it starts and stops the typing. Its bottom margin is 64px,
 * so WhatsApp is gone a moment BEFORE the row scrolls in and the two never
 * share a frame. No scroll listener, no layout read.
 *
 * THE TYPING ("one line in english, then one line in arabic, and so on")
 * runs only while the row is on screen AND the tab is visible: one
 * setTimeout chain, cleared the moment either stops being true, and never
 * started for a visitor who asks for reduced motion — she keeps the first
 * line, printed by the server, still. Screen readers get the lines once from
 * a visually-hidden span; the typing span is aria-hidden.
 */
(function () {
  'use strict';
  var row = document.querySelector('.kfa[data-kfa]');
  if (!row) return;
  var html = document.documentElement, nav = navigator, ua = nav.userAgent || '', mm = window.matchMedia;
  if (nav.standalone === true) { row.hidden = true; return; }

  var btn = row.querySelector('.kfa-bt'), tpl = row.querySelector('template.kfa-tpl'), ty = row.querySelector('.kfa-ty');
  var offer = null;
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); offer = e; });
  window.addEventListener('appinstalled', function () { offer = null; row.hidden = true; inView = false; html.classList.remove('kfa-on'); sync(); });

  /* ── the typing ── */
  var seq = [];
  try { seq = JSON.parse(row.getAttribute('data-kfa') || '[]'); } catch (e) { seq = []; }
  var still = !!(mm && mm('(prefers-reduced-motion: reduce)').matches);
  var inView = false, timer = 0, at = 0, n = 0, phase = 'hold', chars = [];
  function load(k) {
    chars = Array.from(String(seq[k][1]));
    ty.setAttribute('lang', seq[k][0] ? 'ar' : 'en');
    ty.setAttribute('dir', seq[k][0] ? 'rtl' : 'ltr');
  }
  function live() { return inView && !document.hidden && !still && !!ty && seq.length > 1; }
  function step() {
    timer = 0;
    var wait;
    if (phase === 'type') {
      n++;
      if (n >= chars.length) { phase = 'hold'; wait = 2400; } else wait = 55;
    } else if (phase === 'hold') {
      phase = 'del'; wait = 30;
    } else {
      n--;
      if (n <= 0) { n = 0; at = (at + 1) % seq.length; load(at); phase = 'type'; wait = 420; } else wait = 22;
    }
    ty.textContent = chars.slice(0, n).join('');
    if (live()) timer = setTimeout(step, wait);
  }
  function sync() {
    var go = live();
    row.classList.toggle('kfa-run', go);
    if (!go) { if (timer) clearTimeout(timer); timer = 0; return; }
    if (!timer) timer = setTimeout(step, phase === 'hold' ? 1600 : 200);
  }
  if (ty && seq.length) { load(0); n = chars.length; }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (es) {
      inView = es[es.length - 1].isIntersecting && !row.hidden;
      html.classList.toggle('kfa-on', inView);
      sync();
    }, { rootMargin: '0px 0px 64px 0px' }).observe(row);
    document.addEventListener('visibilitychange', sync);
  }

  /* ── the tap ── */
  var CSS = '.kfa-bk{position:fixed;inset:0;z-index:2147483000;background:rgba(42,34,40,.38);display:flex;align-items:flex-end;justify-content:center}'
    + '.kfa-sh{box-sizing:border-box;position:relative;width:100%;max-width:440px;background:#fff;color:#2A2228;border-radius:24px 24px 0 0;padding:22px 20px calc(20px + env(safe-area-inset-bottom));box-shadow:0 -8px 30px rgba(42,34,40,.16);text-align:start}'
    + '.kfa-sh h2{margin:0 0 16px;padding-inline-end:40px;font-size:18px;line-height:1.3;font-weight:700;color:#2A2228}'
    + '.kfa-sh ol{list-style:none;margin:0;padding:0;display:grid;gap:14px;counter-reset:k}'
    + '.kfa-sh li{display:flex;gap:14px;align-items:center;font-size:14.5px;line-height:1.45;color:#5E545A;counter-increment:k}'
    + '.kfa-sh li>i{flex:none;position:relative;width:42px;height:42px;border-radius:13px;background:#FFF0F4;color:#C13E63;display:grid;place-items:center}'
    + '.kfa-sh li>i::before{content:counter(k);position:absolute;top:-6px;inset-inline-start:-6px;width:19px;height:19px;border-radius:50%;background:#C13E63;color:#fff;font-size:11px;font-weight:700;line-height:19px;text-align:center;font-style:normal}'
    + '.kfa-sh li svg{width:22px;height:22px}'
    + '.kfa-x{position:absolute;top:14px;inset-inline-end:14px;width:36px;height:36px;border:0;border-radius:50%;background:#F5EEF1;color:#2A2228;font-size:22px;line-height:1;cursor:pointer}'
    + '.kfa-x:focus-visible{outline:2px solid #C13E63;outline-offset:2px}'
    + '.kfa-qr,.kfa-qr svg{display:block;width:184px;height:184px;margin:0 auto 12px}'
    + '.kfa-sh p{margin:0;text-align:center;font-size:14px;color:#5E545A}'
    + '@media (min-width:600px){.kfa-bk{align-items:center}.kfa-sh{border-radius:24px}}';

  function kind() {
    if (/Instagram|FBAN|FBAV|FB_IAB|TikTok|musical_ly|BytedanceWebview|Snapchat/i.test(ua)) return 'inapp';
    if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && nav.maxTouchPoints > 1)) return 'ios';
    if (!/Android/i.test(ua) && tpl && tpl.content.querySelector('[data-s="qr"]')) return 'qr';
    return 'and';
  }
  function sheet(k) {
    var part = tpl && tpl.content.querySelector('[data-s="' + k + '"]');
    if (!part) return;
    if (!document.getElementById('kfa-css')) {
      var st = document.createElement('style');
      st.id = 'kfa-css';
      st.textContent = CSS;
      document.head.appendChild(st);
    }
    var back = document.createElement('div'), box = document.createElement('div'), x = document.createElement('button');
    back.className = 'kfa-bk';
    box.className = 'kfa-sh';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'kfa-sh-h');
    box.setAttribute('dir', html.getAttribute('dir') || 'ltr');
    x.type = 'button';
    x.className = 'kfa-x';
    x.setAttribute('aria-label', tpl.getAttribute('data-close') || '');
    x.textContent = '×';
    var body = document.importNode(part, true), h = body.querySelector('h2');
    if (h) h.id = 'kfa-sh-h';
    box.appendChild(x);
    box.appendChild(body);
    back.appendChild(box);
    function close() {
      document.removeEventListener('keydown', esc);
      if (back.parentNode) back.parentNode.removeChild(back);
      if (btn) btn.focus();
    }
    function esc(e) { if (e.key === 'Escape') close(); }
    x.addEventListener('click', close);
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    document.addEventListener('keydown', esc);
    document.body.appendChild(back);
    x.focus();
  }
  if (btn) btn.addEventListener('click', function () {
    if (offer) {
      // The browser's own box, from this tap. One offer prompts once.
      var o = offer;
      offer = null;
      o.prompt();
      if (o.userChoice) o.userChoice.then(function (c) { if (c && c.outcome === 'accepted') row.hidden = true; }).catch(function () {});
      return;
    }
    sheet(kind());
  });
})();

(function () {
  'use strict';
  var s = document.currentScript;
  if (!s || !('serviceWorker' in navigator)) return;
  var sw = s.getAttribute('data-sw'), scope = s.getAttribute('data-scope');
  if (!sw || !scope) return;

  /** How long "Not now" holds on this phone before the sheet may show again. */
  var ASK_AGAIN_DAYS = 7;
  var DAY_MS = 864e5;
  var NP = 'kbb.sa.np';     // when the sheet was last shown ("Not now")
  var SYNC = 'kbb.sa.ps';   // when the shop last took this phone's subscription
  var API = scope + 'api/site-app/push';

  function standalone() {
    var m = window.matchMedia;
    return navigator.standalone === true
      || !!(m && (m('(display-mode: standalone)').matches || m('(display-mode: fullscreen)').matches));
  }
  function pushable() { return 'PushManager' in window && 'Notification' in window; }
  function when(k) { try { return +(window.localStorage.getItem(k) || 0); } catch (e) { return 0; } }
  function mark(k) { try { window.localStorage.setItem(k, String(Date.now())); } catch (e) { /* private mode */ } }
  function lang() { return (document.documentElement.getAttribute('lang') || '').slice(0, 2).toLowerCase(); }

  /** What this open should do: 'ask', 'sync' or nothing. */
  function plan(now) {
    if (!standalone() || !pushable()) return '';
    var p = window.Notification.permission;
    if (p === 'granted') return now - when(SYNC) > DAY_MS ? 'sync' : '';
    if (p !== 'default') return '';
    var t = when(NP);
    return t > 0 && now - t < ASK_AGAIN_DAYS * DAY_MS ? '' : 'ask';
  }

  function keyBytes(b64) {
    var raw = window.atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function sameKey(sub, key) {
    var k = sub && sub.options && sub.options.applicationServerKey;
    if (!k) return false;
    var a = new Uint8Array(k), b = keyBytes(key);
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
  }

  function send(sub) {
    var j = sub.toJSON();
    return fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || '' },
      body: JSON.stringify({ endpoint: j.endpoint, keys: j.keys, lang: lang() }),
    }).then(function (r) { if (r.ok) mark(SYNC); });
  }

  /** Subscribe with the shop's key (re-subscribing if the key changed), then tell the shop. */
  function subscribe(reg, key) {
    return reg.pushManager.getSubscription().then(function (sub) {
      if (sub && sameKey(sub, key)) return sub;
      return (sub ? sub.unsubscribe() : Promise.resolve()).then(function () {
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
      });
    }).then(send);
  }

  var CSS = '.kbb-np-back{position:fixed;inset:0;z-index:2147483000;background:rgba(42,34,40,.38);display:flex;align-items:flex-end;justify-content:center}'
    + '.kbb-np{box-sizing:border-box;width:100%;max-width:440px;background:#fff;color:#2A2228;border-radius:24px 24px 0 0;padding:22px 20px calc(18px + env(safe-area-inset-bottom));box-shadow:0 -8px 30px rgba(42,34,40,.16);text-align:start;font:inherit}'
    + '.kbb-np-top{display:flex;align-items:center;gap:14px}'
    + '.kbb-np-top img{width:52px;height:52px;border-radius:13px;flex:none;display:block}'
    + '.kbb-np h2{margin:0;font-size:19px;line-height:1.3;font-weight:700;color:#2A2228}'
    + '.kbb-np p{margin:12px 0 0;font-size:14.5px;line-height:1.55;color:#5E545A}'
    + '.kbb-np-btns{display:flex;gap:10px;margin-top:20px}'
    + '.kbb-np-btns button{flex:1 1 0;min-height:48px;border-radius:999px;font:inherit;font-size:15px;font-weight:700;cursor:pointer;padding:0 14px}'
    + '.kbb-np-later{background:#fff;color:#2A2228;border:1px solid rgba(42,34,40,.16)}'
    + '.kbb-np-allow{background:#C6395F;color:#fff;border:1px solid #C6395F}'
    + '.kbb-np-btns button:focus-visible{outline:2px solid #C13E63;outline-offset:2px}'
    + '@media (min-width:600px){.kbb-np-back{align-items:center}.kbb-np{border-radius:24px;padding-bottom:20px}}';

  function el(tag, cls, text) {
    var x = document.createElement(tag);
    if (cls) x.className = cls;
    if (text) x.textContent = text;
    return x;
  }

  /** The shop's own sheet. Every string arrives from the server and is set as text. */
  function ask(reg, cfg) {
    var t = cfg.t || {};
    if (!t.title || !t.allow || !t.later) return;
    mark(NP);
    if (!document.getElementById('kbb-np-css')) {
      var st = el('style');
      st.id = 'kbb-np-css';
      st.textContent = CSS;
      document.head.appendChild(st);
    }
    var back = el('div', 'kbb-np-back');
    var box = el('div', 'kbb-np');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'kbb-np-h');
    box.setAttribute('dir', document.documentElement.getAttribute('dir') || 'ltr');
    var top = el('div', 'kbb-np-top');
    var icon = document.querySelector('link[rel="apple-touch-icon"]');
    if (icon && icon.getAttribute('href')) {
      var img = el('img');
      img.src = icon.getAttribute('href');
      img.alt = '';
      top.appendChild(img);
    }
    var h = el('h2', '', t.title);
    h.id = 'kbb-np-h';
    top.appendChild(h);
    box.appendChild(top);
    if (t.body) box.appendChild(el('p', '', t.body));
    var btns = el('div', 'kbb-np-btns');
    var later = el('button', 'kbb-np-later', t.later);
    var allow = el('button', 'kbb-np-allow', t.allow);
    later.type = allow.type = 'button';
    btns.appendChild(later);
    btns.appendChild(allow);
    box.appendChild(btns);
    back.appendChild(box);

    function close() {
      document.removeEventListener('keydown', esc);
      if (back.parentNode) back.parentNode.removeChild(back);
    }
    function esc(e) { if (e.key === 'Escape') close(); }
    later.addEventListener('click', close);
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    document.addEventListener('keydown', esc);
    allow.addEventListener('click', function () {
      close();
      // The browser's own question: from this tap, synchronously, and nowhere else.
      var asked = window.Notification.requestPermission();
      if (asked && asked.then) {
        asked.then(function (p) { if (p === 'granted') return subscribe(reg, cfg.key); }).catch(function () {});
      }
    });
    document.body.appendChild(back);
    allow.focus();
  }

  function notify(reg) {
    var what = plan(Date.now());
    if (!what) return;
    fetch(API + '?lang=' + encodeURIComponent(lang()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (cfg) {
        if (!cfg || typeof cfg.key !== 'string' || !cfg.key) return null;
        if (what === 'sync') return subscribe(reg, cfg.key);
        if (cfg.ask === true) ask(reg, cfg);
        return null;
      })
      .catch(function () {});
  }

  /*
   * Out-of-stock interest, for "back in stock" later: a phone the shop holds
   * a subscription for (allowed, and synced) that opens a product page the
   * server drew as sold out (.stockline.out beside the cart form's
   * data-product_id, both already on the page) says so once per product per
   * app session. The shop checks the product really is out and finds the
   * phone by its HttpOnly cookie; nothing here names the shopper.
   */
  var SEEN = 'kbb.sa.oos';
  function interest() {
    if (!pushable() || window.Notification.permission !== 'granted' || !when(SYNC)) return;
    var form = document.querySelector('form.kbb-cart-form[data-product_id]');
    if (!form || !document.querySelector('.stockline.out')) return;
    var id = parseInt(form.getAttribute('data-product_id'), 10);
    if (!(id > 0)) return;
    var list = [];
    try { list = (window.sessionStorage.getItem(SEEN) || '').split(',').filter(Boolean); } catch (e) { /* private mode */ }
    if (list.indexOf(String(id)) !== -1) return;
    list.push(String(id));
    try { window.sessionStorage.setItem(SEEN, list.slice(-60).join(',')); } catch (e) { /* private mode */ }
    fetch(API + '/viewed', {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || '' },
      body: JSON.stringify({ product_id: id }),
    }).catch(function () {});
  }

  function go() {
    navigator.serviceWorker.register(sw, { scope: scope, updateViaCache: 'none' }).catch(function () {});
    if (plan(Date.now())) navigator.serviceWorker.ready.then(notify).catch(function () {});
    interest();
  }
  if (document.readyState === 'complete') go();
  else window.addEventListener('load', go, { once: true });
})();
