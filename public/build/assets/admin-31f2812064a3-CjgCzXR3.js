
(function () {
  'use strict';

  var SCREEN = 'sets';

  /* `stock` is null until /admin-api/set-stock has answered, and STAYS null on a
     404 or a 403 — so the Stock card is not drawn at all on a shop whose route
     table does not carry it yet, rather than showing the owner a control that
     cannot save. (Lane SP) */
  var state = { sets: [], busy: false, error: null, stock: null };

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(m) { try { window.toast(m); } catch (e) {} }

  /* The XSRF cookie, not a <meta> tag. The admin console has no csrf-token meta
     and a screen that read one sent '' on every write and was refused with 419
     — which the screen then reported as "could not be saved", a sentence that
     names the symptom and hides the cause. That happened on the Shoppable video
     screen and nobody could create a section until it was found. */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body, method) {
    var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined || method) {
      options.method = method || 'POST';
      options.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      if (body !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
      }
    }
    var response = await fetch(base() + path, options);
    var payload = null;
    try { payload = await response.json(); } catch (e) {}
    if (!response.ok) { throw { status: response.status, body: payload }; }
    return payload || {};
  }

  /* A 404 here means one specific thing and it is worth saying rather than
     hiding behind "something went wrong": the package's routes are not in the
     server's compiled route table. That is what the clear_caches migration
     shipping beside this exists to fix, and a shop that applied the files
     without the migration lands exactly here. */
  function explain(e, fallback) {
    if (e && e.status === 404) {
      return 'The Sets endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) {
      return 'Your account does not hold the Sets capability.';
    }
    if (e && e.body && e.body.errors) {
      var first = Object.keys(e.body.errors)[0];
      if (first) return String(e.body.errors[first][0]);
    }
    if (e && e.body && e.body.error) return String(e.body.error);
    return fallback;
  }

  /* Two decimals in, two decimals out, and the SERVER decides every figure on
     this screen. Nothing here multiplies a price by anything — a set's price is
     worked out by App\Support\SetPricing and arrives already resolved. */
  function money(v) {
    var n = Number(v);
    return isFinite(n) ? n.toFixed(2) : '0.00';
  }

  /* ----------------------------------------------------------------- render */

  function render() {
    var el = document.querySelector('#content');
    if (!el) return;
    el.innerHTML = '<div class="wrap kst-wrap">'
      + (state.error ? '<div class="kst-note is-bad">' + esc(state.error) + '</div>' : '')
      + list()
      + stockCard()
      + '</div>';
  }

  /* A set whose price is a RULE rather than a number says so on its row, because
     "AED 179.00" means something different when it is going to follow its
     products down. */
  function ruleLabel(mode) {
    return (mode === 'discount_percent' || mode === 'discount_amount') ? 'Priced from the box' : '';
  }

  function list() {
    var rows = state.sets.map(function (s) {
      var rule = ruleLabel(s.price_mode);

      return '<div class="kst-row">'
        + '<span class="kst-th" style="' + (s.image ? 'background-image:url(\'' + esc(s.image) + '\')' : '') + '"></span>'
        + '<div class="kst-mid">'
          + '<div class="kst-nm">' + esc(s.name) + '</div>'
          + '<div class="kst-meta">' + esc(s.status) + (s.category ? ' &middot; ' + esc(s.category) : '')
            + ' &middot; ' + s.member_count + ' product' + (s.member_count === 1 ? '' : 's')
            + ' &middot; AED ' + esc(money(s.price_aed)) + '</div>'
        + '</div>'
        + (rule ? '<span class="kst-pill">' + esc(rule) + '</span>' : '')
        + '<button class="kst-mini" data-kst-edit="' + s.id + '">Edit</button>'
        + '<button class="kst-mini is-danger" data-kst-del="' + s.id + '">Delete</button>'
        + '</div>';
    }).join('');

    return '<div class="kst-card">'
      + '<div class="kst-actions" style="justify-content:space-between">'
        + '<div><div class="kst-title">Sets</div>'
        + '<div class="kst-sub">A set is a product made of other products. It has its own price, '
        + 'category, description, pictures and search appearance, and it publishes and displays exactly '
        + 'like any other product. <b>Sets are built on the product editor</b> &mdash; this page is the '
        + 'list of them, and both buttons here open that editor.</div></div>'
        + '<button class="kst-btn is-primary" data-kst-new>New set</button>'
      + '</div>'
      + '<div class="kst-list" style="margin-top:14px">'
        + (rows || '<div class="kst-note">No sets yet. <b>New set</b> opens the product editor with the '
          + 'type already set to Set.</div>')
      + '</div></div>';
  }

  /* ────────────────────────────────────────────────────────────────────────
     STOCK — Catalog → Sets → Stock · When a set is sold. (Lane SP)

     The owner has been asked twice whether selling a set should take one of
     each product in the box off its own shelf, and there is a real argument
     either way: a shop that buys ready-made gift boxes counts them separately,
     and a shop that makes a box up per order does not. So the rule is built and
     SHIPPED AT WHAT THIS SHOP DOES TODAY — the set carries its own stock — and
     this is the one place it changes.

     IT IS ON THIS SCREEN AND NOT ON A PRODUCT'S PAGE because it is a shop-wide
     rule rather than a property of any one set. A switch repeated on every set's
     editor would be four hundred copies of one decision.

     BOTH OPTION VALUES ARE LITERALS IN THIS FILE and the server validates what
     arrives against its own list before storing it; SetStockApiController stores
     one of its own two options or the default, never the string that was posted.
     ──────────────────────────────────────────────────────────────────────── */
  function stockCard() {
    if (!state.stock) return '';

    var mode = state.stock.mode === 'members' ? 'members' : 'set';

    return '<div class="kst-card">'
      + '<div class="kst-title">Stock</div>'
      + '<div class="kst-sub">What happens to the stock of the products inside a set when '
      + 'the set itself is sold. This ships set to what this shop already does, so nothing '
      + 'changes until you change it here.</div>'
      + '<div class="kst-f" style="margin-top:14px;max-width:520px">'
        + '<label for="kstStockMode">When a set is sold</label>'
        + '<select id="kstStockMode" data-kst-stock>'
          + '<option value="set"' + (mode === 'set' ? ' selected' : '') + '>'
          + 'Take it off the set&rsquo;s own stock only</option>'
          + '<option value="members"' + (mode === 'members' ? ' selected' : '') + '>'
          + 'Also take each product in the box off its own stock</option>'
        + '</select>'
      + '</div>'
      + '<div class="kst-note" style="margin-top:12px">'
      + (mode === 'members'
          ? 'Selling one Glow Set also takes one of every product in that box off its own '
            + 'shelf, multiplied by how many of it the box holds. A set whose box has run out '
            + 'of one product can no longer be bought.'
          : 'The set is counted on its own, exactly like any other product. The products '
            + 'inside it keep whatever stock they had. <b>This is what the shop does today.</b>')
      + '</div></div>';
  }

  /* ------------------------------------------------------------------ reads */

  async function load() {
    state.busy = true; state.error = null; render();

    try {
      var body = await api('/sets');
      state.sets = body.sets || [];
    } catch (e) {
      state.error = explain(e, 'The list of sets could not be loaded.');
    }

    /* SWALLOWED ON PURPOSE, and it is the only swallowed error on this screen.
       The Stock switch is a second, independent endpoint with a capability of
       its own: a shop whose route table predates it answers 404 and an account
       without `sets.stock` answers 403, and NEITHER is a reason to put a red
       box over the list of sets the operator came here for. The card is simply
       not drawn — see stockCard(). (Lane SP) */
    try { state.stock = await api('/set-stock'); }
    catch (e) { state.stock = null; }

    state.busy = false;
    render();
  }

  /* ------------------------------------------------------------- the events */

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.closest || !t.closest('[data-kst-stock]')) return;

    /* Saved on change. It is one control with two values, and a switch that
       needs a second press to take effect is a switch an owner walks away from
       thinking he set it.

       BOUNDED HERE TOO. Only the two values this screen offers are ever sent;
       anything else falls back to the default, which is exactly what the server
       does with what arrives. Two guards on the same value, because this one
       decides whether an order empties three shelves. */
    var mode = t.value === 'members' ? 'members' : 'set';

    (async function () {
      try {
        var body = await api('/set-stock', { mode: mode });
        state.stock = { mode: body.mode || mode };
        say(mode === 'members'
          ? 'Selling a set now takes each product in the box off its own stock.'
          : 'A set is now counted on its own stock only.');
      } catch (err) {
        state.error = explain(err, 'That stock rule could not be saved.');
      }
      render();
    })();
  });

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var hit = function (a) { var n = t.closest('[' + a + ']'); return n ? n.getAttribute(a) : null; };

    /* BOTH DOORS LEAD TO THE PRODUCT EDITOR, and that is the whole point of the
       rebuild: there is exactly one screen in this console that can change what
       a set is. window.peoNew and window.peoEdit are the product editor's own
       entry points — the same two Catalog → Products already uses. (Lane SP) */
    if (t.closest('[data-kst-new]')) {
      if (typeof window.peoNew === 'function') window.peoNew({ type: 'set' });
      else say('The product editor is not on this page.');
      return;
    }

    var edit = hit('data-kst-edit');
    if (edit) {
      if (typeof window.peoEdit === 'function') window.peoEdit(Number(edit));
      else say('The product editor is not on this page.');
      return;
    }

    var del = hit('data-kst-del');
    if (del) {
      if (!window.confirm('Delete this set? The products in it are not touched.')) return;
      (async function () {
        try { await api('/sets/' + del, undefined, 'DELETE'); say('Set deleted.'); await load(); }
        catch (err) { state.error = explain(err, 'That set could not be deleted.'); render(); }
      })();
      return;
    }
  });

  /* --------------------------------------------------------------- the nav */

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;

    /* Keyed on the screen id, so a second call replaces the row rather than
       adding another one. */
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Sets',
      icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18"/><path d="M12 7V4"/><path d="M8 4h8"/>',
      group: 'Catalog',
      after: ['catalog']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Catalog"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Catalog';
    if (title) title.textContent = 'Sets';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    /* render() BEFORE load(), synchronously. That is the condition
       LATE_RENDERED carries in app.blade.php: the deep-link replay's marker
       inside #content has to be destroyed by the time its task runs, or the
       screen is drawn twice. */
    render();
    load();
    return undefined;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
