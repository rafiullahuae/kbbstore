{{--
    Emails → Customer emails (mock e3) and Emails → Customer emails → {email} →
    Edit, the template builder (mock e4)                              Lane EK

    The owner: "give facility in edit any template, to re-position any section
    by drag n drop", on docs/rj-email-previews/admin/e3-customer-emails and
    e4-template-editor ("i want 100% same stuff as in previews"); and, 4 Oct,
    "proper tabs ... for all emails pages". Transactional email only — nothing
    marketing lives here.

    THE SWITCHES are the existing module toggles (CustomerEmails); THE WORDS
    are `email_templates` rows (EmailWording); THE ORDER is KitSections. The
    preview is the real email drawn by the kit through the endpoint Design &
    branding already uses (POST carries the unsaved changes; nothing is stored
    until Save) in an iframe with sandbox="" — no script in it can run.

    DRAG AND DROP WITHOUT GEOMETRY (CLAUDE.md rule 4): pointerdown on a row's
    handle releases the pointer capture, so `pointerover` fires on whichever
    row is under the pointer — mouse, pen or finger — and the dragged row is
    moved before or after that row by its place in the LIST. Nothing is
    measured. The keyboard alternative is the ↑ ↓ buttons on every row (and
    ArrowUp/ArrowDown on a focused handle).

    EVERY STRING FROM THE SERVER IS ESCAPED (esc()) before it reaches
    innerHTML; the one exception is a row's sub-line, server constants that may
    carry <code>, which are escaped too and only <code>/</code> put back.

    Wiring (the integrator): ONE include at the foot of admin/app.blade.php,
    beside the emails-screens include —
        @include('admin.partials.emails-templates-screens')
    the screen ids 'emails-customer' and 'emails-edit' in LATE_RENDERED and
    TITLES, and 'emails-customer' in NAV's Emails group after
    'emails-sending' (this partial also registers it, a no-op once NAV
    declares it). tools/ek-wire.py applies exactly these lines.
--}}
@verbatim
<style>
.ek{min-width:0}
.ek .sub{font-size:11.5px;color:var(--ink-faint,#97a0b2);margin-top:2px}
.ek .sub code{font-size:11.5px}
.ek-subj{font-size:11.5px;color:var(--ink-soft,#626c80);margin-top:3px;overflow-wrap:anywhere}
.ek-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);border-radius:var(--r,18px);box-shadow:var(--sh-s,none);padding:18px;min-width:0}
.ek-card h3{font-size:14.5px;font-weight:700;margin:0 0 4px}
.ek-card p.d{font-size:12.5px;color:var(--ink-soft,#626c80);margin:0 0 12px;line-height:1.5}
.ek-tbl{width:100%;border-collapse:collapse}
.ek-tbl td,.ek-tbl th{vertical-align:middle}
.ek-grp td{background:var(--surface-2,#f4f6fb);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft,#626c80);padding:8px 12px}
.ek-scroll{overflow-x:auto;padding:6px}
.ek-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ek-sp{flex:1}
.ek-h-note{display:block;font-size:11.5px;color:var(--ink-faint,#97a0b2);margin-top:4px;line-height:1.5}
.ek-f{display:block;margin:0 0 12px}
.ek-l{display:block;font-size:12px;font-weight:600;color:var(--ink-2,#3c465c);margin-bottom:5px}
.ek-in{display:block;border:1px solid var(--border,#e6e9f2);border-radius:10px;padding:9px 11px;font-size:13px;background:#fff;color:var(--ink,#101729);width:100%;box-sizing:border-box;font-family:inherit}
textarea.ek-in{resize:vertical;min-height:84px;line-height:1.45}
.ek-tag{display:inline-block;font-size:11.5px;font-weight:600;background:var(--surface-3,#eef1f7);border:0;border-radius:7px;padding:3px 8px;margin:0 4px 4px 0;color:var(--ink-2,#3c465c);font-family:var(--mono,ui-monospace,monospace);cursor:pointer}
.ek-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:16px;align-items:start}
.ek-split > *{min-width:0}
.ek-dev{display:inline-flex;border:1px solid var(--border,#e6e9f2);border-radius:99px;overflow:hidden;background:#fff}
.ek-dev button{border:0;background:none;font:inherit;font-size:12px;font-weight:600;padding:6px 12px;color:var(--ink-soft,#626c80);cursor:pointer}
.ek-dev button.on{background:var(--ink,#101729);color:#fff}
.ek-frame{border:1px solid var(--border,#e6e9f2);border-radius:14px;background:#fff8f5;display:block;width:100%;height:1240px}
.ek-phone{width:390px;max-width:100%;margin:0 auto}
.ek-desk{width:600px;max-width:100%;margin:0 auto}
.ek-sec{list-style:none;margin:0;padding:0}
.ek-srow,.ek-fixed{display:flex;align-items:center;gap:8px;padding:7px 8px;border:1px solid var(--border-2,#eef0f6);border-radius:10px;margin-bottom:6px;background:#fff;min-width:0}
.ek-srow .ek-sl,.ek-fixed .ek-sl{font-size:13px;font-weight:600;min-width:0;overflow-wrap:anywhere}
.ek-srow.off .ek-sl{color:var(--ink-faint,#97a0b2)}
.ek-handle{font-size:16px;color:#8a7f86;cursor:grab;touch-action:none;user-select:none;-webkit-user-select:none;padding:0 4px;border-radius:6px;line-height:1}
.ek-fixed .ek-handle{color:#c9c2c6;cursor:default}
.ek-fixed .ek-fx{font-size:11px;color:#8a7f86}
.ek-srow.dragging{background:var(--green-soft,#e9f7ef);box-shadow:0 6px 18px -8px rgba(0,0,0,.25)}
.ek-srow.dragging .ek-sl::after{content:'dragging…';margin-left:8px;font-size:11px;color:#1d7a46;font-weight:700}
.ek-sec.is-dragging,.ek-sec.is-dragging *{cursor:grabbing!important;user-select:none;-webkit-user-select:none}
.ek-ud{border:0;background:none;font-size:13px;color:#8a7f86;cursor:pointer;padding:2px 4px;border-radius:6px}
.ek-ud:disabled{opacity:.35;cursor:default}
.ek-x{border:1px solid var(--border,#e6e9f2);background:#fff;border-radius:8px;width:26px;height:26px;color:var(--ink-soft,#626c80);cursor:pointer}
.ek-bform{list-style:none;border:1px dashed var(--border,#d9deea);border-radius:10px;padding:12px;margin:-2px 0 8px}
.ek-menu{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.ek-menu button{border:1px solid var(--border,#e6e9f2);background:#fff;border-radius:99px;padding:6px 12px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
.ek-ready{border:2px solid var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee);border-radius:14px;padding:14px;margin-top:10px}
.ek-foot{font-size:11.5px;color:var(--ink-faint,#97a0b2);margin-top:8px}
button.tog{border:0;padding:0;cursor:pointer}
button.tog.lock{cursor:not-allowed}
.ek-only-narrow{display:none!important}
.ek-edited{display:inline-block;font-size:10.5px;font-weight:700;border-radius:99px;padding:1px 7px;background:var(--blue-soft,#e8eefc);color:#2f53b0;margin-left:6px;vertical-align:1px}
@media(max-width:880px){
  .ek-split{grid-template-columns:minmax(0,1fr)}
  .ek-hide-sm{display:none}
  .ek-only-narrow{display:flex!important}
  .ek-split[data-tab]:not([data-tab="preview"]) .ek-prevcol{display:none}
  .ek-split[data-tab="preview"] .ek-leftcol{display:none}
  .ek-frame{height:1100px}
}
</style>

<script>
(function () {
  'use strict';

  var GROUP = 'Emails';
  var SCREENS = { 'emails-customer': 'Customer emails', 'emails-edit': 'Customer emails' };
  var ICON = '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>';
  var state = { rows: null, tab: null, ed: null, device: 'phone', focusWord: null, openBlock: null, adding: false, ready: false, edTab: 'sections' };
  var currentScreen = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function codeOnly(s) { return esc(s).replace(/&lt;(\/?)code&gt;/g, '<$1code>'); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function host() { return document.getElementById('content'); }
  function toastMsg(t) { try { if (typeof window.toast === 'function') window.toast(t); } catch (e) {} }
  function tabs() { return window.kbbTabs; }

  async function api(path, body, raw) {
    var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: raw ? 'text/html' : 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api' + path, opts);
    if (raw) { if (!r.ok) { var er = new Error('preview ' + r.status); er.status = r.status; throw er; } return r.text(); }
    var data = null; try { data = await r.json(); } catch (e) { data = null; }
    if (r.ok || r.status === 422) return { status: r.status, data: data || {} };
    var err = new Error('emails ' + r.status); err.status = r.status; err.data = data; throw err;
  }
  function why(e) {
    if (e && e.status === 403) return 'Your role cannot do that here. Reading and test sends are for the owner and managers; changes are the owner\'s.';
    if (e && e.status === 404) return 'Not in the server\'s route table yet. Clear the route cache (Platform → Cache) and reload.';
    if (e && e.status === 429) return 'Too many presses in one minute. Wait a moment and try again.';
    return 'The request did not complete. Try again in a moment.';
  }
  function when(iso) {
    if (!iso) return '—';
    var d = new Date(iso); if (isNaN(d)) return '—';
    var now = new Date(), y = new Date(now); y.setDate(now.getDate() - 1);
    var hm = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return 'Today<br>' + esc(hm);
    if (d.toDateString() === y.toDateString()) return 'Yesterday';
    return esc(d.toLocaleDateString([], { day: 'numeric', month: 'short' }));
  }

  /* --------------------------------------------------------- Customer emails */

  var GROUPS = [['all', 'All emails'], ['Orders — to the customer', 'Orders'], ['To you', 'To you'], ['Account & sign-up', 'Account & sign-up'], ['Shopping reminders', 'Shopping reminders']];

  function onCell(r) {
    var label = esc(r.name) + (r.on ? ' is on' : ' is off');
    if (r.state === 'manual' && !r.on) return '<span class="pill amber">Manual</span>';
    if (r.state === 'always') return '<button type="button" class="tog on lock" role="switch" aria-checked="true" aria-disabled="true" title="Always on" aria-label="' + label + ', always"></button>';
    if (r.state === 'follows') return '<button type="button" class="tog' + (r.on ? ' on' : '') + ' lock" role="switch" aria-checked="' + (r.on ? 'true' : 'false') + '" aria-disabled="true" title="Follows Store → Modules → Email Capture" aria-label="' + label + '"></button>';
    return '<button type="button" class="tog' + (r.on ? ' on' : '') + '" role="switch" aria-checked="' + (r.on ? 'true' : 'false') + '" data-ek-switch="' + esc(r.template) + '" aria-label="' + label + '"></button>';
  }

  function rowHtml(r) {
    return '<tr data-ek-row="' + esc(r.template) + '"><td><b>' + esc(r.name) + '</b>' + (r.customised ? '<span class="ek-edited">Edited</span>' : '')
      + (r.sub_html ? '<div class="sub">' + codeOnly(r.sub_html) + '</div>' : '')
      + (r.subject ? '<div class="ek-subj">Subject: “' + esc(r.subject) + '”</div>' : '') + '</td>'
      + '<td class="ek-hide-sm">' + esc(r.when) + '</td><td>' + onCell(r) + '</td>'
      + '<td class="ek-hide-sm">' + when(r.last_sent) + '</td>'
      + '<td style="white-space:nowrap"><button type="button" class="btn ghost sm" data-ek-edit="' + esc(r.template) + '">Edit</button> '
      + '<button type="button" class="btn ghost sm ek-hide-sm" data-ek-test="' + esc(r.template) + '">Test</button></td></tr>';
  }

  function tableHtml(group) {
    var rows = state.rows || [];
    var html = '<div class="ek-card ek-scroll"><table class="ek-tbl"><tbody><tr><th>Email</th><th class="ek-hide-sm">Sent when</th><th>On</th><th class="ek-hide-sm">Last sent</th><th></th></tr>';
    var last = null;
    rows.forEach(function (r) {
      if (group !== 'all' && r.group !== group) return;
      if (group === 'all' && r.group !== last) { html += '<tr class="ek-grp"><td colspan="5">' + esc(r.group) + '</td></tr>'; last = r.group; }
      html += rowHtml(r);
    });
    return html + '</tbody></table></div>';
  }

  function paintCustomer() {
    var h = host(); if (!h) return;
    var ids = GROUPS.map(function (g) { return g[0]; });
    var cur = state.tab || (tabs() ? tabs().pick('ek-customer', ids, 'all') : 'all');
    var count = function (g) { return g === 'all' ? (state.rows || []).length : (state.rows || []).filter(function (r) { return r.group === g; }).length; };
    var bar = tabs() ? tabs().bar('ek-customer', GROUPS.map(function (g) { return [g[0], g[1], count(g[0])]; }), cur, 'Customer emails') : '';
    var panels = GROUPS.map(function (g) {
      var attrs = tabs() ? tabs().panel('ek-customer', g[0], cur) : (g[0] === cur ? '' : ' hidden');
      return '<div' + attrs + '>' + tableHtml(g[0]) + '</div>';
    }).join('');
    h.innerHTML = '<div class="wrap ek" data-ek="customer"><div class="page-head"><h2>Customer emails</h2><p>One row per message, following the shop’s real order statuses. Switch any of them off, edit the words, preview on a phone. Any single status change can still skip its email: untick “Email the customer” on the order.</p></div>'
      + bar + panels
      + '<div class="ek-foot">Draft never emails. Defaults follow the owner’s choices of 3 October: status emails on, on hold sent by hand. These switches are the same ones as Store → Modules.</div></div>';
  }

  async function customer() {
    var h = host(); if (!h) return;
    h.innerHTML = '<div class="wrap ek" data-ek="customer"><div class="page-head"><h2>Customer emails</h2><p>Loading…</p></div></div>';
    try { state.rows = (await api('/emails/customer')).data.rows || []; } catch (e) { h.innerHTML = '<div class="wrap ek" data-ek="customer"><div class="page-head"><h2>Customer emails</h2><p>' + esc(why(e)) + '</p></div></div>'; return; }
    if (currentScreen === 'emails-customer') paintCustomer();
  }

  async function flip(btn) {
    var t = btn.getAttribute('data-ek-switch');
    var on = btn.getAttribute('aria-checked') !== 'true';
    btn.disabled = true;
    try {
      var r = await api('/emails/customer/switch', { template: t, on: on });
      if (r.status === 422) { toastMsg((r.data && r.data.error) || 'That email has no switch here.'); btn.disabled = false; }
      else { state.rows = r.data.rows; paintCustomer(); toastMsg(on ? 'Switched on' : 'Switched off'); }
    } catch (e) { toastMsg(why(e)); btn.disabled = false; }
  }

  async function sendTest(btn, template) {
    var was = btn.textContent; btn.disabled = true; btn.textContent = 'Sending…';
    try {
      var r = await api('/emails/templates/' + encodeURIComponent(template) + '/test', {});
      var d = r.data || {};
      toastMsg(r.status === 422 ? (d.error || 'Could not send.') : (d.ok ? 'Test sent to ' + (d.to || 'you') + ' — accepted by the mail server' : 'Not sent: ' + (d.message || 'the mail server refused it')));
    } catch (e) { toastMsg(why(e)); }
    btn.disabled = false; btn.textContent = was;
  }

  /* ------------------------------------------------------------------- editor */

  var BLOCK_LABELS = { text: 'Text', image: 'Image', button: 'Button', coupon: 'Coupon', product_row: 'Product row', divider: 'Divider', spacer: 'Spacer' };

  function blockLabel(b) {
    var n = BLOCK_LABELS[b.type] || b.type;
    var w = String(b.text || b.label || b.alt || '').replace(/\s+/g, ' ').trim();
    return w ? n + ' · ' + (w.length > 40 ? w.slice(0, 40) + '…' : w) : n;
  }

  function sectionsPayload() {
    return state.ed.sections.map(function (s) { return s.block ? { key: s.key, block: s.block } : { key: s.key, on: !!s.on }; });
  }
  function wordsPayload() {
    var out = { en: {}, ar: {} };
    ['en', 'ar'].forEach(function (l) { state.ed.fields.forEach(function (f) { out[l][f.field] = state.ed.words[l][f.field] || ''; }); });
    return out;
  }

  function rowsHtml() {
    var list = state.ed.sections, n = list.length;
    return list.map(function (s, i) {
      var label = s.block ? blockLabel(s.block) : s.label;
      var ctl = s.block
        ? '<button type="button" class="btn ghost sm" data-ek-bedit="' + esc(s.key) + '" aria-expanded="' + (state.openBlock === s.key ? 'true' : 'false') + '">Edit</button> <button type="button" class="ek-x" data-ek-bdel="' + esc(s.key) + '" aria-label="Remove ' + esc(label) + '">×</button>'
        : '<button type="button" class="tog' + (s.on ? ' on' : '') + '" role="switch" aria-checked="' + (s.on ? 'true' : 'false') + '" data-ek-son="' + esc(s.key) + '" aria-label="Show ' + esc(label) + '"></button>';
      var row = '<li class="ek-srow' + (s.on || s.block ? '' : ' off') + '" data-key="' + esc(s.key) + '">'
        + '<span class="ek-handle" data-ek-drag tabindex="0" role="button" aria-label="Drag to move ' + esc(label) + ' (or use the arrow keys)">⠿</span>'
        + '<span class="ek-sl">' + esc(label) + '</span><span class="ek-sp"></span>'
        + '<button type="button" class="ek-ud" data-ek-up="' + i + '" aria-label="Move ' + esc(label) + ' up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
        + '<button type="button" class="ek-ud" data-ek-down="' + i + '" aria-label="Move ' + esc(label) + ' down"' + (i === n - 1 ? ' disabled' : '') + '>↓</button>'
        + ctl + '</li>';
      if (s.block && state.openBlock === s.key) row += '<li class="ek-bform">' + blockForm(s) + '</li>';
      return row;
    }).join('');
  }

  function fld(key, label, value, type, extra) {
    return '<label class="ek-f"><span class="ek-l">' + esc(label) + '</span>' + (type === 'area'
      ? '<textarea class="ek-in" rows="3" data-ek-bf="' + esc(key) + '">' + esc(value || '') + '</textarea>'
      : '<input class="ek-in" type="' + (type || 'text') + '" data-ek-bf="' + esc(key) + '" value="' + esc(value == null ? '' : value) + '"' + (extra || '') + '>') + '</label>';
  }
  function sel(key, label, value, options) {
    return '<label class="ek-f"><span class="ek-l">' + esc(label) + '</span><select class="ek-in" data-ek-bf="' + esc(key) + '">'
      + options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></label>';
  }

  function blockForm(s) {
    var b = s.block, ed = state.ed;
    if (b.type === 'text') return fld('text', 'Text — **bold**, *italic*, [link](https://…)', b.text, 'area') + sel('align', 'Align', b.align || 'center', [['center', 'Centre'], ['left', 'Left']]);
    if (b.type === 'button') return fld('label', 'Button text', b.label) + fld('url', 'Link (https://… or /a-page-on-the-shop/)', b.url);
    if (b.type === 'image') return fld('src', 'Picture (a Media Library path or https:// address)', b.src) + '<button type="button" class="btn ghost sm" data-ek-pick="' + esc(s.key) + '">Choose from Media Library</button><div style="height:10px"></div>' + fld('alt', 'Describe the picture (alt text)', b.alt) + fld('url', 'Link (optional)', b.url);
    if (b.type === 'coupon') return sel('coupon_id', 'Coupon (Marketing → Coupons)', b.coupon_id || 0, [[0, 'Pick a coupon']].concat((ed.coupons || []).map(function (c) { return [c.id, c.code]; }))) + fld('line', 'Line under the code', b.line) + fld('expires', 'Small print (e.g. when it ends)', b.expires);
    if (b.type === 'product_row') return sel('fill', 'Fill with', b.fill || 'best', [['best', 'Best sellers'], ['newest', 'New arrivals'], ['sale', 'On sale'], ['under', 'Under a price'], ['brand', 'A brand'], ['category', 'A category']])
      + sel('count', 'How many', b.count || 3, [[1, '1'], [2, '2'], [3, '3']])
      + ((b.fill === 'brand') ? sel('brand_id', 'Brand', b.brand_id || 0, [[0, 'Pick a brand']].concat((ed.brands || []).map(function (x) { return [x.id, x.name]; }))) : '')
      + ((b.fill === 'category') ? sel('category_id', 'Category', b.category_id || 0, [[0, 'Pick a category']].concat((ed.categories || []).map(function (x) { return [x.id, x.name]; }))) : '')
      + ((b.fill === 'under') ? fld('max_price', 'Under (AED)', b.max_price || 54, 'number', ' min="1" max="100000"') : '')
      + fld('cta', 'Button text', b.cta || 'Shop now');
    if (b.type === 'spacer') return sel('h', 'Height', b.h || 24, [[12, 'Small'], [24, 'Medium'], [40, 'Large']]);
    return '<p class="ek-h-note" style="margin:0">Nothing to set — a thin line across the email.</p>';
  }

  function wordsHtml(locale) {
    var ed = state.ed, rtl = locale === 'ar';
    var tags = {};
    ed.fields.forEach(function (f) { f.tags.forEach(function (t) { tags[t] = true; }); });
    return '<div class="ek-card"><h3>Words · ' + (rtl ? 'العربية' : 'English') + '</h3><p class="d">Tap a tag to insert it. Anything you leave blank uses the built-in wording.' + (rtl ? ' Blank here uses your English words if you wrote some, then the built-in Arabic.' : '') + '</p>'
      + ed.fields.map(function (f) {
          var v = ed.words[locale][f.field] || '';
          var attrs = ' data-ek-word="' + locale + '.' + esc(f.field) + '" maxlength="' + f.max + '" placeholder="' + esc(f.builtin) + '"' + (rtl ? ' dir="rtl" lang="ar"' : '');
          return '<label class="ek-f"><span class="ek-l">' + esc(f.label) + '</span>'
            + (f.multiline ? '<textarea class="ek-in" rows="4"' + attrs + '>' + esc(v) + '</textarea>' : '<input class="ek-in" type="text"' + attrs + ' value="' + esc(v) + '">')
            + (f.shared > 0 ? '<span class="ek-h-note">' + (f.shared + 1) + ' emails share this line; what you type here is for this email only.</span>' : '')
            + '</label>';
        }).join('')
      + (ed.fields.length ? '' : '<p class="ek-h-note">This email has no words of its own to edit here.</p>')
      + '<div>' + Object.keys(tags).map(function (t) { return '<button type="button" class="ek-tag" data-ek-tag="{' + esc(t) + '}">{' + esc(t) + '}</button>'; }).join('') + '</div>'
      + (ed.note ? '<span class="ek-h-note" style="margin-top:10px">' + esc(ed.note) + '</span>' : '')
      + '</div>';
  }

  function sectionsHtml() {
    var ed = state.ed;
    var add = ed.add.map(function (a) { return a.label; }).join(' · ');
    return '<div class="ek-card"><p class="d">(3 Oct, owner) “give facility in edit any template, to re-position any section by drag n drop.” Drag a row, or use ↑ ↓; the live preview moves with it. Header and footer stay at the top and bottom.</p>'
      + (ed.header ? '<div class="ek-fixed"><span class="ek-handle" aria-hidden="true">⠿</span><span class="ek-sl">Header</span><span class="ek-sp"></span><span class="ek-fx">fixed</span></div>' : '')
      + '<ol class="ek-sec" data-ek-list aria-label="Sections, in order">' + rowsHtml() + '</ol>'
      + '<div class="ek-fixed"><span class="ek-handle" aria-hidden="true">⠿</span><span class="ek-sl">Footer</span><span class="ek-sp"></span><span class="ek-fx">fixed</span></div>'
      + '<div style="margin-top:8px"><div class="ek-row"><button type="button" class="btn ghost" data-ek-addmenu aria-expanded="' + (state.adding ? 'true' : 'false') + '">+ Add a section</button><span style="font-size:12px;color:#8a7f86">' + esc(add) + '</span></div>'
      + (state.adding ? '<div class="ek-menu" role="group" aria-label="Add a section">' + ed.add.map(function (a) { return '<button type="button" data-ek-add="' + esc(a.type) + '">' + esc(a.label) + '</button>'; }).join('') + '</div>' : '')
      + '</div>'
      + (state.ready ? '<div class="ek-ready"><b>Approved design — look A (3 October 2026)</b><div class="ek-h-note" style="color:var(--ink-2,#3c465c)">The owner\'s approved preview of this email: every section in its approved place and the built-in words. No other layout was approved for this email, so this is its one ready template; another appears here only once its design is approved.</div><div class="ek-row" style="margin-top:10px"><button type="button" class="btn sm" data-ek-useready>Use this design</button><button type="button" class="btn ghost sm" data-ek-ready>Close</button></div></div>' : '')
      + '</div>';
  }

  function paintEditor() {
    var h = host(), ed = state.ed; if (!h || !ed) return;
    var ids = ['sections', 'en', 'ar', 'preview'];
    var cur = tabs() ? tabs().pick('ek-editor', ids, 'sections') : 'sections';
    if (cur === 'preview' && window.matchMedia && !window.matchMedia('(max-width:880px)').matches) cur = 'sections';
    state.edTab = cur;
    var bar = tabs() ? tabs().bar('ek-editor', [['sections', 'Sections'], ['en', 'Wording · English'], ['ar', 'Wording · العربية'], ['preview', 'Preview', null, 'ek-only-narrow']], cur, 'Edit this email') : '';
    var P = function (id) { return tabs() ? tabs().panel('ek-editor', id, cur) : (id === cur ? '' : ' hidden'); };
    h.innerHTML = '<div class="wrap ek" data-ek="editor">'
      + '<div class="ek-row" style="margin-bottom:14px"><div class="page-head" style="margin:0"><h2>' + esc(ed.name) + '</h2><p>' + esc(ed.when ? 'Sent: ' + ed.when + '.' : '') + (ed.edited_at ? ' Last edited ' + esc(new Date(ed.edited_at).toLocaleString()) + '.' : '') + '</p></div><span class="ek-sp"></span><button type="button" class="btn ghost sm" data-ek-back>← All customer emails</button></div>'
      + bar
      + '<div class="ek-split" data-tab="' + esc(cur) + '"><div class="ek-leftcol">'
      + '<div' + P('sections') + ' data-ek-sections>' + sectionsHtml() + '</div>'
      + '<div' + P('en') + '>' + wordsHtml('en') + '</div>'
      + '<div' + P('ar') + '>' + wordsHtml('ar') + '</div>'
      + '<div' + P('preview') + '></div>'
      + '<div class="ek-row" style="margin-top:14px"><button type="button" class="btn ghost" data-ek-reset>Reset to default</button><button type="button" class="btn ghost" data-ek-ready>Start from a ready template</button><span class="ek-sp"></span><button type="button" class="btn ghost" data-ek-mytest>Send test to me</button><button type="button" class="btn" data-ek-save>Save</button></div>'
      + '<div class="ek-foot" data-ek-dirty>' + (ed.dirty ? 'Unsaved changes — the preview shows them; customers get them only after Save.' : '') + '</div>'
      + '</div><div class="ek-prevcol"><div class="ek-row" style="margin-bottom:10px"><b style="font-size:13px">Live preview</b><span class="ek-sp"></span>'
      + '<span class="ek-dev" role="group" aria-label="Preview width"><button type="button" data-ek-dev="desktop" class="' + (state.device === 'desktop' ? 'on' : '') + '">Desktop</button><button type="button" data-ek-dev="phone" class="' + (state.device === 'phone' ? 'on' : '') + '">Phone</button></span></div>'
      + '<div class="' + (state.device === 'phone' ? 'ek-phone' : 'ek-desk') + '" data-ek-framebox><iframe class="ek-frame" title="Preview of ' + esc(ed.name) + '" sandbox="" data-ek-frame srcdoc="&lt;p style=&quot;font-family:sans-serif;color:#626c80;padding:24px&quot;&gt;Loading the preview…&lt;/p&gt;"></iframe></div>'
      + '</div></div></div>';
    preview(0);
  }

  function repaintSections() {
    var box = document.querySelector('[data-ek-sections]');
    if (box) box.innerHTML = sectionsHtml();
  }

  function markDirty() {
    state.ed.dirty = true;
    var d = document.querySelector('[data-ek-dirty]');
    if (d) d.textContent = 'Unsaved changes — the preview shows them; customers get them only after Save.';
    preview(400);
  }

  var previewTimer = null, previewSeq = 0;
  function preview(delay) {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(async function () {
      var ed = state.ed; if (!ed) return;
      var seq = ++previewSeq;
      var locale = state.edTab === 'ar' ? '&locale=ar' : '';
      try {
        var html = await api('/emails/preview?template=' + encodeURIComponent(ed.template) + locale, { sections: sectionsPayload(), words: wordsPayload() }, true);
        if (seq !== previewSeq) return;
        var f = document.querySelector('[data-ek-frame]'); if (f) f.srcdoc = html;
      } catch (e) {
        var g = document.querySelector('[data-ek-frame]');
        if (g) g.srcdoc = '<p style="font-family:sans-serif;color:#626c80;padding:24px">' + esc(e && e.status === 403 ? 'The live preview is the owner\'s (it shows the latest real order). Send yourself a test instead.' : why(e)) + '</p>';
      }
    }, delay);
  }

  function load(d) {
    var words = { en: {}, ar: {} };
    (d.words || []).forEach(function (f) { words.en[f.field] = f.en || ''; words.ar[f.field] = f.ar || ''; });
    state.ed = { template: d.template, name: d.name, when: d.when, header: d.header, note: d.note, edited_at: d.edited_at, add: d.add || [],
      sections: (d.sections || []).map(function (s) { return { key: s.key, label: s.label, on: s.on, def: s.default, block: s.block || null }; }),
      fields: d.words || [], words: words, coupons: d.coupons || [], brands: d.brands || [], categories: d.categories || [], defaults: d.defaults || [], dirty: false };
    state.openBlock = null; state.adding = false; state.ready = false;
  }

  async function editor(template) {
    var h = host(); if (!h) return;
    h.innerHTML = '<div class="wrap ek" data-ek="editor"><div class="page-head"><h2>Customer emails</h2><p>Loading…</p></div></div>';
    var d;
    try { d = (await api('/emails/templates/' + encodeURIComponent(template))).data; } catch (e) { h.innerHTML = '<div class="wrap ek" data-ek="editor"><div class="page-head"><h2>Customer emails</h2><p>' + esc(why(e)) + '</p></div></div>'; return; }
    load(d);
    if (currentScreen === 'emails-edit') paintEditor();
  }

  function move(from, to) {
    var list = state.ed.sections;
    if (to < 0 || to >= list.length || from === to) return;
    var item = list.splice(from, 1)[0];
    list.splice(to, 0, item);
    repaintSections(); markDirty();
  }

  async function save(btn) {
    btn.disabled = true;
    try {
      var r = await api('/emails/templates/' + encodeURIComponent(state.ed.template), { sections: sectionsPayload(), words: wordsPayload() });
      if (r.status === 422) toastMsg('Not saved: ' + (r.data.error || 'one of the fields was refused'));
      else { load(r.data); paintEditor(); toastMsg('Saved — customers get this from now on'); }
    } catch (e) { toastMsg(why(e)); }
    btn.disabled = false;
  }

  async function reset(btn) {
    if (!window.confirm('Put this email back to the approved design and the built-in words, in English and Arabic?')) return;
    btn.disabled = true;
    try { var r = await api('/emails/templates/' + encodeURIComponent(state.ed.template) + '/reset', {}); load(r.data); paintEditor(); toastMsg('Back to the approved design'); }
    catch (e) { toastMsg(why(e)); btn.disabled = false; }
  }

  /* --------------------------------------------------------- drag and drop */

  var drag = null;
  document.addEventListener('pointerdown', function (e) {
    var hnd = e.target.closest && e.target.closest('[data-ek-drag]');
    if (!hnd || !state.ed) return;
    var li = hnd.closest('li.ek-srow'); if (!li) return;
    e.preventDefault();
    // Hand the pointer back so `pointerover` reaches the row under it: a
    // touch pointer is captured by the element it went down on.
    try { hnd.releasePointerCapture(e.pointerId); } catch (x) {}
    drag = li; li.classList.add('dragging');
    li.parentNode.classList.add('is-dragging');
  });
  document.addEventListener('pointerover', function (e) {
    if (!drag) return;
    var over = e.target.closest && e.target.closest('li.ek-srow');
    if (!over || over === drag || over.parentNode !== drag.parentNode) return;
    var kids = Array.prototype.slice.call(drag.parentNode.children);
    if (kids.indexOf(over) > kids.indexOf(drag)) drag.parentNode.insertBefore(drag, over.nextSibling);
    else drag.parentNode.insertBefore(drag, over);
  });
  function endDrag() {
    if (!drag) return;
    var list = drag.parentNode;
    drag.classList.remove('dragging'); list.classList.remove('is-dragging');
    var order = Array.prototype.slice.call(list.querySelectorAll('li.ek-srow')).map(function (li) { return li.getAttribute('data-key'); });
    var byKey = {}; state.ed.sections.forEach(function (s) { byKey[s.key] = s; });
    var moved = order.join('|') !== state.ed.sections.map(function (s) { return s.key; }).join('|');
    state.ed.sections = order.map(function (k) { return byKey[k]; });
    drag = null;
    repaintSections();
    if (moved) markDirty();
  }
  document.addEventListener('pointerup', endDrag);
  document.addEventListener('pointercancel', endDrag);

  /* ------------------------------------------------------------------ events */

  document.addEventListener('keydown', function (e) {
    var hnd = e.target.closest && e.target.closest('[data-ek-drag]');
    if (!hnd || !state.ed || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    var key = hnd.closest('li').getAttribute('data-key');
    var i = state.ed.sections.findIndex(function (s) { return s.key === key; });
    move(i, e.key === 'ArrowUp' ? i - 1 : i + 1);
    var again = document.querySelector('li.ek-srow[data-key="' + key + '"] [data-ek-drag]'); if (again) again.focus();
  });

  document.addEventListener('click', function (e) {
    if (!document.querySelector('[data-ek]')) return;
    var t = e.target, c = function (s) { return t.closest && t.closest(s); };
    var x;
    if ((x = c('[data-ek-switch]'))) return flip(x);
    if ((x = c('[data-ek-edit]'))) { state.editTemplate = x.getAttribute('data-ek-edit'); return window.go('emails-edit'); }
    if ((x = c('[data-ek-test]'))) return sendTest(x, x.getAttribute('data-ek-test'));
    if (!state.ed) return;
    if ((x = c('[data-ek-back]'))) return window.go('emails-customer');
    if ((x = c('[data-ek-mytest]'))) return sendTest(x, state.ed.template);
    if ((x = c('[data-ek-save]'))) return save(x);
    if ((x = c('[data-ek-reset]'))) return reset(x);
    if ((x = c('[data-ek-useready]'))) {
      var order = state.ed.defaults || [];
      state.ed.sections = state.ed.sections.filter(function (s) { return !s.block; }).map(function (s) { s.on = s.def; return s; });
      if (order.length) state.ed.sections.sort(function (a, b) { return order.indexOf(a.key) - order.indexOf(b.key); });
      ['en', 'ar'].forEach(function (l) { Object.keys(state.ed.words[l]).forEach(function (k) { state.ed.words[l][k] = ''; }); });
      state.ready = false; paintEditor(); markDirty(); toastMsg('The approved design is in the editor — press Save to use it'); return;
    }
    if ((x = c('[data-ek-ready]'))) { state.ready = !state.ready; return repaintSections(); }
    if ((x = c('[data-ek-son]'))) {
      var k = x.getAttribute('data-ek-son');
      state.ed.sections.forEach(function (s) { if (s.key === k) s.on = !s.on; });
      repaintSections(); return markDirty();
    }
    if ((x = c('[data-ek-up]'))) { var i = +x.getAttribute('data-ek-up'); return move(i, i - 1); }
    if ((x = c('[data-ek-down]'))) { var j = +x.getAttribute('data-ek-down'); return move(j, j + 1); }
    if ((x = c('[data-ek-addmenu]'))) { state.adding = !state.adding; return repaintSections(); }
    if ((x = c('[data-ek-add]'))) {
      var type = x.getAttribute('data-ek-add');
      var key = 'b' + Date.now().toString(36).slice(-8);
      var defaults = { text: { text: '' }, button: { label: 'Shop now', url: '/shop/' }, image: { src: '', alt: '' }, coupon: { coupon_id: 0, line: '', expires: '' }, product_row: { fill: 'best', count: 3, cta: 'Shop now' }, divider: {}, spacer: { h: 24 } };
      var block = Object.assign({ type: type }, defaults[type] || {});
      // Straight after the headline: the top of an email is where a note is read.
      state.ed.sections.splice(Math.min(1, state.ed.sections.length), 0, { key: key, label: '', on: true, block: block });
      state.openBlock = key; state.adding = false;
      repaintSections(); return markDirty();
    }
    if ((x = c('[data-ek-bedit]'))) { var bk = x.getAttribute('data-ek-bedit'); state.openBlock = state.openBlock === bk ? null : bk; return repaintSections(); }
    if ((x = c('[data-ek-bdel]'))) { var dk = x.getAttribute('data-ek-bdel'); state.ed.sections = state.ed.sections.filter(function (s) { return s.key !== dk; }); repaintSections(); return markDirty(); }
    if ((x = c('[data-ek-pick]'))) {
      var pk = x.getAttribute('data-ek-pick');
      if (typeof window.kbbPickMedia !== 'function') { toastMsg('The media picker is not loaded on this page — paste the picture\'s address instead.'); return; }
      window.kbbPickMedia({ title: 'Picture for this email', note: 'Shown 600px wide.', upload: false, onPick: function (urls) {
        var u = (urls && urls[0]) || ''; if (!u) return;
        state.ed.sections.forEach(function (s) { if (s.key === pk) s.block.src = u; });
        repaintSections(); markDirty();
      } });
      return;
    }
    if ((x = c('[data-ek-tag]'))) {
      var el = state.focusWord && document.contains(state.focusWord) && !state.focusWord.closest('[hidden]') ? state.focusWord : document.querySelector('[data-kbt-panel="ek-editor"]:not([hidden]) [data-ek-word]');
      if (!el) return;
      var tag = x.getAttribute('data-ek-tag'), s0 = el.selectionStart == null ? el.value.length : el.selectionStart, s1 = el.selectionEnd == null ? s0 : el.selectionEnd;
      el.value = el.value.slice(0, s0) + tag + el.value.slice(s1);
      el.focus(); el.selectionStart = el.selectionEnd = s0 + tag.length;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    if ((x = c('[data-ek-dev]'))) {
      state.device = x.getAttribute('data-ek-dev');
      document.querySelectorAll('[data-ek-dev]').forEach(function (b) { b.classList.toggle('on', b === x); });
      var box = document.querySelector('[data-ek-framebox]'); if (box) box.className = state.device === 'phone' ? 'ek-phone' : 'ek-desk';
    }
  });

  document.addEventListener('focusin', function (e) { if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-ek-word')) state.focusWord = e.target; });

  var NUMERIC = { coupon_id: 1, count: 1, h: 1, brand_id: 1, category_id: 1, max_price: 1 };
  document.addEventListener('input', function (e) {
    if (!state.ed) return;
    var t = e.target;
    if (t.hasAttribute && t.hasAttribute('data-ek-word')) {
      var p = t.getAttribute('data-ek-word').split('.');
      state.ed.words[p[0]][p[1]] = t.value;
      return markDirty();
    }
    if (t.hasAttribute && t.hasAttribute('data-ek-bf')) {
      var key = state.openBlock, f = t.getAttribute('data-ek-bf');
      state.ed.sections.forEach(function (s) { if (s.key === key && s.block) s.block[f] = NUMERIC[f] ? +t.value : t.value; });
      var lab = document.querySelector('li.ek-srow[data-key="' + key + '"] .ek-sl');
      state.ed.sections.forEach(function (s) { if (s.key === key && s.block && lab) lab.textContent = blockLabel(s.block); });
      return markDirty();
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!state.ed || !(t.hasAttribute && t.hasAttribute('data-ek-bf'))) return;
    var key = state.openBlock, f = t.getAttribute('data-ek-bf');
    state.ed.sections.forEach(function (s) { if (s.key === key && s.block) s.block[f] = NUMERIC[f] ? +t.value : t.value; });
    if (f === 'fill') repaintSections();
    markDirty();
  });

  document.addEventListener('kbb:tab', function (e) {
    if (!e.detail || e.detail.group !== 'ek-editor') return;
    state.edTab = e.detail.id;
    var split = document.querySelector('.ek-split'); if (split) split.setAttribute('data-tab', e.detail.id);
    if (e.detail.id === 'ar' || e.detail.id === 'en') preview(0);
  });
  document.addEventListener('kbb:tab', function (e) {
    if (e.detail && e.detail.group === 'ek-customer') state.tab = e.detail.id;
  });

  /* ------------------------------------------------------------------ wiring */

  var previousGo = window.go;
  window.go = function (id) {
    if (!Object.prototype.hasOwnProperty.call(SCREENS, id)) { currentScreen = null; state.ed = null; return previousGo.apply(this, arguments); }
    currentScreen = id;
    try { cur = id; } catch (e) {}
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === 'emails-customer'); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]'); if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = SCREENS[id];
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    var h = host(); if (h) { h.innerHTML = ''; h.scrollTop = 0; }
    if (id === 'emails-customer') { state.ed = null; customer(); }
    else {
      var q = null; try { q = new URLSearchParams(window.location.search).get('email'); } catch (e) {}
      editor(state.editTemplate || q || 'order_confirmation').then(function () {
        if (title && state.ed) title.textContent = 'Edit · ' + state.ed.name;
      });
    }
    return undefined;
  };

  /* The row is declared in NAV's Emails group beside its siblings (the
     integrator's line), exactly as emails-screens.blade.php's four are, so it
     exists at buildNav(); this registration is the same no-op theirs is. */
  var NAV_ROWS = [['emails-customer', 'Customer emails', ['emails-sending', 'emails']]];
  function addNav() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    NAV_ROWS.forEach(function (r) { window.kbbAddNavEntry({ screen: r[0], label: r[1], icon: ICON, group: GROUP, after: r[2] }); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNav); else addNav();
})();
</script>
@endverbatim
