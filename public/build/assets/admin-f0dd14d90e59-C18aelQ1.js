
(function () {
  'use strict';

  var SCREEN = 'igembeds';
  var TITLE = 'Instagram embeds';

  var data = null, items = [], values = {}, banner = null, busy = false, seq = 0;
  var refused = [], dev = 'm', live = false, dirty = false;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function root() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
  }

  async function api(path, body) {
    var opts = { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(root() + '/admin-api' + path, opts);
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
    if (e && e.status === 404) return 'The Instagram embeds endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot change Instagram embeds. An owner, manager or editor can.';
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  /* The shortcode alphabet, the server's CODE_RE. Only used to build the
     preview's links and frames from what the server already returned. */
  var CODE = /^[A-Za-z0-9_-]{5,40}$/;

  function src(it, captioned) {
    if (!CODE.test(it.c) || (it.k !== 'p' && it.k !== 'reel')) return null;
    return data.origin + '/' + it.k + '/' + it.c + '/embed/' + (captioned ? 'captioned/' : '');
  }

  function href(it) {
    if (!CODE.test(it.c) || (it.k !== 'p' && it.k !== 'reel')) return null;
    return data.origin + '/' + it.k + '/' + it.c + '/';
  }

  function field(key) {
    return (data.fields || []).filter(function (f) { return f.key === key; })[0] || null;
  }

  function opt(key) {
    var f = field(key), v = values[key];
    if (!f || !f.options) return String(v);
    return f.options.some(function (o) { return o[0] === String(v); }) ? String(v) : String(f['default']);
  }

  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: 'igembeds',
      label: 'Instagram embeds',
      icon: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M9.5 21h5"/>',
      group: 'Content',
      after: ['instagram', 'ugcsections', 'media']
    });
  }

  var previousGo = window.go;

  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);

    document.querySelectorAll('.side .nav-item').forEach(function (b) {
      b.classList.toggle('on', b.dataset.go === SCREEN);
    });
    var group = document.querySelector('#nav .nav-group[data-sec="Content"]');
    if (group) group.classList.add('open');

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = 'Content';
    if (title) title.textContent = TITLE;

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    render();
    load();
    return undefined;
  };

  function adopt(body) {
    data = body;
    items = (body.items || []).map(function (it) { return { c: it.c, k: it.k, l: it.l || '', on: !!it.on }; });
    values = {};
    (body.fields || []).forEach(function (f) { values[f.key] = f.value; });
    dirty = false;
  }

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();
    try {
      var body = await api('/ig-embeds');
      if (mine !== seq) return;
      adopt(body);
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'Could not load Instagram embeds.');
    }
    busy = false;
    render();
  }

  async function add() {
    var ta = document.querySelector('[data-ige-paste]');
    var text = ta ? ta.value : '';
    if (!text.trim()) { say('Paste one or more Instagram post or reel addresses first.'); return; }
    busy = true; render();
    try {
      var r = await api('/ig-embeds/parse', { text: text });
      var have = {};
      items.forEach(function (it) { have[it.c] = true; });
      var added = 0;
      (r.items || []).forEach(function (it) {
        if (have[it.c] || items.length >= data.max_items) return;
        have[it.c] = true; items.push({ c: it.c, k: it.k, l: '', on: true }); added++;
      });
      refused = r.refused || [];
      if (ta && !refused.length) ta.value = '';
      if (added) dirty = true;
      say(added ? added + ' added. Press Save to put them on the shop.' : 'Nothing new to add.');
    } catch (e) {
      say(explain(e, 'Could not read that paste.'));
    }
    busy = false;
    render();
  }

  async function save() {
    busy = true; render();
    try {
      var body = await api('/ig-embeds', { options: values, items: items });
      adopt(body);
      say('Saved. The shop shows it now.');
    } catch (e) {
      if (e && e.body && e.body.fields) adopt(e.body);
      say(explain(e, 'Could not save.'));
    }
    busy = false;
    render();
  }

  /* ── the preview: the shop's markup, built from what is on screen ── */

  function classes(phone, styleKey) {
    var cm = opt('cols_m'), cd = phone ? cm : opt('cols_d');
    return 'kie kie-s-' + (styleKey || opt('style')) + ' kie-l-' + opt('layout') + ' kie-cd-' + cd + ' kie-cm-' + cm + (values.caption ? ' kie-cap' : '');
  }

  function cardHtml(it, opts) {
    var s = src(it, !!values.caption), h = href(it);
    if (!s || !h) return '';
    var name = it.l || (it.k === 'reel' ? 'Reel' : 'Instagram post');
    var out = '<li class="kie-card kie-k-' + it.k + '"><div class="kie-fr"><div class="kie-in">';
    if (values.badge || it.l) out += '<div class="kie-top">' + (values.badge ? data.glyph : '') + (it.l ? '<span>' + esc(it.l) + '</span>' : '') + '</div>';
    if (opts.live) {
      out += '<div class="kie-box"><div class="kie-fac" aria-hidden="true">' + data.glyph + '<span>' + esc(name) + '</span></div>'
        + '<iframe class="kie-if" src="' + esc(s) + '" loading="lazy" title="' + esc(name) + '" sandbox="' + esc(data.sandbox) + '" referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
    } else {
      out += '<div class="kie-box' + (opt('load') === 'tap' ? ' kie-tap' : '') + '"><div class="kie-fac">' + data.glyph + '<span>' + esc(name) + '</span>'
        + (opt('load') === 'tap' ? '<span class="kie-go">Show the post</span>' : '') + '</div></div>';
    }
    if (values.link) out += '<a class="kie-more" href="' + esc(h) + '" target="_blank" rel="noopener nofollow">View on Instagram <span aria-hidden="true">↗</span></a>';
    return out + '</div></div></li>';
  }

  function build(phone, list, opts) {
    var heading = String(values.heading || '').trim();
    var style = '--kie-fit:' + opt('fit') + 'px' + (phone && opt('layout') === 'slider' ? ';--kie-peek:.86' : '');
    return '<div class="' + classes(phone, opts.style) + '" style="' + esc(style) + '">'
      + (heading && !opts.mini ? '<h2 class="kie-h">' + esc(heading) + '</h2>' : '')
      + '<ul class="kie-list" role="list">' + list.map(function (it) { return cardHtml(it, opts); }).join('') + '</ul></div>';
  }

  function shown() {
    var max = parseInt(opt('max'), 10) || 6;
    return items.filter(function (it) { return it.on; }).slice(0, max);
  }

  function fieldHtml(f) {
    var v = values[f.key];
    if (f.key === 'style') {
      var sample = [{ c: 'Preview01', k: 'p', l: '', on: true }];
      return '<div class="ige-f"><label>' + esc(f.label) + '</label><div class="ige-styles">'
        + f.options.map(function (o) {
          return '<button type="button" class="ige-st" data-ige-style="' + esc(o[0]) + '" aria-pressed="' + (opt('style') === o[0]) + '">'
            + build(true, sample, { style: o[0], mini: true }) + '<span>' + esc(o[1]) + '</span></button>';
        }).join('') + '</div><span class="ige-help">' + esc(f.help) + '</span></div>';
    }
    if (f.type === 'bool') {
      return '<label class="ige-chk"><input type="checkbox" data-ige-opt="' + esc(f.key) + '"' + (v ? ' checked' : '') + '><span>' + esc(f.label)
        + (f.help ? '<span class="ige-help">' + esc(f.help) + '</span>' : '') + '</span></label>';
    }
    if (f.type === 'select') {
      return '<div class="ige-f"><label for="ige-o-' + esc(f.key) + '">' + esc(f.label) + '</label><select id="ige-o-' + esc(f.key) + '" data-ige-opt="' + esc(f.key) + '">'
        + f.options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(v) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('')
        + '</select>' + (f.help ? '<span class="ige-help">' + esc(f.help) + '</span>' : '') + '</div>';
    }
    return '<div class="ige-f"><label for="ige-o-' + esc(f.key) + '">' + esc(f.label) + '</label><input type="text" id="ige-o-' + esc(f.key) + '" data-ige-opt="' + esc(f.key) + '" value="' + esc(v) + '" maxlength="120">'
      + (f.help ? '<span class="ige-help">' + esc(f.help) + '</span>' : '') + '</div>';
  }

  function rowHtml(it, i) {
    var h = href(it);
    return '<li class="ige-row' + (it.on ? '' : ' is-off') + '"><span class="ige-kind' + (it.k === 'reel' ? ' is-reel' : '') + '">' + (it.k === 'reel' ? 'Reel' : 'Post') + '</span>'
      + '<div class="ige-mid"><div class="ige-code">' + (h ? '<a href="' + esc(h) + '" target="_blank" rel="noopener nofollow">' + esc(h.replace('https://www.', '')) + '</a>' : esc(it.c)) + '</div>'
      + '<input class="ige-lbl" type="text" maxlength="' + data.label_max + '" placeholder="Label on the card (optional)" value="' + esc(it.l) + '" data-ige-label="' + i + '" aria-label="Label for card ' + (i + 1) + '"></div>'
      + '<div class="ige-acts"><label class="ige-sw"><input type="checkbox" data-ige-on="' + i + '"' + (it.on ? ' checked' : '') + '>On</label>'
      + '<button type="button" class="ige-ic" data-ige-up="' + i + '" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
      + '<button type="button" class="ige-ic" data-ige-down="' + i + '" aria-label="Move down"' + (i === items.length - 1 ? ' disabled' : '') + '>↓</button>'
      + '<button type="button" class="ige-ic" data-ige-del="' + i + '" aria-label="Remove">×</button></div></li>';
  }

  function drawStage() {
    var stage = document.querySelector('[data-ige-stage]');
    if (!stage || !data) return;
    stage.setAttribute('data-ige-dev', dev);
    var list = shown();
    stage.innerHTML = list.length ? build(dev === 'm', list, { live: live })
      : '<div class="ige-empty">Nothing to show yet: add an address and switch it on. The shop draws no section until then.</div>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== TITLE) return;

    if (!data) {
      host.innerHTML = '<div class="ige-wrap"><div class="ige-card"><p class="ige-title">' + TITLE + '</p><p class="ige-sub">'
        + esc(busy ? 'Loading…' : (banner || 'Nothing to show yet.')) + '</p>'
        + (busy ? '' : '<div class="ige-actions"><button type="button" class="ige-btn" data-ige-reload>Retry</button></div>') + '</div></div>';
      return;
    }

    var opts = (data.fields || []).map(fieldHtml).join('');

    host.innerHTML = '<style>' + data.css + '</style><div class="ige-wrap"><div>'
      + '<div class="ige-card"><p class="ige-title">Add posts and reels</p>'
      + '<p class="ige-sub">Paste Instagram addresses — one per line, or several at once. Posts (/p/…), reels (/reel/…) and /tv/ links all work, with or without the ?igsh=… part. No Instagram login or API is used: the shop shows Instagram’s own public embed of each post.</p>'
      + '<textarea class="ige-ta" data-ige-paste placeholder="https://www.instagram.com/p/…&#10;https://www.instagram.com/reel/…"></textarea>'
      + '<div class="ige-bar"><button type="button" class="ige-btn is-primary" data-ige-add' + (busy ? ' disabled' : '') + '>Add</button>'
      + '<span class="ige-sub" style="margin:0">' + items.length + ' of ' + data.max_items + ' in the list</span></div>'
      + (refused.length ? '<ul class="ige-ref">' + refused.map(function (r) { return '<li><b>' + esc(r.line) + '</b> — ' + esc(r.reason) + '</li>'; }).join('') + '</ul>' : '')
      + (items.length ? '<ul class="ige-list">' + items.map(rowHtml).join('') + '</ul>' : '<div class="ige-empty">No posts yet.</div>')
      + '</div>'
      + '<div class="ige-card" style="margin-top:14px"><p class="ige-title">Section</p>'
      + '<p class="ige-sub">The card styles change the frame around each post. The inside of the post is Instagram’s own page and cannot be styled.</p>'
      + opts
      + '<div class="ige-actions"><button type="button" class="ige-btn is-primary" data-ige-save' + (busy ? ' disabled' : '') + '>Save</button>'
      + '<button type="button" class="ige-btn" data-ige-reload>Discard changes</button>' + (dirty ? '<span class="ige-sub" style="margin:0;align-self:center">Unsaved changes</span>' : '') + '</div>'
      + '<p class="ige-note">On the homepage: Appearance → Homepage → <b>Instagram embeds</b> row (move it, or switch it per device). '
      + 'On any page, post or HTML block: <code>' + esc(data.shortcode) + '</code>, or for example <code>[kbb_instagram_embeds layout="slider" style="ring" max="4"]</code>.</p></div>'
      + '</div><div class="ige-card ige-prev"><p class="ige-title">Preview</p>'
      + '<div class="ige-bar"><button type="button" class="ige-btn" data-ige-dev="m" aria-pressed="' + (dev === 'm') + '">Phone</button>'
      + '<button type="button" class="ige-btn" data-ige-dev="d" aria-pressed="' + (dev === 'd') + '">Laptop</button>'
      + '<button type="button" class="ige-btn" data-ige-live aria-pressed="' + live + '">' + (live ? 'Showing the real posts' : 'Load the real posts') + '</button></div>'
      + '<p class="ige-sub">The shop shows this light card until the post loads — ' + (opt('load') === 'tap' ? 'when the shopper taps it.' : 'as the shopper scrolls near it.') + ' “Load the real posts” fetches them from Instagram here.</p>'
      + '<div class="ige-stage" data-ige-stage></div></div></div>';

    drawStage();
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest || (document.querySelector('#ptitle') || {}).textContent !== TITLE) return;
    var t;
    if (e.target.closest('[data-ige-add]')) { add(); return; }
    if (e.target.closest('[data-ige-save]')) { save(); return; }
    if (e.target.closest('[data-ige-reload]')) { refused = []; load(); return; }
    if ((t = e.target.closest('[data-ige-dev]')) && !t.closest('[data-ige-stage]')) { dev = t.getAttribute('data-ige-dev') === 'd' ? 'd' : 'm'; render(); return; }
    if (e.target.closest('[data-ige-live]')) { live = !live; render(); return; }
    if ((t = e.target.closest('[data-ige-style]'))) { values.style = t.getAttribute('data-ige-style'); dirty = true; render(); return; }
    if ((t = e.target.closest('[data-ige-up]'))) { move(+t.getAttribute('data-ige-up'), -1); return; }
    if ((t = e.target.closest('[data-ige-down]'))) { move(+t.getAttribute('data-ige-down'), 1); return; }
    if ((t = e.target.closest('[data-ige-del]'))) { items.splice(+t.getAttribute('data-ige-del'), 1); dirty = true; render(); return; }
  });

  function move(i, d) {
    var j = i + d;
    if (j < 0 || j >= items.length) return;
    var x = items[i]; items[i] = items[j]; items[j] = x;
    dirty = true; render();
  }

  document.addEventListener('change', function (e) {
    if ((document.querySelector('#ptitle') || {}).textContent !== TITLE) return;
    var t = e.target;
    if (t.hasAttribute('data-ige-opt')) {
      var k = t.getAttribute('data-ige-opt');
      values[k] = t.type === 'checkbox' ? t.checked : t.value;
      dirty = true; render(); return;
    }
    if (t.hasAttribute('data-ige-on')) { items[+t.getAttribute('data-ige-on')].on = t.checked; dirty = true; render(); return; }
    if (t.hasAttribute('data-ige-label')) { items[+t.getAttribute('data-ige-label')].l = t.value.slice(0, data.label_max); dirty = true; drawStage(); return; }
  });

  /* A label redraws only the stage as it is typed — no request, no re-render
     of the input being typed in. */
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t.hasAttribute || !t.hasAttribute('data-ige-label') || !data) return;
    items[+t.getAttribute('data-ige-label')].l = t.value.slice(0, data.label_max);
    dirty = true; drawStage();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addNavEntry);
  } else {
    addNavEntry();
  }
})();
