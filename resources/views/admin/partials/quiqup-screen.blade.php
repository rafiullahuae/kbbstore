{{--
    Store → Quiqup (Lane QQB, docs/plans/quiqup/QUIQUP-PLAN.md §3.4, plus the
    owner's 14 Nov 2026 additions: Off → Internal testing → Live, a Go-Live
    checklist, no page reloads, toasts with Quiqup's exact words).

    Endpoints (routes/quiqup-admin.php; AdminCapabilities: quiqup.view reads,
    quiqup.settings writes / tests / changes mode, failing closed):

        GET  /admin-api/quiqup-settings    secrets come back as {filled, last4} only
        POST /admin-api/quiqup-settings
        POST /admin-api/quiqup-test        verifies a key (a Go-Live check)
        POST /admin-api/quiqup-mode        {mode, confirm} or {testing_env, confirm}
        GET  /admin-api/quiqup-activity

    One GET when the screen opens; one request per press. No polling, no
    timer, no layout measurement. Every value printed passes through esc().
    Every late answer checks ours() before painting (the #content landmine).

    Also exposes window.kbbQQ (call, esc, toast, confirm dialog) for
    partials/quiqup-orders.blade.php, which must be included AFTER this one.

    Wiring (the integrator): @include('admin.partials.quiqup-screen') with the
    other screen partials, 'quiqup' in LATE_RENDERED and in TITLES
    ('quiqup':['Store','Quiqup']), then delete the `pending` key of the
    'quiqup' row in App\Support\AdminNav.
--}}
@verbatim
<style>
.qq{max-width:1040px;margin:0 auto;min-width:0}
.qq *{box-sizing:border-box}
.qq-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);border-radius:var(--r,18px);padding:18px 20px;box-shadow:var(--sh-s);margin-bottom:14px}
.qq-hero h2{margin:0 0 4px;font-size:21px;letter-spacing:-.01em;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.qq-hero p{margin:0;color:var(--ink-soft,#626c80);font-size:13.5px;line-height:1.5}
.qq-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 10px;font-size:11.5px;font-weight:700;letter-spacing:.03em;white-space:nowrap}
.qq-chip::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.qq-guide ol{margin:8px 0 0;padding-inline-start:20px;display:grid;gap:7px}.qq-guide li{line-height:1.5}.qq-guide .qq-step{display:flex;gap:12px;align-items:flex-start}.qq-guide .qq-n{flex:none;display:inline-grid;place-items:center;width:30px;height:30px;border-radius:50%;background:var(--surface-3,#eef1f9);font-weight:700}.qq-guide .qq-n.ok{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}.qq-guide h3{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.qq-guide code{word-break:break-all}.qq-guide .qq-warn{border-inline-start:3px solid #e2a23b;padding:8px 12px;background:var(--amber-soft,#fdf2e2);border-radius:10px;margin-top:10px}
.qq-chip.off{background:var(--surface-3,#eef1f9);color:var(--ink-soft,#626c80)}
.qq-chip.testing{background:var(--amber-soft,#fdf2e2);color:#9a5b0e}
.qq-chip.live{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.qq-chip.bad{background:var(--red-soft,#fdeceb);color:#a8261d}
.qq-chip.info{background:var(--blue-soft,#eaf0fd);color:#2848a8}
.qq-seg{display:inline-grid;grid-template-columns:repeat(3,auto);background:var(--surface-2,#f2f4fb);border:1px solid var(--border,#e6e9f2);border-radius:999px;padding:4px;gap:2px}
.qq-seg button{border:0;background:transparent;border-radius:999px;padding:9px 16px;font:inherit;font-size:13px;font-weight:650;cursor:pointer;color:var(--ink-2,#3c465c);min-height:40px;transition:background .18s var(--ease),color .18s var(--ease),box-shadow .18s}
.qq-seg button:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.qq-seg button[aria-checked=true]{background:var(--surface,#fff);box-shadow:var(--sh-s),0 0 0 1px var(--border,#e6e9f2);color:var(--ink,#101729)}
.qq-seg button[data-m=testing][aria-checked=true]{color:#9a5b0e}
.qq-seg button[data-m=live][aria-checked=true]{background:var(--accent,#15a85a);color:#fff;box-shadow:none}
.qq-banner{display:flex;gap:12px;align-items:flex-start;border-radius:var(--r-sm,12px);padding:12px 14px;margin:0 0 14px;font-size:13.5px;line-height:1.5}
.qq-banner.warn{background:var(--amber-soft,#fdf2e2);color:#7a4a0c}
.qq-banner.info{background:var(--blue-soft,#eaf0fd);color:#223f94}
.qq-banner b{font-weight:700}
.qq-tabs{display:flex;gap:4px;margin:0 0 14px;border-bottom:1px solid var(--border,#e6e9f2);overflow-x:auto;scrollbar-width:none}
.qq-tabs button{border:0;background:none;padding:10px 14px;font:inherit;font-size:13.5px;font-weight:650;cursor:pointer;color:var(--ink-soft,#626c80);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;min-height:42px}
.qq-tabs button[aria-selected=true]{color:var(--ink,#101729);border-bottom-color:var(--accent,#15a85a)}
.qq-tabs button:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:-2px;border-radius:8px}
.qq-card{border:1px solid var(--border,#e6e9f2);border-radius:var(--r,18px);padding:18px;background:var(--surface,#fff);margin-bottom:14px;min-width:0;box-shadow:var(--sh-s)}
.qq-card h3{margin:0 0 4px;font-size:15px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.qq-card>p,.qq-help{margin:2px 0 12px;color:var(--ink-soft,#626c80);font-size:13px;line-height:1.5}
.qq-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px 14px}
.qq-f{display:flex;flex-direction:column;gap:5px;min-width:0}
.qq-f label{font-size:12.5px;font-weight:650;color:var(--ink-2,#3c465c)}
.qq-f input,.qq-f select,.qq-f textarea{font:inherit;font-size:14px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);padding:9px 11px;min-height:42px;min-width:0;background:var(--surface,#fff);color:var(--ink,#101729);transition:border-color .15s,box-shadow .15s}
.qq-f input:focus,.qq-f select:focus,.qq-f textarea:focus{outline:none;border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px var(--accent-soft,#e7f7ee)}
.qq-f input[aria-invalid=true]{border-color:var(--red,#e3493f);box-shadow:0 0 0 3px var(--red-soft,#fdeceb)}
.qq-f small{color:var(--ink-soft,#626c80);font-size:12px;line-height:1.4}
.qq-f .qq-err{color:#a8261d;font-weight:600}
.qq-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.qq-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);border-radius:999px;padding:9px 16px;font:inherit;font-size:13.5px;font-weight:650;cursor:pointer;min-height:40px;color:var(--ink,#101729);transition:transform .08s,background .15s}
.qq-btn:active{transform:scale(.98)}
.qq-btn:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.qq-btn.pri{background:var(--ink,#101729);color:#fff;border-color:transparent}
.qq-btn.go{background:var(--accent,#15a85a);color:#fff;border-color:transparent}
.qq-btn.danger{background:var(--red,#e3493f);color:#fff;border-color:transparent}
.qq-btn[disabled]{opacity:.5;cursor:not-allowed;transform:none}
.qq-spin{width:14px;height:14px;border-radius:50%;border:2px solid currentColor;border-right-color:transparent;animation:qqspin .7s linear infinite;flex:none}
@keyframes qqspin{to{transform:rotate(360deg)}}
.qq-copy{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.qq-copy code{flex:1 1 260px;min-width:0;overflow-wrap:anywhere;background:var(--surface-2,#f2f4fb);border:1px dashed var(--border,#e6e9f2);border-radius:var(--r-xs,9px);padding:10px 12px;font-family:var(--mono);font-size:12.5px}
.qq-envs{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
.qq-env.sandbox{border-top:3px solid var(--amber,#e0922f)}
.qq-env.live{border-top:3px solid var(--accent,#15a85a)}
.qq-meta{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;color:var(--ink-soft,#626c80);margin-top:10px}
.qq-meta b{color:var(--ink,#101729);font-weight:650}
.qq-chk{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;cursor:pointer;line-height:1.45}
.qq-chk input{width:18px;height:18px;margin:1px 0 0;accent-color:var(--accent,#15a85a);flex:none}
.qq-svc{display:flex;flex-wrap:wrap;gap:8px}
.qq-svc label{display:flex;gap:8px;align-items:center;border:1px solid var(--border,#e6e9f2);border-radius:999px;padding:8px 14px;cursor:pointer;font-size:13.5px;font-weight:600;min-height:40px}
.qq-svc label:has(input:checked){border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.qq-svc input{accent-color:var(--accent,#15a85a)}
.qq-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.qq-tag{display:inline-flex;align-items:center;gap:6px;background:var(--surface-2,#f2f4fb);border:1px solid var(--border,#e6e9f2);border-radius:999px;padding:4px 6px 4px 11px;font-size:12.5px;max-width:100%}
.qq-tag span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.qq-tag button{border:0;background:none;cursor:pointer;width:24px;height:24px;border-radius:50%;color:var(--ink-soft,#626c80);font-size:15px;line-height:1}
.qq-tag button:hover{background:var(--surface-3,#eef1f9)}
.qq-st{display:grid;gap:8px}
.qq-st-row{display:grid;grid-template-columns:minmax(150px,1.1fr) minmax(130px,.9fr) minmax(0,1.2fr) minmax(0,1.2fr) auto;gap:8px;align-items:center;padding:8px 10px;border:1px solid var(--border-2,#eef0f6);border-radius:var(--r-sm,12px)}
.qq-st-row.head{border:0;padding:0 10px;font-size:11.5px;font-weight:700;color:var(--ink-faint,#97a0b2);text-transform:uppercase;letter-spacing:.05em}
.qq-st-row.mapped{background:var(--accent-soft,#e7f7ee);border-color:transparent}
.qq-st-row b{font-size:13.5px}
.qq-st-row small{display:block;color:var(--ink-faint,#97a0b2);font-family:var(--mono);font-size:11px}
.qq-st-row input[type=text],.qq-st-row select{width:100%;min-width:0;font:inherit;font-size:13px;border:1px solid var(--border,#e6e9f2);border-radius:8px;padding:7px 9px;min-height:36px;background:var(--surface,#fff);color:var(--ink,#101729)}
.qq-st-row .qq-flags{display:flex;gap:10px;font-size:12px;white-space:nowrap}
.qq-st-row .qq-flags label{display:flex;gap:4px;align-items:center;cursor:pointer}
.qq-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px}
.qq-kpi{border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);padding:14px;background:var(--surface,#fff)}
.qq-kpi b{display:block;font-size:24px;letter-spacing:-.02em}
.qq-kpi span{font-size:12.5px;color:var(--ink-soft,#626c80)}
.qq-list{display:grid;gap:8px}
.qq-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:4px 12px;align-items:center;border:1px solid var(--border-2,#eef0f6);border-radius:var(--r-sm,12px);padding:10px 12px;font-size:13px}
.qq-item .qq-sub{grid-column:2/-1;color:var(--ink-soft,#626c80);overflow-wrap:anywhere}
.qq-empty{text-align:center;padding:28px 14px;color:var(--ink-soft,#626c80);font-size:13.5px}
.qq-empty svg{display:block;margin:0 auto 10px;color:var(--accent,#15a85a)}
.qq-sk{background:linear-gradient(90deg,var(--surface-2,#f2f4fb) 25%,var(--surface-3,#eef1f9) 50%,var(--surface-2,#f2f4fb) 75%);background-size:200% 100%;animation:qqsk 1.2s ease-in-out infinite;border-radius:10px}
@keyframes qqsk{to{background-position:-200% 0}}
@media (prefers-reduced-motion:reduce){.qq-sk,.qq-spin{animation:none}}
.qq-opts{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
.qq-opt{display:grid;grid-template-columns:20px minmax(0,1fr);gap:4px 10px;align-items:start;text-align:start;border:1px solid var(--border,#e6e9f2);background:var(--surface,#fff);border-radius:var(--r-sm,12px);padding:12px 14px;font:inherit;color:var(--ink,#101729);cursor:pointer;min-height:44px;transition:border-color .15s,background .15s}
.qq-opt::before{content:"";width:18px;height:18px;border-radius:50%;border:2px solid var(--border,#c9cfdd);margin-top:1px;box-sizing:border-box}
.qq-opt b{font-size:13.5px;font-weight:700}
.qq-opt small{grid-column:2;color:var(--ink-soft,#626c80);font-size:12.5px;line-height:1.45}
.qq-opt[aria-checked=true]{border-color:var(--amber,#e0922f);background:var(--amber-soft,#fdf2e2)}
.qq-opt[aria-checked=true]::before{border:5px solid #9a5b0e}
.qq-opt[data-qq-books=live][aria-checked=true]{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.qq-opt[data-qq-books=live][aria-checked=true]::before{border-color:var(--accent-ink,#0b6e3a)}
.qq-opt:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.qq-opt[disabled]{cursor:not-allowed;background:var(--surface-2,#f2f4fb);color:var(--ink-soft,#626c80)}
.qq-opt[disabled]::before{background:var(--surface-3,#eef1f9)}
.qq-opt .qq-why{color:#9a5b0e;font-weight:600}
.qq-log{display:grid;gap:6px;font-size:12.5px;color:var(--ink-soft,#626c80);margin-top:10px}
/* Toasts and dialog: shared with partials/quiqup-orders. */
.qq-toasts{position:fixed;inset-block-start:16px;inset-inline-end:16px;display:grid;gap:8px;z-index:10050;width:min(420px,calc(100vw - 32px));pointer-events:none}
.qq-toast{pointer-events:auto;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:start;background:var(--surface,#fff);color:var(--ink,#101729);border:1px solid var(--border,#e6e9f2);border-inline-start:4px solid var(--accent,#15a85a);border-radius:var(--r-sm,12px);padding:12px 12px 12px 14px;box-shadow:var(--sh-l);font-size:13.5px;line-height:1.45;animation:qqin .22s var(--ease)}
.qq-toast.bad{border-inline-start-color:var(--red,#e3493f)}
.qq-toast.warn{border-inline-start-color:var(--amber,#e0922f)}
.qq-toast b{display:block;margin-bottom:2px}
.qq-toast .qq-tx{overflow-wrap:anywhere}
.qq-toast button{border:0;background:none;cursor:pointer;color:var(--ink-soft,#626c80);font-size:16px;width:28px;height:28px;border-radius:50%}
@keyframes qqin{from{opacity:0;transform:translateY(-8px)}}
.qq-dlg{position:fixed;inset:0;margin:auto;height:fit-content;border:0;border-radius:var(--r,18px);padding:0;width:min(560px,calc(100vw - 24px));max-height:calc(100vh - 24px);box-shadow:var(--sh-l);background:var(--surface,#fff);color:var(--ink,#101729)}
.qq-dlg::backdrop{background:rgba(16,23,41,.45)}
.qq-dlg-b{padding:20px 22px;overflow:auto;max-height:calc(100vh - 110px)}
.qq-dlg h3{margin:0 0 6px;font-size:18px}
.qq-dlg p{margin:6px 0;color:var(--ink-2,#3c465c);font-size:13.5px;line-height:1.55}
.qq-dlg ul{margin:8px 0;padding-inline-start:18px;font-size:13.5px;line-height:1.6;color:var(--ink-2,#3c465c)}
.qq-dlg-f{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb)}
.qq-cl{list-style:none;padding:0;margin:10px 0;display:grid;gap:8px}
.qq-cl li{display:grid;grid-template-columns:24px minmax(0,1fr);gap:8px;align-items:start;font-size:13.5px}
.qq-cl i{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-style:normal;font-size:12px;font-weight:800;color:#fff;background:var(--ink-faint,#97a0b2)}
.qq-cl li.ok i{background:var(--accent,#15a85a)}
.qq-cl small{display:block;color:var(--ink-soft,#626c80)}
@media (max-width:760px){
  .qq-hero{grid-template-columns:minmax(0,1fr)}
  .qq-seg{width:100%;grid-template-columns:repeat(3,1fr)}
  .qq-seg button{padding:9px 6px}
  .qq-st-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
  .qq-st-row.head{display:none}
  .qq-st-row>div:first-child{grid-column:1/-1}
  .qq-st-row .qq-flags{grid-column:1/-1}
}
</style>
<div class="qq-toasts" id="qqToasts" role="status" aria-live="polite"></div>
<script>
(function () {
  'use strict';
  var SCREEN = 'quiqup';
  var BASE = window.location.pathname.replace(/\/+$/, '');

  /* ── shared helpers (window.kbbQQ) ─────────────────────────────────── */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function url(p) { return BASE.replace(/\/[^\/]*$/, '') + '/admin-api/' + p; }
  async function call(path, body) {
    var opts = { headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) { opts.method = 'POST'; opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r, j = null;
    try { r = await fetch(url(path), opts); } catch (e) { return { status: 0, body: {} }; }
    try { j = await r.json(); } catch (e) { j = null; }
    return { status: r.status, body: j || {} };
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fail(status, body, what) {
    if (status === 404) return 'The Quiqup endpoints are not in the server\'s route table yet. Platform → Cache → Clear everything, then try again.';
    if (status === 403) return 'Your role cannot ' + (what || 'do this') + '.';
    if (status === 419) return 'Your session expired. Sign in again in another tab, then retry.';
    if (status === 0) return 'The shop did not answer. Check your connection and try again.';
    return (body && body.message) || 'Something went wrong (HTTP ' + status + '). Nothing was changed.';
  }
  /* A toast: title + exact text. Errors stay until closed; successes leave
     after 5 s. One setTimeout per toast, cleared on close: no timer outlives it. */
  function toast(kind, title, text) {
    var box = document.getElementById('qqToasts');
    if (!box) return;
    var t = document.createElement('div');
    t.className = 'qq-toast' + (kind === 'ok' ? '' : ' ' + kind);
    t.setAttribute('role', kind === 'bad' ? 'alert' : 'status');
    t.innerHTML = '<span aria-hidden="true">' + (kind === 'ok' ? '✓' : kind === 'warn' ? '!' : '✕') + '</span>' +
      '<div class="qq-tx"><b>' + esc(title) + '</b>' + (text ? esc(text) : '') + '</div><button type="button" aria-label="Dismiss">×</button>';
    var timer = null;
    var close = function () { if (timer) clearTimeout(timer); if (t.parentNode) t.parentNode.removeChild(t); };
    t.querySelector('button').onclick = close;
    box.appendChild(t);
    while (box.children.length > 4) box.removeChild(box.firstChild);
    if (kind === 'ok') timer = setTimeout(close, 5000);
  }
  /* A native <dialog>: focus is trapped and Esc closes, by the browser. */
  function dialog(html, buttons) {
    return new Promise(function (resolve) {
      var d = document.createElement('dialog');
      d.className = 'qq-dlg';
      d.innerHTML = '<div class="qq-dlg-b">' + html + '</div><div class="qq-dlg-f">' + buttons.map(function (b, i) {
        return '<button type="button" class="qq-btn ' + (b.cls || '') + '" data-i="' + i + '"' + (b.disabled ? ' disabled' : '') + '>' + esc(b.label) + '</button>';
      }).join('') + '</div>';
      document.body.appendChild(d);
      var done = function (v) { try { d.close(); } catch (e) {} if (d.parentNode) d.parentNode.removeChild(d); resolve(v); };
      d.addEventListener('cancel', function (e) { e.preventDefault(); done(null); });
      d.querySelectorAll('[data-i]').forEach(function (b) { b.onclick = function () { done(buttons[+b.getAttribute('data-i')].value); }; });
      if (d.showModal) d.showModal(); else d.setAttribute('open', '');
      var first = d.querySelector('.qq-dlg-f .qq-btn:not([disabled])');
      if (first) first.focus();
    });
  }
  window.kbbQQ = { call: call, esc: esc, fail: fail, toast: toast, dialog: dialog };

  /* ── Store → Quiqup ─────────────────────────────────────────────────── */
  var S;
  function reset() { S = { data: null, tab: 'conn', busy: '', act: null, test: {}, err: {} }; }
  reset();

  function host() { return document.querySelector('[data-qq-screen]'); }
  function ours() { var h = host(), c = document.getElementById('content'); return !!(h && c && c.contains(h)); }
  function when(iso) {
    if (!iso) return 'never';
    try { return new Date(iso).toLocaleString('en-GB', { timeZone: 'Asia/Dubai', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { return iso; }
  }
  var MODE_TEXT = {
    off: 'Off. Nothing is booked with Quiqup; bookings already made keep updating.',
    testing: 'Internal testing · Quiqup test system. Runs on this website against Quiqup\'s STAGING system with your staging key: no courier comes and nothing is billed. Only your test orders can be booked, and only they will show delivery steps. Every other shopper sees the shop exactly as before.',
    testing_live: 'Internal testing · real courier for your test orders. Only your test orders can be booked; they go to Quiqup production with your live key and a real courier collects them (Quiqup bills a delivery once it is picked up). Every other shopper sees the shop exactly as before.',
    live: 'Live. Every order can be booked with Quiqup\'s production system, and customers see their delivery steps.'
  };

  /* The mode's own words, with where Internal testing books. */
  function modeKey(d) { return d.mode === 'testing' && d.testing_env === 'live' ? 'testing_live' : d.mode; }
  function modeChip(d) { return d.mode === 'testing' && d.testing_env === 'live' ? 'Internal testing · real courier' : d.mode_labels[d.mode]; }
  var BOOKS_SHORT = { sandbox: 'Quiqup test system', live: 'real courier' };

  function spin(on, label) { return on ? '<span class="qq-spin" aria-hidden="true"></span>' + label : label; }

  function hero() {
    var d = S.data, m = d ? d.mode : 'off';
    var h = '<section class="qq-hero" aria-label="Quiqup mode"><div><h2>Quiqup deliveries ' +
      (d ? '<span class="qq-chip ' + m + '">' + esc(modeChip(d)) + '</span>' : '') + '</h2>' +
      '<p>' + (d ? esc(MODE_TEXT[modeKey(d)]) : 'Loading…') + '</p></div>';
    if (d) {
      h += '<div class="qq-seg" role="radiogroup" aria-label="Mode">' + ['off', 'testing', 'live'].map(function (k) {
        return '<button type="button" role="radio" data-m="' + k + '" aria-checked="' + (m === k) + '" data-qq-mode="' + k + '"' + (S.busy ? ' disabled' : '') + '>' +
          (S.busy === 'mode:' + k ? spin(true, '') : '') + esc(d.mode_labels[k]) + '</button>';
      }).join('') + '</div>';
    }
    h += '</section>' + booksOn();
    if (d && !d.envs.sandbox.api_key.filled && !(d.mode === 'testing' && d.testing_env === 'live')) {
      h += '<div class="qq-banner warn" role="note"><span aria-hidden="true">⚑</span><div><b>Add your staging API key</b> to start. Quiqdash (staging) → Organization → API keys → expiry <b>Never</b>, then paste it in the Sandbox card below. Nothing is sent to Quiqup until then.</div></div>';
    }
    return h;
  }

  /* "Internal testing books on": shown only while Internal testing is on.
     Live is disabled, with the reason, until the live key is verified, the
     live webhook secret saved and the pickup address set. */
  function booksOn() {
    var d = S.data;
    if (!d || d.mode !== 'testing') return '';
    var cur = d.testing_env === 'live' ? 'live' : 'sandbox', miss = d.testing_live_missing || [], L = d.testing_env_labels || {};
    var opt = function (env, text, off) {
      return '<button type="button" class="qq-opt" role="radio" data-qq-books="' + env + '" aria-checked="' + (cur === env) + '"' + (off || S.busy ? ' disabled' : '') + '>' +
        '<b>' + (S.busy === 'books:' + env ? spin(true, '') : '') + esc(L[env]) + '</b><small>' + text + '</small></button>';
    };
    var why = miss.length ? '<span class="qq-why">Not available yet: ' + esc(miss.join(', ')) + '.</span>' : '';
    return '<section class="qq-card qq-books" aria-labelledby="qq_books_h"><h3 id="qq_books_h">Internal testing books on</h3>' +
      '<p class="qq-help">Only your test orders are booked in Internal testing; every other order is still refused.</p>' +
      '<div class="qq-opts" role="radiogroup" aria-labelledby="qq_books_h">' +
      opt('sandbox', 'Instant checks: no courier, no charges. Move each order through its steps in staging Quiqdash.', false) +
      opt('live', (cur === 'live' && why ? why + ' ' : '') + 'A real Quiqup courier collects your test orders. Quiqup bills a delivery once it is picked up.' + (cur !== 'live' && why ? ' ' + why : ''), cur !== 'live' && miss.length > 0) +
      '</div></section>';
  }

  function tabs() {
    return '<div class="qq-tabs" role="tablist">' + [['guide', 'Setup guide'], ['conn', 'Connection'], ['pick', 'Pickup & defaults'], ['test', 'Test customers'], ['stat', 'Statuses'], ['act', 'Activity']].map(function (t) {
      return '<button type="button" role="tab" id="qqtab-' + t[0] + '" aria-selected="' + (S.tab === t[0]) + '" tabindex="' + (S.tab === t[0] ? 0 : -1) + '" data-qq-tab="' + t[0] + '">' + t[1] + '</button>';
    }).join('') + '</div>';
  }

  function secretField(env, key, label, help) {
    var m = S.data.envs[env][key] || {};
    return '<div class="qq-f"><label for="qq_' + env + '_' + key + '">' + label + ' ' + (m.filled ? '<span class="qq-chip live">Saved ••••' + esc(m.last4) + '</span>' : '<span class="qq-chip off">Not set</span>') + '</label>' +
      '<input type="password" autocomplete="off" spellcheck="false" id="qq_' + env + '_' + key + '" placeholder="' + (m.filled ? 'Type to replace' : 'Paste it here') + '">' +
      '<small>' + help + '</small></div>';
  }

  /* Setup guide (owner, 14 Nov 2026: "you didn't mention from where to get api, on which url etc. i need a
     complete guide ... make this guide available in the quiqup tab too"). Every address and menu name is
     a constant from developer-docs.quiqup.com (Authentication, Environments, Webhooks, Testing on staging,
     read 14 Nov 2026; saved in docs/plans/quiqup/api-docs). Only the ticks and the two webhook addresses
     come from the shop's own data, and those go through esc(). */
  function guideTab() {
    var d = S.data, sb = d.envs.sandbox, lv = d.envs.live, p = (d.options || {}).pickup || {};
    var done = function (ok, yes, no) { return ok ? '<span class="qq-chip live">' + yes + '</span>' : '<span class="qq-chip off">' + no + '</span>'; };
    var step = function (n, ok, title, chip, body) {
      return '<section class="qq-card"><div class="qq-step"><span class="qq-n' + (ok ? ' ok' : '') + '" aria-hidden="true">' + (ok ? '✓' : n) + '</span><div style="min-width:0;flex:1">' +
        '<h3>' + title + ' ' + chip + '</h3>' + body + '</div></div></section>';
    };
    var hook = function (e) {
      return e.webhook_url ? '<div class="qq-copy"><code>' + esc(e.webhook_url) + '</code><button type="button" class="qq-btn" data-qq-copy="' + esc(e.webhook_url) + '">Copy</button></div>'
        : '<p class="qq-help">Your address appears here after you save anything once on the Connection tab.</p>';
    };
    var STG = 'https://quiqdash-beta.staging.quiqup.com', PRD = 'https://business-ae-beta.quiqup.com';
    var a = function (u, t) { return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + t + '</a>'; };
    var keyOk = !!(sb.api_key && sb.api_key.filled), verOk = !!sb.verified_at, secOk = !!(sb.webhook_secret && sb.webhook_secret.filled),
        hookOk = !!sb.last_webhook_at, pickOk = !!(p.address1 && p.contact_phone), liveOk = !!(lv.api_key && lv.api_key.filled && lv.verified_at && lv.webhook_secret && lv.webhook_secret.filled);
    return '<div class="qq-guide">' +
      '<section class="qq-card"><h3>How the shop connects to Quiqup</h3><p class="qq-help">The shop talks to Quiqup\'s official API with an <b>API key</b> you create in Quiqup\'s dashboard (Quiqdash). Your Quiqdash email and password are never used by the shop and never typed here. Quiqup has two separate systems:</p>' +
        '<ol><li><b>Staging (test system)</b>: dashboard ' + a(STG, 'quiqdash-beta.staging.quiqup.com') + ', API <code>api.staging.quiqup.com</code>. No courier comes, nothing is billed. Build and test here first.</li>' +
        '<li><b>Production (live)</b>: dashboard ' + a(PRD, 'business-ae-beta.quiqup.com') + ', API <code>api-ae.quiqup.com</code>. Real couriers, billed once a parcel is picked up.</li></ol>' +
        '<p class="qq-help">The same Quiqdash login works on both dashboards, but keys, orders and webhooks are separate: a staging key only works on staging, a live key only on live.</p></section>' +
      step(1, false, 'Ask Quiqup to switch on your account', '<span class="qq-chip info">Once</span>',
        '<ol><li>Email your Quiqup account manager (commercial) and ask for <b>staging access for Last-mile</b> on your account, and which services you have: Same Day, Next Day, 4-hour.</li>' +
        '<li>Technical questions about the API or webhooks: ' + a('mailto:apisupport@quiqup.com', 'apisupport@quiqup.com') + '.</li>' +
        '<li>For going live later they need: commercial terms agreed, trade licence, bank card and finance details, and the services enabled on the <b>production</b> account.</li></ol>') +
      step(2, keyOk && verOk, 'Create your staging API key', done(keyOk && verOk, 'Saved and verified', keyOk ? 'Saved, press Test connection' : 'Not yet'),
        '<ol><li>Open ' + a(STG, 'quiqdash-beta.staging.quiqup.com') + ' and sign in with your Quiqup login.</li>' +
        '<li>Click your account name at the bottom of the left menu. On a new account it reads <b>Create Team</b>: press it (keys live inside a team, even if you work alone).</li>' +
        '<li>Open the same menu again and choose <b>Manage</b>, then the <b>API keys</b> tab, then <b>Add new key</b>.</li>' +
        '<li>Name it <code>kbeautybliss staging</code>. Set <b>Expiration</b> to <b>Never</b>.</li>' +
        '<li>Copy the key, open the <b>Connection</b> tab here, paste it into <b>Sandbox · staging → API key</b>, press <b>Save</b>, then <b>Test connection</b>.</li></ol>' +
        '<div class="qq-warn"><b>Choose Never.</b> A key made with 1, 7, 30 or 60 days stops working on that day and every booking fails. That is the most common Quiqup setup mistake.</div>') +
      step(3, secOk, 'Connect live status updates (webhook) on staging', done(secOk, hookOk ? 'Saved · updates arriving' : 'Secret saved', 'Not yet'),
        '<p class="qq-help">This is how Quiqup tells your website the moment a status changes (out for delivery, failed attempt, delivered…).</p><ol>' +
        '<li>Copy your staging webhook address:</li></ol>' + hook(sb) + '<ol start="2">' +
        '<li>In ' + a(STG, 'staging Quiqdash') + ' open <b>Integrations → Webhooks</b>, tab <b>Subscriptions</b>, press <b>New subscription</b>.</li>' +
        '<li><b>Target URL</b>: paste the address. <b>Events</b>: Quick select → <b>All order events</b> (19 events). Leave Custom headers empty. <b>Active</b>: on. Save.</li>' +
        '<li>On the new subscription press the <b>eye icon</b> to show its signing secret, copy it, and paste it into <b>Connection → Sandbox · staging → Webhook signing secret</b>, then <b>Save</b>.</li>' +
        '<li>Press <b>Send test</b> on the subscription in Quiqdash: “Last webhook” on the Connection tab should change.</li></ol>') +
      step(4, pickOk, 'Pickup address and services', done(pickOk, 'Set', 'Not yet'),
        '<ol><li>Open the <b>Pickup & defaults</b> tab: the address the courier collects from, contact name and phone (with +971).</li><li>Tick only the services your account manager confirmed, and set your cut-off times.</li></ol>') +
      step(5, d.mode !== 'off', 'Test on your own website', done(d.mode !== 'off', d.mode === 'live' ? 'Live' : 'Internal testing', 'Mode is Off'),
        '<ol><li><b>Test customers</b> tab: add your own email or phone.</li><li>Switch the mode at the top to <b>Internal testing</b>. Only your test orders can be booked; shoppers see nothing new.</li>' +
        '<li>Place an order on the website yourself. In <b>Store → Orders</b> tick it, choose <b>Schedule with Quiqup…</b>, press Schedule.</li>' +
        '<li>Move it through its steps in ' + a(STG, 'staging Quiqdash') + ': <b>Orders</b> → select it → <b>Admin Actions → Bulk Change State</b> (e.g. collected → out for delivery → delivered, and once to delivery failed). Watch its Delivery card on the order update each time.</li>' +
        '<li>Try a cancel, and print a label from the order or the Orders bar.</li></ol>') +
      step(6, liveOk, 'Live key and live webhook', done(liveOk, 'Ready', 'Not yet'),
        '<ol><li>When Quiqup has activated production: open ' + a(PRD, 'business-ae-beta.quiqup.com') + ' and repeat step 2 there (key named <code>kbeautybliss live</code>, expiry <b>Never</b>). Paste it into <b>Connection → Live · production → API key</b>, Save, Test connection.</li>' +
        '<li>Repeat step 3 in the <b>production</b> dashboard with your live webhook address (staging subscriptions do not carry over):</li></ol>' + hook(lv) +
        '<ol start="3"><li>Optional: <b>Internal testing books on → Live (real courier)</b> sends only your test orders with a real courier, billed once picked up.</li></ol>') +
      step(7, d.mode === 'live', 'Go Live', done(d.mode === 'live', 'Live', 'Not yet'),
        '<ol><li>Press <b>Live</b> at the top. A checklist must show four ticks: live key verified, live webhook secret saved, pickup set, one test order delivered.</li>' +
        '<li>Confirm. From then every order can be scheduled and customers see their delivery steps. You can go back to Internal testing or Off with one click.</li></ol>') +
      '</div>';
  }

  function connTab() {
    var d = S.data;
    return '<div class="qq-envs">' + ['sandbox', 'live'].map(function (env) {
      var e = d.envs[env], t = S.test[env], used = env === d.env;
      return '<section class="qq-card qq-env ' + env + '" aria-labelledby="qq_h_' + env + '"><h3 id="qq_h_' + env + '">' + (env === 'live' ? 'Live · production' : 'Sandbox · staging') +
        ' <span class="qq-chip ' + (env === 'live' ? 'live' : 'testing') + '">' + esc(e.host) + '</span>' + (used && d.mode !== 'off' ? ' <span class="qq-chip info">In use</span>' : '') + '</h3>' +
        '<p class="qq-help">' + (env === 'live' ? 'Used in Live mode, and for your test orders when Internal testing books with a real courier. Every booking is a real, billed courier.' : 'Used in Internal testing. Bookings go to Quiqup\'s staging system: no real courier comes.') + '</p>' +
        '<div class="qq-grid">' +
          secretField(env, 'api_key', 'API key', 'Quiqdash → Organization → API keys, expiry <b>Never</b>.') +
          secretField(env, 'webhook_secret', 'Webhook signing secret', 'Quiqdash → Integrations → Webhooks → your subscription → eye icon.') +
        '</div>' +
        '<div class="qq-f" style="margin-top:12px"><label>Webhook address for this environment\'s Quiqdash</label><div class="qq-copy"><code>' + (e.webhook_url ? esc(e.webhook_url) : 'Save once to create it') + '</code>' +
          (e.webhook_url ? '<button type="button" class="qq-btn" data-qq-copy="' + esc(e.webhook_url) + '">Copy</button>' : '') +
          '<button type="button" class="qq-btn" data-qq-regen="' + env + '"' + (S.busy ? ' disabled' : '') + '>New address</button></div>' +
          '<small>New subscription → Quick select <b>All order events</b> → Active. Staging and production keep separate subscriptions.</small></div>' +
        '<div class="qq-meta"><span>Key verified: <b>' + esc(e.verified_at ? when(e.verified_at) : 'not yet') + '</b></span><span>Last webhook: <b>' + esc(when(e.last_webhook_at)) + '</b>' + (e.last_webhook_state ? ' · ' + esc(e.last_webhook_state) : '') + '</span></div>' +
        '<div class="qq-row"><button type="button" class="qq-btn pri" data-qq-savekeys="' + env + '"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'keys:' + env, 'Save') + '</button>' +
          '<button type="button" class="qq-btn" data-qq-test="' + env + '"' + (S.busy || !e.api_key.filled ? ' disabled' : '') + '>' + spin(S.busy === 'test:' + env, 'Test connection') + '</button>' +
          (t ? '<span class="qq-chip ' + (t.ok ? 'live' : 'bad') + '">' + esc(t.text) + '</span>' : '') + '</div></section>';
    }).join('') + '</div>';
  }

  function txt(id, label, val, max, help, type) {
    var err = S.err[id];
    return '<div class="qq-f"><label for="' + id + '">' + label + '</label><input type="' + (type || 'text') + '" id="' + id + '" maxlength="' + max + '" value="' + esc(val) + '"' + (err ? ' aria-invalid="true" aria-describedby="' + id + '_e"' : '') + '>' +
      (err ? '<small class="qq-err" id="' + id + '_e">' + esc(err) + '</small>' : help ? '<small>' + help + '</small>' : '') + '</div>';
  }
  function chk(id, label, on) { return '<label class="qq-chk"><input type="checkbox" id="' + id + '"' + (on ? ' checked' : '') + '><span>' + label + '</span></label>'; }

  function pickTab() {
    var o = S.data.options, p = o.pickup, sv = S.data.services, methods = S.data.shipping_methods || [];
    var h = '<section class="qq-card"><h3>Pickup</h3><p>Where the courier collects, and who they call.</p><div class="qq-grid">' +
      txt('qq_p_name', 'Contact name', p.contact_name, 100) + txt('qq_p_phone', 'Contact phone', p.contact_phone, 40, 'With the country code, e.g. +971 50 123 4567.', 'tel') +
      txt('qq_p_a1', 'Address line 1', p.address1, 200, 'Building and street.') + txt('qq_p_a2', 'Address line 2', p.address2, 200, 'Area or unit.') +
      txt('qq_p_town', 'Town', p.town, 100) +
      '<div class="qq-f"><label for="qq_p_em">Emirate</label><select id="qq_p_em">' + S.data.emirates.map(function (x) { return '<option' + (p.emirate === x ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select></div>' +
      txt('qq_p_notes', 'Notes for the courier', p.notes, 250) + '</div></section>';
    h += '<section class="qq-card"><h3>Services</h3><p>Tick only the services your account manager confirmed. Quiqup checks Same Day when booking; the others are accepted even when off.</p><div class="qq-svc">' +
      Object.keys(sv).map(function (k) { return '<label><input type="checkbox" id="qq_sv_' + k + '"' + (o.services.enabled.indexOf(k) >= 0 ? ' checked' : '') + '>' + esc(sv[k]) + '</label>'; }).join('') + '</div>' +
      '<div class="qq-grid" style="margin-top:14px"><div class="qq-f"><label for="qq_sv_def">Default service</label><select id="qq_sv_def">' +
      Object.keys(sv).map(function (k) { return '<option value="' + k + '"' + (o.services.default === k ? ' selected' : '') + '>' + esc(sv[k]) + '</option>'; }).join('') + '</select></div>' +
      '<div class="qq-f"><label for="qq_parcels">Parcels per order</label><input type="number" min="1" max="10" id="qq_parcels" value="' + esc(o.parcels) + '"></div>' +
      '<div class="qq-f"><label for="qq_4h">4-hour: earliest collection</label><input type="time" min="08:00" max="18:59" id="qq_4h" value="' + esc(o.four_hour_time) + '"><small>Dubai time, 08:00 to 18:59.</small></div></div>' +
      (methods.length ? '<h3 style="margin-top:18px">Service per delivery method</h3><p>The delivery methods customers chose in the last 90 days.</p><div class="qq-grid">' + methods.map(function (m, i) {
        var cur = o.services.by_method[m] || '';
        return '<div class="qq-f"><label for="qq_bm_' + i + '">' + esc(m) + '</label><select id="qq_bm_' + i + '" data-qq-method="' + esc(m) + '"><option value="">Default</option>' +
          Object.keys(sv).map(function (k) { return '<option value="' + k + '"' + (cur === k ? ' selected' : '') + '>' + esc(sv[k]) + '</option>'; }).join('') + '</select></div>';
      }).join('') + '</div>' : '') +
      '<h3 style="margin-top:18px">Cut-off times</h3><p>Only for a warning on the schedule sheet: Quiqup accepts a late order and collects it next cycle.</p><div class="qq-grid">' +
      Object.keys(sv).map(function (k) { return '<div class="qq-f"><label for="qq_co_' + k + '">' + esc(sv[k]) + '</label><input type="time" id="qq_co_' + k + '" value="' + esc(o.cutoffs[k] || '') + '"></div>'; }).join('') + '</div></section>';
    h += '<section class="qq-card"><h3>When booking</h3><div style="display:grid;gap:12px">' +
      chk('qq_release', 'Release to the courier as soon as it is booked', o.release) +
      chk('qq_products', 'Send the product list (SKU, short name, quantity) so Quiqup support can see what is in the box', o.send_products) +
      chk('qq_uae', 'UAE addresses only — Quiqup export needs HS codes, weights and sizes the shop does not hold', o.uae_only) +
      '<div class="qq-f" style="max-width:380px"><label for="qq_np">Note for the courier</label><select id="qq_np"><option value="customer"' + (o.note_policy === 'customer' ? ' selected' : '') + '>The customer\'s order note</option><option value="none"' + (o.note_policy === 'none' ? ' selected' : '') + '>None</option></select><small>Never the gift message: that one is private.</small></div>' +
      '</div><div class="qq-row"><button type="button" class="qq-btn pri" data-qq-save="pick"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'save:pick', 'Save pickup & defaults') + '</button></div></section>';
    return h;
  }

  function tagList(kind, list) {
    return '<div class="qq-tags">' + (list.length ? list.map(function (v, i) {
      return '<span class="qq-tag"><span>' + esc(v) + '</span><button type="button" aria-label="Remove ' + esc(v) + '" data-qq-untag="' + kind + ':' + i + '">×</button></span>';
    }).join('') : '<small style="color:var(--ink-faint,#97a0b2)">None yet.</small>') + '</div>';
  }
  function testTab() {
    var t = S.data.options.test;
    return '<section class="qq-card"><h3>Who is a test customer <span class="qq-chip testing">Internal testing</span></h3>' +
      '<p>In Internal testing only these orders can be booked, and only they will show delivery steps on the track page and in My account. Place an order on the website with one of these, then book it from Store → Orders.</p>' +
      '<div class="qq-grid"><div class="qq-f"><label for="qq_t_email">Emails</label><div class="qq-copy"><input type="email" id="qq_t_email" placeholder="you@example.com" style="flex:1 1 200px"><button type="button" class="qq-btn" data-qq-tag="emails">Add</button></div>' + tagList('emails', t.emails) + '</div>' +
      '<div class="qq-f"><label for="qq_t_phone">Phones</label><div class="qq-copy"><input type="tel" id="qq_t_phone" placeholder="+971 50 123 4567" style="flex:1 1 200px"><button type="button" class="qq-btn" data-qq-tag="phones">Add</button></div>' + tagList('phones', t.phones) + '</div></div>' +
      '<p class="qq-help" style="margin-top:12px">You can also flag any single order as a test order from its row on Store → Orders (' + esc((t.orders || []).length) + ' flagged).</p>' +
      '<div class="qq-row"><button type="button" class="qq-btn pri" data-qq-save="test"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'save:test', 'Save test customers') + '</button></div></section>';
  }

  function statTab() {
    var o = S.data.options.statuses, lab = S.data.state_labels;
    var name = { '': '— no change —', shipped: 'Shipped', completed: 'Completed', onhold: 'On hold', cancelled: 'Cancelled' };
    return '<section class="qq-card"><h3>What each Quiqup status does</h3><p>The order moves only forward (Processing → Shipped → Completed); a refunded or cancelled order is never touched. A move sends the same email as changing it by hand. Customer steps are for the delivery timeline.</p>' +
      '<div class="qq-st"><div class="qq-st-row head"><div>Quiqup status</div><div>Our order</div><div>Customer step · English</div><div>Arabic</div><div>Notify</div></div>' +
      S.data.states.map(function (st) {
        var r = o[st] || {};
        return '<div class="qq-st-row' + (r.status ? ' mapped' : '') + '"><div><b>' + esc(lab[st] || st) + '</b><small>' + esc(st) + '</small></div>' +
          '<div><select data-qq-st="' + st + ':status" aria-label="Order status when ' + esc(lab[st] || st) + '">' + S.data.targets.map(function (t) { return '<option value="' + t + '"' + (r.status === t ? ' selected' : '') + '>' + esc(name[t] || t) + '</option>'; }).join('') + '</select></div>' +
          '<div><input type="text" maxlength="60" data-qq-st="' + st + ':en" value="' + esc(r.en) + '" aria-label="English step for ' + esc(st) + '"></div>' +
          '<div><input type="text" maxlength="60" dir="rtl" lang="ar" data-qq-st="' + st + ':ar" value="' + esc(r.ar) + '" aria-label="Arabic step for ' + esc(st) + '"></div>' +
          '<div class="qq-flags"><label><input type="checkbox" data-qq-st="' + st + ':email"' + (r.email ? ' checked' : '') + '>Email</label><label><input type="checkbox" data-qq-st="' + st + ':push"' + (r.push ? ' checked' : '') + '>Push</label></div></div>';
      }).join('') + '</div><div class="qq-row"><button type="button" class="qq-btn pri" data-qq-save="stat"' + (S.busy ? ' disabled' : '') + '>' + spin(S.busy === 'save:stat', 'Save statuses') + '</button></div></section>';
  }

  function skeleton(n) {
    var h = '';
    for (var i = 0; i < n; i++) h += '<div class="qq-sk" style="height:' + (i ? 54 : 84) + 'px;margin-bottom:10px"></div>';
    return h;
  }

  function actTab() {
    var a = S.act;
    if (!a) return '<div class="qq-kpis">' + skeleton(1).repeat(3) + '</div>' + skeleton(3);
    if (a.error) return '<div class="qq-banner warn">' + esc(a.error) + '</div>';
    var h = '';
    if (a.webhook_stale) h += '<div class="qq-banner warn" role="note"><span aria-hidden="true">⚠</span><div><b>No webhook from Quiqup for over 6 hours while deliveries are moving.</b> Quiqup pauses a subscription after repeated failures and tells no one. Fix: Quiqdash → Integrations → Webhooks → Edit → Active → Save. Meanwhile the shop checks with Quiqup every few minutes.</div></div>';
    h += '<div class="qq-kpis"><div class="qq-kpi"><b>' + esc(a.inflight) + '</b><span>On the way</span></div><div class="qq-kpi"><b>' + esc(a.unapplied) + '</b><span>Updates waiting</span></div>' +
      '<div class="qq-kpi"><b>' + esc(when(a.last_webhook_at)) + '</b><span>Last webhook' + (a.last_webhook_state ? ' · ' + esc(a.last_webhook_state) : '') + '</span></div>' +
      '<div class="qq-kpi"><b>' + esc(when(a.last_poll_at)) + '</b><span>Last check with Quiqup</span></div></div>';
    if (a.last_poll_error) h += '<div class="qq-banner warn">' + esc(a.last_poll_error) + '</div>';
    h += '<section class="qq-card"><h3>Needs a look</h3>';
    if (!a.rows.length) {
      h += '<div class="qq-empty"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.5 2.5L16 9.5"/></svg>Nothing needs a look. Failed bookings, missed deliveries and returns appear here.</div>';
    } else {
      h += '<div class="qq-list">' + a.rows.map(function (r) {
        var detail = r.error || r.reason || (r.stuck_pending ? 'Still pending at Quiqup: open it in Quiqdash.' : '') || (r.state === 'returned_to_origin' ? 'Returned to you: decide what happens to the order.' : '');
        var bad = r.job === 'failed' || r.state === 'delivery_failed' || r.state === 'returned_to_origin';
        return '<div class="qq-item"><span class="qq-chip ' + (bad ? 'bad' : 'testing') + '">' + esc(r.state_label || r.job) + '</span><b>#' + esc(r.order_number) + (r.env === 'live' ? '' : ' <span class="qq-chip testing">STAGING</span>') + '</b>' +
          '<span>' + (r.tracking_url ? '<a href="' + esc(r.tracking_url) + '" target="_blank" rel="noopener noreferrer">Track ↗</a>' : (r.qq_id ? 'Quiqup ' + esc(r.qq_id) : '')) + '</span>' +
          (detail ? '<span class="qq-sub">' + esc(detail) + '</span>' : '') + '</div>';
      }).join('') + '</div>';
    }
    return h + '</section>';
  }

  function modeLog() {
    var log = (S.data && S.data.mode_log) || [];
    if (!log.length) return '';
    var L = S.data.mode_labels;
    return '<section class="qq-card"><h3>Mode history</h3><div class="qq-log">' + log.slice(0, 6).map(function (e) {
      if (e.kind === 'books_on') return '<div>' + esc(when(e.at)) + ' · <b>' + esc(e.by) + '</b> · Internal testing books on: ' + esc(BOOKS_SHORT[e.from] || e.from) + ' → ' + esc(BOOKS_SHORT[e.to] || e.to) + '</div>';
      return '<div>' + esc(when(e.at)) + ' · <b>' + esc(e.by) + '</b> · ' + esc(L[e.from] || e.from) + ' → ' + esc(L[e.to] || e.to) + '</div>';
    }).join('') + '</div></section>';
  }

  function render() {
    var h = host();
    if (!h) return;
    if (!S.data) { h.innerHTML = '<div class="qq-hero"><div style="width:100%">' + skeleton(1) + '</div></div>' + skeleton(4); return; }
    var body = S.tab === 'guide' ? guideTab() : S.tab === 'pick' ? pickTab() : S.tab === 'test' ? testTab() : S.tab === 'stat' ? statTab() : S.tab === 'act' ? actTab() : connTab() + modeLog();
    var focusId = document.activeElement && document.activeElement.id;
    h.innerHTML = hero() + tabs() + '<div role="tabpanel" aria-labelledby="qqtab-' + S.tab + '">' + body + '</div>';
    if (focusId) { var f = document.getElementById(focusId); if (f) f.focus(); }
  }

  function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
  function on(id) { var el = document.getElementById(id); return !!(el && el.checked); }

  function collect(tab) {
    if (tab === 'pick') {
      var bm = {};
      document.querySelectorAll('[data-qq-method]').forEach(function (s) { if (s.value) bm[s.getAttribute('data-qq-method')] = s.value; });
      var co = {}; Object.keys(S.data.services).forEach(function (k) { co[k] = val('qq_co_' + k); });
      return { options: {
        pickup: { contact_name: val('qq_p_name'), contact_phone: val('qq_p_phone'), address1: val('qq_p_a1'), address2: val('qq_p_a2'), town: val('qq_p_town'), emirate: val('qq_p_em'), notes: val('qq_p_notes') },
        services: { enabled: Object.keys(S.data.services).filter(function (k) { return on('qq_sv_' + k); }), default: val('qq_sv_def'), by_method: bm },
        parcels: parseInt(val('qq_parcels'), 10) || 1, four_hour_time: val('qq_4h'), cutoffs: co,
        release: on('qq_release'), send_products: on('qq_products'), uae_only: on('qq_uae'), note_policy: val('qq_np')
      } };
    }
    if (tab === 'test') return { options: { test: { emails: S.data.options.test.emails, phones: S.data.options.test.phones } } };
    var st = {};
    document.querySelectorAll('[data-qq-st]').forEach(function (el) {
      var p = el.getAttribute('data-qq-st').split(':');
      st[p[0]] = st[p[0]] || {};
      st[p[0]][p[1]] = el.type === 'checkbox' ? el.checked : el.value;
    });
    return { options: { statuses: st } };
  }

  /* Inline checks before anything is sent; the server checks again. */
  function validate(tab) {
    S.err = {};
    if (tab === 'pick') {
      var ph = val('qq_p_phone').replace(/[\s\-()]/g, '');
      if (ph && !/^(\+|00)?\d{9,15}$/.test(ph)) S.err.qq_p_phone = 'A full number with the country code, e.g. +971 50 123 4567.';
      if (!val('qq_p_a1').trim()) S.err.qq_p_a1 = 'Quiqup needs a street and building to collect from.';
    }
    return Object.keys(S.err).length === 0;
  }

  async function run(busy, path, body, okTitle, okText) {
    if (S.busy) return null;
    S.busy = busy; render();
    var r = await call(path, body);
    S.busy = '';
    if (!ours()) return r;
    if (r.status === 200 && r.body.ok !== false) {
      if (r.body.envs) S.data = Object.assign({}, S.data, r.body);
      render();
      if (okTitle) toast('ok', okTitle, okText || '');
    } else {
      render();
      toast('bad', 'Not saved', fail(r.status, r.body, 'change Store → Quiqup'));
    }
    return r;
  }

  async function save(tab) {
    if (!S.data) return;
    var c = collect(tab);
    if (!validate(tab)) { render(); toast('bad', 'Check the highlighted field', ''); return; }
    await run('save:' + tab, 'quiqup-settings', c, 'Saved', tab === 'stat' ? 'Status mapping updated.' : tab === 'test' ? 'Test customers updated.' : 'Pickup and defaults updated.');
  }

  async function saveKeys(env) {
    var k = val('qq_' + env + '_api_key').trim(), w = val('qq_' + env + '_webhook_secret').trim();
    var r = await run('keys:' + env, 'quiqup-settings', { secrets_env: env, api_key: k, webhook_secret: w },
      'Saved', k ? 'New ' + (env === 'live' ? 'live' : 'staging') + ' key stored. Press Test connection to verify it.' : 'Webhook address ready.');
    if (r && r.status === 200) S.test[env] = null;
  }

  async function regen(env) {
    var yes = await dialog('<h3>Make a new webhook address?</h3><p>The ' + (env === 'live' ? 'live' : 'staging') + ' address stops working at once. Paste the new one into that environment\'s Quiqdash straight away, or updates stop arriving.</p>',
      [{ label: 'Keep the old one', value: false }, { label: 'Make a new address', value: true, cls: 'danger' }]);
    if (yes) await run('regen:' + env, 'quiqup-settings', { secrets_env: env, regenerate_url: true }, 'New webhook address made', 'Paste it into Quiqdash now.');
  }

  async function test(env) {
    if (S.busy) return;
    S.busy = 'test:' + env; render();
    var r = await call('quiqup-test', { env: env });
    S.busy = '';
    if (!ours()) return;
    var ok = r.status === 200 && !!r.body.ok;
    S.test[env] = { ok: ok, text: r.status === 200 ? r.body.message || '' : fail(r.status, r.body, 'test the connection') };
    if (ok) S.data.envs[env].verified_at = new Date().toISOString();
    render();
    toast(ok ? 'ok' : 'bad', ok ? 'Quiqup answered' : 'Quiqup refused the test', S.test[env].text);
    if (ok) load(true);
  }

  async function setMode(to) {
    var d = S.data, from = d.mode;
    if (!d || to === from || S.busy) return;
    var L = d.mode_labels, html, buttons;
    if (to === 'live') {
      var cl = d.checklist, ready = cl.every(function (c) { return c.ok; });
      html = '<h3>Go Live with Quiqup?</h3><p>From the moment you confirm:</p><ul>' +
        '<li>New bookings go to Quiqup <b>production</b> (' + esc(d.envs.live.host) + ') with your live key ••••' + esc(d.envs.live.api_key.last4 || '—') + '. Each one is a real, billed courier.</li>' +
        '<li><b>Every</b> order can be booked, not just test orders.</li>' +
        '<li>Customers see their delivery steps on the track page and in My account.</li>' +
        '<li>Orders already booked in testing keep updating from staging.</li></ul>' +
        '<ul class="qq-cl">' + cl.map(function (c) { return '<li class="' + (c.ok ? 'ok' : '') + '"><i aria-hidden="true">' + (c.ok ? '✓' : '!') + '</i><div>' + esc(c.label) + '<small>' + esc(c.detail) + '</small></div></li>'; }).join('') + '</ul>' +
        (ready ? '' : '<p><b>Not ready yet.</b> Every item above needs a tick before Live can be switched on.</p>');
      buttons = [{ label: 'Not now', value: false }, { label: 'Go Live', value: true, cls: 'go', disabled: !ready }];
    } else if (to === 'testing') {
      html = '<h3>Switch to Internal testing?</h3>' + (d.testing_env === 'live'
        ? '<p>Your test orders are booked with a <b>real courier</b> on Quiqup production (' + esc(d.envs.live.host) + '). <b>A real Quiqup courier will collect your test orders. Quiqup bills a delivery once it is picked up.</b></p><p>Only your test orders can be booked and only they show delivery steps; every other shopper sees the shop exactly as before.</p>'
        : '<p>Bookings go to Quiqup <b>staging</b> (' + esc(d.envs.sandbox.host) + ') with your staging key. Only your test orders can be booked and only they show delivery steps; every other shopper sees the shop exactly as before.</p>') +
        (from === 'live' ? '<p>Live bookings already made keep updating from production.</p>' : '');
      buttons = [{ label: 'Cancel', value: false }, { label: from === 'live' ? 'Leave Live' : 'Start testing', value: true, cls: 'pri' }];
    } else {
      html = '<h3>Turn Quiqup off?</h3><p>Nothing new can be booked. Parcels already booked keep updating until they are delivered.</p>';
      buttons = [{ label: 'Cancel', value: false }, { label: 'Turn off', value: true, cls: from === 'live' ? 'danger' : 'pri' }];
    }
    var yes = await dialog(html, buttons);
    if (!yes || !ours()) return;
    var r = await run('mode:' + to, 'quiqup-mode', { mode: to, confirm: true }, 'Mode: ' + L[to], to === 'testing' && d.testing_env === 'live' ? MODE_TEXT.testing_live : MODE_TEXT[to]);
    if (r && r.status === 422 && r.body.checklist && ours()) { S.data.checklist = r.body.checklist; render(); }
  }

  async function setBooksOn(to) {
    var d = S.data;
    if (!d || d.mode !== 'testing' || to === d.testing_env || S.busy) return;
    if (to === 'live') {
      var yes = await dialog('<h3>Book your test orders with a real courier?</h3>' +
        '<p><b>A real Quiqup courier will collect your test orders. Quiqup bills a delivery once it is picked up.</b></p><ul>' +
        '<li>Your test orders go to Quiqup <b>production</b> (' + esc(d.envs.live.host) + ') with your live key ••••' + esc(d.envs.live.api_key.last4 || '—') + '.</li>' +
        '<li>Every other order is still refused while Internal testing is on.</li>' +
        '<li>Test orders already booked on staging keep updating from staging.</li></ul>',
        [{ label: 'Keep the test system', value: false }, { label: 'Use a real courier', value: true, cls: 'go' }]);
      if (!yes || !ours()) return;
    }
    var r = await run('books:' + to, 'quiqup-mode', { testing_env: to, confirm: to === 'live' }, 'Internal testing books on: ' + BOOKS_SHORT[to],
      to === 'live' ? 'Your next test order is booked with a real courier.' : 'Instant checks on staging: no courier, no charges.');
    if (r && r.status === 422 && r.body.testing_live_missing && ours()) { S.data.testing_live_missing = r.body.testing_live_missing; render(); }
  }

  function addTag(kind) {
    var id = kind === 'emails' ? 'qq_t_email' : 'qq_t_phone', v = val(id).trim();
    if (!v) return;
    if (kind === 'emails' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { toast('bad', 'That is not an email address', v); return; }
    if (kind === 'phones' && !/^(\+|00|0)?\d{8,15}$/.test(v.replace(/[\s\-()]/g, ''))) { toast('bad', 'Add the number with its country code', v); return; }
    var list = S.data.options.test[kind];
    if (list.indexOf(v) < 0) list.push(kind === 'emails' ? v.toLowerCase() : v);
    render();
    var el = document.getElementById(id); if (el) el.focus();
  }

  async function loadActivity() {
    S.act = null; render();
    var r = await call('quiqup-activity');
    if (!ours() || S.tab !== 'act') return;
    S.act = r.status === 200 && r.body.ok ? r.body : { error: fail(r.status, r.body, 'open Activity') };
    render();
  }

  async function load(quiet) {
    var r = await call('quiqup-settings');
    if (!ours()) return;
    if (r.status !== 200 || !r.body.ok) { if (!quiet) { host().innerHTML = '<div class="qq-banner warn">' + esc(fail(r.status, r.body, 'open Store → Quiqup')) + '</div>'; } return; }
    S.data = r.body;
    // A shop with no staging key yet opens on the Setup guide.
    if (S.tab === 'conn' && !(S.data.envs && S.data.envs.sandbox && S.data.envs.sandbox.api_key && S.data.envs.sandbox.api_key.filled)) S.tab = 'guide';
    render();
  }

  function copyText(value, button) {
    var done = function () { button.textContent = 'Copied ✓'; toast('ok', 'Copied', 'Paste it into Quiqdash → Integrations → Webhooks.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(value).then(done, function () { toast('warn', 'Copy it by hand', value); });
    else toast('warn', 'Copy it by hand', value);
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-qq-screen]')) return;
    var b;
    if ((b = t.closest('[data-qq-tab]'))) { S.tab = b.getAttribute('data-qq-tab'); S.err = {}; render(); if (S.tab === 'act') loadActivity(); return; }
    if ((b = t.closest('[data-qq-mode]'))) { setMode(b.getAttribute('data-qq-mode')); return; }
    if ((b = t.closest('[data-qq-books]'))) { setBooksOn(b.getAttribute('data-qq-books')); return; }
    if ((b = t.closest('[data-qq-save]'))) { save(b.getAttribute('data-qq-save')); return; }
    if ((b = t.closest('[data-qq-savekeys]'))) { saveKeys(b.getAttribute('data-qq-savekeys')); return; }
    if ((b = t.closest('[data-qq-test]'))) { test(b.getAttribute('data-qq-test')); return; }
    if ((b = t.closest('[data-qq-regen]'))) { regen(b.getAttribute('data-qq-regen')); return; }
    if ((b = t.closest('[data-qq-tag]'))) { addTag(b.getAttribute('data-qq-tag')); return; }
    if ((b = t.closest('[data-qq-untag]'))) { var p = b.getAttribute('data-qq-untag').split(':'); S.data.options.test[p[0]].splice(+p[1], 1); render(); return; }
    if ((b = t.closest('[data-qq-copy]'))) { copyText(b.getAttribute('data-qq-copy'), b); }
  });
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-qq-screen]')) return;
    if (e.key === 'Enter' && (t.id === 'qq_t_email' || t.id === 'qq_t_phone')) { e.preventDefault(); addTag(t.id === 'qq_t_email' ? 'emails' : 'phones'); return; }
    var tab = t.closest('[role=tab]'), seg = t.closest('[role=radio]');
    var group = tab ? '[role=tab]' : seg ? '[role=radio]' : null;
    if (!group || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
    var all = Array.prototype.slice.call(t.parentNode.querySelectorAll(group)), i = all.indexOf(t.closest(group));
    var next = all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length];
    e.preventDefault();
    if (next) next.focus();
  });

  function addNavEntry() {
    if (!window.kbbAddNavEntry) return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Quiqup',
      icon: '<path d="M2 7h11v9H2z"/><path d="M13 10h4.5l3.5 3.5V16h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/><path d="M5 4h6"/>',
      group: 'Store',
      after: ['shipping']
    });
  }

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="Store"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle'), side = document.querySelector('#side');
    if (crumb) crumb.textContent = 'Store';
    if (title) title.textContent = 'Quiqup';
    if (side) side.classList.remove('open');
    var c = document.getElementById('content');
    if (!c) return undefined;
    c.innerHTML = '<div class="wrap qq" data-qq-screen></div>';
    reset();
    render();
    load(false);
    return undefined;
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry);
  else addNavEntry();
})();
</script>
@endverbatim
