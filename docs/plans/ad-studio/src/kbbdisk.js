/* KBB Ad Studio · inside KBB Studio (18 Nov 2026). The owner: "i need also the Ads studio too" -- next to the video
 * editor, and not one design lost.
 *
 * KBB Studio serves this file at /app/adstudio/ad-studio.html from its engine (one fixed origin, 127.0.0.1:41917) and
 * writes <meta name="rs-token"> into it. When that token is here, everything the studio saves goes to the owner's data
 * folder through the engine -- ~/KBB Studio Data/adstudio/ -- so designs survive updates, a browser reset and a change
 * of browser. Browser storage stays a cache. Opened as a plain file (file://) there is no token and nothing changes.
 *
 *   adstudio/projects/<id>.json      {id, name, updated, co, doc}        a saved design
 *   adstudio/images/<id>.json        {id, name, aspect, kind, data}      an uploaded picture (data: URL)
 *   adstudio/kv/<key>.json           {v}                                 companies, the picked company, brand kit, drafts
 *   adstudio/video/projects/<id>.json, adstudio/video/media/<id>.json (+ <id>.bin, the clip itself)
 *
 * Every name is checked against a fixed pattern before it becomes part of a path; the engine refuses '..', hidden
 * names and anything outside adstudio/ on its own as well.
 */
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="rs-token"]');
  var token = meta && /^https?:$/.test(location.protocol) ? meta.getAttribute('content') || '' : '';
  var NAME = /^[A-Za-z0-9][A-Za-z0-9_~-]{0,79}\.(json|bin)$/, DIR = /^(projects|images|kv|video\/projects|video\/media)$/;
  var ready = null, ok = false, es = null;
  // the names each folder holds (read once per folder, kept up to date by put/del), so a read of a file that is not
  // there is answered here instead of with a 404 the browser would print in red
  var have = Object.create(null);
  function names(dir) { return have[dir] || (have[dir] = api.list(dir).then(function (a) { var s = Object.create(null); a.forEach(function (e) { s[e.name] = 1; }); return s; }).catch(function (e) { delete have[dir]; throw e; })); }
  function rel(dir, name) {
    if (!DIR.test(dir) || !NAME.test(name)) throw new Error('Not a storage name: ' + dir + '/' + name);
    return 'adstudio/' + dir + '/' + name;
  }
  function call(url, opts) {
    opts = opts || {};
    return fetch(url, { method: opts.method || 'GET', body: opts.body, cache: 'no-store', headers: Object.assign({ 'X-RS-Token': token }, opts.headers || {}) }).then(function (r) {
      if (!r.ok) { var e = new Error('KBB Studio refused (' + r.status + ')'); e.status = r.status; throw e; }
      return r;
    });
  }
  var api = {
    // true when this page runs inside KBB Studio (the engine served it); the engine is checked by init()
    get inside() { return !!token; },
    get on() { return ok; },
    init: function () {
      if (ready) return ready;
      if (!token) return (ready = Promise.resolve(false));
      ready = call('/api/info').then(function (r) { return r.json(); }).then(function (i) {
        // an engine older than the Ad Studio's folder (api 5) refuses adstudio/: stay in the browser and say so
        ok = !!i && +i.api >= 5; api.info = i;
        // the engine stops a few seconds after its last page closes; this page is one of them
        if (ok && !es && typeof EventSource === 'function') { try { es = new EventSource('/api/events?t=' + encodeURIComponent(token)); } catch (e) {} }
        return ok;
      }).catch(function () { ok = false; return false; });
      return ready;
    },
    key: function (k) { return String(k).replace(/:/g, '~') + '.json'; },
    list: function (dir) {
      if (!DIR.test(dir)) return Promise.reject(new Error('Not a storage folder'));
      return call('/api/list?path=' + encodeURIComponent('adstudio/' + dir)).then(function (r) { return r.json(); })
        .then(function (a) {
          a = (Array.isArray(a) ? a : []).filter(function (e) { return !e.dir && NAME.test(e.name); });
          var s = Object.create(null); a.forEach(function (e) { s[e.name] = 1; }); have[dir] = Promise.resolve(s);
          return a;
        })
        .catch(function (e) { if (e.status === 404) return []; throw e; });
    },
    getJSON: function (dir, name) {
      var p; try { p = rel(dir, name); } catch (e) { return Promise.reject(e); }
      return names(dir).then(function (s) { return !s[name] ? null : fetch('/' + p, { cache: 'no-store' }).then(function (r) { if (r.status === 404) return null; if (!r.ok) throw new Error('KBB Studio could not read ' + name); return r.json(); }); });
    },
    getBlob: function (dir, name, type) {
      var p; try { p = rel(dir, name); } catch (e) { return Promise.reject(e); }
      return names(dir).then(function (s) { return !s[name] ? null : fetch('/' + p, { cache: 'no-store' }).then(function (r) { if (r.status === 404) return null; if (!r.ok) throw new Error('KBB Studio could not read ' + name); return r.blob(); }); })
        .then(function (b) { return b && type ? new Blob([b], { type: type }) : b; });
    },
    put: function (dir, name, body) {
      var p; try { p = rel(dir, name); } catch (e) { return Promise.reject(e); }
      var b = body instanceof Blob ? body : new Blob([JSON.stringify(body)], { type: 'application/json' });
      return call('/api/write?path=' + encodeURIComponent(p), { method: 'PUT', body: b }).then(function () { if (have[dir]) return have[dir].then(function (s) { s[name] = 1; }, function () {}); });
    },
    del: function (dir, name) {
      var p; try { p = rel(dir, name); } catch (e) { return Promise.reject(e); }
      return call('/api/delete?path=' + encodeURIComponent(p), { method: 'POST' }).then(function () { if (have[dir]) return have[dir].then(function (s) { delete s[name]; }, function () {}); });
    },
    // KBB Studio's company picker: its key 'kbbstudio.workspace', which KBB Studio keeps in this origin's localStorage
    // AND in KBB Studio Data/settings/prefs.json (app/core/prefs.js restores it from there on every start). Read on
    // start; a switch here is written to both, so KBB Studio opens on the company last used in either.
    studioCompany: function () {
      var ls = ''; try { ls = localStorage.getItem('kbbstudio.workspace') || ''; } catch (e) {}
      if (!ok) return Promise.resolve(ls);
      return call('/api/prefs').then(function (r) { return r.json(); }).then(function (j) { var v = j && j.ui && j.ui['kbbstudio.workspace']; return typeof v === 'string' && v ? v : ls; }).catch(function () { return ls; });
    },
    setStudioCompany: function (id) {
      if (!ok || !/^[a-z0-9-]{1,40}$/.test(id)) return Promise.resolve(false);
      // only a company KBB Studio also has: an unknown id there would fall back to its first company
      return call('/api/stat?path=' + encodeURIComponent('workspaces/' + id + '/workspace.json')).then(function (r) { return r.json(); }).then(function (st) {
        if (!st || !st.exists) return false;
        try { localStorage.setItem('kbbstudio.workspace', id); } catch (e) {}
        return call('/api/prefs?merge=1', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ui: { 'kbbstudio.workspace': id } }) }).then(function () { return true; });
      }).catch(function () { return false; });
    }
  };
  window.KBB_DISK = api;
  // the way back to the video editor (the header link, hidden in the standalone file)
  if (token) { var back = document.getElementById('kbb-back'); if (back) back.hidden = false; }
})();
