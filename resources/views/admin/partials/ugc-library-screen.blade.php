{{--
    Content → Shoppable video. (Lane V2 — Phase 20, the data model and the ingest)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry, window.KBBArabic and toast(). It
    registers its own sidebar entry and wraps window.go, exactly as the nine
    screens beside it do, so that one include is the whole of the change to
    that file.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    The owner's library of creator clips: upload one, say who made it and
    whether they said yes, and tag SEVERAL PRODUCTS on it — which is the whole
    feature and the one thing the benchmark app cannot do, because somebody
    else owns its player.

    It draws NOTHING on the storefront. No rail, no player, no section, no
    setting. The owner has not picked a rail or a player yet
    (docs/UGC-VIDEO-PLAN.md §8, questions 1 and 2) and the round that draws one
    is the round that adds a page. This is the library under it.

    ── THE SERVER IS ASKED WHAT IT CAN DO, NOT ASSUMED ──────────────────────

    §8 question 4 — does ffmpeg exist on that Cloudways box — has never been
    answered, so GET /admin-api/ugc-videos reports it and the header card says
    which of the two worlds this shop is in BEFORE the owner uploads anything:

        with ffmpeg   upload ONE file. The poster and the 2.5-second teaser are
                      cut from it on the same request.
        without       upload the clip, then a poster image. A teaser is
                      optional and its absence is a supported state, not an
                      error — the tile shows its poster, ~22 KB, which is what
                      the previews already draw under Save-Data.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included
    — with the next closing one, so writing the word in prose swallows
    everything between them and serves the whole docblock to the browser as
    visible text.

    ── THE LAYOUT RULE ──────────────────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto. Every
    horizontal offset is a LOGICAL property — padding-inline, margin-inline,
    inset-inline — so the screen is correct the day this console grows a
    dir="rtl", and every creator handle is wrapped in <bdi> so an Arabic title
    beside "@layla.skin" does not render it "layla.skin@".

    EVERY CLASS IS PREFIXED ugs- AND APPEARS NOWHERE ELSE IN THE CONSOLE, and
    so is every data- attribute anything clicks: app.blade.php binds delegated
    listeners to `document` itself, each claiming a bare attribute name, and a
    click on any element carrying one is handled by that listener whichever
    screen it belongs to.

    ── EVERY OPERATOR STRING IS ESCAPED ON THE WAY OUT ──────────────────────

    esc() on every interpolation of a title, a caption, a creator handle, a
    product name, a blocker sentence and a server error. The rule is CLAUDE.md
    rule 5: anything printed unescaped is a constant, never a setting. A URL
    that becomes an href has already been scheme-checked on the server by
    App\Services\UgcPath::link() — and is escaped here as well, because a
    second lock costs nothing.
--}}
@verbatim
<style>
.ugs-wrap{display:grid;gap:14px;min-width:0}
.ugs-wrap > *{min-width:0}
.ugs-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
          border-radius:var(--r,12px);padding:16px;min-width:0}
.ugs-title{font-weight:650;font-size:15px}
.ugs-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:68ch}
.ugs-note{border:1px dashed var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;margin-top:12px;
          font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280);min-width:0}
.ugs-note b{color:var(--ink,#16181d)}
.ugs-note.is-warm{border-style:solid;border-color:#e9d5a1;background:#fdf9ef}
.ugs-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;min-width:0}
.ugs-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;
         color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.ugs-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.ugs-btn.is-danger{border-color:#d9534f;color:#d9534f}
.ugs-btn[disabled]{opacity:.45;cursor:default}

/* ── the library list ─────────────────────────────────────────────────────
   A grid that is one column on a phone and as many 280px columns as fit on a
   desktop. auto-fill, not auto-fit: a library of one should not stretch one
   card across 1280px. */
.ugs-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));min-width:0}
.ugs-row{display:grid;grid-template-columns:78px 1fr;gap:12px;align-items:start;
         border:1px solid var(--border,#e6e6e6);border-radius:11px;padding:11px;min-width:0}
.ugs-row > *{min-width:0}
/* aspect-ratio, so the box exists at first paint whether or not a poster has
   been uploaded. The same reason the storefront tile reserves from width and
   height rather than measuring. */
.ugs-thumb{width:78px;aspect-ratio:9/16;border-radius:8px;background:#f2f2f4;overflow:hidden;
           display:grid;place-items:center;color:var(--ink-soft,#9ca3af);font-size:10.5px;text-align:center}
.ugs-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.ugs-rowname{font-weight:650;font-size:13.5px;line-height:1.35;overflow-wrap:anywhere}
.ugs-rowmeta{font-size:11.5px;color:var(--ink-soft,#6b7280);margin-top:3px;line-height:1.5;overflow-wrap:anywhere}
.ugs-pills{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.ugs-pill{font-size:10.5px;font-weight:650;padding:2px 7px;border-radius:999px;
          border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280);white-space:nowrap}
.ugs-pill.is-live{border-color:#15a85a;color:#15a85a}
.ugs-pill.is-hold{border-color:#d9534f;color:#d9534f}
.ugs-pill.is-soft{border-color:#c9a227;color:#a07d12}
.ugs-rowacts{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}

/* ── the editor ───────────────────────────────────────────────────────── */
.ugs-fields{display:grid;gap:14px;margin-top:14px;min-width:0}
.ugs-two{display:grid;gap:14px;grid-template-columns:1fr;min-width:0}
@media (min-width:820px){ .ugs-two{grid-template-columns:1fr 1fr} }
.ugs-f{display:grid;gap:5px;min-width:0}
.ugs-f label{font-size:12.5px;font-weight:650;overflow-wrap:anywhere}
.ugs-f input[type=text],.ugs-f input[type=datetime-local],.ugs-f input[type=number],
.ugs-f select,.ugs-f textarea{width:100%;min-width:0;padding:8px 10px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.ugs-f textarea{resize:vertical;min-height:70px}
.ugs-help{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin:0;max-width:68ch}
.ugs-sec{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
         color:var(--ink-soft,#6b7280);margin-top:4px}

/* ── uploads ──────────────────────────────────────────────────────────── */
.ugs-up{display:grid;gap:8px;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px;min-width:0}
.ugs-uph{display:flex;justify-content:space-between;align-items:baseline;gap:10px;min-width:0}
.ugs-uph b{font-size:12.5px}
.ugs-uph span{font-size:11px;color:var(--ink-soft,#6b7280);white-space:nowrap}
.ugs-up input[type=file]{font:inherit;font-size:12px;max-width:100%}

/* ── product tagging ──────────────────────────────────────────────────── */
.ugs-tagged{display:grid;gap:7px;min-width:0}
.ugs-tag{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;
         border:1px solid var(--border,#e6e6e6);border-radius:9px;padding:8px 10px;min-width:0}
.ugs-tag > *{min-width:0}
.ugs-tagname{font-size:12.5px;line-height:1.35;overflow-wrap:anywhere}
.ugs-tagacts{display:flex;gap:4px;flex-wrap:wrap}
.ugs-mini{padding:4px 8px;border:1px solid var(--border,#e6e6e6);border-radius:7px;background:transparent;
          color:inherit;font:inherit;font-size:11.5px;cursor:pointer;line-height:1.2}
.ugs-results{display:grid;gap:5px;margin-top:8px;max-height:220px;overflow:auto;min-width:0}
.ugs-res{display:block;width:100%;text-align:start;padding:7px 9px;border:1px solid var(--border,#e6e6e6);
         border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:12.5px;cursor:pointer;
         overflow-wrap:anywhere}
.ugs-empty{padding:22px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}
.ugs-bad{border:1px solid #e9b3b1;background:#fdf3f3;color:#8c2f2c;border-radius:10px;padding:11px 12px;
         font-size:12.5px;line-height:1.55;min-width:0}
.ugs-bad ul{margin:6px 0 0;padding-inline-start:18px}
@media (max-width:640px){ .ugs-card{padding:13px} }
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'ugcvideo';

  var videos = null, transcoder = null, limits = null, vocab = null, translatable = null;
  var editing = null;          // the full row being edited, or null for the list
  var tagged = [];             // [{id, name, brand, at_ms}] in order
  var results = [];            // the product search's last answer
  var term = '';               // ...and what was typed to get it
  var banner = null, busy = false, seq = 0;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* One fetch helper for the whole screen. `body` undefined means GET. */
  async function api(path, body, method) {
    var opts = { headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (body !== undefined) {
      opts.method = method || 'POST';
      if (body instanceof FormData) {
        opts.body = body;                 // never set Content-Type by hand for
      } else {                            // multipart: the boundary is the
        opts.headers['Content-Type'] = 'application/json';  // browser's to write
        opts.body = JSON.stringify(body);
      }
    } else if (method) {
      opts.method = method;
    }

    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    var r = await fetch(base + '/admin-api' + path, opts);
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
    if (e && e.status === 404) {
      return 'The Shoppable video endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) {
      return 'Your account does not hold the Shoppable video permission.';
    }
    /* The upload endpoint is throttled to twelve a minute — it is the only one
       here that moves up to 64MB and, where ffmpeg exists, runs two transcodes
       on the request. Without this branch a 429 reads as "that file was not
       accepted", which sends somebody to re-encode a file that was fine. */
    if (e && e.status === 429) {
      return 'Too many uploads in one minute. Wait a moment and try again — the file was fine.';
    }
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  function kb(n) {
    if (!n) return '—';
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  }

  /* ------------------------------------------------------------- the sidebar */

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Shoppable video',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m10 9 5 3-5 3z"/>',
      group: 'Content',
      after: ['media', 'htmlblocks', 'posts']
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
    if (title) title.textContent = 'Shoppable video';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    editing = null;
    render();
    load();
    return undefined;
  };

  /* ------------------------------------------------------------------ reads */

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    try {
      var body = await api('/ugc-videos');
      if (mine !== seq) return;

      videos = body.videos || [];
      transcoder = body.transcoder || null;
      limits = body.limits || null;
      vocab = body.vocabulary || null;
      translatable = body.translatable || null;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The video library could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function open(id) {
    busy = true; render();
    try {
      var body = await api('/ugc-videos/' + encodeURIComponent(id));
      editing = body.video;
      tagged = (body.video.products || []).map(function (p) {
        return { id: p.id, name: p.name, brand: p.brand, at_ms: p.at_ms };
      });
      results = [];
      term = '';
    } catch (e) {
      say(explain(e, 'That video could not be opened.'));
    } finally {
      busy = false; render();
    }
  }

  function blank() {
    editing = {
      id: null, title: '', caption: '', status: 'draft', rights_status: 'pending',
      source_platform: 'upload', source_url: '', creator_handle: '', creator_url: '',
      rights_evidence: '', locale: '', position: 0, published_at: '',
      file_path: null, teaser_path: null, poster_path: null,
      bytes: null, teaser_bytes: null, poster_bytes: null,
      media_state: 'none', blockers: [], products: [], translations: null
    };
    tagged = [];
    results = [];
    term = '';
    render();
  }

  /* ----------------------------------------------------------------- writes */

  function form() {
    var host = document.querySelector('#ugs-form');
    if (!host) return null;

    var value = function (name) {
      var el = host.querySelector('[data-ugs-field="' + name + '"]');
      return el ? el.value : '';
    };

    return {
      title: value('title'),
      caption: value('caption'),
      status: value('status'),
      rights_status: value('rights_status'),
      source_platform: value('source_platform'),
      source_url: value('source_url'),
      creator_handle: value('creator_handle'),
      creator_url: value('creator_url'),
      rights_evidence: value('rights_evidence'),
      locale: value('locale') || null,
      position: Number(value('position') || 0),
      published_at: value('published_at') || null,
      /* The Arabic boxes, collected by the shared helper rather than read by
         hand: one screen's copy of that shape is one screen that drifts. */
      translations: window.KBBArabic ? window.KBBArabic.collect(host) : {}
    };
  }

  async function save() {
    if (busy || !editing) return;
    var payload = form();
    if (!payload) return;

    busy = true; render();

    try {
      var body = editing.id
        ? await api('/ugc-videos/' + encodeURIComponent(editing.id), payload, 'PUT')
        : await api('/ugc-videos', payload);

      var id = body.video.id;
      say('Saved.');

      /* The product list is a second call on purpose: the row has to exist
         before anything can be tagged on it, and a create plus a tag in one
         request would mean a half-created video when the second half failed. */
      await api('/ugc-videos/' + encodeURIComponent(id) + '/products', {
        products: tagged.map(function (t) { return { id: t.id, at_ms: t.at_ms }; })
      });

      await load();
      await open(id);
    } catch (e) {
      /* A publish refusal comes back with its reasons named. Shown as a list
         rather than a toast: "cannot be published yet" with no reasons is a
         message nobody can act on. */
      if (e && e.body && e.body.blockers) {
        banner = null;
        editing._blockers = e.body.blockers;
        say(e.body.error || 'This video cannot be published yet.');
      } else {
        say(explain(e, 'That could not be saved.'));
      }
    } finally {
      busy = false; render();
    }
  }

  async function remove(id, title) {
    if (!window.confirm('Delete "' + title + '" and its files? This cannot be undone.')) return;
    busy = true; render();
    try {
      await api('/ugc-videos/' + encodeURIComponent(id), {}, 'DELETE');
      editing = null;
      say('Deleted.');
      await load();
    } catch (e) {
      say(explain(e, 'That could not be deleted.'));
    } finally {
      busy = false; render();
    }
  }

  async function upload(kind, input) {
    if (!editing || !editing.id || !input.files || !input.files.length) return;

    var data = new FormData();
    data.append('kind', kind);
    data.append('file', input.files[0]);

    busy = true; render();
    try {
      var body = await api('/ugc-videos/' + encodeURIComponent(editing.id) + '/media', data);
      (body.notes || []).forEach(say);
      say('Uploaded.');
      await load();
      await open(editing.id);
    } catch (e) {
      say(explain(e, 'That file was not accepted.'));
    } finally {
      busy = false; render();
    }
  }

  function choosePoster() {
    if (!editing || !editing.id) { say('Save the video first.'); return; }
    if (typeof window.kbbPickMedia !== 'function') {
      say('The Media Library is not loaded on this page.');
      return;
    }

    window.kbbPickMedia({
      /* The library's own folder for these, NOT 'ugc'. /uploads/ugc/ is this
         module's directory — the one UgcPath::stored() allows and
         UgcMedia::forget() deletes from — and a library upload landing in it
         would put files this module may delete beside files it owns. */
      folder: 'posters',
      title: 'Choose a poster',
      note: 'The still the tile shows before anything moves — and all it shows when there is no teaser.',
      onPick: function (urls) {
        /* onPick always receives an ARRAY, even for a single-select call —
           media-picker.blade.php says so in its own docblock, so a caller
           cannot be written against the wrong shape and work by accident. */
        if (!urls || !urls.length) return;
        adoptPoster(urls[0]);
      }
    });
  }

  async function adoptPoster(url) {
    busy = true; render();
    try {
      await api('/ugc-videos/' + encodeURIComponent(editing.id) + '/poster', { url: url });
      say('Poster set.');
      await load();
      await open(editing.id);
    } catch (e) {
      say(explain(e, 'That picture could not be used as a poster.'));
    } finally {
      busy = false; render();
    }
  }

  async function derive() {
    if (!editing || !editing.id) return;
    busy = true; render();
    try {
      var body = await api('/ugc-videos/' + encodeURIComponent(editing.id) + '/derive', {});
      (body.notes || []).forEach(say);
      if (!(body.notes || []).length) say('Poster and teaser cut.');
      await load();
      await open(editing.id);
    } catch (e) {
      say(explain(e, 'Nothing could be cut from that clip.'));
    } finally {
      busy = false; render();
    }
  }

  async function search() {
    try {
      var body = await api('/ugc-videos/products?q=' + encodeURIComponent(term));
      results = body.products || [];
    } catch (e) {
      results = [];
      say(explain(e, 'Products could not be searched.'));
    }
    render();
  }

  /* ------------------------------------------------------------------ paint */

  function pill(text, tone) {
    return '<span class="ugs-pill' + (tone ? ' is-' + tone : '') + '">' + esc(text) + '</span>';
  }

  function stateWords(v) {
    if (v.media_state === 'ready') return ['loops a teaser', 'live'];
    if (v.media_state === 'poster_only') return ['poster only', 'soft'];
    return ['no video yet', 'hold'];
  }

  function rowHTML(v) {
    var state = stateWords(v);

    return '<div class="ugs-row">'
      + '<div class="ugs-thumb">'
      +   (v.poster_path
            ? '<img src="' + esc(v.poster_path) + '" alt="">'
            : 'no<br>poster')
      + '</div>'
      + '<div>'
      +   '<div class="ugs-rowname">' + esc(v.title || '(untitled)') + '</div>'
      +   '<div class="ugs-rowmeta">'
      /* bdi, not span: "@layla.skin" inside an Arabic title renders as
         "layla.skin@" without it. §5 of the plan, and it broke in the
         previews before it was fixed. */
      +     (v.creator_handle ? '<bdi>' + esc(v.creator_handle) + '</bdi> · ' : '')
      +     esc(v.products_count) + ' product' + (v.products_count === 1 ? '' : 's')
      +     (v.bytes ? ' · ' + esc(kb(v.bytes)) : '')
      +   '</div>'
      +   '<div class="ugs-pills">'
      +     pill(v.status === 'publish' ? 'published' : 'draft', v.status === 'publish' ? 'live' : '')
      +     pill('rights: ' + v.rights_status, v.rights_status === 'granted' ? 'live' : 'hold')
      +     pill(state[0], state[1])
      +     (v.locale ? pill(v.locale === 'ar' ? 'Arabic shop only' : 'English shop only') : pill('both shops'))
      +   '</div>'
      +   '<div class="ugs-rowacts">'
      +     '<button class="ugs-mini" data-ugs-open="' + esc(v.id) + '">Edit</button>'
      +     '<button class="ugs-mini" data-ugs-del="' + esc(v.id) + '" data-ugs-delname="'
      +        esc(v.title || '(untitled)') + '">Delete</button>'
      +   '</div>'
      + '</div>'
      + '</div>';
  }

  function transcoderNote() {
    if (!transcoder) return '';

    if (transcoder.available) {
      return '<div class="ugs-note"><b>This server can cut the poster and the teaser for you.</b> '
        + 'Upload one video file and nothing else: a poster frame and a '
        + esc(transcoder.teaser_seconds) + '-second silent loop at '
        + esc(transcoder.teaser_size) + ' are made from it on the same request.</div>';
    }

    return '<div class="ugs-note is-warm"><b>This server has no ffmpeg, so it cannot cut anything.</b> '
      + 'Upload the video, then upload a <b>poster image</b> — that is all a video needs to be published. '
      + 'A teaser is optional: without one the tile shows its poster instead of a short loop, which is '
      + 'about 22 KB a tile rather than 130 KB and is exactly what the shop already does for anyone '
      + 'browsing in data-saver mode.</div>';
  }

  function selectHTML(name, options, current, blankLabel) {
    var out = '<select data-ugs-field="' + esc(name) + '">';
    if (blankLabel) {
      out += '<option value=""' + (current ? '' : ' selected') + '>' + esc(blankLabel) + '</option>';
    }
    (options || []).forEach(function (o) {
      out += '<option value="' + esc(o) + '"' + (String(current) === String(o) ? ' selected' : '') + '>'
           + esc(o) + '</option>';
    });
    return out + '</select>';
  }

  function arabicBox(field, label, type) {
    if (!window.KBBArabic) return '';
    var shape = (editing && editing.translations) || translatable;
    return window.KBBArabic.boxIf(shape, {
      field: field, label: label, type: type || 'text',
      prefill: (editing && editing.translations) || null,
      maxlength: field === 'caption' ? 2000 : 180,
      rows: 3
      /* NO `from`, so no Translate button is drawn for either field — and
         that is §5's decision rather than an omission: a creator's caption is
         her own voice, and a machine-translated caption attributed to a named
         person is putting words in her mouth. Type it, or leave the English
         and set "Show on" to the English shop. */
    });
  }

  /*
   * A file input for the CLIP and the TEASER only, and it carries an `accept`
   * that says what it is.
   *
   * THE POSTER HAS NO FILE INPUT AT ALL — see posterHTML() below. The owner's
   * rule is that the Media Library must be offered for any media upload in the
   * back office, AdminMediaPickerEverywhereTest enforces it by scanning these
   * files for <input type="file">, and it caught this screen's first draft.
   * A video is excluded from that rule by what it accepts: the library is an
   * image library, and a 64 MB clip has no business in it.
   */
  function uploadHTML(kind, label, note, path, bytes) {
    return '<div class="ugs-up">'
      + '<div class="ugs-uph"><b>' + esc(label) + '</b><span>' + esc(kb(bytes)) + '</span></div>'
      + '<p class="ugs-help">' + note + '</p>'
      + (path ? '<p class="ugs-help">Stored: <code>' + esc(path) + '</code></p>' : '')
      + '<input type="file" accept="video/mp4,video/webm" data-ugs-upload="' + esc(kind) + '">'
      + '</div>';
  }

  /* The poster, from the Media Library and from nowhere else. Its own Upload
     new is how a picture that is not in the library yet gets there, so nothing
     is lost by not having a second file input here. */
  function posterHTML(v) {
    return '<div class="ugs-up">'
      + '<div class="ugs-uph"><b>Poster image</b><span>' + esc(kb(v.poster_bytes)) + '</span></div>'
      + '<p class="ugs-help">JPG, PNG or WebP, up to ' + esc(limits ? limits.poster_mb : 4)
      +   ' MB. <b>Required to publish</b> — it is what holds the tile\'s shape before anything '
      +   'loads, and what a tile shows when there is no teaser.</p>'
      + (v.poster_path
          ? '<p class="ugs-help">Stored: <code>' + esc(v.poster_path) + '</code></p>'
          : '')
      + '<button class="ugs-btn" data-ugs-poster="1">Choose from the Media Library</button>'
      + '</div>';
  }

  function editorHTML() {
    var v = editing;
    var blockers = v._blockers || v.blockers || [];

    var html = '<div class="ugs-card">'
      + '<div class="ugs-title">' + (v.id ? 'Edit video' : 'New video') + '</div>'
      + '<p class="ugs-sub">Everything on this screen is the library. Nothing here appears on the shop '
      +   'until a rail has been chosen and built.</p>';

    if (blockers.length) {
      html += '<div class="ugs-bad" style="margin-top:12px"><b>Not publishable yet:</b><ul>'
        + blockers.map(function (b) { return '<li>' + esc(b) + '</li>'; }).join('')
        + '</ul></div>';
    }

    html += '<div id="ugs-form" class="ugs-fields">'

      + '<div class="ugs-f"><label for="ugs-title">Title</label>'
      +   '<input id="ugs-title" type="text" maxlength="180" data-ugs-field="title" dir="auto" value="'
      +     esc(v.title) + '">'
      +   '<p class="ugs-help">Shown under the clip. Short — a rail tile is 158px wide.</p>'
      +   arabicBox('title', 'Title')
      + '</div>'

      + '<div class="ugs-f"><label for="ugs-caption">Caption</label>'
      +   '<textarea id="ugs-caption" maxlength="2000" data-ugs-field="caption" dir="auto">'
      +     esc(v.caption) + '</textarea>'
      +   '<p class="ugs-help">The creator\'s own words, if you are using them.</p>'
      +   arabicBox('caption', 'Caption', 'textarea')
      + '</div>'

      + '<div class="ugs-sec">The files</div>'
      + transcoderNote()
      + '<div class="ugs-two">'
      +   uploadHTML('clip', 'Video', 'MP4 or WebM, up to ' + esc(limits ? limits.clip_mb : 64)
          + ' MB. 720&times;1280 at about 800 kbps is the right encode.', v.file_path, v.bytes)
      +   posterHTML(v)
      + '</div>'
      + '<div class="ugs-two">'
      +   uploadHTML('teaser', 'Teaser loop (optional)', 'MP4 or WebM, up to '
          + esc(limits ? limits.teaser_mb : 8) + ' MB. 2&ndash;3 seconds, 360&times;640, no sound. '
          + 'Leave it empty and the tile shows its poster instead.', v.teaser_path, v.teaser_bytes)
      +   '<div class="ugs-up"><div class="ugs-uph"><b>Size</b><span>'
      +     (v.width && v.height ? esc(v.width + '×' + v.height) : '—') + '</span></div>'
      +     '<p class="ugs-help">Read from the poster, and used to hold the tile\'s shape so the page '
      +       'does not jump while the video loads.</p>'
      +     (transcoder && transcoder.available && v.id
             ? '<button class="ugs-btn" data-ugs-derive="1">Cut the poster and teaser again</button>'
             : '')
      +   '</div>'
      + '</div>'

      + '<div class="ugs-sec">Credit and permission</div>'
      + '<div class="ugs-two">'
      +   '<div class="ugs-f"><label for="ugs-handle">Creator handle</label>'
      +     '<input id="ugs-handle" type="text" maxlength="120" data-ugs-field="creator_handle" value="'
      +       esc(v.creator_handle) + '"></div>'
      +   '<div class="ugs-f"><label for="ugs-curl">Creator link</label>'
      +     '<input id="ugs-curl" type="text" maxlength="512" data-ugs-field="creator_url" value="'
      +       esc(v.creator_url) + '">'
      +     '<p class="ugs-help">http:// or https:// only. Anything else is dropped.</p></div>'
      + '</div>'
      + '<div class="ugs-two">'
      +   '<div class="ugs-f"><label for="ugs-plat">Where it came from</label>'
      +     selectHTML('source_platform', vocab ? vocab.platform : [], v.source_platform)
      +     '<p class="ugs-help">For the credit line only. The video is always served from this shop, '
      +       'never embedded from theirs.</p></div>'
      +   '<div class="ugs-f"><label for="ugs-surl">Original post</label>'
      +     '<input id="ugs-surl" type="text" maxlength="512" data-ugs-field="source_url" value="'
      +       esc(v.source_url) + '"></div>'
      + '</div>'
      + '<div class="ugs-two">'
      +   '<div class="ugs-f"><label for="ugs-rights">Permission</label>'
      +     selectHTML('rights_status', vocab ? vocab.rights : [], v.rights_status)
      +     '<p class="ugs-help">A video cannot be published until this says <b>granted</b>. '
      +       'One written yes from the creator is all it takes.</p></div>'
      +   '<div class="ugs-f"><label for="ugs-ev">Where the permission is recorded</label>'
      +     '<textarea id="ugs-ev" maxlength="2000" data-ugs-field="rights_evidence">'
      +       esc(v.rights_evidence) + '</textarea>'
      +     '<p class="ugs-help">A DM, an email, a signed release. Never shown on the shop.</p></div>'
      + '</div>'

      + '<div class="ugs-sec">Where and when</div>'
      + '<div class="ugs-two">'
      +   '<div class="ugs-f"><label>Status</label>'
      +     selectHTML('status', vocab ? vocab.status : [], v.status) + '</div>'
      +   '<div class="ugs-f"><label>Show on</label>'
      +     selectHTML('locale', vocab ? vocab.locale : [], v.locale, 'Both shops')
      +     '<p class="ugs-help">A clip spoken in English is not automatically right for the Arabic '
      +       'shop.</p></div>'
      + '</div>'
      + '<div class="ugs-two">'
      +   '<div class="ugs-f"><label>Order</label>'
      +     '<input type="number" min="0" max="9999" data-ugs-field="position" value="'
      +       esc(v.position) + '">'
      +     '<p class="ugs-help">Lowest first. The good one, not the new one.</p></div>'
      +   '<div class="ugs-f"><label>Publish from</label>'
      +     '<input type="datetime-local" data-ugs-field="published_at" value="'
      +       esc(v.published_at) + '">'
      +     '<p class="ugs-help">Leave it empty to publish as soon as the status says published.</p></div>'
      + '</div>'

      + '<div class="ugs-sec">Products in this video</div>'
      + '<p class="ugs-help">As many as you like — this is the thing Instagram cannot do. '
      +   'The first is the one a tile shows before anyone taps.</p>'
      + taggedHTML()
      + '<div class="ugs-f" style="margin-top:10px"><label for="ugs-search">Add a product</label>'
      /* The typed term is kept in `term` and written back here, because
         render() repaints #content wholesale: adding a product re-renders the
         list, and a box that emptied itself under a list of results nobody
         searched for reads as a bug. */
      +   '<input id="ugs-search" type="text" placeholder="Search by name" data-ugs-search="1" value="'
      +     esc(term) + '">'
      +   '<div class="ugs-results">' + resultsHTML() + '</div>'
      + '</div>'

      + '</div>'

      + '<div class="ugs-actions">'
      +   '<button class="ugs-btn is-primary" data-ugs-save="1"' + (busy ? ' disabled' : '') + '>Save</button>'
      +   '<button class="ugs-btn" data-ugs-back="1">Back to the library</button>'
      +   (v.id ? '<button class="ugs-btn is-danger" data-ugs-del="' + esc(v.id)
                + '" data-ugs-delname="' + esc(v.title || '(untitled)') + '">Delete</button>' : '')
      + '</div>'
      + '</div>';

    return html;
  }

  function taggedHTML() {
    if (!tagged.length) {
      return '<div class="ugs-empty">No products tagged yet.</div>';
    }

    return '<div class="ugs-tagged">' + tagged.map(function (t, i) {
      return '<div class="ugs-tag">'
        + '<div class="ugs-tagname">' + esc(i + 1) + '. ' + esc(t.name)
        +   (t.brand ? ' <span style="opacity:.6">· ' + esc(t.brand) + '</span>' : '')
        + '</div>'
        + '<div class="ugs-tagacts">'
        +   '<button class="ugs-mini" data-ugs-up="' + esc(i) + '"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>'
        +   '<button class="ugs-mini" data-ugs-down="' + esc(i) + '"'
        +     (i === tagged.length - 1 ? ' disabled' : '') + '>&darr;</button>'
        +   '<button class="ugs-mini" data-ugs-untag="' + esc(i) + '">Remove</button>'
        + '</div>'
        + '</div>';
    }).join('') + '</div>';
  }

  function resultsHTML() {
    if (!results.length) return '';
    return results.map(function (p) {
      return '<button class="ugs-res" data-ugs-add="' + esc(p.id) + '" data-ugs-addname="' + esc(p.name)
        + '" data-ugs-addbrand="' + esc(p.brand || '') + '">' + esc(p.name)
        + (p.brand ? ' <span style="opacity:.6">· ' + esc(p.brand) + '</span>' : '') + '</button>';
    }).join('');
  }

  function listHTML() {
    var html = '<div class="ugs-card">'
      + '<div class="ugs-title">Shoppable video</div>'
      + '<p class="ugs-sub">Creator clips with products tagged on them. Upload a video, record who made '
      +   'it and that they said yes, and tag as many products as appear in it.</p>'
      + transcoderNote()
      + '<div class="ugs-note"><b>Nothing here is on the shop yet.</b> The rail and the opened player '
      +   'are built once you have picked which of them you want — the previews are in '
      +   '<code>docs/UGC-VIDEO-PREVIEWS.html</code>. Everything you add now is waiting for that.</div>'
      + '<div class="ugs-actions"><button class="ugs-btn is-primary" data-ugs-new="1">New video</button></div>'
      + '</div>';

    if (banner) {
      html += '<div class="ugs-card"><div class="ugs-bad">' + esc(banner) + '</div></div>';
    }

    if (videos === null) {
      html += '<div class="ugs-card"><div class="ugs-empty">Reading the library…</div></div>';
    } else if (!videos.length) {
      html += '<div class="ugs-card"><div class="ugs-empty">No videos yet.</div></div>';
    } else {
      html += '<div class="ugs-grid">' + videos.map(rowHTML).join('') + '</div>';
    }

    return html;
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host) return;
    /* Only paint when this screen is the one on show. The console swaps
       #content wholesale, so a late render from an in-flight request must not
       overwrite whichever screen the owner moved to. */
    var title = document.querySelector('#ptitle');
    if (!title || title.textContent !== 'Shoppable video') return;

    host.innerHTML = '<div class="wrap ugs-wrap">'
      + (editing ? editorHTML() : listHTML())
      + '</div>';

    if (editing && window.KBBArabic) window.KBBArabic.wire(host);
  }

  /* ------------------------------------------------------------- listeners */

  var searchTimer = null;

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-ugs-open],[data-ugs-del],[data-ugs-new],'
      + '[data-ugs-save],[data-ugs-back],[data-ugs-derive],[data-ugs-untag],[data-ugs-up],'
      + '[data-ugs-down],[data-ugs-add],[data-ugs-poster]') : null;
    if (!t) return;

    if (t.hasAttribute('data-ugs-new')) { e.preventDefault(); blank(); return; }
    if (t.hasAttribute('data-ugs-open')) { e.preventDefault(); open(t.getAttribute('data-ugs-open')); return; }
    if (t.hasAttribute('data-ugs-save')) { e.preventDefault(); save(); return; }
    if (t.hasAttribute('data-ugs-back')) { e.preventDefault(); editing = null; render(); return; }
    if (t.hasAttribute('data-ugs-derive')) { e.preventDefault(); derive(); return; }
    if (t.hasAttribute('data-ugs-poster')) { e.preventDefault(); choosePoster(); return; }

    if (t.hasAttribute('data-ugs-del')) {
      e.preventDefault();
      remove(t.getAttribute('data-ugs-del'), t.getAttribute('data-ugs-delname') || '');
      return;
    }

    if (t.hasAttribute('data-ugs-untag')) {
      e.preventDefault();
      tagged.splice(Number(t.getAttribute('data-ugs-untag')), 1);
      render();
      return;
    }

    if (t.hasAttribute('data-ugs-up') || t.hasAttribute('data-ugs-down')) {
      e.preventDefault();
      var from = Number(t.getAttribute('data-ugs-up') || t.getAttribute('data-ugs-down'));
      var to = t.hasAttribute('data-ugs-up') ? from - 1 : from + 1;
      if (to < 0 || to >= tagged.length) return;
      var moved = tagged.splice(from, 1)[0];
      tagged.splice(to, 0, moved);
      render();
      return;
    }

    if (t.hasAttribute('data-ugs-add')) {
      e.preventDefault();
      var id = Number(t.getAttribute('data-ugs-add'));
      /* Already tagged is a no-op rather than a second row: the pivot has a
         unique index and a duplicate would be a constraint violation the
         operator cannot act on. */
      if (tagged.some(function (x) { return x.id === id; })) { say('Already tagged.'); return; }
      tagged.push({
        id: id,
        name: t.getAttribute('data-ugs-addname') || '',
        brand: t.getAttribute('data-ugs-addbrand') || '',
        at_ms: null
      });
      render();
      return;
    }
  });

  document.addEventListener('input', function (e) {
    if (!e.target || !e.target.hasAttribute || !e.target.hasAttribute('data-ugs-search')) return;
    term = e.target.value;
    if (searchTimer) clearTimeout(searchTimer);
    /* Debounced, and the caret is put back after the repaint: render() replaces
       #content wholesale, so the element being typed into is a new node by the
       time the answer lands. */
    searchTimer = setTimeout(function () {
      search().then(function () {
        var box = document.querySelector('[data-ugs-search]');
        if (box) { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }
      });
    }, 250);
  });

  document.addEventListener('change', function (e) {
    if (!e.target || !e.target.hasAttribute || !e.target.hasAttribute('data-ugs-upload')) return;
    upload(e.target.getAttribute('data-ugs-upload'), e.target);
  });

  addNavEntry();
})();
</script>
@endverbatim
