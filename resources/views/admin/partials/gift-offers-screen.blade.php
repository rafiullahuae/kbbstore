{{--
    Growth & Marketing → Gift offers.                                  Lane GO

    One extra full-size gift at AED 0, by cart-value range, in the abandoned-
    cart email and both pending-order reminders (ON once a gift is configured,
    approved 3 November) and the exit popup (built, OFF). Everything for it
    lives here, under Marketing (the owner, 3 November: "keep such everything
    under Marketing"); the reminders' own schedule and wording are linked, not
    copied. Six tabs:

      Settings          master switch, a switch per place, hold hours, popup
                        minutes, the email's timer (live clock / fixed text),
                        reminder 2's window, every email and cart-row text EN|AR
      Ranges & products cart-value ranges; per range one primary and backups
                        from a search box (Free gifts' own product search), each
                        a box with photo, name, brand, real stock, its role,
                        its own allocation (given / left), reorder, remove
      Popup             design A-D, content style S1-S5, words 1-5 (EN|AR),
                        who sees it
      Live preview      the REAL email Blades and the real popup markup in an
                        iframe, drawn from the UNSAVED values on this screen;
                        light/dark, phone/computer, a range or a bag value
      Test              send either email, EN or AR, to an address; its claim
                        is marked test (never allocation, never the report)
      Report            per day/week/month, per place and per range

    LIGHT: GET gift-offers when the screen opens; the search asks at most once
    per 300 ms of typing (2+ characters) and aborts the question before it; the
    preview asks once per Refresh, or once 700 ms after the last change while
    the Live preview tab is open -- never per keystroke; the report once per
    Show. No polling, no timer left running (both debounces are cleared when
    the console leaves this screen).

    SAFE: every value is escaped (esc()) before it reaches innerHTML; the
    preview HTML goes into a sandboxed iframe's srcdoc. A late answer never
    paints over another screen: every repaint checks ours() (an empty #content
    or our own [data-screen]) and drops an answer that is no longer the latest
    (CLAUDE.md, the Marketing Emails landmine).
--}}
<style>
.go-wrap{display:grid;gap:14px;min-width:0}
.go-tabs{display:flex;gap:2px;overflow-x:auto;border-bottom:1px solid var(--border);scrollbar-width:none}
.go-tab{font:inherit;font-size:13.5px;padding:10px 12px;border:0;background:none;color:var(--ink-soft);border-bottom:2px solid transparent;cursor:pointer;margin-bottom:-1px;white-space:nowrap}
.go-tab[aria-selected="true"]{color:var(--ink);font-weight:650;border-bottom-color:var(--accent)}
.go-card{padding:18px;min-width:0;display:grid;gap:12px}
.go-h{font-weight:700;font-size:15px;margin:0}
.go-sub{color:var(--ink-soft);font-size:12.5px;line-height:1.55;margin:0;max-width:86ch}
.go-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;min-width:0}
.go-g2{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))}
.go-f{display:grid;gap:5px;min-width:0;align-content:start}
.go-f>span{font-size:12px;font-weight:600;color:var(--ink-2)}
.go-f small{font-size:11.5px;color:var(--ink-faint);line-height:1.45}
.go-in{font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--ink);min-width:0;width:100%;box-sizing:border-box}
.go-in:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.go-in.num{width:110px}
.go-sw{display:inline-flex;align-items:center;gap:10px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;background:none;border:0;padding:0;color:inherit;text-align:start}
.go-sw i{position:relative;width:40px;height:22px;border-radius:999px;background:#cfd7e2;flex:none}
.go-sw i::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
.go-sw[aria-checked="true"] i{background:var(--accent)}
.go-sw[aria-checked="true"] i::after{transform:translateX(18px)}
[dir=rtl] .go-sw[aria-checked="true"] i::after{transform:translateX(-18px)}
.go-sw[disabled]{opacity:.5;cursor:not-allowed}
.go-modes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:4px 0 10px}
.go-mode{display:grid;gap:2px;text-align:start;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--ink);font:inherit;cursor:pointer}
.go-mode b{font-size:14px}
.go-mode small{font-size:11.5px;color:var(--ink-soft);line-height:1.35}
.go-mode[aria-checked="true"]{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 2px var(--accent-soft)}
.go-mode.m-testing[aria-checked="true"]{border-color:#e0a23a;background:var(--amber-soft);box-shadow:0 0 0 2px var(--amber-soft)}
.go-mode.m-off[aria-checked="true"]{border-color:var(--ink-faint);background:var(--surface-2);box-shadow:none}
.go-mode[disabled]{opacity:.5;cursor:not-allowed}
.go-mode:focus-visible{outline:2px solid var(--ink);outline-offset:2px}
.go-banner{padding:11px 14px;border-radius:12px;background:var(--amber-soft);border:1px solid #f0d199;color:#7a4d06;font-size:13px;margin:0 0 10px}
.go-banner.off{background:var(--surface-2);border-color:var(--border);color:var(--ink-2)}
.go-iplist{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.go-iplist li{display:flex;gap:8px;align-items:center;padding:5px 6px 5px 11px;border:1px solid var(--border);border-radius:10px;background:var(--surface);min-width:0}
.go-iplist code{font-size:12.5px;font-weight:650;overflow-wrap:anywhere}
.go-iplab{flex:1;min-width:0;font-size:12.5px;color:var(--ink-soft);overflow-wrap:anywhere}
.go-you{font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:#e0ecff;color:#1d4ed8}
.go-ipadd{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) auto;gap:8px;margin-top:8px}
.go-err{color:#b91c1c;font-size:12.5px;margin-top:6px}
@media(max-width:700px){.go-modes{grid-template-columns:minmax(0,1fr)}.go-ipadd{grid-template-columns:minmax(0,1fr)}}
.go-places{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr))}
.go-place{border:1px solid var(--border);border-radius:12px;padding:12px;display:grid;gap:6px;background:var(--surface)}
.go-place small{color:var(--ink-soft);font-size:12px;line-height:1.45}
.go-tx{display:grid;gap:6px}
.go-txr{display:grid;grid-template-columns:minmax(120px,.8fr) minmax(0,1.3fr) minmax(0,1.3fr);gap:8px;align-items:center}
.go-txr.hd span{font-size:11.5px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em}
.go-txr>span{font-size:12.5px;color:var(--ink-2)}
.go-save{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px}
.go-msg{font-size:12.5px;color:var(--ink-soft)}
.go-msg.bad{color:#b8362d}
.go-msg.ok{color:#1f7a4c}
.go-note{border:1px solid #ecd9a8;background:#fffaf0;color:#7a5a12;border-radius:11px;padding:10px 12px;font-size:12.5px;line-height:1.5}
.go-range{border:1px solid var(--border);border-radius:14px;padding:14px;display:grid;gap:12px;background:var(--surface)}
.go-range.off{opacity:.75}
.go-amt{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.go-amt b{font-size:13px;color:var(--ink-soft);font-weight:600}
.go-boxes{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(min(100%,250px),1fr))}
.go-box{position:relative;border:1px solid var(--border);border-radius:14px;padding:10px;display:grid;grid-template-columns:64px minmax(0,1fr);gap:10px;background:#fff;color:#2a2228}
.go-box.primary{border-color:#d9b77a;background:#fffaf2;box-shadow:0 0 0 1px #d9b77a inset}
.go-box.giving::after{content:"Giving now";position:absolute;top:-9px;inset-inline-end:10px;background:#1f7a4c;color:#fff;font-size:10.5px;font-weight:800;border-radius:999px;padding:2px 8px}
.go-box img,.go-box .go-noimg{width:64px;height:64px;border-radius:10px;object-fit:contain;background:#fff;border:1px solid #f1e4cc;display:block;box-sizing:border-box}
.go-box .go-noimg{background:repeating-linear-gradient(135deg,#faf5f7 0 6px,#f3ebef 6px 12px)}
.go-bn{min-width:0;display:grid;gap:3px;align-content:start}
.go-bn b{font-size:13px;line-height:1.3;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
.go-bn small{font-size:11.5px;color:#7a6a71}
.go-role{justify-self:start;font-size:10.5px;font-weight:800;letter-spacing:.05em;border-radius:6px;padding:1px 7px;background:#efeae6;color:#5e545a;text-transform:uppercase}
.go-role.p{background:#8a6a2c;color:#fffaf2}
.go-stock{font-size:11.5px;font-weight:700}
.go-stock.out{color:#b8362d}
.go-stock.in{color:#1f7a4c}
.go-alloc{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px;color:#5e545a}
.go-alloc input{width:90px}
.go-alloc select{width:auto;min-width:72px}
.go-acts{grid-column:1/-1;display:flex;gap:4px;flex-wrap:wrap}
.go-mini{font:inherit;font-size:12px;padding:5px 9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--ink);cursor:pointer}
.go-mini:hover{background:var(--surface-2)}
.go-mini.danger{color:#b8362d}
.go-mini[disabled]{opacity:.45;cursor:default}
.go-pick{display:grid;gap:8px}
.go-res{display:grid;gap:6px;max-height:320px;overflow:auto}
.go-ri{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:10px;align-items:center;border:1px solid var(--border);border-radius:10px;padding:6px 8px;background:var(--surface)}
.go-ri img,.go-ri .go-noimg{width:40px;height:40px;border-radius:8px;object-fit:contain;border:1px solid var(--border);background:#fff;display:block;box-sizing:border-box}
.go-ri b{font-size:12.5px;display:block}
.go-ri small{font-size:11.5px;color:var(--ink-soft)}
.go-opts{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr))}
.go-opt{font:inherit;text-align:start;cursor:pointer;display:grid;gap:3px;border:1.5px solid var(--border);border-radius:12px;padding:10px 12px;background:var(--surface);color:var(--ink)}
.go-opt b{font-size:13.5px}
.go-opt span{font-size:12px;color:var(--ink-soft);line-height:1.4}
.go-opt[aria-pressed="true"]{border-color:var(--accent);background:var(--accent-soft)}
.go-pv{display:grid;gap:12px;grid-template-columns:minmax(0,280px) minmax(0,1fr);align-items:start}
.go-stage{display:flex;justify-content:center;border:1px solid var(--border);border-radius:14px;padding:12px;background:#f3eef0;overflow:hidden;min-width:0}
.go-stage.dark{background:#1b1518}
.go-stage iframe{border:0;width:680px;max-width:100%;height:1400px;background:#fff8f5;border-radius:10px}
.go-stage.ph iframe{width:390px}
.go-stage.pop iframe{height:760px}
.go-stage.pop.dt iframe{width:1280px}
.go-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.go-tbl th,.go-tbl td{padding:7px 8px;border-bottom:1px solid var(--border);text-align:end;white-space:nowrap}
.go-tbl th:first-child,.go-tbl td:first-child{text-align:start}
.go-tbl th{font-weight:650;color:var(--ink-soft);font-size:11.5px}
.go-scroll{overflow-x:auto}
.go-kpis{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr))}
.go-kpi{border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:var(--surface)}
.go-kpi b{display:block;font-size:20px}
.go-kpi span{font-size:11.5px;color:var(--ink-soft)}
@media (max-width:860px){.go-pv{grid-template-columns:minmax(0,1fr)}.go-txr{grid-template-columns:minmax(0,1fr)}.go-txr.hd{display:none}}
</style>
@verbatim
<script>
(function () {
  'use strict';

  var SCREEN = 'giftoffers';
  var GROUP = 'Growth & Marketing';
  var TITLE = 'Gift offers';
  var TABS = [['settings', 'Settings'], ['ranges', 'Ranges & products'], ['popup', 'Popup'], ['preview', 'Live preview'], ['test', 'Test'], ['report', 'Report']];
  var PLACES = [
    ['cart', 'Abandoned-cart email', 'The Golden ticket at the top, before the bag. Ships on.'],
    ['reminder_1', 'Pending reminder 1 (30 minutes)', 'At the top of "Your items". Added to the order when it is paid in time.'],
    ['reminder_2', 'Pending reminder 2 (24 hours)', 'The same, in the second reminder.'],
    ['popup', 'Exit popup', 'When a shopper with a bag tries to leave. Built, off until you switch it on.']
  ];
  var DESIGNS = [['A', 'Ribbon card', 'Centred card with a bow, big product photo.'], ['B', 'Bottom sheet', 'Slides up from the bottom, easy for a thumb.'], ['C', 'Unwrap & reveal', 'A wiggling gift box she taps to open.'], ['D', 'Luxe minimal', 'Cream and ink, magazine style.']];
  var STYLES = [['S1', 'Timer pill', '"Held for you · 14:59" in a soft pink pill.'], ['S2', 'Shrinking bar', 'A pink bar that shortens as time runs.'], ['S3', 'Cream clock', 'Chips under the name and the cream clock, like the cart row.'], ['S4', 'Countdown tiles', 'Big minute and second boxes.'], ['S5', 'Luxe ticket', 'Gold dashed frame (on design A) and a plum timer strip.']];
  var AUDS = [['bag', 'Bag', 'Anyone with a product in her bag.'], ['checkout', 'Checkout', 'Only shoppers who did something on checkout.']];
  var EMAIL_TEXTS = [
    ['email_subject', 'Start of the subject line (only when the gift is in the email)'], ['email_eyebrow', 'Small line on top'], ['email_title', 'Heading'], ['email_body_cart', 'Abandoned-cart email: text'], ['email_button_cart', 'Abandoned-cart email: button'],
    ['email_body_order', 'Pending reminders: text'], ['email_button_order', 'Pending reminders: button'], ['email_worth', 'Line under the button (keep :price)'],
    ['clock_label', 'Above the clock (email and cart row)'], ['held', 'Fixed end time / clock alt text (keep :time)'], ['today', 'Today'], ['tomorrow', 'Tomorrow'], ['chip_free', 'Chip: Free'], ['chip_full', 'Chip: Full size']
  ];
  var ROW_TEXTS = [['row_free', 'Row chip: Free'], ['row_full', 'Row chip: Full size'], ['row_below', 'Bag below the range (keep :amount)'], ['row_over', 'When the time is up'], ['ended', 'An ended or unavailable gift'], ['pay_note', 'Order-pay page, under the row']];
  var POP_TEXTS = [['wait', 'Big word'], ['pop_full', 'After FREE'], ['pop_held', 'S1 pill'], ['pop_held_for', 'S2 bar'], ['pop_minutes', 'S4 minutes'], ['pop_seconds', 'S4 seconds'], ['pop_gold', 'S5 strip'], ['pop_box', 'C: before opening'], ['pop_tap', 'C: tap to open'], ['pop_added', 'Green tick'], ['pop_opening', 'Under the tick'], ['pop_close', 'Close button (screen readers)']];
  /* Off / Testing / Live (the owner, 4 November: "the same TEST / LIVE / OFF
     function" as Free gifts). Each saves at once and asks Yes / No first in the
     console's "Are you sure?" box (partials/reset-guard, data-kbb-sure); the
     current mode does not ask and clicking it does nothing. */
  var MODES = [['off', 'Off', 'Nobody gets a gift offer: no email box, no cart row, no popup.'], ['testing', 'Testing', 'Only the IPs listed below. Everyone else as if Off.'], ['live', 'Live', 'Every shopper.']];
  var EVENTS_ORDER = ['sent', 'open', 'click', 'shown', 'claimed', 'dismissed', 'added', 'orders', 'revenue'];

  var st = { data: null, cfg: null, tab: 'settings', seq: 0, busy: false, msg: '', msgKind: '', drafts: {}, pick: null,
    pv: { place: 'cart', locale: 'en', scheme: 'light', width: 'ph', range: '', bag: '', html: '', bytes: 0, seq: 0, timer: 0, busy: false },
    test: { to: '', place: 'cart', locale: 'en', range: '', msg: '', kind: '' },
    rep: { from: '', to: '', by: 'day', data: null, seq: 0 },
    tester: { ip: '', label: '', err: '' } };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function say(m, kind) { try { window.toast(esc(m), kind); } catch (e) {} }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function ours() { var c = document.querySelector('#content'); return !!c && (!c.firstChild || !!c.querySelector('[data-screen="' + SCREEN + '"]')); }
  function can() { return !!(st.data && st.data.can_manage); }
  function fmt(n) { return Number(n).toLocaleString('en-US'); }
  function money(fils) { return 'AED ' + (Number(fils) / 100).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
  function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

  async function api(method, path, body, signal) {
    var opts = { method: method, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (signal) opts.signal = signal;
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/gift-offers' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('gift-offers ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404 && !(e.body && e.body.message)) return 'The Gift offers endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot do this. An owner or a manager can.';
    if (e && e.body && e.body.errors) { var k = Object.keys(e.body.errors)[0]; return e.body.errors[k][0]; }
    return (e && e.body && e.body.message) || fallback;
  }

  /* ------------------------------------------------------------- the screen */
  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { stopTimers(); return previousGo.apply(this, arguments); }
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-go') === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE;
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    var c = document.querySelector('#content');
    if (c) c.textContent = '';
    /* st.data goes with st.cfg. Clearing only the draft left the last visit's
       data behind, so coming back drew settings() over a null cfg and threw
       ("reading 'places'"): an empty screen until a refresh (the owner,
       4 November). With both cleared it shows "Loading…" until the fresh GET. */
    st.drafts = {}; st.pick = null; st.msg = ''; st.data = null; st.cfg = null; st.pv.html = '';
    render();
    run(function () { return api('GET', ''); }, 'Gift offers could not be loaded.');
    return undefined;
  };
  function saveTesters(list, msg) {
    st.tester.err = '';
    run(function () { return api('POST', '/testers', { testers: list }); }, 'The IP list was not saved.', function (d) { syncLive(d); st.tester.ip = ''; st.tester.label = ''; say(msg); });
  }
  function addTester(addr, label) {
    var t = st.tester, list = (st.data.config.testers || []).slice();
    addr = String(addr || '').trim();
    if (!addr) { t.err = 'Type an IP address.'; render(); return; }
    if (list.some(function (x) { return x.ip === addr; })) { t.err = addr + ' is already on the list.'; render(); return; }
    if (list.length >= 50) { t.err = 'The list holds at most 50 IPs.'; render(); return; }
    saveTesters(list.concat([{ ip: addr, label: String(label || '').trim() }]), 'Added ' + addr + ' to the Testing list.');
  }
  function stopTimers() {
    clearTimeout(st.pv.timer); st.pv.timer = 0;
    if (st.pick) { clearTimeout(st.pick.timer); if (st.pick.ctl) st.pick.ctl.abort(); st.pick = null; }
  }

  async function run(fn, fallback, done) {
    var mine = ++st.seq;
    st.busy = true; render();
    try {
      var d = await fn();
      if (mine !== st.seq) return;
      if (d && d.config) { st.data = d; if (!st.cfg || d.saved_config) st.cfg = clone(d.config); }
      if (done) done(d);
    } catch (e) { if (mine === st.seq) { st.msg = explain(e, fallback); st.msgKind = 'bad'; say(st.msg, 'bad'); } }
    if (mine === st.seq) { st.busy = false; render(); }
  }

  /* ---------------------------------------------------------------- render */
  function render() {
    var c = document.querySelector('#content');
    if (!c || !ours()) return;
    var focus = document.activeElement && document.activeElement.getAttribute ? document.activeElement.getAttribute('data-k') : null;
    var tabs = '<div class="go-tabs" role="tablist">' + TABS.map(function (t) {
      return '<button type="button" class="go-tab" role="tab" data-tab="' + t[0] + '" aria-selected="' + (st.tab === t[0]) + '">' + esc(t[1]) + '</button>';
    }).join('') + '</div><p class="ectabs-hint go-sub">One extra full-size gift: its switches and texts, the ranges and products it gives, the exit popup, a live preview, a test send and the report.</p>';
    var body = !st.data ? '<div class="card go-card"><p class="go-sub">' + (st.busy ? 'Loading…' : esc(st.msg || '')) + '</p></div>'
      : st.tab === 'ranges' ? ranges() : st.tab === 'popup' ? popup() : st.tab === 'preview' ? preview() : st.tab === 'test' ? test() : st.tab === 'report' ? report() : settings();
    c.innerHTML = '<div class="go-wrap" data-screen="' + SCREEN + '">' + tabs + body + '</div>';
    if (st.tab === 'preview') paintFrame();
    if (focus) { var f = c.querySelector('[data-k="' + focus + '"]'); if (f) { f.focus(); if (f.setSelectionRange && f.type !== 'number' && f.type !== 'email') { var l = f.value.length; f.setSelectionRange(l, l); } } }
  }

  function sw(on, label, key, disabled) {
    return '<button type="button" class="go-sw" role="switch" data-sw="' + key + '" aria-checked="' + (!!on) + '"' + (disabled ? ' disabled' : '') + '><i></i><span>' + esc(label) + '</span></button>';
  }
  function txtRows(list) {
    var t = st.cfg.texts, ro = can() ? '' : ' disabled';
    return '<div class="go-tx"><div class="go-txr hd"><span>Where</span><span>English</span><span>Arabic</span></div>' + list.map(function (r) {
      return '<div class="go-txr"><span>' + esc(r[1]) + '</span>' +
        '<input class="go-in" data-k="t:' + r[0] + '_en" maxlength="200" value="' + esc(t[r[0] + '_en']) + '"' + ro + '>' +
        '<input class="go-in" dir="rtl" data-k="t:' + r[0] + '_ar" maxlength="200" value="' + esc(t[r[0] + '_ar']) + '"' + ro + '></div>';
    }).join('') + '</div>';
  }
  function saveBar() {
    return '<div class="go-save"><span class="go-msg ' + esc(st.msgKind) + '">' + esc(st.msg) + '</span>' +
      '<button type="button" class="btn ghost" data-act="reset"' + (can() ? '' : ' disabled') + '>Undo changes</button>' +
      '<button type="button" class="btn" data-act="save"' + (can() && !st.busy ? '' : ' disabled') + '>Save</button></div>';
  }
  /* Mode, Testing list and place switches are saved by their own requests;
     the draft takes the server's answer for exactly those, and nothing else. */
  function syncLive(d) {
    if (!d || !d.config || !st.cfg) return;
    st.cfg.mode = d.config.mode; st.cfg.testers = clone(d.config.testers); st.cfg.places = clone(d.config.places);
  }
  function modeOf(c) { return (c && c.mode) || 'testing'; }
  function modes() {
    var mode = modeOf(st.data.config), ro = !can();
    return '<div class="go-modes" role="radiogroup" aria-label="Mode">' + MODES.map(function (m) {
      var on = mode === m[0];
      return '<button type="button" role="radio" class="go-mode m-' + m[0] + '" data-mode="' + m[0] + '" aria-checked="' + on + '"' +
        (on ? '' : ' data-kbb-sure="' + esc(m[2] + ' Switch gift offers to ' + m[1] + '?') + '"') + (ro || st.busy ? ' disabled' : '') + '><b>' + esc(m[1]) + '</b><small>' + esc(m[2]) + '</small></button>';
    }).join('') + '</div>';
  }
  function testersCard() {
    var d = st.data, list = d.config.testers || [], t = st.tester, ro = !can(), mine = d.my_ip || '';
    var listed = list.some(function (x) { return x.ip === mine; });
    return '<h3 class="go-h" style="font-size:13.5px">IPs that see it in Testing</h3>' +
      '<p class="go-sub">In Testing only these addresses get the offer: the popup and the cart row by the address they browse from, the emails by the address the bag or the order was made from. Up to 50. Test sends from the Test tab work in every mode.</p>' +
      (list.length ? '<ul class="go-iplist">' + list.map(function (x, i) {
        return '<li><code>' + esc(x.ip) + '</code><span class="go-iplab">' + esc(x.label) + '</span>' + (x.ip === mine ? '<span class="go-you">You</span>' : '') +
          (ro ? '' : '<button type="button" class="btn ghost sm" data-iprm="' + i + '" aria-label="Remove ' + esc(x.ip) + '">Remove</button>') + '</li>';
      }).join('') + '</ul>' : '<p class="go-sub">No IPs yet.</p>') +
      (ro ? '' : '<div class="go-ipadd"><input class="go-in" data-k="tip" maxlength="45" placeholder="203.0.113.7 or 2001:db8::1" aria-label="IP address" value="' + esc(t.ip) + '" style="font-family:ui-monospace,monospace">' +
        '<input class="go-in" data-k="tlab" maxlength="60" placeholder="Label (optional)" aria-label="Label for this IP" value="' + esc(t.label) + '">' +
        '<button type="button" class="btn ghost sm" data-act="ipadd"' + (st.busy ? ' disabled' : '') + '>Add IP</button></div>' +
        (mine ? '<div class="go-row" style="margin-top:8px"><button type="button" class="btn ghost sm" data-act="ipmine"' + (st.busy || listed ? ' disabled' : '') + '>' + (listed ? 'Your IP is on the list' : 'Add my current IP') + '</button><span class="go-sub" style="margin:0">The server sees you as <code>' + esc(mine) + '</code></span></div>' : '')) +
      (t.err ? '<div class="go-err" role="alert">' + esc(t.err) + '</div>' : '');
  }
  function modeBanner() {
    var mode = modeOf(st.data.config), n = (st.data.config.testers || []).length;
    if (mode === 'testing') return '<div class="go-banner" role="status"><b>Testing: </b>only ' + n + (n === 1 ? ' IP gets' : ' IPs get') + ' gift offers. Every other shopper sees the shop and the emails as if Off.</div>';
    if (mode === 'off') return '<div class="go-banner off" role="status"><b>Off: </b>nobody gets a gift offer. The emails go out exactly as before.</div>';
    return '';
  }
  function dirty() { return st.data && st.cfg && JSON.stringify(st.cfg) !== JSON.stringify(st.data.config); }

  function settings() {
    var c = st.cfg, ro = !can();
    var places = PLACES.map(function (p) {
      return '<div class="go-place">' + sw(c.places[p[0]], p[1], 'place:' + p[0], ro) + '<small>' + esc(p[2]) + '</small></div>';
    }).join('');
    var mode = modeOf(st.data.config);
    return '<div class="card go-card"><h2 class="go-h">Gift offers: ' + (mode === 'live' ? 'Live' : mode === 'testing' ? 'Testing' : 'Off') + '</h2>' + modes() + modeBanner() +
      testersCard() + '</div>' +
      '<div class="card go-card"><h2 class="go-h">Where it shows</h2><p class="go-sub">Each switch saves at once.</p>' +
      '<div class="go-places">' + places + '</div></div>' +
      '<div class="card go-card"><p class="go-sub">One extra full-size gift at AED 0, chosen by the bag\'s value. Nothing shows anywhere until a range has a product that is in stock with allocation left; with none, every email goes out exactly as it does today.</p>' +
      '<div class="go-g2">' +
        '<label class="go-f"><span>Emails: hold the gift for (hours)</span><input class="go-in num" type="number" data-k="hold_hours" min="1" max="72" value="' + esc(c.hold_hours) + '"' + (ro ? ' disabled' : '') + '><small>From when each email is sent. Every email gets its own window.</small></label>' +
        '<label class="go-f"><span>Popup: hold the gift for (minutes)</span><input class="go-in num" type="number" data-k="popup_minutes" min="5" max="120" value="' + esc(c.popup_minutes) + '"' + (ro ? ' disabled' : '') + '><small>From the moment she claims it.</small></label>' +
        '<label class="go-f"><span>Timer in the email</span><select class="go-in" data-k="timer"' + (ro ? ' disabled' : '') + '><option value="live"' + (c.timer === 'live' ? ' selected' : '') + '>Live moving clock</option><option value="fixed"' + (c.timer === 'fixed' ? ' selected' : '') + '>Fixed end time ("Held until 4:15 AM tomorrow")</option></select><small>' + (st.data.live_clock ? 'The live clock is a small picture the server draws when the email is opened; Outlook on a computer shows it still.' : 'This server cannot draw the clock (no GD), so the fixed end time is used.') + '</small></label>' +
        '<label class="go-f"><span>Pending reminder 2</span><select class="go-in" data-k="window"' + (ro ? ' disabled' : '') + '><option value="each"' + (c.window === 'each' ? ' selected' : '') + '>A fresh window of its own</option><option value="first"' + (c.window === 'first' ? ' selected' : '') + '>The same window as reminder 1</option></select><small>With "the same window", reminder 2 carries no gift once reminder 1\'s has ended.</small></label>' +
      '</div>' +
      '<h3 class="go-h" style="font-size:13.5px">Email texts</h3>' + txtRows(EMAIL_TEXTS) +
      '<h3 class="go-h" style="font-size:13.5px">Cart row texts</h3>' + txtRows(ROW_TEXTS) +
      '<p class="go-sub">The reminders\' own schedule, subject and message stay where they were: <button type="button" class="btn ghost sm" data-goto="carttracking">Cart Tracking → Reminder emails</button> <button type="button" class="btn ghost sm" data-goto="emails-customer">Emails → Customer emails</button></p>' +
      saveBar() + '</div>';
  }

  function popup() {
    var c = st.cfg, p = c.popup, ro = !can(), w = p.words, t = c.texts;
    function opts(list, key, cur) {
      return '<div class="go-opts">' + list.map(function (o) {
        return '<button type="button" class="go-opt" data-opt="' + key + '" data-v="' + o[0] + '" aria-pressed="' + (cur === o[0]) + '"' + (ro ? ' disabled' : '') + '><b>' + esc(o[0] + ' · ' + o[1]) + '</b><span>' + esc(o[2]) + '</span></button>';
      }).join('') + '</div>';
    }
    var words = ['1', '2', '3', '4', '5'].map(function (n) { return [n, (t['w' + n + '_h_en'] || ''), 'Button: ' + (t['w' + n + '_c_en'] || '')]; });
    return '<div class="card go-card"><div class="go-row" style="justify-content:space-between"><h2 class="go-h">Exit popup</h2>' + sw(c.places.popup, c.places.popup ? 'On' : 'Off', 'place:popup', ro) + '</div>' + modeBanner() + '<p class="go-sub">The switch saves at once.</p>' +
      '<p class="go-sub">Off by default. When on, it shows once per visit to a shopper with a bag who moves to leave (the mouse leaving through the top of the window on a computer; leaving and coming back to the tab on a phone), never during Place order, never for you while signed in to this console, and not for 24 hours after "Maybe later". Its script loads only at that moment, only on pages whose visitor has a bag.</p>' +
      '<h3 class="go-h" style="font-size:13.5px">1 · Popup design</h3>' + opts(DESIGNS, 'design', p.design) +
      '<h3 class="go-h" style="font-size:13.5px">2 · Content style (the timer)</h3>' + opts(STYLES, 'style', p.style) +
      '<h3 class="go-h" style="font-size:13.5px">3 · Words</h3>' + opts(words.map(function (x) { return [x[0], x[1], x[2]]; }), 'words', w) +
      '<p class="go-sub">Words ' + esc(w) + ', in both languages. {p} is the product\'s name, {m} the minutes.</p>' +
      txtRows([['w' + w + '_h', 'Line under the big word'], ['w' + w + '_p', 'Text'], ['w' + w + '_c', 'Button'], ['w' + w + '_l', 'Maybe later']]) +
      '<h3 class="go-h" style="font-size:13.5px">4 · Who sees it</h3>' + opts(AUDS, 'audience', p.audience) +
      '<h3 class="go-h" style="font-size:13.5px">Popup texts</h3>' + txtRows(POP_TEXTS) +
      '<p class="go-sub">See it before saving: <button type="button" class="btn ghost sm" data-act="pvpop">Live preview of the popup</button></p>' +
      saveBar() + '</div>';
  }

  /* --------------------------------------------------------- ranges */
  function rangeView(r) { return st.drafts[r.id] || { min: r.min, max: r.max, enabled: r.enabled, items: clone(r.items) }; }
  function draftOf(id) {
    if (!st.drafts[id]) { var r = (st.data.ranges || []).filter(function (x) { return String(x.id) === String(id); })[0]; st.drafts[id] = r ? { min: r.min, max: r.max, enabled: r.enabled, items: clone(r.items) } : { min: 0, max: null, enabled: true, items: [] }; }
    return st.drafts[id];
  }
  function stockText(p) {
    if (!p) return '<span class="go-stock out">Product removed</span>';
    if (!p.in_stock) return '<span class="go-stock out">Out of stock</span>';
    return '<span class="go-stock in">' + (p.stock == null ? 'In stock' : fmt(p.stock) + ' in stock') + '</span>';
  }
  /* Gives per order (Lane GQ): the select's own options; anything else reads as ×1. */
  function qtys() { return (st.data && st.data.choices && st.data.choices.qtys) || [1, 2, 3, 4, 5]; }
  function qtyOf(n) { n = Number(n); return qtys().indexOf(n) >= 0 ? n : 1; }
  function boxes(id, v, giving) {
    if (!v.items.length) return '<p class="go-sub">No product yet: search below and add the primary first. With no product, this range offers nothing.</p>';
    var ro = !can();
    return '<div class="go-boxes">' + v.items.map(function (it, i) {
      var p = it.product, primary = it.role === 'primary';
      var img = p && p.thumb ? '<img src="' + esc(p.thumb) + '" alt="" width="64" height="64" loading="lazy">' : '<span class="go-noimg"></span>';
      var used = it.used || 0, left = Math.max(0, (Number(it.allocation) || 0) - used), qty = qtyOf(it.qty);
      return '<div class="go-box' + (primary ? ' primary' : '') + (giving && it.id === giving ? ' giving' : '') + '">' + img +
        '<div class="go-bn"><span class="go-role' + (primary ? ' p' : '') + '">' + (primary ? 'Primary' : 'Backup ' + i) + '</span><b>' + esc(p ? p.name : 'Product #' + it.product_id) + '</b><small>' + esc(p ? p.brand : '') + (p && p.price ? ' · ' + esc(p.price) : '') + '</small>' + stockText(p) + '</div>' +
        '<label class="go-alloc">For this offer <input class="go-in" type="number" min="0" max="100000" data-k="al:' + id + ':' + i + '" value="' + esc(it.allocation) + '"' + (ro ? ' disabled' : '') + '> <span>given ' + fmt(used) + ' · left ' + fmt(left) + (qty > 1 ? ' · enough for ' + fmt(Math.floor(left / qty)) + ' order' + (Math.floor(left / qty) === 1 ? '' : 's') : '') + '</span></label>' +
        '<label class="go-alloc go-qty">Gives per order <select class="go-in" data-k="qt:' + id + ':' + i + '"' + (ro ? ' disabled' : '') + '>' + qtys().map(function (n) { return '<option value="' + n + '"' + (n === qty ? ' selected' : '') + '>×' + n + '</option>'; }).join('') + '</select>' + (qty > 1 ? ' <span>' + qty + ' pcs to each order, at AED 0. Each order uses ' + qty + ' of this offer\'s units; with fewer than ' + qty + ' left, the next backup is given.</span>' : '') + '</label>' +
        '<div class="go-acts">' + (primary ? '' : '<button type="button" class="go-mini" data-it="primary" data-r="' + id + '" data-i="' + i + '"' + (ro ? ' disabled' : '') + '>Make primary</button>') +
          '<button type="button" class="go-mini" data-it="up" data-r="' + id + '" data-i="' + i + '"' + (ro || i < 2 || primary ? ' disabled' : '') + ' aria-label="Move up">↑</button>' +
          '<button type="button" class="go-mini" data-it="down" data-r="' + id + '" data-i="' + i + '"' + (ro || primary || i === v.items.length - 1 ? ' disabled' : '') + ' aria-label="Move down">↓</button>' +
          '<button type="button" class="go-mini danger" data-it="remove" data-r="' + id + '" data-i="' + i + '"' + (ro ? ' disabled' : '') + '>Remove</button></div></div>';
    }).join('') + '</div>';
  }
  function picker(id) {
    var pk = st.pick && String(st.pick.range) === String(id) ? st.pick : null;
    if (!pk) return '<div class="go-row"><button type="button" class="btn ghost sm" data-act="pick" data-r="' + id + '"' + (can() ? '' : ' disabled') + '>+ Add a product</button></div>';
    var res = pk.busy ? '<p class="go-sub">Searching…</p>' : (pk.results || []).map(function (p) {
      var rows = p.variants && p.variants.length ? p.variants : [p];
      return rows.map(function (q) {
        var img = q.thumb ? '<img src="' + esc(q.thumb) + '" alt="" width="40" height="40" loading="lazy">' : '<span class="go-noimg"></span>';
        var ok = q.type !== 'variable' && q.status === 'publish';
        return '<div class="go-ri">' + img + '<div><b>' + esc(q.name) + '</b><small>' + esc(q.brand || '') + ' · ' + (q.in_stock ? (q.stock == null ? 'In stock' : fmt(q.stock) + ' in stock') : 'Out of stock') + (q.status !== 'publish' ? ' · not published' : '') + '</small></div>' +
          '<button type="button" class="go-mini" data-add="' + esc(q.id) + '" data-v="' + esc(q.variant_id || '') + '" data-r="' + id + '"' + (ok ? '' : ' disabled title="Pick one of its options"') + '>Add</button></div>';
      }).join('');
    }).join('');
    return '<div class="go-pick"><input class="go-in" type="search" data-k="q:' + id + '" placeholder="Search a product by name or SKU" value="' + esc(pk.q) + '" maxlength="80" autocomplete="off">' +
      '<div class="go-res">' + (res || (pk.q.length < 2 ? '<p class="go-sub">Type two letters or more.</p>' : '<p class="go-sub">No product found.</p>')) + '</div>' +
      '<div class="go-row"><button type="button" class="btn ghost sm" data-act="unpick">Done</button></div></div>';
  }
  function rangeCard(id, v, label, giving) {
    var ro = !can();
    return '<div class="go-range' + (v.enabled ? '' : ' off') + '" data-range="' + id + '"><div class="go-row" style="justify-content:space-between">' +
      '<div class="go-amt"><b>Bag from AED</b><input class="go-in num" type="number" min="0" data-k="min:' + id + '" value="' + esc(v.min) + '"' + (ro ? ' disabled' : '') + '><b>to AED</b><input class="go-in num" type="number" min="0" data-k="max:' + id + '" placeholder="no top" value="' + esc(v.max == null ? '' : v.max) + '"' + (ro ? ' disabled' : '') + '></div>' +
      sw(v.enabled, v.enabled ? 'Running' : 'Paused', 'renabled:' + id, ro) + '</div>' +
      '<p class="go-sub">' + esc(label) + '. The primary is offered; when it is out of stock or its allocation is used up, the next backup is. When none can be given, this range offers nothing.</p>' +
      boxes(id, v, giving) + picker(id) +
      '<div class="go-save">' + (id === 'new' ? '' : '<button type="button" class="btn ghost" data-act="del" data-r="' + id + '"' + (ro ? ' disabled' : '') + '>Delete range</button>') +
      '<button type="button" class="btn" data-act="saverange" data-r="' + id + '"' + (ro || st.busy ? ' disabled' : '') + '>Save range</button></div></div>';
  }
  function ranges() {
    var list = (st.data.ranges || []).map(function (r) { return rangeCard(r.id, rangeView(r), r.label, r.giving); }).join('');
    if (st.drafts['new']) list += rangeCard('new', st.drafts['new'], 'New range', null);
    return '<div class="card go-card"><div class="go-row" style="justify-content:space-between"><h2 class="go-h">Ranges & products</h2><button type="button" class="btn" data-act="newrange"' + (can() && !st.drafts['new'] ? '' : ' disabled') + '>+ Add range</button></div>' +
      '<p class="go-sub">A bag in two ranges gets the higher one. Each product has its own quantity for this offer: "given" counts orders placed with it (pending orders: when they are paid); tests never count.</p>' +
      (list || '<p class="go-sub">No range yet.</p>') + (st.msg ? '<p class="go-msg ' + esc(st.msgKind) + '">' + esc(st.msg) + '</p>' : '') + '</div>';
  }

  /* --------------------------------------------------------- preview */
  function rangeOptions(cur) {
    return '<option value="">The first range that gives a gift</option>' + (st.data.ranges || []).map(function (r) {
      return '<option value="' + r.id + '"' + (String(cur) === String(r.id) ? ' selected' : '') + '>' + esc(r.label) + '</option>';
    }).join('');
  }
  function placeOptions(cur, withPopup) {
    return PLACES.filter(function (p) { return withPopup || p[0] !== 'popup'; }).map(function (p) { return '<option value="' + p[0] + '"' + (cur === p[0] ? ' selected' : '') + '>' + esc(p[1]) + '</option>'; }).join('');
  }
  function preview() {
    var v = st.pv;
    return '<div class="card go-card"><h2 class="go-h">Live preview</h2><p class="go-sub">The real email and the real popup, drawn from what is on this screen now, saved or not. Nothing is sent and nothing is stored.' + (dirty() ? ' <b>You have unsaved changes.</b>' : '') + '</p>' +
      '<div class="go-pv"><div class="go-f" style="gap:10px">' +
        '<label class="go-f"><span>Show</span><select class="go-in" data-k="pv:place">' + placeOptions(v.place, true) + '</select></label>' +
        '<label class="go-f"><span>Language</span><select class="go-in" data-k="pv:locale"><option value="en"' + (v.locale === 'en' ? ' selected' : '') + '>English</option><option value="ar"' + (v.locale === 'ar' ? ' selected' : '') + '>Arabic</option></select></label>' +
        '<label class="go-f"><span>Range</span><select class="go-in" data-k="pv:range">' + rangeOptions(v.range) + '</select></label>' +
        '<label class="go-f"><span>…or a bag value (AED)</span><input class="go-in num" type="number" min="0" data-k="pv:bag" value="' + esc(v.bag) + '"></label>' +
        '<div class="go-row"><button type="button" class="go-mini" data-pv="scheme" aria-pressed="' + (v.scheme === 'dark') + '">' + (v.scheme === 'dark' ? 'Dark mode' : 'Light mode') + '</button><button type="button" class="go-mini" data-pv="width">' + (v.width === 'ph' ? 'Phone (390)' : 'Computer') + '</button></div>' +
        '<button type="button" class="btn" data-act="refresh"' + (v.busy ? ' disabled' : '') + '>' + (v.busy ? 'Drawing…' : 'Refresh preview') + '</button>' +
        (v.bytes ? '<p class="go-sub">' + (v.place === 'popup' ? 'Popup page' : 'Email size') + ': ' + (v.bytes / 1024).toFixed(1) + ' KB' + (v.place === 'popup' ? '' : ' (Gmail clips at 102 KB)') + '</p>' : '') +
      '</div><div class="go-stage' + (v.width === 'ph' ? ' ph' : ' dt') + (v.place === 'popup' ? ' pop' : '') + (v.scheme === 'dark' ? ' dark' : '') + '"><iframe title="Gift offer preview" sandbox="allow-scripts" data-go-frame></iframe></div></div></div>';
  }
  function paintFrame() {
    var f = document.querySelector('[data-go-frame]');
    if (!f) return;
    if (!st.pv.html) { if (!st.pv.busy) loadPreview(); return; }
    var h = st.pv.html;
    // Dark mode as a dark-mode mail client draws it: the kit's own prefers-color-scheme rules, applied.
    if (st.pv.scheme === 'dark') h = h.replace(/@media \(prefers-color-scheme:dark\)\{/g, '@media all{').replace('<meta name="color-scheme" content="light dark">', '<meta name="color-scheme" content="dark">');
    f.srcdoc = h;
  }
  function schedulePreview() {
    clearTimeout(st.pv.timer);
    if (st.tab !== 'preview') { st.pv.html = ''; return; }
    st.pv.timer = setTimeout(loadPreview, 700);
  }
  function loadPreview() {
    var v = st.pv, mine = ++v.seq;
    v.busy = true;
    var body = { place: v.place, locale: v.locale, config: st.cfg };
    if (v.range) body.range_id = Number(v.range); else if (v.bag !== '') body.bag = Number(v.bag);
    api('POST', '/preview', body).then(function (d) {
      if (mine !== v.seq) return;
      v.busy = false; v.html = d.html; v.bytes = d.bytes;
      if (ours() && st.tab === 'preview') render();
    }).catch(function (e) {
      if (mine !== v.seq) return;
      v.busy = false; v.html = '<p style="font:14px system-ui;padding:20px;color:#b8362d">' + esc(explain(e, 'The preview could not be drawn.')) + '</p>';
      if (ours() && st.tab === 'preview') render();
    });
  }

  /* --------------------------------------------------------- test */
  function test() {
    var t = st.test;
    return '<div class="card go-card"><h2 class="go-h">Send a test email</h2><p class="go-sub">A real email to the address you type, with the gift for the range you pick, drawn from your SAVED settings. Its claim link works but is marked as a test: it never uses up an allocation and never shows in the report. The pending reminder test uses the shop\'s sample order.</p>' +
      '<div class="go-g2"><label class="go-f"><span>Send to</span><input class="go-in" type="email" data-k="ts:to" value="' + esc(t.to) + '" placeholder="you@example.com" maxlength="190"></label>' +
      '<label class="go-f"><span>Email</span><select class="go-in" data-k="ts:place">' + placeOptions(t.place, false) + '</select></label>' +
      '<label class="go-f"><span>Language</span><select class="go-in" data-k="ts:locale"><option value="en"' + (t.locale === 'en' ? ' selected' : '') + '>English</option><option value="ar"' + (t.locale === 'ar' ? ' selected' : '') + '>Arabic</option></select></label>' +
      '<label class="go-f"><span>Range</span><select class="go-in" data-k="ts:range">' + rangeOptions(t.range) + '</select></label></div>' +
      (dirty() ? '<p class="go-note">You have unsaved changes: the test uses what is saved.</p>' : '') +
      '<div class="go-save"><span class="go-msg ' + esc(t.kind) + '">' + esc(t.msg) + '</span><button type="button" class="btn" data-act="send"' + (can() && !st.busy ? '' : ' disabled') + '>Send test</button></div></div>';
  }
  function sendTest() {
    var t = st.test;
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(t.to)) { t.msg = 'Type an email address.'; t.kind = 'bad'; render(); return; }
    var body = { to: t.to, place: t.place, locale: t.locale };
    if (t.range) body.range_id = Number(t.range);
    t.msg = 'Sending…'; t.kind = ''; render();
    api('POST', '/test', body).then(function (d) {
      t.msg = (d && d.message) || 'Sent.'; t.kind = 'ok'; if (ours()) render();
    }).catch(function (e) { t.msg = explain(e, 'Not sent.'); t.kind = 'bad'; if (ours()) render(); });
  }

  /* --------------------------------------------------------- report */
  function report() {
    var r = st.rep, d = r.data;
    if (!r.from) { var now = new Date(), from = new Date(now.getTime() - 6 * 864e5); r.from = iso(from); r.to = iso(now); }
    var head = '<div class="go-row"><label class="go-f"><span>From</span><input class="go-in" type="date" data-k="rp:from" value="' + esc(r.from) + '"></label><label class="go-f"><span>To</span><input class="go-in" type="date" data-k="rp:to" value="' + esc(r.to) + '"></label>' +
      '<label class="go-f"><span>By</span><select class="go-in" data-k="rp:by">' + [['day', 'Day'], ['week', 'Week'], ['month', 'Month']].map(function (o) { return '<option value="' + o[0] + '"' + (r.by === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>' +
      '<button type="button" class="btn" data-act="report" style="align-self:end">Show</button></div>';
    if (!d) { if (!r.busy) loadReport(); return '<div class="card go-card"><h2 class="go-h">Report</h2>' + head + '<p class="go-sub">Loading…</p></div>'; }
    function val(e, n) { return e === 'revenue' ? money(n) : fmt(n); }
    var kpis = '<div class="go-kpis">' + EVENTS_ORDER.map(function (e) { return '<div class="go-kpi"><b>' + esc(val(e, d.totals[e] || 0)) + '</b><span>' + esc(d.events[e]) + '</span></div>'; }).join('') + '</div>';
    var th = '<tr><th></th>' + EVENTS_ORDER.map(function (e) { return '<th>' + esc(d.events[e]) + '</th>'; }).join('') + '</tr>';
    function row(label, n) { return '<tr><td>' + esc(label) + '</td>' + EVENTS_ORDER.map(function (e) { return '<td>' + esc(val(e, n[e] || 0)) + '</td>'; }).join('') + '</tr>'; }
    var byDay = d.buckets.map(function (b) { return row(b.label, b.n); }).join('');
    var byPlace = Object.keys(d.places).map(function (k) { return row(d.place_names[k], d.places[k]); }).join('');
    var byRange = d.ranges.map(function (x) { return row(x.label, x.n); }).join('') || '<tr><td colspan="10">Nothing yet.</td></tr>';
    return '<div class="card go-card"><h2 class="go-h">Report</h2>' + head + kpis +
      '<h3 class="go-h" style="font-size:13.5px">By ' + esc(d.by) + '</h3><div class="go-scroll"><table class="go-tbl">' + th + byDay + '</table></div>' +
      '<h3 class="go-h" style="font-size:13.5px">By place</h3><div class="go-scroll"><table class="go-tbl">' + th + byPlace + '</table></div>' +
      '<h3 class="go-h" style="font-size:13.5px">By range</h3><div class="go-scroll"><table class="go-tbl">' + th + byRange + '</table></div>' +
      '<p class="go-sub">Clock opens count each time an email\'s moving clock is fetched. Revenue is the order total of orders that carried the gift. Tests never count.</p></div>';
  }
  function loadReport() {
    var r = st.rep, mine = ++r.seq;
    r.busy = true;
    api('GET', '/report?from=' + encodeURIComponent(r.from) + '&to=' + encodeURIComponent(r.to) + '&by=' + encodeURIComponent(r.by)).then(function (d) {
      if (mine !== r.seq) return; r.busy = false; r.data = d; if (ours() && st.tab === 'report') render();
    }).catch(function (e) { if (mine !== r.seq) return; r.busy = false; r.data = null; say(explain(e, 'The report could not be loaded.'), 'bad'); });
  }

  /* --------------------------------------------------------- search */
  function search(id) {
    var pk = st.pick;
    clearTimeout(pk.timer);
    if (pk.q.length < 2) { pk.results = []; render(); return; }
    pk.timer = setTimeout(function () {
      if (pk.ctl) pk.ctl.abort();
      pk.ctl = typeof AbortController === 'function' ? new AbortController() : null;
      pk.busy = true; render();
      api('GET', '/products?q=' + encodeURIComponent(pk.q), undefined, pk.ctl ? pk.ctl.signal : undefined).then(function (d) {
        if (st.pick !== pk) return;
        pk.busy = false; pk.results = d.products || []; if (ours()) render();
      }).catch(function (e) { if (st.pick !== pk || (e && e.name === 'AbortError')) return; pk.busy = false; pk.results = []; if (ours()) render(); });
    }, 300);
  }
  function addProduct(id, pid, vid) {
    var d = draftOf(id), found = null;
    (st.pick.results || []).forEach(function (p) { (p.variants && p.variants.length ? p.variants : [p]).forEach(function (q) { if (String(q.id) === String(pid) && String(q.variant_id || '') === String(vid || '')) found = q; }); });
    if (!found) return;
    if (d.items.some(function (it) { return it.product_id === found.id && String(it.variant_id || '') === String(found.variant_id || ''); })) { say('That product is already in this range.'); return; }
    if (d.items.length >= st.data.choices.max_items) { say('At most ' + st.data.choices.max_items + ' products per range.'); return; }
    d.items.push({ id: null, product_id: found.id, variant_id: found.variant_id || null, role: d.items.length ? 'backup' : 'primary', allocation: 50, qty: 1, used: 0,
      product: { name: found.name, brand: found.brand, thumb: found.thumb, stock: found.stock, in_stock: found.in_stock, status: found.status, price: found.price } });
    render();
  }
  function saveRange(id) {
    var d = draftOf(id);
    var body = { min: Number(d.min) || 0, max: d.max === '' || d.max == null ? null : Number(d.max), enabled: !!d.enabled,
      items: d.items.map(function (it) { return { product_id: it.product_id, variant_id: it.variant_id || null, role: it.role, allocation: Math.max(0, Math.round(Number(it.allocation) || 0)), qty: qtyOf(it.qty) }; }) };
    run(function () { return api(id === 'new' ? 'POST' : 'PUT', id === 'new' ? '/ranges' : '/ranges/' + id, body); }, 'The range was not saved.', function () {
      delete st.drafts[id]; st.pick = null; st.msg = 'Saved.'; st.msgKind = 'ok'; say('Range saved.');
    });
  }

  /* --------------------------------------------------------- events */
  function setCfg(key, value) {
    var c = st.cfg;
    if (key.indexOf('t:') === 0) c.texts[key.slice(2)] = value;
    else if (key === 'hold_hours' || key === 'popup_minutes') c[key] = value === '' ? value : Number(value);
    else c[key] = value;
    st.msg = ''; schedulePreview();
  }
  document.addEventListener('click', function (e) {
    var host = e.target.closest && e.target.closest('[data-screen="' + SCREEN + '"]');
    if (!host) return;
    var b;
    if ((b = e.target.closest('[data-tab]'))) { st.tab = b.getAttribute('data-tab'); stopTimers(); st.msg = ''; if (st.tab === 'preview') st.pv.html = ''; if (st.tab === 'report') st.rep.data = null; render(); return; }
    if ((b = e.target.closest('[data-goto]'))) { window.go(b.getAttribute('data-goto')); return; }
    if ((b = e.target.closest('[data-sw]'))) {
      if (b.disabled) return;
      var k = b.getAttribute('data-sw');
      if (k.indexOf('place:') === 0) {
        var pl = k.slice(6), want = !st.data.config.places[pl];
        run(function () { return api('POST', '/place', { place: pl, on: want }); }, 'Not changed.', function (d) {
          syncLive(d); say((PLACES.filter(function (x) { return x[0] === pl; })[0] || [0, pl])[1] + (want ? ' is on.' : ' is off.'));
        });
        return;
      }
      if (k.indexOf('renabled:') === 0) { var dr = draftOf(k.slice(9)); dr.enabled = !dr.enabled; }
      st.msg = ''; schedulePreview(); render(); return;
    }
    if ((b = e.target.closest('[data-mode]'))) {
      var m = b.getAttribute('data-mode');
      if (b.disabled || m === modeOf(st.data.config)) return;
      run(function () { return api('POST', '/mode', { mode: m }); }, 'Mode not changed.', function (d) {
        syncLive(d);
        say(m === 'live' ? 'Gift offers are Live: every shopper gets them.' : m === 'testing' ? (d && d.added_ip ? 'Gift offers are in Testing. Your IP ' + d.added_ip + ' was added to the Testing list, so you see them; nobody else does.' : 'Gift offers are in Testing: only the IPs on the list get them.') : 'Gift offers are Off: nobody gets them.');
      });
      return;
    }
    if ((b = e.target.closest('[data-iprm]'))) {
      var all = (st.data.config.testers || []).slice(), gone = all.splice(Number(b.getAttribute('data-iprm')), 1)[0];
      saveTesters(all, 'Removed ' + (gone ? gone.ip : '') + ' from the Testing list.');
      return;
    }
    if ((b = e.target.closest('[data-opt]'))) { st.cfg.popup[b.getAttribute('data-opt')] = b.getAttribute('data-v'); schedulePreview(); render(); return; }
    if ((b = e.target.closest('[data-it]'))) {
      var d = draftOf(b.getAttribute('data-r')), i = Number(b.getAttribute('data-i')), act = b.getAttribute('data-it'), list = d.items;
      if (act === 'remove') { list.splice(i, 1); if (list.length && !list.some(function (x) { return x.role === 'primary'; })) list[0].role = 'primary'; }
      else if (act === 'primary') { list.forEach(function (x) { x.role = 'backup'; }); var it = list.splice(i, 1)[0]; it.role = 'primary'; list.unshift(it); }
      else if (act === 'up' && i > 1) { var t1 = list[i - 1]; list[i - 1] = list[i]; list[i] = t1; }
      else if (act === 'down' && i < list.length - 1) { var t2 = list[i + 1]; list[i + 1] = list[i]; list[i] = t2; }
      render(); return;
    }
    if ((b = e.target.closest('[data-add]'))) { addProduct(b.getAttribute('data-r'), b.getAttribute('data-add'), b.getAttribute('data-v')); return; }
    if ((b = e.target.closest('[data-pv]'))) {
      var x = b.getAttribute('data-pv');
      if (x === 'scheme') st.pv.scheme = st.pv.scheme === 'dark' ? 'light' : 'dark'; else st.pv.width = st.pv.width === 'ph' ? 'dt' : 'ph';
      render(); return;
    }
    if (!(b = e.target.closest('[data-act]')) || b.disabled) return;
    var a = b.getAttribute('data-act');
    if (a === 'save') run(function () { return api('POST', '/settings', { config: st.cfg }); }, 'Not saved.', function (d) { st.cfg = clone(d.config); st.msg = 'Saved.'; st.msgKind = 'ok'; say('Gift offers saved.'); });
    else if (a === 'reset') { st.cfg = clone(st.data.config); st.msg = ''; render(); }
    else if (a === 'newrange') { st.drafts['new'] = { min: 0, max: null, enabled: true, items: [] }; render(); }
    else if (a === 'saverange') saveRange(b.getAttribute('data-r'));
    else if (a === 'del') { if (window.confirm('Delete this range and its products?')) run(function () { return api('DELETE', '/ranges/' + b.getAttribute('data-r')); }, 'Not deleted.', function () { say('Range deleted.'); }); }
    else if (a === 'pick') { stopTimers(); st.pick = { range: b.getAttribute('data-r'), q: '', results: [], timer: 0, ctl: null, busy: false }; render(); var f = document.querySelector('[data-k="q:' + st.pick.range + '"]'); if (f) f.focus(); }
    else if (a === 'unpick') { stopTimers(); render(); }
    else if (a === 'refresh') { clearTimeout(st.pv.timer); loadPreview(); render(); }
    else if (a === 'pvpop') { st.pv.place = 'popup'; st.pv.html = ''; st.tab = 'preview'; render(); }
    else if (a === 'send') sendTest();
    else if (a === 'ipadd') addTester(st.tester.ip, st.tester.label);
    else if (a === 'ipmine') addTester(st.data.my_ip, 'My device');
    else if (a === 'report') { st.rep.data = null; render(); }
  });
  function onField(e) {
    var f = e.target, k = f.getAttribute && f.getAttribute('data-k');
    if (!k || !f.closest('[data-screen="' + SCREEN + '"]')) return;
    var v = f.value, parts = k.split(':');
    if (parts[0] === 'q') { if (st.pick) { st.pick.q = v.trim(); search(parts[1]); } return; }
    if (parts[0] === 'min' || parts[0] === 'max') { draftOf(parts[1])[parts[0]] = v === '' ? (parts[0] === 'max' ? null : 0) : Number(v); return; }
    if (parts[0] === 'al') { draftOf(parts[1]).items[Number(parts[2])].allocation = v; return; }
    if (parts[0] === 'qt') { draftOf(parts[1]).items[Number(parts[2])].qty = qtyOf(v); if (e.type === 'change') render(); return; }
    if (parts[0] === 'pv') { st.pv[parts[1]] = v; if (parts[1] === 'range' && v) st.pv.bag = ''; if (e.type === 'change') { st.pv.html = ''; render(); } return; }
    if (parts[0] === 'ts') { st.test[parts[1]] = v; return; }
    if (k === 'tip') { st.tester.ip = v; return; }
    if (k === 'tlab') { st.tester.label = v; return; }
    if (parts[0] === 'rp') { st.rep[parts[1]] = v; return; }
    setCfg(k, v);
  }
  document.addEventListener('input', onField);
  document.addEventListener('change', onField);

  /* The sidebar row is AdminNav's static `late` entry; this only returns it
     when the console's own nav is drawn without it. */
  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Gift offers',
      icon: '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13M3 12h18"/><circle cx="17.5" cy="4.5" r="2.5"/><path d="M17.5 3.3v1.2l.8.6"/>',
      group: GROUP,
      after: ['freegifts', 'push', 'searchterms', 'pixels']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();
})();
</script>
@endverbatim
