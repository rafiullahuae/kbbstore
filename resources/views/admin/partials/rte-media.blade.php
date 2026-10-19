{{--
    Picture galleries in the admin's rich-text boxes. (16 October 2026)

    The owner: "in backend input description etc box, i need an underline
    functionality ... and also to insert media functionality too from the media
    box ... the insert images should be inserted together by selecting number of
    columns." Preview he was shown: docs/editor-media-preview/.

    ONE script for every editor. A toolbar button carrying `data-rte-media`
    (blog post, page, product description/short description, product tabs)
    opens the shared Media Library (window.kbbPickMedia, multiple, upload
    included). One picture goes in full width; several ask how many columns
    (1-4) first. It inserts

        <div class="kbb-gal kbb-gal-N"><img src="…" alt="" loading="lazy">…</div>

    at the cursor with execCommand('insertHTML'), so Undo works and the editor's
    own `input` listener sees the change. Columns are a CLASS: RichText keeps
    class and strips style, and the shop styles kbb-gal-1..4 (kbb.css) and gives
    the pictures their resized copies (App\Support\DescriptionGallery).

    Clicking a gallery in a box opens a small bar fixed to the bottom of the
    window -- columns, add pictures, remove -- placed without measuring
    anything (CLAUDE.md rule 4). Light: no request until the picker opens, no
    timer, listeners delegated once on document.

    Each editor's own toolbar handler also sees the click and runs
    execCommand('kbbMedia'), which browsers ignore; the button carries that
    name so a handler that reads data-cmd unguarded never meets `undefined`.
--}}
<style>
[contenteditable] .kbb-gal{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 .8em;outline:2px dashed transparent;outline-offset:3px;border-radius:8px;cursor:pointer}
[contenteditable] .kbb-gal.kbb-gal-1{grid-template-columns:minmax(0,1fr)}
[contenteditable] .kbb-gal.kbb-gal-2{grid-template-columns:repeat(2,minmax(0,1fr))}
[contenteditable] .kbb-gal.kbb-gal-4{grid-template-columns:repeat(4,minmax(0,1fr))}
[contenteditable] .kbb-gal img{display:block;width:100%;max-width:100%;height:auto;aspect-ratio:1;object-fit:cover;border-radius:8px;margin:0}
[contenteditable] .kbb-gal.kbb-gal-1 img{aspect-ratio:auto;object-fit:contain}
[contenteditable] .kbb-gal a{display:block;border-radius:8px;overflow:hidden;box-shadow:inset 0 0 0 2px #1f7d52}
[contenteditable] .kbb-gal:hover,[contenteditable] .kbb-gal.rgm-sel{outline-color:#1f7d52}
.rgm-shade{position:fixed;inset:0;background:rgba(17,24,39,.45);display:grid;place-items:center;padding:16px;z-index:100000}
.rgm-shade[hidden],.rgm-bar[hidden]{display:none}
.rgm-dlg{background:#fff;color:#1f2430;border-radius:14px;padding:18px;width:min(420px,100%);display:grid;gap:14px;box-shadow:0 24px 60px rgba(0,0,0,.3);font:inherit}
.rgm-dlg h3{margin:0;font-size:16px}
.rgm-dlg p{margin:0;font-size:13px;color:#6b7280}
.rgm-seg{display:inline-flex;border:1px solid #e5e7eb;border-radius:9px;overflow:hidden}
.rgm-seg button{border:0;border-right:1px solid #e5e7eb;background:#fff;color:#1f2430;padding:8px 14px;font:inherit;font-weight:700;cursor:pointer}
.rgm-seg button:last-child{border-right:0}
.rgm-seg button.on{background:#1f7d52;color:#fff}
.rgm-mini{display:grid;gap:3px;grid-template-columns:repeat(var(--rgm-c,3),18px)}
.rgm-mini i{display:block;width:18px;height:13px;border-radius:3px;background:#e5e7eb}
.rgm-acts{display:flex;justify-content:flex-end;gap:8px}
.rgm-btn{border:1px solid #e5e7eb;background:#fff;color:#1f2430;border-radius:9px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer}
.rgm-btn.pri{background:#1f7d52;border-color:#1f7d52;color:#fff}
.rgm-bar{position:fixed;left:50%;bottom:max(16px,env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:100001;display:flex;flex-wrap:wrap;align-items:center;gap:6px;background:#fff;color:#1f2430;border:1px solid #e5e7eb;border-radius:12px;padding:8px 10px;box-shadow:0 10px 30px rgba(0,0,0,.18);font:inherit;font-size:13px;max-width:calc(100vw - 24px)}
.rgm-bar b{font-weight:600;margin-right:4px}
.rgm-bar button{border:1px solid #e5e7eb;background:#fff;color:#1f2430;border-radius:8px;min-width:32px;height:32px;padding:0 10px;font:inherit;font-weight:700;cursor:pointer}
.rgm-bar button.on{background:#1f7d52;border-color:#1f7d52;color:#fff}
.rgm-bar button.rm{color:#b42318}
</style>
<script>
(function () {
  if (window.kbbRteMedia) return;
  window.kbbRteMedia = true;

  var GAL = 'kbb-gal';
  var saved = null, savedArea = null, bar = null, shade = null, current = null;

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function colsOf(g) {
    var m = /(?:^|\s)kbb-gal-([1-4])(?:\s|$)/.exec(g.className);
    return m ? Number(m[1]) : 3;
  }
  /* The box a toolbar button belongs to: the nearest ancestor that holds an
     editable area. Every editor puts its bar and its area in one wrapper. */
  function areaFor(btn) {
    for (var el = btn.parentElement; el && el !== document.body; el = el.parentElement) {
      var a = el.querySelector('[contenteditable="true"]');
      if (a) return a;
    }
    return null;
  }
  function changed(area) {
    try { area.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
  }
  function lock(area) {
    area.querySelectorAll('.' + GAL).forEach(function (g) { g.setAttribute('contenteditable', 'false'); });
  }

  /* Keep where the cursor was: the picker takes focus away. The last caret
     placed in each box is remembered as it moves (a live Range, so typing
     before it keeps it on the same words), because by the time the Media
     button is pressed the selection may already have left the box: a tap on a
     phone, or a click in the title field and back to the toolbar. */
  var lastCaret = new WeakMap();
  function boxOf(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    var a = el && el.closest ? el.closest('[contenteditable="true"]') : null;
    return a && a.isContentEditable ? a : null;
  }
  document.addEventListener('selectionchange', function () {
    var s = window.getSelection();
    if (!s || !s.rangeCount) return;
    var a = boxOf(s.anchorNode);
    if (a) lastCaret.set(a, s.getRangeAt(0).cloneRange());
  });
  document.addEventListener('mousedown', function (e) {
    var b = e.target.closest && e.target.closest('[data-rte-media]');
    if (!b) return;
    e.preventDefault();
    var area = areaFor(b), s = window.getSelection();
    savedArea = area;
    saved = area && s.rangeCount && area.contains(s.anchorNode) ? s.getRangeAt(0).cloneRange()
      : (area && lastCaret.get(area)) || null;
  }, true);

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-rte-media]');
    if (!b) return;
    e.preventDefault();
    /* Ours alone: the editor's own toolbar handler would focus its box again
       behind the open picker. Stopped here, in capture, it never runs. */
    e.stopPropagation();
    var area = areaFor(b);
    if (!area) return;
    if (savedArea !== area) saved = lastCaret.get(area) || null;
    if (typeof window.kbbPickMedia !== 'function') { window.alert('The Media Library is not available on this screen.'); return; }
    pick(function (urls) {
      if (urls.length === 1) { insert(area, urls, 1); return; }
      askCols(urls.length, function (n) { insert(area, urls, n); });
    });
  }, true);

  function pick(done) {
    window.kbbPickMedia({
      multiple: true,
      title: 'Insert pictures',
      note: 'Tick the pictures in the order you want them shown, or upload new ones.',
      folder: 'content',
      onPick: function (urls) {
        urls = (urls || []).filter(function (u) { return typeof u === 'string' && u !== ''; });
        if (urls.length) done(urls);
      }
    });
  }

  function html(urls, n) {
    return '<div class="' + GAL + ' ' + GAL + '-' + n + '" contenteditable="false">'
      + urls.map(function (u) { return '<img src="' + esc(u) + '" alt="" loading="lazy">'; }).join('')
      + '</div>';
  }

  /* WHERE A GALLERY GOES (Lane BIM, 19 October 2026). The owner: "the image
     change it's position to random ... i added the image at one place, it's
     added in some different location." The old rule climbed from the cursor
     to the box's TOP-LEVEL child and went after that. An article imported or
     pasted inside one wrapper -- <div>, <section>, <span>, the <b> Google Docs
     wraps a paste in -- has exactly one top-level child, so every picture went
     to the end of the article; a cursor between blocks (right after a gallery,
     on an empty line) climbed to nothing and went to the end too; and in a run
     of plain lines it split the sentence at the next link.

     The rule now, from the cursor:
       - inside a paragraph, heading, list, table, quote, figure or <pre>: as a
         new block straight AFTER the outermost of them (never inside a list
         item or a heading), or straight BEFORE it when the cursor is at the
         very start of one that has words in it;
       - between blocks: exactly there;
       - in plain lines (text and <br>, no paragraphs): at the end of the
         cursor's line, in the same wrapper.
     With no cursor in the box this visit (a fresh page load): at the end. */
  var HOLD = /^(P|H[1-6]|LI|UL|OL|DL|DT|DD|TABLE|THEAD|TBODY|TFOOT|TR|TD|TH|CAPTION|PRE|BLOCKQUOTE|FIGURE|FIGCAPTION|ADDRESS)$/;
  var BLOCK = /^(P|H[1-6]|UL|OL|DL|TABLE|PRE|BLOCKQUOTE|FIGURE|HR|DIV|SECTION|ARTICLE|ASIDE|HEADER|FOOTER|MAIN|NAV|ADDRESS|DETAILS)$/;
  function isGal(el) { return el.nodeType === 1 && el.classList.contains(GAL); }
  function breaks(el) {   // a line ends here: a <br> or any block
    return el && el.nodeType === 1 && (el.tagName === 'BR' || BLOCK.test(el.tagName) || isGal(el));
  }
  function spot(area, r) {
    if (!r || !area.contains(r.endContainer)) return [area, null];
    var node = r.endContainer, hold = null;
    for (var el = node; el && el !== area; el = el.parentNode) {
      if (el.nodeType === 1 && (HOLD.test(el.tagName) || isGal(el))) hold = el;
    }
    if (hold) {
      var head = document.createRange();
      head.setStart(hold, 0); head.setEnd(r.endContainer, r.endOffset);
      var atStart = !isGal(hold) && head.toString() === '' && hold.textContent.trim() !== '';
      return [hold.parentNode, atStart ? hold : hold.nextSibling, atStart];
    }
    /* Not in a block: the cursor is between children of a wrapper, or in a
       run of plain text. c is the node just before it. */
    var c;
    if (node.nodeType === 1) {
      c = node.childNodes[r.endOffset - 1] || null;
      if (!c || breaks(c)) return [node, node.childNodes[r.endOffset] || null];
    } else {
      c = node;
    }
    // Up through inline wrappers (<a>, <b>, <span>) to the one that holds lines.
    while (c.parentNode !== area && !Array.prototype.some.call(c.parentNode.childNodes, breaks)) c = c.parentNode;
    var s = c.nextSibling;
    while (s && !breaks(s)) s = s.nextSibling;
    if (s && s.tagName === 'BR') s = s.nextSibling;   // after the line's own <br>
    return [c.parentNode, s];
  }

  function insert(area, urls, n) {
    /* A gallery is a block of its own, inserted as DOM: execCommand('insertHTML')
       let Chrome pull the caret back into the last bullet first, which put it
       inside the <li>. Where: spot() above. */
    var at = spot(area, saved);
    var box = document.createElement('div');
    box.innerHTML = html(urls, n) + '<p><br></p>';
    var gal = box.firstChild, para = box.lastChild;
    at[0].insertBefore(gal, at[1]);
    // Before a paragraph: the cursor goes back to its start, no empty line.
    if (at[2]) para = at[1]; else gal.after(para);
    area.focus();
    var r = document.createRange(), s = window.getSelection();
    r.setStart(para, 0); r.collapse(true); s.removeAllRanges(); s.addRange(r);
    saved = null;
    lock(area);
    changed(area);
  }


  /* ---- how many columns ---- */
  function askCols(count, done) {
    if (!shade) {
      shade = document.createElement('div');
      shade.className = 'rgm-shade';
      shade.hidden = true;
      shade.innerHTML = '<div class="rgm-dlg" role="dialog" aria-modal="true" aria-labelledby="rgmT">'
        + '<h3 id="rgmT">Show these pictures in</h3><p id="rgmN"></p>'
        + '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><div class="rgm-seg" id="rgmSeg">'
        + [1, 2, 3, 4].map(function (k) { return '<button type="button" data-n="' + k + '">' + k + '</button>'; }).join('')
        + '</div><div class="rgm-mini" id="rgmMini"></div></div>'
        + '<p>On a phone, galleries show at most 2 pictures a row.</p>'
        + '<div class="rgm-acts"><button type="button" class="rgm-btn" data-x>Cancel</button><button type="button" class="rgm-btn pri" data-ok>Insert</button></div></div>';
      document.body.appendChild(shade);
    }
    var n = Math.min(count, 3);
    function draw() {
      shade.querySelectorAll('#rgmSeg button').forEach(function (x) { x.classList.toggle('on', Number(x.getAttribute('data-n')) === n); });
      var mini = shade.querySelector('#rgmMini');
      mini.style.setProperty('--rgm-c', n);
      mini.innerHTML = new Array(count + 1).join('<i></i>');
    }
    shade.querySelector('#rgmN').textContent = count + ' pictures, in the order you ticked them.';
    draw();
    shade.hidden = false;
    shade.onclick = function (ev) {
      var t = ev.target;
      if (t === shade || t.closest('[data-x]')) { shade.hidden = true; return; }
      var c = t.closest('[data-n]');
      if (c) { n = Number(c.getAttribute('data-n')); draw(); return; }
      if (t.closest('[data-ok]')) { shade.hidden = true; done(n); }
    };
    var ok = shade.querySelector('[data-ok]');
    if (ok) ok.focus();
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (dlg && !dlg.hidden) dlg.hidden = true;
    else if (shade && !shade.hidden) shade.hidden = true;
    else if (bar && !bar.hidden) hideBar();
  });

  /* ---- a gallery already in a box ---- */
  document.addEventListener('focusin', function (e) {
    var a = e.target;
    if (a && a.isContentEditable && a.getAttribute && a.getAttribute('contenteditable') === 'true') lock(a);
  });
  document.addEventListener('click', function (e) {
    /* A click on the bar, in the picture's dialog or in the columns question
       is theirs alone: it must not also take the Gallery bar away. Asked of
       the event's path, not contains(): the bar repaints its buttons on a
       Columns click, so by the time this runs the clicked button is no
       longer inside the bar, and the bar used to hide itself. And closing
       the dialog (Cancel, Save, the backdrop) used to hide it too, so after
       clicking a picture the Columns buttons were first under the dialog,
       then gone. */
    var path = e.composedPath ? e.composedPath() : [];
    if ((bar && (path.indexOf(bar) !== -1 || bar.contains(e.target)))
      || (dlg && path.indexOf(dlg) !== -1) || (shade && path.indexOf(shade) !== -1)) return;
    var g = e.target.closest && e.target.closest('.' + GAL);
    var area = g && g.closest('[contenteditable="true"]');
    if (!g || !area) { if (current) hideBar(); return; }
    e.preventDefault();          // a linked picture must not navigate the admin
    showBar(g, area);
    var img = e.target.closest('img');
    if (img && g.contains(img)) openPicture(img, area);
  });

  function showBar(g, area) {
    if (current && current.g !== g) current.g.classList.remove('rgm-sel');
    current = { g: g, area: area };
    g.classList.add('rgm-sel');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'rgm-bar';
      bar.setAttribute('role', 'toolbar');
      bar.setAttribute('aria-label', 'Gallery');
      document.body.appendChild(bar);
      bar.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b || !current) return;
        var cg = current.g, ca = current.area;
        if (b.hasAttribute('data-c')) {
          cg.className = cg.className.replace(/(^|\s)kbb-gal-[1-4](?=\s|$)/g, '') + ' ' + GAL + '-' + b.getAttribute('data-c');
          cg.className = cg.className.replace(/\s+/g, ' ').trim();
          paint();
          changed(ca);
        } else if (b.hasAttribute('data-add')) {
          if (typeof window.kbbPickMedia !== 'function') return;
          pick(function (urls) {
            cg.insertAdjacentHTML('beforeend', urls.map(function (u) { return '<img src="' + esc(u) + '" alt="" loading="lazy">'; }).join(''));
            changed(ca);
          });
        } else if (b.hasAttribute('data-rm')) {
          cg.remove();
          hideBar();
          changed(ca);
        } else if (b.hasAttribute('data-done')) {
          hideBar();
        }
      });
    }
    paint();
    bar.hidden = false;
  }
  function paint() {
    var c = colsOf(current.g);
    bar.innerHTML = '<b>Gallery</b><span>Columns</span>'
      + [1, 2, 3, 4].map(function (k) { return '<button type="button" data-c="' + k + '"' + (k === c ? ' class="on" aria-pressed="true"' : ' aria-pressed="false"') + '>' + k + '</button>'; }).join('')
      + '<button type="button" data-add>+ Pictures</button><button type="button" class="rm" data-rm>Remove</button><button type="button" data-done>Done</button>';
  }
  function hideBar() {
    if (current) current.g.classList.remove('rgm-sel');
    current = null;
    if (bar) bar.hidden = true;
  }

  /* ---- one picture: its link and its alt text ----
     The owner: "each image should have a separate custom link ... and the alt
     text ... when i enter the link, the alt text should be copied from the
     linked product/page title." A link is chosen from the shop's own products,
     categories, brands and articles (one request per visit, filtered here as
     he types) or pasted: a same-site path, or an https:// address, which opens
     in a new tab. Choosing a product fills the alt text with its name unless
     he has written his own; he can always edit it. */
  var targets = null, loading = null, dlg = null, autoAlt = new WeakMap(), targetsNote = '';
  function apiBase() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }
  function loadTargets() {
    if (targets) return Promise.resolve(targets);
    if (loading) return loading;
    loading = fetch(apiBase() + '/media/link-targets', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (r.ok) return r.json();
        /* A 404 with no message is this server's compiled route table not
           knowing the path yet (a package shipped without its clear_caches
           migration), not a missing product. Say which one click fixes it. */
        targetsNote = r.status === 404
          ? 'Suggestions are not available: this server\'s compiled route table does not know them yet. Clear the route cache (Platform \u2192 Cache) and reload. You can still paste an address.'
          : 'Suggestions could not be loaded. You can still paste an address.';
        return { items: [] };
      })
      .then(function (j) { targets = Array.isArray(j && j.items) ? j.items : []; return targets; })
      .catch(function () { loading = null; return []; });
    return loading;
  }
  /* What a link may be: a path on this shop, or http(s) elsewhere. */
  function cleanLink(v) {
    v = String(v || '').trim();
    if (v === '') return '';
    if (/^\/(?!\/)/.test(v)) return v;
    if (/^https?:\/\/[^\s"<>]+$/i.test(v)) return v;
    return null;
  }
  function nameFor(v) {
    v = String(v || '').trim();
    if (!targets || v === '') return '';
    var path = '';
    if (/^\/(?!\/)/.test(v)) path = v;
    else {
      try {
        var u = new URL(v), h = function (x) { return x.replace(/^www\./, ''); };
        if (h(u.host) !== h(window.location.host)) return fromAddress(v);   // another site: its own words
        path = u.pathname;
      } catch (e) { return ''; }
    }
    path = path.split(/[?#]/)[0].replace(/^\/ar(?=\/)/, '');
    if (path.slice(-1) !== '/') path += '/';
    for (var i = 0; i < targets.length; i++) if (targets[i][2] === path) return String(targets[i][1]);
    return fromAddress(v);
  }
  /* Any other address: its own words. /product/anua-heartleaf-intense-calming-
     cream/ -> "Anua Heartleaf Intense Calming Cream"; a bare site -> its name.
     Built here from the address alone: nothing is fetched from another site. */
  function fromAddress(v) {
    var host = '', path = v;
    try { var u = new URL(v, window.location.origin); host = u.host.replace(/^www\./, ''); path = u.pathname; } catch (e) {}
    var segs = path.split('/').filter(function (x) { return x !== '' && x !== 'ar'; });
    var last = segs.length ? decodeURIComponent(segs[segs.length - 1]).replace(/\.[a-z0-9]{2,5}$/i, '') : '';
    var words = last.replace(/[-_+]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (!words || /^\d+$/.test(words)) words = host;
    return words.slice(0, 160).replace(/(^|\s)(\S)/g, function (m, s, c) { return s + c.toUpperCase(); });
  }
  function own(v) {
    if (/^\/(?!\/)/.test(v)) return true;
    try { return new URL(v).host.replace(/^www\./, '') === window.location.host.replace(/^www\./, ''); } catch (e) { return false; }
  }
  function openPicture(img, area) {
    if (!dlg) {
      dlg = document.createElement('div');
      dlg.className = 'rgm-shade';
      dlg.hidden = true;
      dlg.innerHTML = '<div class="rgm-dlg" role="dialog" aria-modal="true" aria-labelledby="rgpT" style="width:min(480px,100%)">'
        + '<h3 id="rgpT">This picture</h3>'
        + '<div style="display:flex;gap:12px;align-items:flex-start"><img id="rgpImg" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:8px;flex:none">'
        + '<div style="display:grid;gap:10px;flex:1;min-width:0">'
        + '<label style="display:grid;gap:4px;font-size:13px;font-weight:600">Link (optional)<input id="rgpLink" type="text" autocomplete="off" data-1p-ignore data-lpignore="true" placeholder="Type a product, category, brand, page or article — or paste any address" style="border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font:inherit;font-weight:400"></label>'
        + '<div id="rgpSug" role="listbox" style="display:grid;gap:2px;max-height:180px;overflow:auto"></div>'
        + '<p id="rgpBad" style="color:#b42318" hidden>Use an address starting with / or https://</p>'
        + '<p id="rgpNote" style="color:#8a5a00" hidden></p>'
        + '<label style="display:grid;gap:4px;font-size:13px;font-weight:600">Alt text (for Google and screen readers)<input id="rgpAlt" type="text" maxlength="160" autocomplete="off" placeholder="What the picture shows" style="border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;font:inherit;font-weight:400"></label>'
        + '<p>Filled in for you from the link — a product, page or any address — and yours to edit. A few words with the product name are best.</p>'
        + '</div></div>'
        + '<div class="rgm-acts" style="justify-content:space-between;flex-wrap:wrap"><span style="display:flex;gap:6px"><button type="button" class="rgm-btn" data-mv="-1" title="Move earlier">&larr;</button><button type="button" class="rgm-btn" data-mv="1" title="Move later">&rarr;</button><button type="button" class="rgm-btn" data-del style="color:#b42318">Remove picture</button></span>'
        + '<span style="display:flex;gap:6px"><button type="button" class="rgm-btn" data-x>Cancel</button><button type="button" class="rgm-btn pri" data-ok>Save</button></span></div></div>';
      document.body.appendChild(dlg);
    }
    var link = dlg.querySelector('#rgpLink'), alt = dlg.querySelector('#rgpAlt'), sug = dlg.querySelector('#rgpSug'), bad = dlg.querySelector('#rgpBad');
    var a = img.parentElement && img.parentElement.tagName === 'A' ? img.parentElement : null;
    dlg.querySelector('#rgpImg').src = img.getAttribute('src') || '';
    link.value = a ? (a.getAttribute('href') || '') : '';
    alt.value = img.getAttribute('alt') || '';
    bad.hidden = true;
    sug.innerHTML = '';
    dlg.hidden = false;
    link.focus();
    loadTargets();

    function suggest() {
      var q = link.value.trim().toLowerCase();
      if (q.length < 2 || q.charAt(0) === '/' || /^https?:/.test(q) || !targets) { sug.innerHTML = ''; return; }
      var hits = [];
      for (var i = 0; i < targets.length && hits.length < 8; i++) {
        if (String(targets[i][1]).toLowerCase().indexOf(q) >= 0) hits.push(i);
      }
      sug.innerHTML = hits.map(function (i) {
        var t = targets[i];
        return '<button type="button" role="option" data-t="' + i + '" style="text-align:left;border:0;background:#f7f8fa;border-radius:7px;padding:6px 8px;font:inherit;font-size:13px;cursor:pointer"><span style="color:#6b7280;font-size:11px;text-transform:uppercase;margin-right:6px">' + esc(t[0]) + '</span>' + esc(t[1]) + '</button>';
      }).join('');
    }
    var note = dlg.querySelector('#rgpNote');
    note.hidden = true;
    /* The alt text from a pasted or typed address too, not only from a picked
       suggestion (the owner pasted https://kbeautybliss.com/product/… and the
       alt stayed empty, 16 October 2026). Only ever fills an EMPTY alt, or one
       this panel filled itself; his own words are never replaced. */
    function fillFromLink() {
      var name = nameFor(link.value);
      if (name && (alt.value.trim() === '' || alt.value === autoAlt.get(img))) { alt.value = name; autoAlt.set(img, name); }
    }
    // A picture linked before its alt was filled: fill it now, from the link.
    loadTargets().then(fillFromLink);
    link.oninput = function () {
      bad.hidden = true;
      loadTargets().then(function () { note.textContent = targetsNote; note.hidden = targetsNote === ''; suggest(); fillFromLink(); });
    };
    sug.onclick = function (ev) {
      var b = ev.target.closest('[data-t]'); if (!b) return;
      var t = targets[Number(b.getAttribute('data-t'))];
      link.value = t[2];
      sug.innerHTML = '';
      if (alt.value.trim() === '' || alt.value === autoAlt.get(img)) { alt.value = t[1]; autoAlt.set(img, t[1]); }
    };
    dlg.onclick = function (ev) {
      var t = ev.target;
      if (t === dlg || t.closest('[data-x]')) { dlg.hidden = true; return; }
      var node = a || img;
      if (t.closest('[data-mv]')) {
        var dir = Number(t.closest('[data-mv]').getAttribute('data-mv'));
        var sib = dir < 0 ? node.previousElementSibling : node.nextElementSibling;
        if (sib) { if (dir < 0) sib.before(node); else sib.after(node); changed(area); }
        return;
      }
      if (t.closest('[data-del]')) {
        var g = node.closest('.' + GAL);
        node.remove();
        if (g && !g.querySelector('img')) { g.remove(); hideBar(); }
        dlg.hidden = true; changed(area); return;
      }
      if (!t.closest('[data-ok]')) return;
      loadTargets().then(function () { fillFromLink(); save(); });
    };
    function save() {
      var href = cleanLink(link.value);
      if (href === null) { bad.hidden = false; link.focus(); return; }
      img.setAttribute('alt', alt.value.trim().slice(0, 160));
      if (href === '') {
        if (a) { a.replaceWith(img); a = null; }
      } else {
        if (!a) { a = document.createElement('a'); img.replaceWith(a); a.appendChild(img); }
        a.setAttribute('href', href);
        if (own(href)) { a.removeAttribute('target'); a.removeAttribute('rel'); }
        else { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener'); }
      }
      dlg.hidden = true;
      changed(area);
    }
  }
})();
</script>
