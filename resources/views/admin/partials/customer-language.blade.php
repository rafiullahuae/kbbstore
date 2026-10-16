{{--
    Store → Customers: the customer's language, and why a row is on the
    "Arabic customers" list. (Lane AE)

    THE OWNER'S ASK: "i will want a separate list of customers who did from
    arabic version, or include anything in arabic like their name or address
    etc. so i can ... send them arabic content ... as email marketing."

    WHAT THIS FILE IS. window.kbbCustomerLanguage, three small helpers the
    Customers screen in admin/app.blade.php calls (the integrator wires four
    lines there; nothing here wraps window.go or repaints #content):

      tags(c)        the reason pills on an Arabic-list row -- "ordered in
                     Arabic", "Arabic name", "Arabic address", "chose Arabic";
                     '' for any other row (the field is null there).
      field(c)       the "Language" field on the customer page: Arabic /
                     English / Automatic, with "(set by you)" when the owner
                     fixed it.
      bind(c, done)  wires that select: one POST on change, to
                     /admin-api/customers/{id}/language (capability
                     customers.language -- owner, manager; a 403 is said in
                     words), then done() repaints the page it came from.

    No request until the owner changes the select; nothing measured; every
    server value printed through esc().
--}}
<script>
(function () {
  if (window.kbbCustomerLanguage) return;

  var REASONS = {
    ordered: 'ordered in Arabic',
    name: 'Arabic name',
    address: 'Arabic address',
    chose: 'chose Arabic'
  };
  var ORDER = ['ordered', 'chose', 'name', 'address'];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  function base() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }

  function tags(c) {
    var r = c && Array.isArray(c.arabic_reasons) ? c.arabic_reasons : null;
    if (!r || !r.length) return '';
    return '<div class="kcl-tags" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:3px">' +
      ORDER.filter(function (k) { return r.indexOf(k) >= 0; }).map(function (k) {
        return '<span class="pill grey" data-kcl-reason="' + k + '" style="font-size:10.5px;padding:1px 7px">' + esc(REASONS[k]) + '</span>';
      }).join('') + '</div>';
  }

  function label(code) { return code === 'ar' ? 'Arabic' : code === 'en' ? 'English' : 'Not known yet'; }

  function field(c) {
    var cur = c.locale_admin ? (c.locale || 'auto') : 'auto';
    var opt = function (v, t) { return '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + esc(t) + '</option>'; };
    return '<div class="fld" style="margin:0"><label for="kclSel">Language</label>' +
      '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">' +
      '<select class="inp" id="kclSel" data-kcl-id="' + esc(c.id) + '" style="max-width:260px">' +
        opt('auto', 'Automatic — ' + label(c.locale)) + opt('ar', 'Arabic (العربية)') + opt('en', 'English') +
      '</select>' +
      '<span id="kclNote" style="font-size:11.5px;color:var(--ink-soft)">' +
        (c.locale_admin ? 'Set by you — sign-ins and orders will not change it.' : 'Follows the language they last signed in or ordered in.') +
      '</span></div>' +
      '<div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px">Order emails follow the language each order was placed in. This is for everything else: password reset, basket reminders, back-in-stock, account invites.</div></div>';
  }

  function bind(c, done) {
    var sel = document.getElementById('kclSel');
    if (!sel) return;
    sel.onchange = async function () {
      var note = document.getElementById('kclNote');
      sel.disabled = true;
      try {
        var r = await fetch(base() + '/admin-api/customers/' + encodeURIComponent(c.id) + '/language', {
          method: 'POST', credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
          body: JSON.stringify({ locale: sel.value })
        });
        var d = null;
        try { d = await r.json(); } catch (e) { d = null; }
        if (!r.ok || !d || !d.ok) {
          throw new Error(r.status === 403 ? 'Your role cannot change a customer’s language.'
            : (r.status === 404 && !(d && d.error)) ? 'This setting is not in the server’s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
            : ((d && (d.error || d.message)) || ('The server answered ' + r.status + '.')));
        }
        if (typeof done === 'function') done(d); else if (note) note.textContent = 'Saved.';
      } catch (e) {
        if (note) { note.textContent = e.message; note.style.color = 'var(--red)'; }
        sel.disabled = false;
      }
    };
  }

  window.kbbCustomerLanguage = { tags: tags, field: field, bind: bind, reasons: REASONS };
})();
</script>
