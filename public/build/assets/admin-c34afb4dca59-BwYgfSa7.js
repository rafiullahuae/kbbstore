
(function () {
  'use strict';
  var SCREEN = 'timedeal';
  var data = null, values = null, banner = null, busy = false, seq = 0, lang = 'en', timer = null;

  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  async function api(body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/time-deal-strip', opts), p = null;
    try { p = await r.json(); } catch (e) { p = null; }
    if (!r.ok) { var err = new Error('api ' + r.status); err.status = r.status; err.body = p; throw err; }
    return p;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function say(m) { try { window.toast(m); } catch (e) {} }
  /* a late answer never paints over another screen */
  function ours() { var c = document.querySelector('#content'); return !!c && (!c.firstElementChild || !!c.querySelector('[data-tds]')); }
  function explain(e, f) {
    if (e && e.status === 404) return 'The Time deal strip is not in this server\'s route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change the Time deal strip. An owner, manager or editor can.';
    return (e && e.body && e.body.error) ? e.body.error : f;
  }

  function endMs() {
    if (!values) return Date.now();
    if (values.mode === 'daily') { var n = Date.now(), off = 4 * 3600e3; return Math.floor((n + off) / 864e5) * 864e5 + 864e5 - off; }
    var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(values.ends_at || '');
    return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4] - 4, +m[5]) : Date.now();
  }
  function brands() { return String(values.brands || '').split(/[,\n]+/).map(function (b) { return b.trim(); }).filter(Boolean).slice(0, 20); }
  function pick(k) { var ar = lang === 'ar'; return (ar && values[k + '_ar']) ? values[k + '_ar'] : values[k + '_en']; }
  /* the shop's own markup (partials/time-deal-strip), class for class */
  function strip(width) {
    var ar = lang === 'ar', v = values;
    var hide = (width === 'phone' && !v.show_phone) || (width === 'desk' && !v.show_desktop);
    if (!v.on || hide) return '<div class="tds-msg err" style="margin:8px">Not shown ' + (v.on ? 'on ' + (width === 'phone' ? 'phones' : 'computers') : '— the strip is switched off') + '.</div>';
    var u = ar ? [':', ':', ''] : ['h', 'm', 's'], br = brands(), b = pick('button');
    return '<div class="kdt' + (v.motion ? '' : ' kdt-still') + '" dir="' + (ar ? 'rtl' : 'ltr') + '">'
      + (pick('chip') ? '<span class="kdt-chip">' + esc(pick('chip')) + '</span>' : '') + (pick('offer') ? '<em class="kdt-off">' + esc(pick('offer')) + '</em>' : '')
      + '<span class="kdt-clk"><b data-tdu>00</b><small>' + u[0] + '</small><b data-tdu>00</b><small>' + u[1] + '</small><b data-tdu>00</b><small>' + u[2] + '</small></span>'
      + (pick('heading') ? '<span class="kdt-pill">' + esc(pick('heading')) + '</span>' : '')
      + (br.length ? '<span class="kdt-txt">' + (pick('sub') ? '<span class="kdt-lead">' + esc(pick('sub')) + '</span>' : '') + '<span class="kdt-rot">' + br.map(function (x, i) { return '<span' + (i ? '' : ' class="on"') + '>' + esc(x) + '</span>'; }).join('') + '</span></span>' : '')
      + (b ? '<span class="kdt-go">' + esc(b) + '</span>' : '')
      + (v.closable ? '<span class="kdt-x" aria-hidden="true"><svg viewBox="0 0 12 12" width="11" height="11"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>' : '') + '</div>';
  }
  var rotN = 0;
  function tick() {
    var l = Math.max(0, endMs() - Date.now()), x = Math.floor(l / 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
    var parts = [p(Math.floor(x / 3600)), p(Math.floor(x / 60) % 60), p(x % 60)];
    var bs = document.querySelectorAll('[data-tds] [data-tdu]');
    if (!bs.length) { clearInterval(timer); timer = null; return; }
    bs.forEach(function (b, i) { b.textContent = parts[i % 3]; });
    if (++rotN % 2 === 0) document.querySelectorAll('[data-tds] .kdt-rot').forEach(function (r) {
      var k = r.children, cur = 0; if (k.length < 2) return;
      for (var i = 0; i < k.length; i++) { k[i].classList.remove('out'); if (k[i].classList.contains('on')) cur = i; }
      k[cur].classList.remove('on'); k[cur].classList.add('out'); k[(cur + 1) % k.length].classList.add('on');
    });
  }
  function paintPreview() {
    var pv = document.querySelector('[data-tds-pv]');
    if (!pv || !values) return;
    pv.innerHTML = '<div class="tds-row"><span class="cap">Preview · counting down live</span><span style="margin-left:auto" class="seg">'
      + ['en', 'ar'].map(function (l) { return '<button type="button" data-tdl="' + l + '" class="' + (lang === l ? 'on' : '') + '" aria-pressed="' + (lang === l) + '">' + (l === 'en' ? 'English' : 'العربية') + '</button>'; }).join('') + '</span></div>'
      + '<span class="cap">Computer</span><div class="tds-desk">' + strip('desk') + '<div class="ghost"></div></div>'
      + '<span class="cap">Phone (390 px)</span><div class="tds-phone">' + strip('phone') + '<div class="ghost"></div></div>'
      + (values.mode === 'until' && endMs() <= Date.now() ? '<div class="tds-msg err">The end time has passed, so the shop shows nothing. Set a later time, or choose "Every day, until midnight".</div>' : '');
    tick();
    if (!timer) timer = setInterval(function () { if (!ours() || !document.querySelector('[data-tds]')) { clearInterval(timer); timer = null; return; } tick(); }, 1000);
  }

  function field(key, label, attrs) {
    var v = values[key] == null ? '' : values[key];
    return '<label class="tds-f"><span>' + label + '</span><input type="text" data-tk="' + key + '" maxlength="' + (data.text_max || 80) + '" value="' + esc(v) + '"' + (attrs || '') + '></label>';
  }
  function render() {
    var c = document.querySelector('#content');
    if (!c || !ours()) return;
    if (!values) {
      c.innerHTML = '<div class="wrap" data-tds><div class="page-head"><h2>Time deal strip</h2><p>' + (banner ? esc(banner) : 'Loading…') + '</p></div></div>';
      return;
    }
    var v = values;
    c.innerHTML = '<div class="wrap" data-tds><div class="page-head"><h2>Time deal strip</h2><p>The slim strip above the header with a live countdown. It is not sticky: it scrolls away with the page. It is never shown on the checkout. When it is off, or its time is up, the shop prints nothing of it.</p></div>'
      + (banner ? '<div class="tds-msg ' + (banner.ok ? 'ok' : 'err') + '" role="status" style="margin-bottom:12px">' + esc(banner.text) + '</div>' : '')
      + '<div class="tds-wrap"><div style="display:grid;gap:14px;min-width:0">'
      + '<div class="tds-card"><div class="tds-big"><label class="tds-chk" style="font-size:15px;font-weight:700"><input type="checkbox" data-tb="on"' + (v.on ? ' checked' : '') + '> Show the Time deal strip</label><span class="tds-state ' + (data.live ? 'on' : 'off') + '">' + (data.live ? 'Live on the shop' : 'Not showing') + '</span></div>'
      + '<div class="tds-row"><label class="tds-chk"><input type="checkbox" data-tb="show_phone"' + (v.show_phone ? ' checked' : '') + '> Phones (900 px and narrower)</label><label class="tds-chk"><input type="checkbox" data-tb="show_desktop"' + (v.show_desktop ? ' checked' : '') + '> Computers</label><label class="tds-chk"><input type="checkbox" data-tb="motion"' + (v.motion ? ' checked' : '') + '> Motion (the moving holographic colours)</label><label class="tds-chk"><input type="checkbox" data-tb="closable"' + (v.closable ? ' checked' : '') + '> Close button (×): a shopper can hide it for the rest of their visit</label></div></div>'
      + '<div class="tds-card"><h3>Countdown</h3><div class="tds-2"><label class="tds-f"><span>Counts down</span><select data-tk="mode">' + Object.keys(data.modes).map(function (k) { return '<option value="' + k + '"' + (v.mode === k ? ' selected' : '') + '>' + esc(data.modes[k]) + '</option>'; }).join('') + '</select></label>'
      + (v.mode === 'until' ? '<label class="tds-f"><span>Ends at (UAE time)</span><input type="datetime-local" data-tk="ends_at" value="' + esc(v.ends_at) + '"><small>Midnight tonight is the next day at 00:00. When it reaches zero the strip disappears by itself.</small></label>' : '<div class="tds-f"><span>Ends</span><small>Counts to 12:00 midnight UAE time, then starts again for the next day.</small></div>') + '</div></div>'
      + '<div class="tds-card"><h3>Words and button</h3><div class="tds-2"><div style="display:grid;gap:10px"><b style="font-size:12px">English</b>' + field('offer_en', '“Offer ends in” label') + field('heading_en', 'Pill (computers)') + field('sub_en', 'Words before the brand (computers)') + field('short_en', 'Short version (small computers)') + field('chip_en', 'Chip (phones)') + field('button_en', 'Button (computers)') + '</div>'
      + '<div style="display:grid;gap:10px"><b style="font-size:12px">العربية</b>' + field('offer_ar', 'ينتهي العرض خلال', ' dir="rtl"') + field('heading_ar', 'Pill', ' dir="rtl"') + field('sub_ar', 'Words before the brand', ' dir="rtl"') + field('short_ar', 'Short version', ' dir="rtl"') + field('chip_ar', 'Chip (phones)', ' dir="rtl"') + field('button_ar', 'Button', ' dir="rtl"') + '</div></div>'
      + '<label class="tds-f"><span>Brand names that keep changing</span><input type="text" data-tk="brands" dir="ltr" value="' + esc(v.brands) + '"><small>Separated by commas, up to 20. One changes to the next every 2 seconds, on computers and phones.</small></label>'
      + '<label class="tds-f"><span>Button link</span><input type="text" data-tk="link" dir="ltr" value="' + esc(v.link) + '"><small>A page on this shop such as /super-sale/, or a full https:// address. Arabic pages open the Arabic version of a shop page.</small></label>'
      + '<p style="margin:0;font-size:12px;color:var(--ink-soft,#626c80)">Leave any of them empty to hide that part. The whole strip is a link to the button’s page.</p></div>'
      + '<div class="tds-row"><button type="button" class="btn" data-tds-save' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving…' : 'Save') + '</button></div>'
      + '</div><div class="tds-card tds-pv" data-tds-pv style="position:sticky;top:12px"></div></div></div>';
    paintPreview();
  }

  async function load() {
    var mine = ++seq; banner = null;
    try { data = await api(); if (mine !== seq) return; values = data.values; }
    catch (e) { if (mine !== seq) return; banner = explain(e, 'Could not load the Time deal strip.'); values = null; }
    render();
  }
  async function save() {
    if (busy || !values) return;
    busy = true; render();
    try { data = await api({ settings: values }); values = data.values; banner = { ok: true, text: 'Saved. ' + (data.live ? 'The strip is live on the shop.' : 'The strip is not showing on the shop.') }; say('Time deal strip saved'); }
    catch (e) { banner = { ok: false, text: explain(e, 'Not saved.') }; if (e && e.body && e.body.values) values = e.body.values; }
    busy = false; render();
  }

  document.addEventListener('input', function (e) {
    var t = e.target; if (!values || !t.closest || !t.closest('[data-tds]')) return;
    if (t.dataset.tk && t.tagName === 'INPUT' && t.type === 'text') { values[t.dataset.tk] = t.value; paintPreview(); }
  });
  document.addEventListener('change', function (e) {
    var t = e.target; if (!values || !t.closest || !t.closest('[data-tds]')) return;
    if (t.dataset.tb) { values[t.dataset.tb] = t.checked; paintPreview(); }
    else if (t.dataset.tk && (t.tagName === 'SELECT' || t.type === 'datetime-local')) { values[t.dataset.tk] = t.value; if (t.tagName === 'SELECT') render(); else paintPreview(); }
  });
  document.addEventListener('click', function (e) {
    var t = e.target; if (!t.closest || !t.closest('[data-tds]')) return;
    if (t.closest('[data-tds-save]')) { save(); return; }
    var l = t.closest('[data-tdl]'); if (l) { lang = l.dataset.tdl; paintPreview(); }
  });

  function injectCss() {
    if (document.getElementById('tds-shop-css') || !data || !data.css) return;
    var s = document.createElement('style'); s.id = 'tds-shop-css'; s.textContent = data.css; document.head.appendChild(s);
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]'); if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance'; if (title) title.textContent = 'Time deal strip';
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    var c = document.querySelector('#content'); if (c) c.innerHTML = '';
    values = null; render();
    load().then(injectCss).then(function () { if (values) render(); });
    return undefined;
  };

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({ screen: SCREEN, label: 'Time deal strip', icon: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5"/><path d="M9 2h6"/>', group: 'Appearance', after: ['comingsoon', 'sitelayout', 'layout'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();
})();
