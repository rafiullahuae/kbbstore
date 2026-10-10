{{--
    Emails → Customer emails → Review request → Schedule & rules     Lane RV

    The fifth tab of the template editor, shown for the Review request email
    only. Included ONCE, from admin/partials/emails-templates-screens.blade.php
    (so admin/app.blade.php needs no new line). It defines window.kbbRvRules,
    which that editor asks for when it paints: html() draws the tab, open()
    loads it the first time it is shown. No request until the tab is opened, no
    timer, no polling — one GET to draw it and one POST per Save.

    Every string from the server is escaped before it reaches innerHTML. A
    select offers only the server's own options and the server stores one of
    them or the default (ReviewRequestSettings::clean()). Capability
    `emails.review_requests`; a 403 says so in words.
--}}
@verbatim
<style>
.rv-rules .rv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px}
.rv-rules .rv-stat{background:var(--surface-2,#f4f6fb);border-radius:14px;padding:12px 14px;min-width:0}
.rv-rules .rv-stat b{display:block;font-size:22px;line-height:1.1;color:var(--ink,#101729)}
.rv-rules .rv-stat span{font-size:11.5px;color:var(--ink-soft,#626c80)}
.rv-rules .rv-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--border-2,#eef0f6);min-width:0}
.rv-rules .rv-row:first-child{border-top:0}
.rv-rules .rv-row label{flex:1;min-width:0;font-size:13px;font-weight:600;color:var(--ink-2,#3c465c)}
.rv-rules .rv-row small{display:block;font-weight:400;color:var(--ink-faint,#97a0b2);font-size:11.5px;margin-top:2px}
.rv-rules .rv-row input[type=number]{width:84px;border:1px solid var(--border,#e6e9f2);border-radius:10px;padding:8px 10px;font:inherit;font-size:13px}
.rv-rules .rv-row select{border:1px solid var(--border,#e6e9f2);border-radius:10px;padding:8px 10px;font:inherit;font-size:13px;background:#fff}
.rv-rules .rv-sw{width:20px;height:20px}
.rv-rules .rv-dot{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:-1px;margin-right:6px}
.rv-rules .rv-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.rv-rules .rv-tbl td,.rv-rules .rv-tbl th{padding:7px 6px;border-top:1px solid var(--border-2,#eef0f6);text-align:left;vertical-align:top}
.rv-rules .rv-scroll{overflow-x:auto}
</style>
<script>
(function () {
  'use strict';

  var S = { data: null, loading: false, err: '' };
  var REASONS = { too_late: 'Waited too long (no traffic or cron)', order_gone: 'Order deleted', not_eligible: 'Imported or sample order', unsubscribed: 'Customer unsubscribed', reviews_closed: 'Reviews → Accept new reviews is off', not_completed: 'No longer Completed', refunded: 'Refunded', asked_recently: 'Asked recently', nothing_to_review: 'Nothing left to review', send_failed: 'Send failed (Emails → Sent mail)' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function box() { return document.querySelector('[data-rv-rules]'); }
  function when(iso) { if (!iso) return '—'; try { return new Date(iso).toLocaleString(); } catch (e) { return iso; } }
  function toast(t) { try { if (typeof window.toast === 'function') window.toast(t); } catch (e) {} }

  async function call(body) {
    var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + '/admin-api/emails/review-requests', opts);
    var d = null; try { d = await r.json(); } catch (e) {}
    if (!r.ok) { var e = new Error('rv ' + r.status); e.status = r.status; throw e; }
    return d || {};
  }
  function why(e) {
    if (e && e.status === 403) return 'Your role cannot change the review request rules.';
    if (e && e.status === 404) return 'Not in the server\'s route table yet. Clear the route cache (Platform → Cache) and reload.';
    return 'The request did not complete. Try again in a moment.';
  }

  function field(f, v) {
    var id = 'rv-' + esc(f.key);
    if (f.type === 'bool') return '<div class="rv-row"><label for="' + id + '">' + esc(f.label) + '</label><input class="rv-sw" type="checkbox" id="' + id + '" data-rv="' + esc(f.key) + '"' + (v ? ' checked' : '') + '></div>';
    if (f.type === 'select') {
      var cur = (f.options || []).filter(function (o) { return o.value === v; })[0] || {};
      return '<div class="rv-row"><label for="' + id + '"><span class="rv-dot" style="background:' + esc(cur.hex || '#ccc') + '"></span>' + esc(f.label) + '</label><select id="' + id + '" data-rv="' + esc(f.key) + '">'
        + (f.options || []).map(function (o) { return '<option value="' + esc(o.value) + '"' + (o.value === v ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select></div>';
    }
    return '<div class="rv-row"><label for="' + id + '">' + esc(f.label) + '<small>' + esc(f.min) + '–' + esc(f.max) + ' · default ' + esc(f.default) + '</small></label><input type="number" id="' + id + '" data-rv="' + esc(f.key) + '" min="' + esc(f.min) + '" max="' + esc(f.max) + '" value="' + esc(v) + '"></div>';
  }

  function html() {
    if (S.err) return '<div class="ek-card rv-rules"><p class="d">' + esc(S.err) + '</p></div>';
    var d = S.data;
    if (!d) return '<div class="ek-card rv-rules"><p class="d">Loading the schedule and report…</p></div>';
    var st = d.stats || {};
    var stats = st.ready === false ? '<p class="d">The report appears once the package\'s migration has run.</p>' : '<div class="rv-grid">'
      + [['sent', 'Requests sent'], ['scheduled', 'Waiting for their day'], ['reviews', 'Reviews received'], ['pending', 'Waiting for approval in Reviews'], ['rate', 'Orders that answered (%)'], ['skipped', 'Skipped by the rules']]
        .map(function (p) { return '<div class="rv-stat"><b>' + esc(st[p[0]] == null ? 0 : st[p[0]]) + '</b><span>' + esc(p[1]) + '</span></div>'; }).join('') + '</div>'
      + '<p class="d">Last sent: ' + esc(when(st.last_sent)) + '. The switch for this email is on Emails → Customer emails (' + (d.enabled ? 'on' : 'off') + ' now). Only orders completed while it is on are ever asked.</p>';
    var skipped = st.skipped_by ? Object.keys(st.skipped_by).map(function (k) { return esc(REASONS[k] || k) + ': ' + esc(st.skipped_by[k]); }).join(' · ') : '';
    var form = (d.schema || []).map(function (f) { return field(f, d.settings[f.key]); }).join('');
    var recent = (d.recent || []).length ? '<div class="rv-scroll"><table class="rv-tbl"><thead><tr><th>Order</th><th>Status</th><th>Completed</th><th>Sent</th><th>Reviews</th></tr></thead><tbody>'
      + d.recent.map(function (r) { return '<tr><td>' + esc(r.order) + '</td><td>' + esc(r.status) + (r.reason ? ' · ' + esc(REASONS[r.reason] || r.reason) : '') + '</td><td>' + esc(when(r.completed_at)) + '</td><td>' + esc(when(r.sent_at)) + '</td><td>' + esc(r.reviews) + '</td></tr>'; }).join('')
      + '</tbody></table></div>' : '<p class="d">No order has been completed since this email was switched on.</p>';
    return '<div class="rv-rules">'
      + '<div class="ek-card" style="margin-bottom:14px"><h3>Report</h3>' + stats + (skipped ? '<p class="d">Skipped: ' + skipped + '</p>' : '') + '</div>'
      + '<div class="ek-card" style="margin-bottom:14px"><h3>Schedule & rules</h3><p class="d">Sent once per order — the database refuses a second. Orders imported from WooCommerce and sample orders are never asked; an address that unsubscribed never is again.</p>' + form
      + '<div class="ek-row" style="margin-top:12px"><span class="ek-sp"></span><button type="button" class="btn" data-rv-save>Save rules</button></div></div>'
      + '<div class="ek-card"><h3>Latest requests</h3>' + recent + '</div>'
      + '</div>';
  }

  function paint() { var b = box(); if (b) b.innerHTML = html(); }

  async function open() {
    if (S.loading) return;
    S.loading = true; S.err = '';
    try { S.data = await call(null); } catch (e) { S.err = why(e); }
    S.loading = false;
    paint();
  }

  document.addEventListener('click', async function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-rv-save]') : null;
    if (!t || !S.data) return;
    var out = {};
    document.querySelectorAll('[data-rv]').forEach(function (el) { out[el.getAttribute('data-rv')] = el.type === 'checkbox' ? el.checked : el.value; });
    t.disabled = true;
    try { S.data = await call({ settings: out }); toast('Review request rules saved.'); } catch (err) { toast(why(err)); }
    t.disabled = false;
    paint();
  });

  window.kbbRvRules = { html: html, open: open };
})();
</script>
@endverbatim
