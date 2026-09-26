{{--
    Content → Blog Posts — the list, and the article editor behind it. (Lane J)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before </body>, so this runs once the
    console's own script has defined window.go and the design tokens this
    screen borrows. Same arrangement as admin/partials/product-editor-screen —
    that is the precedent, and this screen is the same shape of thing.

    WHAT WAS HERE BEFORE. renderPosts() in app.blade.php drew a read-only table
    and said so in its own subtitle: "Editing happens on the real page for now".
    There was no "real page". POST /admin-api/posts answered 405 — driven, not
    read — and docs/f2-operator-authored-html.md §1 records the same fact from
    the other end: no endpoint in this application writes posts.body. So an
    owner who had WRITTEN an article had nowhere to put it, and the Journal,
    whose permalink structure Phase 9 finished, served whatever the WordPress
    import or the demo seeder happened to have left behind.

    WHAT IS STILL TRUE AND IS NOT THIS SCREEN'S JOB. `PostImporter` exists and
    fills `posts` from a WordPress export (Store → Store Import / Export), and
    Store → Demo Content → Demo Blog Posts writes four samples. This screen is
    the third way in — the one for an article that was never on WordPress — and
    it changes neither of the other two.

    renderPosts() in app.blade.php now delegates here, so there is one Blog
    Posts screen and not two. The sidebar row and the TITLES entry for 'posts'
    and 'blog' are unchanged and stay in app.blade.php; this screen appends no
    nav entry of its own, for the same reason the HTML Blocks screen does not —
    'posts' is already in NAV, and a second row would show the owner two.

    THE ADDRESS BOX IS THE POINT OF THIS SCREEN, not a nicety. An article is
    served from the SITE ROOT, /{slug}/, shared with the whole storefront, and
    PageController::slugPattern() refuses a reserved first segment. An article
    slugged `about`, `wishlist`, `feed` or `concern` is a row no request can
    reach: it would look published here, appear in this very list, and 404 for
    every reader. So the address is checked AS HE TYPES, against the router's
    own pattern, and the refusal names the slug and says what to do. The server
    refuses it as well — PostEditorApiController::address() — because a check
    that only exists in a browser is a check.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file — inside a comment included — with the next
    closing one, and the whole docblock is then served to the browser as
    visible text.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Every rule is prefixed pj- and appears nowhere else in the console, so this
   file cannot restyle another screen by accident. Same discipline, and the
   same reason, as the peo- prefix on the product editor.

   SIZED WITH calc() AND grid, NEVER MEASURED. CLAUDE.md rule 4: this project
   sizes with CSS for a reason and two tests forbid the element-measuring APIs
   by name. The two-column form collapses to one at 980px with a media query,
   and nothing in the script below reads a width.
--------------------------------------------------------------------------- */
.pj-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.pj-head .pj-grow{flex:1 1 auto;min-width:0}
.pj-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:2px 0 0}
.pj-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
@media (max-width:980px){.pj-grid{grid-template-columns:minmax(0,1fr)}}
.pj-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
  border-radius:12px;padding:16px;min-width:0}
.pj-card+.pj-card{margin-top:14px}
.pj-card h3{font-size:13px;font-weight:700;margin:0 0 12px;letter-spacing:.01em}
.pj-f{margin-bottom:14px;min-width:0}
.pj-f:last-child{margin-bottom:0}
.pj-f>label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-2,#374151);
  margin-bottom:5px}
.pj-ctl{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border,#e6e6e6);
  border-radius:8px;font:inherit;font-size:13.5px;background:var(--surface,#fff);
  color:var(--ink,#111827)}
.pj-ctl:focus{outline:2px solid #94b8e6;outline-offset:-1px}
textarea.pj-ctl{resize:vertical;line-height:1.6}
.pj-hint{font-size:11px;color:var(--ink-soft,#6b7280);margin:6px 0 0;line-height:1.5}
/* The live address verdict. Three states, and the refusal is the loud one:
   it is the only thing on this screen that silently costs an indexed URL. */
.pj-addr{margin:7px 0 0;font-size:12px;line-height:1.55;padding:8px 10px;border-radius:8px;
  border:1px solid var(--border,#e6e6e6);background:var(--surface-2,#f7f8fa);word-break:break-word}
.pj-addr.is-ok{border-color:#bfe3c9;background:#f2fbf5;color:#1c6b36}
.pj-addr.is-bad{border-color:#f0c2c2;background:#fdf3f3;color:#9b1c1c}
.pj-addr b{font-weight:700}
.pj-rte{border:1px solid var(--border,#e6e6e6);border-radius:10px;overflow:hidden;min-width:0}
.pj-rte-bar{display:flex;flex-wrap:wrap;gap:3px;padding:6px;background:var(--surface-2,#f7f8fa);
  border-bottom:1px solid var(--border,#e6e6e6)}
.pj-rte-bar button{font:inherit;font-size:11.5px;font-weight:700;color:var(--ink-2,#374151);
  background:transparent;border:1px solid transparent;border-radius:6px;padding:4px 8px;cursor:pointer}
.pj-rte-bar button:hover{background:var(--surface,#fff);border-color:var(--border,#e6e6e6)}
.pj-rte-area{padding:12px;font-size:13.5px;line-height:1.7;min-height:220px;outline:0;
  background:var(--surface,#fff);color:var(--ink,#111827);overflow-wrap:break-word}
.pj-rte-area:empty:before{content:attr(data-ph);color:var(--ink-faint,#9ca3af)}
.pj-rte-area p{margin:0 0 .7em}
.pj-rte-area h2{font-size:17px;margin:.7em 0 .35em}
.pj-rte-area h3{font-size:15px;margin:.7em 0 .35em}
.pj-rte-area ul,.pj-rte-area ol{margin:0 0 .7em;padding-inline-start:1.4em}
.pj-rte-area img{max-width:100%;height:auto}
.pj-rte-foot{font-size:11px;color:var(--ink-soft,#6b7280);padding:6px 11px;
  border-top:1px solid var(--border,#e6e6e6);background:var(--surface-2,#f7f8fa)}
.pj-cover{display:grid;gap:8px}
.pj-cover-prev{aspect-ratio:16/9;border-radius:9px;border:1px solid var(--border,#e6e6e6);
  background:linear-gradient(135deg,#FFF0F4,#FCE0E8);display:grid;place-items:center;
  overflow:hidden;font-size:12px;color:#9a6b78}
.pj-cover-prev img{width:100%;height:100%;object-fit:cover;display:block}
.pj-row{display:flex;gap:8px;flex-wrap:wrap}
.pj-row>*{flex:1 1 140px;min-width:0}
.pj-note{margin:10px 0 0;padding:9px 11px;border-radius:8px;font-size:12px;line-height:1.55;
  border:1px solid #f0c2c2;background:#fdf3f3;color:#9b1c1c}
.pj-note.is-ok{border-color:#bfe3c9;background:#f2fbf5;color:#1c6b36}
.pj-tablewrap{overflow:auto}
.pj-slugcell{font-size:11.5px;color:var(--ink-soft,#6b7280);word-break:break-all}
@media (prefers-color-scheme: dark){
  .pj-addr.is-ok{background:#0f2a1a;color:#8fd6a6}
  .pj-addr.is-bad{background:#2a1212;color:#f0a6a6}
  .pj-note{background:#2a1212;color:#f0a6a6}
  .pj-note.is-ok{background:#0f2a1a;color:#8fd6a6}
}
</style>

<script>
(function(){
  'use strict';

  /* ------------------------------------------------------------------ api */

  function apiBase(){
    return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api';
  }

  function cookie(name){
    var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, method, body){
    var opts = {
      method: method || 'GET',
      headers: {'Accept':'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN')},
      credentials: 'same-origin'
    };

    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    var r = await fetch(apiBase() + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }

    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = payload;
      throw err;
    }

    return payload;
  }

  function esc(v){
    return String(v == null ? '' : v)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function $(sel){ return document.querySelector(sel); }
  function content(){ return document.querySelector('#content'); }

  /* --------------------------------------------------------------- state */

  var boot = null;      /* /post-editor-bootstrap, fetched once per page load */
  var model = null;     /* the article being edited */
  var creating = false;
  var verdict = null;   /* the last answer from /post-editor-slug */
  var slugTouched = false;
  var busy = false;
  var note = null;      /* {text, ok} — the one message strip on the screen */

  /* --------------------------------------------------------------- list */

  async function list(){
    var posts;

    try { posts = (await api('/posts')).posts; }
    catch (e) { posts = null; }

    if (posts === null) {
      content().innerHTML = '<div class="wrap"><div class="page-head"><h2>Blog Posts</h2></div>'
        + '<p style="padding:24px;color:var(--sale,#c0392b)">Could not load posts.</p></div>';
      return;
    }

    var pill = function(s){ return s === 'published' ? 'green' : 'grey'; };

    content().innerHTML =
      '<div class="wrap">'
      + '<div class="pj-head">'
      +   '<div class="pj-grow"><h2 style="margin:0">Blog Posts</h2>'
      +     '<p class="pj-sub">Every article on the Journal. Write a new one, or open one to edit '
      +       'it — the article and its Arabic are on the same screen.</p></div>'
      +   '<button class="btn primary" id="pj-new">New article</button>'
      + '</div>'
      + '<div class="card pj-tablewrap"><table><thead><tr>'
      +   '<th>Title</th><th>Address</th><th>Tag</th><th>Status</th><th>Published</th><th></th>'
      + '</tr></thead><tbody>'
      + (posts.length ? posts.map(function(p){
          return '<tr><td><b>' + esc(p.title) + '</b></td>'
            + '<td class="pj-slugcell">/' + esc(p.slug) + '/</td>'
            + '<td>' + (p.tag ? esc(p.tag) : '<span style="color:var(--ink-faint)">—</span>') + '</td>'
            + '<td><span class="pill ' + pill(p.status) + '"><span class="d"></span>'
            +   esc(p.status) + '</span></td>'
            + '<td style="font-size:11.5px;color:var(--ink-soft)">'
            +   (p.published_at ? p.published_at.slice(0,10) : '—') + '</td>'
            + '<td style="white-space:nowrap">'
            +   '<button class="btn ghost sm" data-pj-edit="' + esc(p.id) + '">Edit</button> '
            +   '<a class="btn ghost sm" href="' + appRoot() + '/' + encodeURIComponent(p.slug) + '/" '
            +     'target="_blank" rel="noopener">Preview</a>'
            + '</td></tr>';
        }).join('')
        : '<tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:34px">'
          + 'No articles yet. Press <b>New article</b> to write the first one.</td></tr>')
      + '</tbody></table></div></div>';

    var add = $('#pj-new');
    if (add) add.onclick = function(){ open(null); };

    content().querySelectorAll('[data-pj-edit]').forEach(function(b){
      b.onclick = function(){ open(parseInt(b.dataset.pjEdit, 10)); };
    });
  }

  /* The storefront's own root, derived the way the existing Preview link
     derives it: the admin lives one segment below it. */
  function appRoot(){
    return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'');
  }

  /* -------------------------------------------------------------- editor */

  async function open(id){
    note = null;
    verdict = null;
    slugTouched = false;
    creating = (id === null || id === undefined);

    content().innerHTML = '<div class="wrap"><p style="padding:24px;color:var(--ink-soft)">'
      + 'Opening…</p></div>';

    try {
      if (!boot) boot = await api('/post-editor-bootstrap');

      if (creating) {
        model = {
          id: null, slug: '', title: '', excerpt: '', body: '', cover: '',
          tag: '', author: '', status: 'draft', published_at: '', seo: {},
          translations: boot.translations
        };
      } else {
        model = (await api('/post-editor-load/' + id)).post;
      }
    } catch (e) {
      content().innerHTML = '<div class="wrap"><p style="padding:24px;color:var(--sale,#c0392b)">'
        + 'Could not open that article.</p></div>';
      return;
    }

    render();
    if (creating) check();
  }

  function ar(opts){
    /* boxIf, not box: the SERVER says which fields are translatable, off the
       model's own $translatable allowlist. A screen carrying its own list is a
       screen that offers a box the server would silently drop. */
    return (window.KBBArabic && model)
      ? KBBArabic.boxIf(model.translations, opts)
      : '';
  }

  function render(){
    var t = model.translations;

    content().innerHTML =
      '<div class="wrap">'
      + '<div class="pj-head">'
      +   '<button class="btn ghost" id="pj-back">← All articles</button>'
      +   '<div class="pj-grow"><h2 style="margin:0">'
      +     (creating ? 'New article' : 'Edit article') + '</h2>'
      +     '<p class="pj-sub">' + (creating
            ? 'Nothing is published until you press Save, and a draft is never shown on the shop.'
            : 'Saved changes are live straight away on <b>' + esc(model.path) + '</b>.') + '</p></div>'
      +   (creating ? '' : '<a class="btn ghost" href="' + esc(model.url) + '" target="_blank" '
            + 'rel="noopener">View on the shop</a>')
      +   '<button class="btn primary" id="pj-save"' + (busy ? ' disabled' : '') + '>'
      +     (busy ? 'Saving…' : 'Save') + '</button>'
      + '</div>'
      + (note ? '<div class="pj-note' + (note.ok ? ' is-ok' : '') + '">' + esc(note.text) + '</div>' : '')
      + '<div class="pj-grid" style="margin-top:14px">'

      /* ---------------------------------------------------------- left */
      + '<div>'
      +   '<div class="pj-card">'
      +     '<div class="pj-f"><label for="pj-title">Title</label>'
      +       '<input id="pj-title" class="pj-ctl" maxlength="200" value="' + esc(model.title) + '">'
      +       ar({field:'title', label:'Title', prefill:t, from:'#pj-title'})
      +     '</div>'

      /* The address. Create only — see PostEditorApiController's header for
         why an article keeps the address it was published at. */
      +     (creating
          ? '<div class="pj-f"><label for="pj-slug">Address</label>'
            + '<input id="pj-slug" class="pj-ctl" maxlength="200" placeholder="written from the title" '
            +   'value="' + esc(model.slug) + '">'
            + '<div class="pj-addr" id="pj-addr">Type a title and the address appears here.</div>'
            + '<p class="pj-hint">Articles live at the top level of the shop, so this has to be an '
            +   'address the storefront is not already using. It cannot be changed after the article '
            +   'is created.</p>'
            + '</div>'
          : '<div class="pj-f"><label>Address</label>'
            + '<div class="pj-addr is-ok"><b>' + esc(model.path) + '</b></div>'
            + '<p class="pj-hint">Set when the article was created. To move it, add a 301 at '
            +   '<b>Store → SEO &amp; Meta → Redirects</b> so the ranking follows.</p>'
            + '</div>')

      +     '<div class="pj-f"><label for="pj-excerpt">Excerpt</label>'
      +       '<textarea id="pj-excerpt" class="pj-ctl" rows="3" maxlength="500">'
      +         esc(model.excerpt || '') + '</textarea>'
      +       '<p class="pj-hint">The line under the title on the Journal index, and the description '
      +         'Google shows when no SEO description is written. Plain words — any markup here '
      +         'is printed as text.</p>'
      +       ar({field:'excerpt', label:'Excerpt', prefill:t, type:'textarea', rows:3,
                  maxlength:500, from:'#pj-excerpt'})
      +     '</div>'
      +   '</div>'

      +   '<div class="pj-card">'
      +     '<h3>The article</h3>'
      +     '<div class="pj-rte">'
      +       '<div class="pj-rte-bar">'
      +         '<button type="button" data-cmd="bold">B</button>'
      +         '<button type="button" data-cmd="italic"><i>I</i></button>'
      +         '<button type="button" data-cmd="formatBlock:h2">H2</button>'
      +         '<button type="button" data-cmd="formatBlock:h3">H3</button>'
      +         '<button type="button" data-cmd="formatBlock:p">P</button>'
      +         '<button type="button" data-cmd="insertUnorderedList">List</button>'
      +         '<button type="button" data-cmd="insertOrderedList">1.</button>'
      +         '<button type="button" data-cmd="createLink">Link</button>'
      +       '</div>'
      +       '<div class="pj-rte-area" id="pj-body" contenteditable="true" '
      +         'data-ph="Write the article here.">' + (model.body || '') + '</div>'
      +       '<p class="pj-rte-foot" id="pj-words">0 words</p>'
      +     '</div>'
      +     '<p class="pj-hint">Headings, lists, links and images are kept. Inline styles, '
      +       '<b>id</b> attributes, embedded videos and forms are removed when you save — '
      +       'the same rule the WordPress import applies to an imported article.</p>'
      +     ar({field:'body', label:'The article', prefill:t, type:'rich'})
      +   '</div>'
      + '</div>'

      /* --------------------------------------------------------- right */
      + '<div>'
      +   '<div class="pj-card">'
      +     '<h3>Publishing</h3>'
      +     '<div class="pj-f"><label for="pj-status">Status</label>'
      +       '<select id="pj-status" class="pj-ctl">'
      +         (boot.statuses || ['published','draft']).map(function(s){
                  return '<option value="' + esc(s) + '"'
                    + (model.status === s ? ' selected' : '') + '>'
                    + (s === 'published' ? 'Published' : 'Draft') + '</option>';
                }).join('')
      +       '</select>'
      +       '<p class="pj-hint">A draft is not on the Journal, not in the sitemap and 404s at its '
      +         'own address. Setting a published article back to Draft is how you take it down '
      +         'without losing its address.</p>'
      +     '</div>'
      +     '<div class="pj-f"><label for="pj-date">Published</label>'
      +       '<input id="pj-date" class="pj-ctl" type="datetime-local" value="'
      +         esc(model.published_at || '') + '">'
      +       '<p class="pj-hint">Leave it empty and publishing stamps it with now.</p>'
      +     '</div>'
      +     '<div class="pj-row">'
      +       '<div class="pj-f" style="margin-bottom:0"><label for="pj-tag">Tag</label>'
      +         '<input id="pj-tag" class="pj-ctl" maxlength="60" value="' + esc(model.tag || '') + '">'
      +       '</div>'
      +       '<div class="pj-f" style="margin-bottom:0"><label for="pj-author">Author</label>'
      +         '<input id="pj-author" class="pj-ctl" maxlength="100" value="'
      +           esc(model.author || '') + '">'
      +       '</div>'
      +     '</div>'
      +   '</div>'

      +   '<div class="pj-card">'
      +     '<h3>Cover image</h3>'
      +     '<div class="pj-cover">'
      +       '<div class="pj-cover-prev" id="pj-cover-prev">' + coverPreview() + '</div>'
      +       '<input id="pj-cover" class="pj-ctl" maxlength="500" placeholder="/storage/… or https://…" '
      +         'value="' + esc(model.cover || '') + '">'
      +       '<button class="btn ghost" id="pj-cover-pick" type="button">Choose image</button>'
      +     '</div>'
      +     '<p class="pj-hint">An image address, or empty for the Journal’s own blush '
      +       'placeholder. Only <b>https://</b>, <b>http://</b> and addresses starting with '
      +       '<b>/</b> are kept.</p>'
      +   '</div>'

      +   '<div class="pj-card">'
      +     '<h3>Search engines</h3>'
      /* LANE S7 — WHAT GOOGLE WILL ACTUALLY SHOW, above the two boxes that
         decide it. A mount point and nothing else: no control, no stored value,
         nothing added to the save payload below.

         The hints under the two boxes say "empty means the article's own title"
         and "empty means the excerpt above", which is true and still leaves the
         editor unable to see the RESULT — an empty title box publishes the
         headline through `seo_title_template`, so the tag is the headline plus
         " | K-Beauty Bliss" and the 200-character box says nothing about that.
         window.kbbSeoPreview() is defined by
         resources/views/admin/partials/seo-back-office.blade.php and asks the
         server for the real emitted tag. */
      +     '<div class="pj-f" id="pj-seo-prev"></div>'
      +     '<div class="pj-f"><label for="pj-seo-title">Page title</label>'
      +       '<input id="pj-seo-title" class="pj-ctl" maxlength="200" value="'
      +         esc((model.seo || {}).title || '') + '">'
      +       '<p class="pj-hint">Empty means the article’s own title, which is usually right.</p>'
      +     '</div>'
      +     '<div class="pj-f"><label for="pj-seo-desc">Meta description</label>'
      +       '<textarea id="pj-seo-desc" class="pj-ctl" rows="3" maxlength="400">'
      +         esc((model.seo || {}).desc || '') + '</textarea>'
      +       '<p class="pj-hint">Empty means the excerpt above.</p>'
      +     '</div>'
      +     '<div class="pj-f" style="margin-bottom:0">'
      +       '<label style="display:flex;gap:8px;align-items:center">'
      +         '<input type="checkbox" id="pj-seo-noindex"'
      +           + ((model.seo || {}).noindex ? ' checked' : '') + '>'
      +         '<span>Ask search engines not to index this article</span>'
      +       '</label>'
      +     '</div>'
      +   '</div>'
      + '</div>'
      + '</div></div>';

    wire();
  }

  function coverPreview(){
    var url = String(model.cover || '').trim();
    var safe = /^https?:\/\//i.test(url) || (url.charAt(0) === '/' && url.charAt(1) !== '/');
    return safe ? '<img src="' + esc(url) + '" alt="">' : 'No cover — the Journal draws its own';
  }

  /* ---------------------------------------------------------------- wire */

  function wire(){
    var back = $('#pj-back');
    if (back) back.onclick = function(){ if (window.go) window.go('posts'); else list(); };

    var save = $('#pj-save');
    if (save) save.onclick = submit;

    var title = $('#pj-title');
    var slug = $('#pj-slug');

    if (title) title.oninput = function(){
      model.title = title.value;
      if (creating) check();
    };

    if (slug) slug.oninput = function(){
      slugTouched = true;
      model.slug = slug.value;
      check();
    };

    bindRte($('#pj-body'));

    /* LANE S7 — the Google-result preview. `id` is null on a new article, which
       is a supported state: the preview then reads the Title and Address boxes
       on this form. Guarded, so a package shipped without the preview partial
       draws the editor it drew before rather than throwing on open. */
    if (typeof window.kbbSeoPreview === 'function') {
      window.kbbSeoPreview({
        mount: '#pj-seo-prev',
        kind: 'article',
        id: model.id || null,
        title: '#pj-seo-title',
        description: '#pj-seo-desc',
        name: '#pj-title',
        slug: '#pj-slug',
        /* The excerpt, which is what the hint under the SEO description box
           already promises ("Empty means the excerpt above") and what
           Store\PageController::post() really passes. A box on this form. */
        fallback: '#pj-excerpt'
      });
    }

    ['excerpt','tag','author','cover'].forEach(function(f){
      var el = $('#pj-' + f);
      if (!el) return;
      el.oninput = function(){
        model[f] = el.value;
        if (f === 'cover') {
          var prev = $('#pj-cover-prev');
          if (prev) prev.innerHTML = coverPreview();
        }
      };
    });

    var status = $('#pj-status');
    if (status) status.onchange = function(){ model.status = status.value; };

    var date = $('#pj-date');
    if (date) date.oninput = function(){ model.published_at = date.value; };

    var pick = $('#pj-cover-pick');
    if (pick && window.kbbPickMedia) pick.onclick = function(){
      kbbPickMedia({
        title: 'Choose a cover',
        note: 'One image. It is the photograph on the Journal card and at the top of the article.',
        onPick: function(urls){
          model.cover = (urls && urls[0]) || '';
          var box = $('#pj-cover');
          if (box) box.value = model.cover;
          var prev = $('#pj-cover-prev');
          if (prev) prev.innerHTML = coverPreview();
        }
      });
    };

    /* Idempotent, and it also reveals the Translate buttons — which are drawn
       hidden, so with no API key configured they never appear at all and every
       manual path on this screen works with no key and no bill. */
    if (window.KBBArabic) KBBArabic.wire(content());

    words();
  }

  function bindRte(area){
    if (!area) return;

    var bar = area.parentNode.querySelector('.pj-rte-bar');

    if (bar) bar.querySelectorAll('button').forEach(function(b){
      b.onclick = function(e){
        e.preventDefault();
        area.focus();

        var cmd = b.dataset.cmd;

        if (cmd.indexOf('formatBlock:') === 0) {
          document.execCommand('formatBlock', false, cmd.split(':')[1]);
        } else if (cmd === 'createLink') {
          var href = window.prompt('Link address (https://…)');
          if (href) document.execCommand('createLink', false, href);
        } else {
          document.execCommand(cmd, false, null);
        }

        model.body = area.innerHTML;
        words();
      };
    });

    area.addEventListener('input', function(){
      model.body = area.innerHTML;
      words();
    });
  }

  /* A word count, not a measurement: it reads text, never geometry. */
  function words(){
    var area = $('#pj-body');
    var out = $('#pj-words');
    if (!area || !out) return;

    var text = (area.textContent || '').replace(/\s+/g, ' ').trim();
    var n = text ? text.split(' ').length : 0;
    out.textContent = n + (n === 1 ? ' word' : ' words');
  }

  /* --------------------------------------------------- the address check */

  var checkTimer = null;

  function check(){
    if (!creating) return;

    if (checkTimer) window.clearTimeout(checkTimer);

    /* Debounced, because this asks the server on every keystroke otherwise.
       250ms is below the point anyone notices and well above a burst. */
    checkTimer = window.setTimeout(ask, 250);
  }

  async function ask(){
    var box = $('#pj-addr');
    if (!box) return;

    var title = (model.title || '').trim();
    var slug = slugTouched ? (model.slug || '').trim() : '';

    if (title === '' && slug === '') {
      verdict = null;
      box.className = 'pj-addr';
      box.textContent = 'Type a title and the address appears here.';
      return;
    }

    try {
      var body = {};
      if (title !== '') body.title = title;
      if (slug !== '') body.slug = slug;

      verdict = await api('/post-editor-slug', 'POST', body);
      paintVerdict(verdict, true);
    } catch (e) {
      verdict = (e && e.body) || null;
      paintVerdict(verdict, false);
    }
  }

  function paintVerdict(v, ok){
    var box = $('#pj-addr');
    if (!box) return;

    if (!v) {
      box.className = 'pj-addr';
      box.textContent = 'Could not check that address.';
      return;
    }

    if (ok && v.ok) {
      box.className = 'pj-addr is-ok';
      box.innerHTML = 'Will be published at <b>/' + esc(v.slug) + '/</b>'
        + (v.adjusted ? ' — tidied from what you typed, so it is an address the shop can serve.' : '');
      return;
    }

    box.className = 'pj-addr is-bad';
    box.innerHTML = esc(v.message || 'That address cannot be used.')
      + (v.suggestion ? ' Try <b>' + esc(v.suggestion) + '</b>.' : '');
  }

  /* --------------------------------------------------------------- save */

  async function submit(){
    if (busy) return;

    busy = true; note = null;
    var save = $('#pj-save');
    if (save) { save.disabled = true; save.textContent = 'Saving…'; }

    var payload = {
      title: model.title || '',
      excerpt: model.excerpt || '',
      body: model.body || '',
      cover: model.cover || '',
      tag: model.tag || '',
      author: model.author || '',
      status: model.status || 'draft',
      published_at: (model.published_at || '') || null,
      seo: {
        title: ($('#pj-seo-title') || {}).value || '',
        desc: ($('#pj-seo-desc') || {}).value || '',
        noindex: !!(($('#pj-seo-noindex') || {}).checked)
      },
      translations: (window.KBBArabic ? KBBArabic.collect(content()) : {})
    };

    if (creating && slugTouched && (model.slug || '').trim() !== '') {
      payload.slug = model.slug.trim();
    }

    try {
      var out = creating
        ? await api('/post-editor-create', 'POST', payload)
        : await api('/post-editor-save/' + model.id, 'POST', payload);

      model = out.post;
      creating = false;
      slugTouched = false;
      busy = false;
      note = {ok:true, text: out.created
        ? 'Article created at ' + model.path + '.'
        : 'Saved.'};
      render();
    } catch (e) {
      busy = false;
      var b = e && e.body;
      note = {ok:false, text: (b && (b.message || firstError(b)))
        || 'Could not save that article.'};
      render();
      if (b && b.reason) paintVerdict(b, false);
    }
  }

  function firstError(body){
    var errs = body && body.errors;
    if (!errs) return '';
    for (var k in errs) {
      if (Object.prototype.hasOwnProperty.call(errs, k) && errs[k] && errs[k][0]) return errs[k][0];
    }
    return '';
  }

  /* --------------------------------------------------------------- route */

  /* No nav entry and no screen id of its own. 'posts' and 'blog' are already in
     NAV and in TITLES, app.blade.php's renderPosts() delegates to list() here,
     and the editor is opened from a row on that list — so the crumb, the page
     title and the active sidebar row stay the ones the console already sets,
     and there is no second "Blog Posts" row for the owner to choose between. */
  window.KBBPostEditor = { list: list, open: open };

})();
</script>
@endverbatim
