{{--
    kbb-tabs — ONE tab component for the admin console (Lane EK). Used by every
    Emails screen and Emails → All mail settings, and ready for Lane MK's
    Marketing Emails screens.

    The owner, 4 October: "on the email setting page, i need proper tabs not
    just throw the content, follow this also for all emails pages stuff."

    ── MARKUP CONTRACT ─────────────────────────────────────────────────────

      <div class="ectabs kbb-tabs" role="tablist" data-kbt="GROUP" aria-label="…">
        <button type="button" class="ectab kbb-tab [on]" role="tab"
                id="kbt_GROUP_ID" data-kbt-tab="ID" aria-controls="kbtp_GROUP_ID"
                aria-selected="true|false" tabindex="0|-1">Label <span class="ct">3</span></button>
        …
      </div>
      <div class="kbb-tabpanel" role="tabpanel" data-kbt-panel="GROUP" data-kbt-id="ID"
           id="kbtp_GROUP_ID" aria-labelledby="kbt_GROUP_ID" tabindex="0" [hidden]>…</div>

    GROUP names one tab set (and the localStorage key kbbtab:GROUP); ID one
    tab. The look is the console's own tab bar, .ectabs / .ectab (Store →
    Ecommerce, Payments), so every tab row in the back office looks the same;
    .kbb-tabs / .kbb-tab / .kbb-tabpanel name the component.

    ── BEHAVIOUR (delegated on document, so markup painted at any time works) ─

      click a tab        selects it, shows its panel, hides the others
      ← / →              previous / next tab (flipped in RTL), wrapping
      Home / End         first / last tab
      focus              follows the selection (WAI-ARIA automatic activation)
      remembered         localStorage kbbtab:GROUP, per browser
      ?tab=ID            on the URL, opens that tab (kbbTabs.pick)
      event              document 'kbb:tab' {detail:{group, id}} after a change

    NOT the #hash: in this console the hash is the SCREEN id (#mail), and
    writing a tab into it would navigate.

    At 390px the bar scrolls sideways inside itself (.ectabs is overflow-x:auto
    with its scrollbar hidden) and never widens the page.

    ── JS HELPERS (window.kbbTabs) ─────────────────────────────────────────

      kbbTabs.bar(group, [[id, label, count?, extraClass?], …], current, ariaLabel) → HTML
      kbbTabs.panel(group, id, current)   → the panel's attributes (a string)
      kbbTabs.pick(group, ids, fallback)  → ?tab=, else remembered, else fallback
      kbbTabs.show(group, id, focus?)     → select one from code

    Included ONCE, from admin/partials/emails-screens.blade.php (the first
    Emails partial in the document). A second include is a no-op (guarded).
--}}
@verbatim
<style>
.ectabs.kbb-tabs{margin:0 0 16px;max-width:100%}
.kbb-tabpanel[hidden]{display:none!important}
.ectab.kbb-tab:focus-visible{outline:2px solid var(--accent,#E0567B);outline-offset:-2px}
</style>
<script>
(function () {
  'use strict';
  if (window.kbbTabs) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function slug(s) { return String(s).replace(/[^a-z0-9_-]/gi, '-'); }
  function remember(group, id) { try { localStorage.setItem('kbbtab:' + group, id); } catch (e) {} }
  function remembered(group) { try { return localStorage.getItem('kbbtab:' + group); } catch (e) { return null; } }

  var api = {
    bar: function (group, tabs, current, label) {
      return '<div class="ectabs kbb-tabs kbt" role="tablist" data-kbt="' + esc(group) + '" aria-label="' + esc(label || 'Sections') + '">'
        + tabs.map(function (t) {
          var on = t[0] === current;
          return '<button type="button" class="ectab kbb-tab kbt-tab' + (on ? ' on' : '') + (t[3] ? ' ' + esc(t[3]) : '') + '" role="tab"'
            + ' id="kbt_' + slug(group) + '_' + slug(t[0]) + '" data-kbt-tab="' + esc(t[0]) + '"'
            + ' aria-controls="kbtp_' + slug(group) + '_' + slug(t[0]) + '" aria-selected="' + (on ? 'true' : 'false') + '" tabindex="' + (on ? '0' : '-1') + '">'
            + esc(t[1]) + (t[2] != null && t[2] !== '' ? ' <span class="ct">' + esc(t[2]) + '</span>' : '') + '</button>';
        }).join('') + '</div>';
    },
    panel: function (group, id, current) {
      return ' class="kbb-tabpanel kbt-panel" role="tabpanel" data-kbt-panel="' + esc(group) + '" data-kbt-id="' + esc(id) + '"'
        + ' id="kbtp_' + slug(group) + '_' + slug(id) + '" aria-labelledby="kbt_' + slug(group) + '_' + slug(id) + '" tabindex="0"'
        + (id === current ? '' : ' hidden');
    },
    pick: function (group, ids, fallback) {
      var q = null;
      try { q = new URLSearchParams(window.location.search).get('tab'); } catch (e) {}
      if (q && ids.indexOf(q) !== -1) return q;
      var r = remembered(group);
      return r && ids.indexOf(r) !== -1 ? r : fallback;
    },
    show: function (group, id, focus) {
      var bar = document.querySelector('[role="tablist"][data-kbt="' + group + '"]');
      if (!bar) return;
      bar.querySelectorAll('[data-kbt-tab]').forEach(function (b) {
        var on = b.getAttribute('data-kbt-tab') === id;
        b.classList.toggle('on', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.setAttribute('tabindex', on ? '0' : '-1');
        if (on && focus) b.focus();
      });
      document.querySelectorAll('[data-kbt-panel="' + group + '"]').forEach(function (p) {
        p.hidden = p.getAttribute('data-kbt-id') !== id;
      });
      remember(group, id);
      try { document.dispatchEvent(new CustomEvent('kbb:tab', { detail: { group: group, id: id } })); } catch (e) {}
    }
  };
  window.kbbTabs = api;

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[role="tab"][data-kbt-tab]');
    if (!t) return;
    var bar = t.closest('[role="tablist"][data-kbt]');
    if (!bar) return;
    e.preventDefault();
    api.show(bar.getAttribute('data-kbt'), t.getAttribute('data-kbt-tab'), false);
  });

  document.addEventListener('keydown', function (e) {
    var t = e.target.closest && e.target.closest('[role="tab"][data-kbt-tab]');
    if (!t) return;
    var bar = t.closest('[role="tablist"][data-kbt]');
    if (!bar) return;
    var tabs = Array.prototype.slice.call(bar.querySelectorAll('[role="tab"][data-kbt-tab]')).filter(function (b) { return !b.hidden; });
    var i = tabs.indexOf(t), n = -1;
    var rtl = document.documentElement.dir === 'rtl';
    if (e.key === 'ArrowRight') n = rtl ? i - 1 : i + 1;
    else if (e.key === 'ArrowLeft') n = rtl ? i + 1 : i - 1;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    else return;
    e.preventDefault();
    n = (n + tabs.length) % tabs.length;
    api.show(bar.getAttribute('data-kbt'), tabs[n].getAttribute('data-kbt-tab'), true);
  });
})();
</script>
@endverbatim
