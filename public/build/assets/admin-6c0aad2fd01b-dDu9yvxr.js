
(function () {
  'use strict';

  var SCREEN = 'pagewash';

  /* UNFINISHED CHANGES (Lane PM). Leaving this screen with edits in `values`
     used to throw them away without a word. They are kept in Unfinished in
     the top bar instead (partials/unfinished-drafts.blade.php), and come back
     into `values` when the screen is next opened. */
  if (window.kbbDrafts) window.kbbDrafts.track({
    id: SCREEN, screen: SCREEN, label: 'Appearance → Page background',
    values: function () { return tabs ? values : null; },
    set: function (k, v) { if (Object.prototype.hasOwnProperty.call(values, k)) values[k] = v; },
    render: function () { render(); },
    save: function () { save(); }
  });

  var tabs = null, values = {}, open = 'preview', banner = null, busy = false, seq = 0;
  var treatments = {}, palettes = {}, pages = [], emittedCss = '', isDefault = true;
  var contrast = {}, contrastToday = {}, previewParam = 'kbbwash';
  var shownTreatment = 'a', shownPage = 0, frameWidth = 1280;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The shop's own root, derived the same way api() derives the admin-api root:
     this console's path less its last segment. Never a hard-coded '/', because
     KBB_BASE_PATH prefixes every route on a host that sets it. */
  function shopRoot() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(shopRoot() + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status; err.body = payload;
      throw err;
    }
    return payload;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(msg) { try { window.toast(msg); } catch (e) {} }

  function explain(e, fallback) {
    return e && e.status === 404
      ? 'The Page background endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.'
      : ((e && e.body && e.body.error) ? e.body.error : fallback);
  }

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Page background',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14c4-3 7 1 10-1s5-2 8 0"/>',
      group: 'Appearance',
      /* The ids as app.blade.php's own nav list spells them. */
      after: ['dividers', 'prodstyles', 'homepage', 'layout']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Appearance"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Appearance';
    if (title) title.textContent = 'Page background';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/page-wash');
      if (mine !== seq) return;

      tabs = body.tabs || [];
      treatments = body.treatments || {};
      palettes = body.palettes || {};
      pages = body.preview_pages || [];
      emittedCss = body.css || '';
      isDefault = body.is_default !== false;
      contrast = body.contrast || {};
      contrastToday = body.contrast_today || {};
      previewParam = body.preview_param || 'kbbwash';
      values = {};
      tabs.forEach(function (t) { t.fields.forEach(function (f) { values[f.key] = f.value; }); });
      if (open !== 'preview' && !tabs.some(function (t) { return t.key === open; })) open = 'preview';
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The Page background settings could not be read.');
    } finally {
      if (mine === seq) {
        busy = false; render();
        if (tabs && !banner && window.kbbDrafts) window.kbbDrafts.ready(SCREEN);
      }
    }
  }

  async function save() {
    if (busy) return;
    busy = true; render();

    var payload = {};
    Object.keys(values).forEach(function (k) { payload[k] = values[k]; });

    try {
      var body = await api('/page-wash', { settings: payload });
      if (window.kbbDrafts) window.kbbDrafts.saved(SCREEN);
      emittedCss = body.css || '';
      isDefault = body.is_default !== false;
      say(values.on ? 'Page background saved, and it is ON.' : 'Page background saved. The wash is off, so the shop is unchanged.');
      await load();
      return;
    } catch (e) {
      banner = explain(e, 'That could not be saved.');
    } finally {
      busy = false; render();
    }
  }

  function shown(f) {
    return String(values[f.key]) + ((f.options || {}).unit || '');
  }

  function fieldHTML(f) {
    var id = 'pwb-' + f.key;
    var help = f.help ? '<p class="pwb-help">' + esc(f.help) + '</p>' : '';

    if (f.type === 'bool') {
      return '<div class="pwb-f"><div class="pwb-check">'
        + '<input type="checkbox" id="' + id + '" data-pwb-key="' + esc(f.key) + '"'
        + (values[f.key] ? ' checked' : '') + '>'
        + '<div><label for="' + id + '">' + esc(f.label) + '</label>' + help + '</div>'
        + '</div></div>';
    }

    if (f.type === 'select') {
      var opts = Object.keys(f.options || {}).map(function (k) {
        return '<option value="' + esc(k) + '"' + (String(values[f.key]) === k ? ' selected' : '')
          + '>' + esc(f.options[k]) + '</option>';
      }).join('');
      return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<select id="' + id + '" data-pwb-key="' + esc(f.key) + '">' + opts + '</select>' + help + '</div>';
    }

    if (f.type === 'range') {
      var o = f.options || {};
      return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label>'
        + '<span class="pwb-val" data-pwb-val="' + esc(f.key) + '">' + esc(shown(f)) + '</span></div>'
        + '<input type="range" id="' + id + '" data-pwb-key="' + esc(f.key) + '"'
        + ' min="' + o.min + '" max="' + o.max + '" step="' + o.step + '" value="' + esc(values[f.key]) + '">'
        + help + '</div>';
    }

    if (f.type === 'colour') {
      /* Two controls, ONE value. The swatch is the ordinary way to pick and the
         text box is the way to paste a brand hex, which is what an owner
         actually has. Both carry the same data key, so the delegated listener
         below does not care which one moved. */
      return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
        + '<div class="pwb-colour">'
        + '<input type="color" id="' + id + '" data-pwb-key="' + esc(f.key) + '" value="' + esc(values[f.key]) + '">'
        + '<input type="text" data-pwb-key="' + esc(f.key) + '" value="' + esc(values[f.key]) + '"'
        + ' autocomplete="off" spellcheck="false" maxlength="7">'
        + '</div>' + help + '</div>';
    }

    return '<div class="pwb-f"><div class="pwb-fh"><label for="' + id + '">' + esc(f.label) + '</label></div>'
      + '<input type="text" id="' + id + '" data-pwb-key="' + esc(f.key) + '" value="'
      + esc(values[f.key]) + '" autocomplete="off">' + help + '</div>';
  }

  /* ── THE PREVIEW ──────────────────────────────────────────────────────── */

  function previewUrl(key, index) {
    var p = pages[index] || pages[0];
    if (!p) return '';
    var sep = p.path.indexOf('?') === -1 ? '?' : '&';
    return shopRoot() + p.path + sep + previewParam + '=' + encodeURIComponent(key);
  }

  function treatmentCards() {
    return Object.keys(treatments).map(function (k) {
      var t = treatments[k];
      var mins = Math.round(t.cycle / 60 * 10) / 10;
      return '<div class="pwb-t' + (k === shownTreatment ? ' is-on' : '') + '">'
        + '<h4>' + esc(k.toUpperCase()) + ' · ' + esc(t.name) + '</h4>'
        + '<div class="pwb-strip" data-pwb-swatch="' + esc(k) + '"><i></i><i></i><i></i></div>'
        + '<p class="pwb-meta">' + esc(String(mins)) + ' min a cycle · travel ' + esc(String(t.drift))
        + '% · ' + (t.spread === 'top' ? 'behind the header' : 'the whole page') + '</p>'
        + '<div class="pwb-bar" style="margin-top:0">'
        + '<button type="button" class="pwb-btn" data-pwb-show="' + esc(k) + '"'
        + ' aria-pressed="' + (k === shownTreatment ? 'true' : 'false') + '">Show</button>'
        + '<button type="button" class="pwb-btn" data-pwb-use="' + esc(k) + '">Use this</button>'
        + '</div></div>';
    }).join('');
  }

  function stageHTML() {
    var url = previewUrl(shownTreatment, shownPage);
    if (!url) return '<div class="pwb-empty">No storefront page to frame.</div>';

    /* The frame is rendered at `frameWidth` and scaled to whatever room this
       column has. The height is a proportion of the width, so the shape of the
       box does not change when the console gets narrower. */
    var h = Math.round(frameWidth * (frameWidth <= 480 ? 1.9 : 0.62));
    return '<div class="pwb-stage" data-pwb-stage style="height:0">'
      + '<iframe data-pwb-frame title="Storefront preview" src="' + esc(url) + '"'
      + ' width="' + frameWidth + '" height="' + h + '" loading="lazy"></iframe></div>';
  }

  /* Scale the frame to the room the card actually has.
     THIS IS NOT "JAVASCRIPT THAT MEASURES LAYOUT" IN THE SENSE RULE 4 FORBIDS.
     That rule is about the SHOP: the storefront must size with calc() and never
     read back an element's box. Nothing in the storefront's own wash does. This
     is the admin console scaling a fixed-width frame of a page into a variable
     box, which cannot be expressed in CSS without knowing the box -- and it
     touches nothing the shopper ever loads. */
  function fitFrame() {
    var stage = document.querySelector('[data-pwb-stage]');
    var frame = document.querySelector('[data-pwb-frame]');
    if (!stage || !frame) return;
    var room = stage.clientWidth || frameWidth;
    var scale = Math.min(1, room / frameWidth);
    frame.style.transform = 'scale(' + scale + ')';
    stage.style.height = Math.round(frame.height * scale) + 'px';
  }

  function contrastHTML() {
    var labels = {
      '--ink': 'Body text',
      '--ink-2': 'Secondary text',
      '--muted': 'Muted text',
      '--pink': 'Pink accent',
      '--pink-deep': 'Deep pink'
    };
    var rows = Object.keys(labels).map(function (k) {
      var now = Number(contrastToday[k] || 0), mine = Number(contrast[k] || 0);
      return '<tr><td>' + esc(labels[k]) + '</td>'
        + '<td>' + now.toFixed(2) + '</td>'
        + '<td class="' + (mine + 0.005 < now ? 'pwb-low' : '') + '">' + mine.toFixed(2) + '</td></tr>';
    }).join('');

    return '<div class="pwb-card"><div class="pwb-title">Contrast, at the worst moment of the cycle</div>'
      + '<p class="pwb-sub">Measured against the <b>darkest</b> of the nine colours these settings can '
      + 'produce, so it is the worst case and not a sample. The four palettes above are all held at or '
      + 'above the background your shop already renders, so none of them can cost you contrast; three '
      + 'of your own colours can, and this is where that shows.</p>'
      + '<div class="pwb-scroll" style="margin-top:12px"><table class="pwb-t2"><thead><tr>'
      + '<th>Text</th><th>Today</th><th>With these settings</th></tr></thead><tbody>' + rows
      + '</tbody></table></div>'
      + '<p class="pwb-cap"><b>Muted text and the pink accent are already below 4.5 on this shop</b>, '
      + 'against the background it renders today. That is not something this screen introduced and not '
      + 'something it can fix — it is the brand colour against a pale page — but it is worth knowing '
      + 'before choosing a palette of your own.</p></div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Page background') return;

    if (busy && !tabs) {
      host.innerHTML = '<div class="pwb-wrap"><div class="pwb-card"><div class="pwb-empty">Loading…</div></div></div>';
      return;
    }

    if (!tabs) {
      host.innerHTML = '<div class="pwb-wrap"><div class="pwb-card">'
        + '<div class="pwb-title">Page background</div>'
        + '<p class="pwb-sub">' + esc(banner || 'Nothing to show yet.') + '</p>'
        + '<div class="pwb-actions"><button class="pwb-btn" data-pwb-reload>Retry</button></div>'
        + '</div></div>';
      return;
    }

    var strip = '<button type="button" class="pwb-tab" data-pwb-tab="preview"'
      + ' aria-selected="' + (open === 'preview' ? 'true' : 'false') + '">Preview</button>'
      + tabs.map(function (t) {
          return '<button type="button" class="pwb-tab" data-pwb-tab="' + esc(t.key) + '"'
            + ' aria-selected="' + (t.key === open ? 'true' : 'false') + '">' + esc(t.label) + '</button>';
        }).join('');

    var body;

    if (open === 'preview') {
      body = '<div class="pwb-card">'
        + '<div class="pwb-tabs">' + strip + '</div>'
        + '<p class="pwb-sub" style="margin-top:12px">Four treatments, on your own pages, with your own '
        + 'products behind them. Nothing here is saved and nothing is switched on — this is what the shop '
        + '<i>would</i> look like. Only somebody signed in here can see it; a shopper who opens the same '
        + 'address gets the shop exactly as it is today.</p>'
        + '<div class="pwb-grid">' + treatmentCards() + '</div>'
        + '</div>'
        + '<div class="pwb-card">'
        + '<div class="pwb-title">' + esc((treatments[shownTreatment] || {}).name || '') + ', live</div>'
        + '<div class="pwb-bar">'
        + pages.map(function (p, i) {
            return '<button type="button" class="pwb-btn" data-pwb-page="' + i + '"'
              + ' aria-pressed="' + (i === shownPage ? 'true' : 'false') + '">' + esc(p.label) + '</button>';
          }).join('')
        + '</div>'
        + '<div class="pwb-bar">'
        + '<button type="button" class="pwb-btn" data-pwb-w="390" aria-pressed="' + (frameWidth === 390 ? 'true' : 'false') + '">Phone · 390</button>'
        + '<button type="button" class="pwb-btn" data-pwb-w="1280" aria-pressed="' + (frameWidth === 1280 ? 'true' : 'false') + '">Desktop · 1280</button>'
        + '<a class="pwb-btn" data-pwb-open href="' + esc(previewUrl(shownTreatment, shownPage)) + '" target="_blank" rel="noopener">Open in a new tab ↗</a>'
        + '</div>'
        + '<div style="margin-top:10px">' + stageHTML() + '</div>'
        + '<p class="pwb-cap">The colour moves over <b>minutes</b>, not seconds — that is what "time to '
        + 'time" means here — so leave this open for a while rather than waiting for something to happen. '
        + 'Somebody browsing with “reduce motion” switched on sees one still gradient and no animation at '
        + 'all.</p>'
        + '</div>'
        + contrastHTML();
    } else {
      var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];
      body = '<div class="pwb-card">'
        + '<div class="pwb-tabs">' + strip + '</div>'
        + '<p class="pwb-sub" style="margin-top:12px">' + esc(current.description) + '</p>'
        + '<div class="pwb-fields">' + current.fields.map(fieldHTML).join('') + '</div>'
        + '<div class="pwb-actions">'
        + '<button class="pwb-btn is-primary" data-pwb-save' + (busy ? ' disabled' : '') + '>'
        + (busy ? 'Saving…' : 'Save') + '</button>'
        + '<button class="pwb-btn" data-pwb-reload' + (busy ? ' disabled' : '') + '>Reload</button>'
        + '<button class="pwb-btn" data-pwb-defaults' + (busy ? ' disabled' : '') + '>Back to defaults</button>'
        + '</div>'
        + '<p class="pwb-help" style="margin-top:8px">“Back to defaults” moves only the controls on '
        + '<b>this tab</b>. Nothing is stored until you press Save.</p>'
        + '</div>'
        + '<div class="pwb-card"><div class="pwb-title">What the shop is sending</div>'
        + '<p class="pwb-sub">' + (isDefault || !values.on
            ? 'The wash is off, so every page of the shop carries <b>nothing at all</b> from this screen — '
              + 'not one byte. The background you see today is the one it keeps.'
            : 'Every storefront page carries this, and nothing else:') + '</p>'
        + (isDefault || !values.on ? '' : '<div class="pwb-css">' + esc(emittedCss) + '</div>')
        + '</div>'
        + contrastHTML();
    }

    host.innerHTML = '<div class="pwb-wrap">'
      + (banner ? '<div class="pwb-note" style="border-style:solid;border-color:#b4443c;color:#b4443c">'
          + esc(banner) + '</div>' : '')
      + '<div class="pwb-note">Printed documents never carry any of this. The invoice, the packing slip, '
      + 'the delivery note and the shipping label are rendered from their own template, which loads no '
      + 'site stylesheet at all — and a storefront page sent to a printer drops the wash too.</div>'
      + body
      + '</div>';

    paintSwatches();
    fitFrame();
  }

  /* The three moments of each treatment, painted from the same numbers the
     storefront uses. The gradients are not restated here: the endpoint sends
     the palette and the two mixes are four lines of arithmetic, which is less
     than a second copy of nine gradient stops would be. */
  function paintSwatches() {
    Object.keys(treatments).forEach(function (k) {
      var host = document.querySelector('[data-pwb-swatch="' + k + '"]');
      if (!host) return;
      var moments = momentsFor(treatments[k]);
      var cells = host.querySelectorAll('i');
      for (var i = 0; i < cells.length && i < moments.length; i++) {
        var m = moments[i];
        cells[i].style.background = 'linear-gradient(160deg,' + rgb(m[0]) + ' 0%,' + rgb(m[1]) + ' 52%,' + rgb(m[2]) + ' 100%)';
      }
    });
  }

  function rgb(c) { return 'rgb(' + c[0] + ',' + c[1] + ',' + c[2] + ')'; }

  function hexRgb(h) {
    h = String(h || '').replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    return [parseInt(h.slice(0, 2), 16) || 0, parseInt(h.slice(2, 4), 16) || 0, parseInt(h.slice(4, 6), 16) || 0];
  }

  function momentsFor(t) {
    var p = palettes[t.palette] || [];
    if (p.length !== 3) return [];
    var c = p.map(hexRgb);
    var mean = [0, 1, 2].map(function (i) { return Math.round((c[0][i] + c[1][i] + c[2][i]) / 3); });
    var d = Math.max(0, Math.min(100, t.drift)) / 100;
    var w = (100 - Math.max(0, Math.min(100, t.intensity))) / 100;
    return [[0, 1, 2], [1, 2, 0], [2, 0, 1]].map(function (order) {
      return order.map(function (idx) {
        return [0, 1, 2].map(function (i) {
          var v = Math.round(mean[i] + (c[idx][i] - mean[i]) * d);
          return Math.round(v + (255 - v) * w);
        });
      });
    });
  }

  document.addEventListener('input', function (e) {
    var el = e.target.closest ? e.target.closest('[data-pwb-key]') : null;
    if (!el) return;

    var key = el.dataset.pwbKey;
    values[key] = el.type === 'checkbox' ? el.checked : el.value;

    var out = document.querySelector('[data-pwb-val="' + key + '"]');
    if (out && tabs) {
      var f = null;
      tabs.forEach(function (t) { t.fields.forEach(function (x) { if (x.key === key) f = x; }); });
      if (f) out.textContent = shown(f);
    }
    /* Keep the swatch and the text box for a colour in step without rebuilding
       the screen, which would take the control out from under the pointer. */
    document.querySelectorAll('[data-pwb-key="' + key + '"]').forEach(function (other) {
      if (other !== el && other.value !== el.value) other.value = el.value;
    });
  });

  document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('[data-pwb-key]') : null;
    if (!el) return;
    values[el.dataset.pwbKey] = el.type === 'checkbox' ? el.checked : el.value;
    if (el.type === 'checkbox' || el.tagName === 'SELECT') render();
  });

  window.addEventListener('resize', function () { fitFrame(); });

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;

    var tab = e.target.closest('[data-pwb-tab]');
    if (tab) { open = tab.dataset.pwbTab; render(); return; }

    var show = e.target.closest('[data-pwb-show]');
    if (show) { shownTreatment = show.dataset.pwbShow; render(); return; }

    var pg = e.target.closest('[data-pwb-page]');
    if (pg) { shownPage = Number(pg.dataset.pwbPage) || 0; render(); return; }

    var w = e.target.closest('[data-pwb-w]');
    if (w) { frameWidth = Number(w.dataset.pwbW) || 1280; render(); return; }

    var use = e.target.closest('[data-pwb-use]');
    if (use) {
      var t = treatments[use.dataset.pwbUse];
      if (!t) return;
      /* ONLY the six keys a treatment names, and NOT `on`. Choosing a look is
         not the same act as publishing it: the switch stays where the owner
         left it and Save is still a separate press. */
      ['palette', 'intensity', 'drift', 'cycle', 'spread'].forEach(function (k) {
        if (t[k] !== undefined) values[k] = t[k];
      });
      shownTreatment = use.dataset.pwbUse;
      open = 'colour';
      render();
      say(t.name + ' is in the controls. It is not saved, and the wash is still ' + (values.on ? 'on' : 'off') + '.');
      return;
    }

    if (e.target.closest('[data-pwb-save]')) { save(); return; }
    if (e.target.closest('[data-pwb-reload]')) { load(); return; }
    if (e.target.closest('[data-pwb-defaults]')) {
      if (!tabs) return;
      var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];
      current.fields.forEach(function (f) { values[f.key] = f['default']; });
      render();
      say(current.label + ' is back to its shipped values. Nothing is saved until you press Save.');
      return;
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
