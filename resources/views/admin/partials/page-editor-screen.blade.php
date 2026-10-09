{{--
    Pages → User pages — the list, and the content page editor behind
    it. (Lane S9)

    THE EXACT LINE THE INTEGRATOR ADDS, in the include block of
    resources/views/admin/app.blade.php, directly under the article editor's:

        @include('admin.partials.page-editor-screen')

    Position VERIFIED by adding it there, booting the console in Chromium and
    driving the screen end to end, not reasoned about: the list drew 8 rows, Edit
    opened the editor, Save answered "Saved." and /faqs/ then served the typed
    title. It has to come after app.blade.php's FIRST <script> block, which is
    where `async function renderUserPages()` is declared at top level — a
    top-level declaration in a classic script IS a property of the global object,
    so the assignment at the foot of THIS file rebinds the very name the dispatch
    table in go() resolves on every call. app.blade.php overrides renderUsers by
    the same mechanism from its second script block, and its own comment around
    line 6826 documents it.

    (The assignment is not spelled out here on purpose: a test counts that string
    across every admin partial and requires exactly one, so that a second screen
    cannot quietly take the User pages list over. Naming it in prose would make
    this comment the second one.)

    Any position after that block works; under the post-editor include is where
    it was driven.

    WHAT WAS HERE BEFORE. renderUserPages() in app.blade.php drew a read-only
    table with one button on it:

        <button class="btn" onclick="toast('Page editor arrives with the CMS in Phase 11')">New page</button>

    So the seven content pages the whole footer links to — the privacy policy,
    the terms, delivery, returns, the FAQ, about and contact — could not be
    edited anywhere in this console. Nor could their SEO: `pages` carries the
    same {title, desc, og_image, canonical, noindex} column the other four
    tables carry, Lane S6 made the storefront read it and the sitemap honour its
    noindex, and Support\SeoAudit::scanPages() raises findings against it — with
    no box anywhere that could set or clear one. docs/SEO-PREVIEWS.html carried
    that as the one genuinely absent row in the SEO programme.

    THIS SCREEN EDITS. IT DOES NOT CREATE AND IT DOES NOT DELETE, and that is a
    finding rather than a preference: the seven pages are seven LITERAL routes in
    routes/web.php carrying ->defaults('slug', …), and the site-root catch-all
    /{slug}/ reaches PageController::post(), which queries Post and nothing else.
    A page created at any other slug is a row no request can reach. The full
    argument is in App\Http\Controllers\Admin\PageEditorApiController's header,
    and the screen says the short version where the New page button used to be
    rather than leaving the owner to wonder.

    IT ALSO SAYS WHICH ROWS THE SHOP DOES NOT SERVE, which no screen in this
    console has ever said. Store → Demo Content → Demo Pages writes four rows
    slugged about-us-demo and friends, published, and all four 404 — the address
    column here reads "No address on the shop" for every one of them instead of
    offering a View link to a 404.

    THE UNESCAPED-TABLE FIX. app.blade.php's pagesTable() interpolates ${p.name}
    straight into innerHTML. With page titles coming only from a migration and
    the WordPress importer that was a latent hole; the moment an operator can
    TYPE a title it is a live admin-side XSS. So renderUserPages is delegated to
    this file at the bottom, where every cell goes through esc(), and the server
    refuses a tag in a title as well (App\Support\PageTitle::stored()).

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file — inside a comment included — with the next
    closing one, and the whole docblock is then served to the browser as visible
    text.

    The whole body is wrapped in one so that the {{ }} inside JavaScript
    template literals is not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   Every rule is prefixed pg- and appears nowhere else in the console, so this
   file cannot restyle another screen by accident. Same discipline, and the same
   reason, as the pj- prefix on the article editor and peo- on the product one.

   SIZED WITH calc() AND grid, NEVER MEASURED. CLAUDE.md rule 4: this project
   sizes with CSS for a reason and two tests forbid the element-measuring APIs
   by name. The two-column form collapses to one at 980px with a media query,
   and nothing in the script below reads a width.
--------------------------------------------------------------------------- */
.pg-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.pg-head .pg-grow{flex:1 1 auto;min-width:0}
.pg-sub{font-size:12px;color:var(--ink-soft,#6b7280);margin:2px 0 0}
.pg-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
@media (max-width:980px){.pg-grid{grid-template-columns:minmax(0,1fr)}}
.pg-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
  border-radius:12px;padding:16px;min-width:0}
.pg-card+.pg-card{margin-top:14px}
.pg-card h3{font-size:13px;font-weight:700;margin:0 0 12px;letter-spacing:.01em}
.pg-f{margin-bottom:14px;min-width:0}
.pg-f:last-child{margin-bottom:0}
.pg-f>label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-2,#374151);
  margin-bottom:5px}
.pg-ctl{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border,#e6e6e6);
  border-radius:8px;font:inherit;font-size:13.5px;background:var(--surface,#fff);
  color:var(--ink,#111827)}
.pg-ctl:focus{outline:2px solid #94b8e6;outline-offset:-1px}
textarea.pg-ctl{resize:vertical;line-height:1.6}
.pg-hint{font-size:11px;color:var(--ink-soft,#6b7280);margin:6px 0 0;line-height:1.5}
/* The address strip. Three states, and the "no route" one is the loud one:
   it is the only thing on this screen that silently costs an indexed URL. */
.pg-addr{margin:7px 0 0;font-size:12px;line-height:1.55;padding:8px 10px;border-radius:8px;
  border:1px solid var(--border,#e6e6e6);background:var(--surface-2,#f7f8fa);word-break:break-word}
.pg-addr.is-ok{border-color:#bfe3c9;background:#f2fbf5;color:#1c6b36}
.pg-addr.is-bad{border-color:#f0c2c2;background:#fdf3f3;color:#9b1c1c}
.pg-addr b{font-weight:700}
.pg-rte{border:1px solid var(--border,#e6e6e6);border-radius:10px;overflow:hidden;min-width:0}
.pg-rte-bar{display:flex;flex-wrap:wrap;gap:3px;padding:6px;background:var(--surface-2,#f7f8fa);
  border-bottom:1px solid var(--border,#e6e6e6)}
.pg-rte-bar button{font:inherit;font-size:11.5px;font-weight:700;color:var(--ink-2,#374151);
  background:transparent;border:1px solid transparent;border-radius:6px;padding:4px 8px;cursor:pointer}
.pg-rte-bar button:hover{background:var(--surface,#fff);border-color:var(--border,#e6e6e6)}
.pg-rte-area{padding:12px;font-size:13.5px;line-height:1.7;min-height:260px;outline:0;
  background:var(--surface,#fff);color:var(--ink,#111827);overflow-wrap:break-word}
.pg-rte-area:empty:before{content:attr(data-ph);color:var(--ink-faint,#9ca3af)}
.pg-rte-area p{margin:0 0 .7em}
.pg-rte-area h2{font-size:17px;margin:.7em 0 .35em}
.pg-rte-area h3{font-size:15px;margin:.7em 0 .35em}
.pg-rte-area ul,.pg-rte-area ol{margin:0 0 .7em;padding-inline-start:1.4em}
.pg-rte-area img{max-width:100%;height:auto}
.pg-rte-foot{font-size:11px;color:var(--ink-soft,#6b7280);padding:6px 11px;
  border-top:1px solid var(--border,#e6e6e6);background:var(--surface-2,#f7f8fa)}
.pg-note{margin:10px 0 0;padding:9px 11px;border-radius:8px;font-size:12px;line-height:1.55;
  border:1px solid #f0c2c2;background:#fdf3f3;color:#9b1c1c}
.pg-note.is-ok{border-color:#bfe3c9;background:#f2fbf5;color:#1c6b36}
.pg-note.is-info{border-color:#cfd9e6;background:#f4f7fb;color:#3a4d63}
.pg-tablewrap{overflow:auto}
/* The address cell holds one of two things: a PATH, which must be allowed to
   break anywhere because /everything-under-54-aed/ is wider than the column at
   390px — and a SENTENCE, when the row has no route at all. `word-break:break-all`
   on the cell applied it to both, and measured at 390px in Chromium the sentence
   rendered as "No addres / s on the sh / op". So the rule goes on the <code> that
   carries the path, which is the only thing in here that needs it. */
.pg-slugcell{font-size:11.5px;color:var(--ink-soft,#6b7280);overflow-wrap:break-word}
.pg-slugcell code{word-break:break-all}
.pg-img{display:grid;gap:8px}
.pg-img-prev{aspect-ratio:1200/630;border-radius:9px;border:1px solid var(--border,#e6e6e6);
  background:linear-gradient(135deg,#FFF0F4,#FCE0E8);display:grid;place-items:center;
  overflow:hidden;font-size:12px;color:#9a6b78;text-align:center;padding:8px}
.pg-img-prev img{width:100%;height:100%;object-fit:cover;display:block}
.pg-cc-list{display:grid;gap:8px}
.pg-cc-item{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:8px 10px;min-width:0}
.pg-cc-item.is-off{background:var(--surface-2,#f7f8fa)}
.pg-cc-row{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center}
.pg-cc-name{flex:1 1 140px;min-width:0;font-size:13px;font-weight:700;overflow-wrap:anywhere}
.pg-cc-name small{display:block;font-weight:400;font-size:11px;color:var(--ink-soft,#6b7280)}
.pg-cc-show{display:flex;gap:6px;align-items:center;font-size:12px;font-weight:600}
.pg-cc-btn{font:inherit;font-size:12px;min-width:32px;min-height:32px;padding:4px 8px;border-radius:7px;
  border:1px solid var(--border,#e6e6e6);background:var(--surface,#fff);color:var(--ink-2,#374151);cursor:pointer}
.pg-cc-btn:disabled{opacity:.4;cursor:default}
.pg-cc-item details{margin-top:6px}
.pg-cc-item summary{cursor:pointer;font-size:12px;font-weight:600;color:var(--ink-2,#374151);padding:4px 0}
.pg-cc-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:8px}
.pg-cc-fields .pg-f{margin:0}
.pg-cc-fields .pg-cc-wide{grid-column:1/-1}
@media (max-width:600px){.pg-cc-fields{grid-template-columns:minmax(0,1fr)}}
.pg-cc-add{margin-top:10px}
@media (prefers-color-scheme: dark){
  .pg-addr.is-ok{background:#0f2a1a;color:#8fd6a6}
  .pg-addr.is-bad{background:#2a1212;color:#f0a6a6}
  .pg-note{background:#2a1212;color:#f0a6a6}
  .pg-note.is-ok{background:#0f2a1a;color:#8fd6a6}
  .pg-note.is-info{background:#16202b;color:#a9c2da}
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

  /* Every value from the server goes through this before it reaches innerHTML.
     app.blade.php's own pagesTable() did not, which was fine while page titles
     only ever came from a migration and is not fine now that one can be typed. */
  function esc(v){
    return String(v == null ? '' : v)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function $(sel){ return document.querySelector(sel); }
  function content(){ return document.querySelector('#content'); }

  /* --------------------------------------------------------------- state */

  var boot = null;    /* /page-editor-bootstrap, fetched once per page load */
  var model = null;   /* the page being edited */
  var busy = false;
  var note = null;    /* {text, ok} — the one message strip on the screen */

  /* --------------------------------------------------------------- list */

  async function list(){
    var data;

    try { data = await api('/page-editor-list'); }
    catch (e) { data = null; }

    if (!data) {
      content().innerHTML = '<div class="wrap"><div class="page-head"><h2>User pages</h2></div>'
        + '<p style="padding:24px;color:var(--sale,#c0392b)">Could not load pages.</p></div>';
      return;
    }

    var pages = data.pages || [];
    var unrouted = pages.filter(function(p){ return !p.routed; }).length;

    content().innerHTML =
      '<div class="wrap">'
      + '<div class="pg-head">'
      +   '<div class="pg-grow"><h2 style="margin:0">User pages</h2>'
      +     '<p class="pg-sub">The content pages the footer links to — delivery, returns, the FAQ, '
      +       'the terms. Open one to rewrite it, set what Google prints for it, or take it down.</p>'
      +   '</div>'
      + '</div>'

      /* WHERE THE "New page" BUTTON WAS. It called
         toast('Page editor arrives with the CMS in Phase 11') and did nothing.
         A button that cannot work is worse than a sentence that says why. */
      + '<div class="pg-note is-info" style="margin:0 0 14px">'
      +   '<b>' + pages.length + ' page' + (pages.length === 1 ? '' : 's') + ', '
      +   (boot && boot.routed_count ? boot.routed_count : data.routed_count || 0)
      +   ' of them with an address on the shop.</b> A new page cannot be added from here yet: '
      +   'a content page is served by a route that names it, so a page created without one would '
      +   'be published to nobody — it would show up in this list and 404 for every visitor. '
      +   'Ask for the route and the page together.'
      + '</div>'

      + (unrouted
          ? '<div class="pg-note" style="margin:0 0 14px"><b>' + unrouted + ' of these pages is not '
            + 'served at any address.</b> They are rows without a route — the demo pages do this — '
            + 'so they cannot be opened by a visitor or a search engine, whatever their status says. '
            + 'They can still be edited here.</div>'
          : '')

      + '<div class="card pg-tablewrap"><table><thead><tr>'
      +   '<th>Page</th><th>Address</th><th>Search engines</th><th>Status</th><th>Updated</th><th></th>'
      + '</tr></thead><tbody>'
      + (pages.length ? pages.map(function(p){
          return '<tr><td><b>' + esc(p.title) + '</b></td>'
            + '<td class="pg-slugcell">' + (p.routed
                ? '<code>' + esc(p.path) + '</code>'
                : '<span style="color:var(--sale,#c0392b)">No address on the shop</span>') + '</td>'
            + '<td>' + (p.seo_set
                ? '<span class="pill green"><span class="d"></span>set</span>'
                : '<span style="color:var(--ink-faint)">default</span>') + '</td>'
            + '<td><span class="pill ' + (p.status === 'published' ? 'green' : 'grey') + '">'
            +   '<span class="d"></span>' + esc(p.status) + '</span></td>'
            + '<td style="font-size:11.5px;color:var(--ink-soft)">' + esc(p.updated || '—') + '</td>'
            + '<td style="white-space:nowrap">'
            +   '<button class="btn ghost sm" data-pg-edit="' + esc(p.id) + '">Edit</button>'
            +   (p.routed
                  ? ' <a class="btn ghost sm" href="' + esc(p.url) + '" target="_blank" '
                    + 'rel="noopener">View</a>'
                  : '')
            + '</td></tr>';
        }).join('')
        : '<tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:34px">'
          + 'No content pages in this shop.</td></tr>')
      + '</tbody></table></div></div>';

    content().querySelectorAll('[data-pg-edit]').forEach(function(b){
      b.onclick = function(){ open(parseInt(b.dataset.pgEdit, 10)); };
    });
  }

  /* -------------------------------------------------------------- editor */

  async function open(id){
    note = null;

    content().innerHTML = '<div class="wrap"><p style="padding:24px;color:var(--ink-soft)">'
      + 'Opening…</p></div>';

    try {
      if (!boot) boot = await api('/page-editor-bootstrap');
      model = (await api('/page-editor-load/' + id)).page;
    } catch (e) {
      content().innerHTML = '<div class="wrap"><p style="padding:24px;color:var(--sale,#c0392b)">'
        + 'Could not open that page.</p></div>';
      return;
    }

    render();
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
    var seo = model.seo || {};

    content().innerHTML =
      '<div class="wrap">'
      + '<div class="pg-head">'
      +   '<button class="btn ghost" id="pg-back">← All pages</button>'
      +   '<div class="pg-grow"><h2 style="margin:0">Edit page</h2>'
      +     '<p class="pg-sub">' + (model.routed
            ? 'Saved changes are live straight away on <b>' + esc(model.path) + '</b>.'
            : 'This page has no address on the shop, so nothing here is visible to a visitor yet.')
      +     '</p></div>'
      +   (model.routed
            ? '<a class="btn ghost" href="' + esc(model.url) + '" target="_blank" rel="noopener">'
              + 'View on the shop</a>'
            : '')
      +   '<button class="btn primary" id="pg-save"' + (busy ? ' disabled' : '') + '>'
      +     (busy ? 'Saving…' : 'Save') + '</button>'
      + '</div>'
      + (note ? '<div class="pg-note' + (note.ok ? ' is-ok' : '') + '">' + esc(note.text) + '</div>' : '')
      + '<div class="pg-grid" style="margin-top:14px">'

      /* ---------------------------------------------------------- left */
      + '<div>'
      +   '<div class="pg-card">'
      +     '<div class="pg-f"><label for="pg-title">Title</label>'
      +       '<input id="pg-title" class="pg-ctl" maxlength="200" value="' + esc(model.title) + '">'
      +       '<p class="pg-hint">The heading at the top of the page, and the words Google shows '
      +         'unless you write a page title below. Plain words — a tag typed here is printed as '
      +         'text, not read as markup.</p>'
      +       ar({field:'title', label:'Title', prefill:t, from:'#pg-title'})
      +     '</div>'

      /* The address. Read-only, and the note says why — see
         PageEditorApiController's header. A slug change would 404 the page at
         BOTH addresses, which is worse than it is for an article. */
      +     '<div class="pg-f"><label>Address</label>'
      +       (model.routed
            ? '<div class="pg-addr is-ok"><b>' + esc(model.path) + '</b></div>'
              + '<p class="pg-hint">Set by the route that serves this page, so it cannot be changed '
              +   'here. To move it, add a 301 at <b>Store → SEO &amp; Meta → Redirects</b> so the '
              +   'ranking follows.</p>'
            : '<div class="pg-addr is-bad">No address on the shop</div>'
              + '<p class="pg-hint">Nothing routes to this page, so no visitor and no search engine '
              +   'can open it — whatever its status says. The demo pages are like this. It needs a '
              +   'route before it is worth publishing.</p>')
      +     '</div>'
      +   '</div>'

      + contactCard()
      +   '<div class="pg-card">'
      +     '<h3>The page</h3>'
      +     '<div class="pg-rte">'
      +       '<div class="pg-rte-bar">'
      +         '<button type="button" data-cmd="bold">B</button>'
      +         '<button type="button" data-cmd="italic"><i>I</i></button>'
      +         '<button type="button" data-cmd="formatBlock:h2">H2</button>'
      +         '<button type="button" data-cmd="formatBlock:h3">H3</button>'
      +         '<button type="button" data-cmd="formatBlock:p">P</button>'
      +         '<button type="button" data-cmd="insertUnorderedList">List</button>'
      +         '<button type="button" data-cmd="insertOrderedList">1.</button>'
      +         '<button type="button" data-cmd="createLink">Link</button>'
      +       '</div>'
      +       '<div class="pg-rte-area" id="pg-content" contenteditable="true" '
      +         'data-ph="Write the page here.">' + (model.content || '') + '</div>'
      +       '<p class="pg-rte-foot" id="pg-words">0 words</p>'
      +     '</div>'
      +     '<p class="pg-hint">Headings, lists, links and images are kept. Inline styles, '
      +       '<b>id</b> attributes, embedded videos and forms are removed when you save — the same '
      +       'rule the WordPress import applies to an imported page. Shortcodes such as '
      +       '<code>[kbb_products]</code> survive and are expanded on the shop.</p>'
      +     '<p class="pg-hint"><b>A heading ending in a question mark becomes a question Google '
      +       'can read</b>, if <b>Store → SEO &amp; Meta → Sitemap &amp; robots</b> has the FAQ '
      +       'markup switched on. Write “How long does delivery take?” as an H3 with the answer '
      +       'under it.</p>'
      +     ar({field:'content', label:'The page', prefill:t, type:'rich'})
      +   '</div>'
      + '</div>'

      /* --------------------------------------------------------- right */
      + '<div>'
      + headerCard()
      +   '<div class="pg-card">'
      +     '<h3>Publishing</h3>'
      +     '<div class="pg-f" style="margin-bottom:0"><label for="pg-status">Status</label>'
      +       '<select id="pg-status" class="pg-ctl">'
      +         (boot.statuses || ['published','draft']).map(function(s){
                  return '<option value="' + esc(s) + '"'
                    + (model.status === s ? ' selected' : '') + '>'
                    + (s === 'published' ? 'Published' : 'Draft') + '</option>';
                }).join('')
      +       '</select>'
      +       '<p class="pg-hint">A draft 404s at its own address and drops out of the sitemap. '
      +         'It is how you take a page down without losing its address — and the footer goes '
      +         'on linking to it, so a draft is a visible 404 rather than a hidden page.</p>'
      +     '</div>'
      +   '</div>'

      +   '<div class="pg-card">'
      +     '<h3>Search engines</h3>'
      /* LANE S7's live Google preview, on the fifth and last screen that has
         these boxes. Its own report said "Pages still cannot have one, because
         there is no page editor to put it in" — this is that editor. A mount
         point and nothing else: no control, no stored value, nothing added to
         the save payload below. window.kbbSeoPreview() is defined by
         resources/views/admin/partials/seo-back-office.blade.php and asks the
         server for the real emitted tag. */
      +     '<div class="pg-f" id="pg-seo-prev"></div>'
      +     '<div class="pg-f"><label for="pg-seo-title">Page title</label>'
      +       '<input id="pg-seo-title" class="pg-ctl" maxlength="200" value="'
      +         esc(seo.title || '') + '">'
      +       '<p class="pg-hint">Empty means the page’s own title, run through the site title '
      +         'template. Usually right.</p>'
      +     '</div>'
      +     '<div class="pg-f"><label for="pg-seo-desc">Meta description</label>'
      +       '<textarea id="pg-seo-desc" class="pg-ctl" rows="3" maxlength="400">'
      +         esc(seo.desc || '') + '</textarea>'
      +       '<p class="pg-hint">Empty means the shop-wide description, which every content page '
      +         'then shares — the duplicate the SEO Audit screen reports.</p>'
      +     '</div>'
      +     '<div class="pg-f"><label for="pg-seo-canonical">Canonical address</label>'
      +       '<input id="pg-seo-canonical" class="pg-ctl" maxlength="500" '
      +         'placeholder="empty means this page itself" value="' + esc(seo.canonical || '') + '">'
      +       '<p class="pg-hint">Only fill this in to point search engines at a different page. '
      +         'Only <b>https://</b>, <b>http://</b> and addresses starting with <b>/</b> are '
      +         'accepted; anything else is refused when you save.</p>'
      +     '</div>'
      +     '<div class="pg-f">'
      +       '<label for="pg-seo-image">Social image</label>'
      +       '<div class="pg-img">'
      +         '<div class="pg-img-prev" id="pg-img-prev">' + imagePreview() + '</div>'
      +         '<input id="pg-seo-image" class="pg-ctl" maxlength="500" '
      +           'placeholder="/storage/… or https://…" value="' + esc(seo.og_image || '') + '">'
      +         '<button class="btn ghost" id="pg-img-pick" type="button">Choose image</button>'
      +       '</div>'
      +       '<p class="pg-hint">The picture WhatsApp and Facebook show when this page is shared. '
      +         'Empty means the shop’s own default.</p>'
      +     '</div>'
      +     '<div class="pg-f" style="margin-bottom:0">'
      +       '<label style="display:flex;gap:8px;align-items:center">'
      +         '<input type="checkbox" id="pg-seo-noindex"' + (seo.noindex ? ' checked' : '') + '>'
      +         '<span>Ask search engines not to index this page</span>'
      +       '</label>'
      +       '<p class="pg-hint">Ticking this also takes the page out of <code>/sitemap.xml</code>, '
      +         'so the page and the sitemap say the same thing.</p>'
      +     '</div>'
      +   '</div>'
      + '</div>'
      + '</div></div>';

    wire();
  }

  /* LANE PH -- Page header. The owner: "we must should have control to display
     header or normal site banner". So the first control is that choice, worded
     as the shop setting is (Appearance -> Site layout -> Page header (brand
     design)), then this page's own picture, title and subtitle for the
     brand-page design. Every value is printed through esc(); the server
     re-checks each one (PageEditorApiController::headerLayout) and refuses a
     picture that is not an upload or an http(s) address. */
  var HEROES = [['', 'Shop setting'], ['brand', 'Brand-page design'], ['banner', 'Normal page banner (as before)']];

  function header(){ return model.header_layout || (model.header_layout = {}); }

  function headerCard(){
    var h = header();
    var shop = model.header_shop === 'banner' ? 'Normal page banner' : 'Brand-page design';
    return '<div class="pg-card" id="pg-hdr">'
      +   '<h3>Page header</h3>'
      +   '<div class="pg-f"><label for="pg-hdr-hero">Header</label>'
      +     '<select id="pg-hdr-hero" class="pg-ctl">'
      +       HEROES.map(function(o){
                return '<option value="' + esc(o[0]) + '"' + ((h.hero || '') === o[0] ? ' selected' : '') + '>'
                  + esc(o[0] === '' ? o[1] + ' (now: ' + shop + ')' : o[1]) + '</option>';
              }).join('')
      +     '</select>'
      +     '<p class="pg-hint"><b>Brand-page design</b>: the header the brand and category pages have -- '
      +       'a picture as the background with a panel holding the page title, or the no-picture look. '
      +       '<b>Normal page banner</b>: this page as before, with its header and banner from '
      +       '<b>Pages → Page header</b> and <b>Pages → Page banners</b>. <b>Shop setting</b> follows '
      +       '<b>Appearance → Site layout → Page header (brand design)</b>.</p>'
      +   '</div>'
      +   '<div class="pg-f"><label for="pg-hdr-image">Header picture</label>'
      +     '<div class="pg-img">'
      +       '<div class="pg-img-prev" id="pg-hdr-prev">' + picturePreview(h.image) + '</div>'
      +       '<input id="pg-hdr-image" class="pg-ctl" maxlength="2048" placeholder="/uploads/… or https://…" value="' + esc(h.image || '') + '">'
      +       '<button class="btn ghost" id="pg-hdr-pick" type="button">Choose image</button>'
      +       '<button class="btn ghost" id="pg-hdr-clear" type="button">Remove</button>'
      +     '</div>'
      +     '<p class="pg-hint">The brand-page design\u2019s background, 2400 × 600 or wider. Empty uses this '
      +       'page\u2019s banner picture from Pages → Page banners if it has one, else the no-picture look. '
      +       'A picture that is not on this server shows the no-picture look, never a broken image.</p>'
      +   '</div>'
      +   '<div class="pg-f"><label for="pg-hdr-title">Title in the header</label>'
      +     '<input id="pg-hdr-title" class="pg-ctl" maxlength="160" placeholder="' + esc(model.title || '') + '" value="' + esc(h.title || '') + '">'
      +     '<p class="pg-hint">Empty uses the page\u2019s Title. It is the page\u2019s heading (its one H1), '
      +       'in English; the Arabic page keeps its translated title.</p>'
      +   '</div>'
      +   '<div class="pg-f" style="margin-bottom:0"><label for="pg-hdr-sub">Subtitle</label>'
      +     '<textarea id="pg-hdr-sub" class="pg-ctl" rows="2" maxlength="300">' + esc(h.sub || '') + '</textarea>'
      +     '<p class="pg-hint">One line under the title, in English. Empty shows none.</p>'
      +   '</div>'
      + '</div>';
  }

  /* LANE CT2 -- Contact cards, on the Contact Us page only (model.contact_cards
     is null for every other page). The owner: "please add manually and give
     such blocks edits option directly in contact us page edits." Each box
     opens on what the card shows today; a box left as it is follows the shop's
     settings, a typed one is this page's own. Show is the same switch as
     Store -> Inquiries -> Contact page: ContactPage stores one list for both.
     Every value goes through esc(); the server re-checks every one
     (ContactPage::checkCards) and refuses a link that is not https://,
     http://, mailto:, tel: or a /path. Re-renders only this card, so text
     typed in the other boxes on the screen is never redrawn away. */
  var CC_NAMES = {wa: 'WhatsApp', ig: 'Instagram', email: 'Email', phone: 'Phone'};
  var CC_VALUE = {wa: 'Number shown', ig: '@handle shown', email: 'Email address shown', phone: 'Number shown'};
  var CC_SOURCE = {
    wa: 'Settings → Business → How customers reach you',
    ig: 'Store → SEO & Meta → Settings → Social profiles (else Store → Mail’s Instagram, else @kbeauty.bliss)',
    email: 'Settings → Business → How customers reach you',
    phone: 'Settings → Business → How customers reach you'
  };
  var CC_ICONS = {chat: 'Chat bubble', instagram: 'Instagram', mail: 'Envelope', phone: 'Phone', location: 'Map pin', clock: 'Clock', link: 'Link'};

  function cc(){ return model && model.contact_cards; }

  function ccCustoms(){ return cc().cards.filter(function(c){ return c.custom; }).length; }

  function contactCard(){
    var d = cc();
    if (!d) return '';
    var lim = d.limits || {};
    var cards = d.cards || [];
    var room = cards.length < d.max && ccCustoms() < d.max - 4;
    var field = function(c, i, f, label, extra){
      return '<div class="pg-f' + (extra || '') + '"><label for="pg-cc-' + i + '-' + f + '">' + esc(label) + '</label>'
        + '<input id="pg-cc-' + i + '-' + f + '" class="pg-ctl" data-cc-i="' + i + '" data-cc-f="' + f + '" maxlength="' + (lim[f] || 120) + '" value="' + esc(c[f]) + '"></div>';
    };
    return '<div class="pg-card" id="pg-cc">'
      + '<h3>Contact cards</h3>'
      + '<p class="pg-hint" style="margin:0 0 10px">The cards above this page’s text, in this order. Each box opens on what the card shows today: '
      +   'change one and the shop shows yours; leave it and the card follows the shop’s settings (and the Arabic page keeps its Arabic words). '
      +   '<b>Show</b> is the same switch as <b>Store → Inquiries → Contact page</b>. Saved with the page’s Save button.</p>'
      + '<div class="pg-cc-list">'
      + cards.map(function(c, i){
          var name = c.custom ? (c.title || 'Your card') : CC_NAMES[c.k];
          return '<div class="pg-cc-item' + (c.on ? '' : ' is-off') + '">'
            + '<div class="pg-cc-row">'
            +   '<span class="pg-cc-name">' + esc(name) + '<small>' + (c.custom ? 'Your own card' : 'From the shop’s settings') + '</small></span>'
            +   '<label class="pg-cc-show"><input type="checkbox" data-cc-i="' + i + '" data-cc-f="on"' + (c.on ? ' checked' : '') + '> Show</label>'
            +   '<button type="button" class="pg-cc-btn" data-cc-move="-1" data-cc-at="' + i + '" aria-label="Move ' + esc(name) + ' up"' + (i === 0 ? ' disabled' : '') + '>↑</button>'
            +   '<button type="button" class="pg-cc-btn" data-cc-move="1" data-cc-at="' + i + '" aria-label="Move ' + esc(name) + ' down"' + (i === cards.length - 1 ? ' disabled' : '') + '>↓</button>'
            +   (c.custom
                  ? '<button type="button" class="pg-cc-btn" data-cc-del="' + i + '">Remove</button>'
                  : '<button type="button" class="pg-cc-btn" data-cc-reset="' + i + '">Shop’s own</button>')
            + '</div>'
            + '<details data-cc-open="' + i + '"' + (c._open ? ' open' : '') + '><summary>Edit the words and the link</summary>'
            + '<div class="pg-cc-fields">'
            +   (c.custom
                  ? '<div class="pg-f"><label for="pg-cc-' + i + '-icon">Icon</label><select id="pg-cc-' + i + '-icon" class="pg-ctl" data-cc-i="' + i + '" data-cc-f="icon">'
                    + (d.icons || []).map(function(k){ return '<option value="' + esc(k) + '"' + (c.icon === k ? ' selected' : '') + '>' + esc(CC_ICONS[k] || k) + '</option>'; }).join('')
                    + '</select></div>'
                  : '')
            +   field(c, i, 'title', 'Title')
            +   field(c, i, 'note', 'Subtitle')
            +   field(c, i, 'value', c.custom ? 'Value shown' : CC_VALUE[c.k])
            +   field(c, i, 'action', 'Button text')
            +   field(c, i, 'link', 'Link', ' pg-cc-wide')
            + '</div>'
            + '<p class="pg-hint">' + (c.custom
                ? 'Title, button text and link are needed. The link: https://…, mailto:name@example.com, tel:+971… or /a-page-on-this-shop/.'
                : 'From ' + esc(CC_SOURCE[c.k]) + (c.shop && c.shop.link ? ': ' + esc(c.shop.link) + '.' : ' — not set there yet.')
                  + ' A new value carries its own link when the link box is left as it was. Links: https://…, http://…, mailto:, tel:.')
            + ' Title and subtitle up to ' + (lim.title || 60) + ' and ' + (lim.note || 120) + ' characters; on phones the subtitle is hidden.</p>'
            + '</details>'
            + '</div>';
        }).join('')
      + '</div>'
      + '<button type="button" class="btn ghost pg-cc-add" data-cc-add' + (room ? '' : ' disabled') + '>+ Add card</button>'
      + '<p class="pg-hint">Up to ' + esc(d.max) + ' cards: the four above and ' + esc(d.max - 4) + ' of your own (a map pin for the shop, the opening hours, a link…). '
      +   'Phones show them two a row, the first one across when the number is odd; computers three a row.</p>'
      + '</div>';
  }

  function ccRedraw(){
    var host = $('#pg-cc');
    if (!host) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = contactCard();
    host.replaceWith(wrap.firstChild);
    wireCards();
  }

  function wireCards(){
    var host = $('#pg-cc');
    if (!host || !cc()) return;
    var cards = cc().cards;
    var edit = function(e){
      var t = e.target;
      if (!t || !t.dataset || t.dataset.ccI === undefined) return;
      var c = cards[+t.dataset.ccI];
      if (!c) return;
      if (t.dataset.ccF === 'on') {
        c.on = t.checked;
        var item = t.closest('.pg-cc-item');
        if (item) item.classList.toggle('is-off', !c.on);
      } else {
        c[t.dataset.ccF] = t.value;
      }
    };
    host.addEventListener('input', edit);
    host.addEventListener('change', edit);
    host.querySelectorAll('details[data-cc-open]').forEach(function(dt){
      dt.addEventListener('toggle', function(){ var c = cards[+dt.dataset.ccOpen]; if (c) c._open = dt.open; });
    });
    host.addEventListener('click', function(e){
      var b = e.target && e.target.closest ? e.target.closest('button') : null;
      if (!b || !host.contains(b)) return;
      if (b.dataset.ccMove) {
        var i = +b.dataset.ccAt, j = i + (+b.dataset.ccMove);
        if (j < 0 || j >= cards.length) return;
        var x = cards[i]; cards[i] = cards[j]; cards[j] = x;
      } else if (b.dataset.ccDel !== undefined) {
        cards.splice(+b.dataset.ccDel, 1);
      } else if (b.dataset.ccReset !== undefined) {
        var r = cards[+b.dataset.ccReset];
        if (r && r.shop) ['title', 'note', 'value', 'action', 'link'].forEach(function(f){ r[f] = r.shop[f] || ''; });
      } else if (b.hasAttribute('data-cc-add')) {
        var used = {}; cards.forEach(function(c){ used[c.k] = 1; });
        var n = 1; while (used['c' + n] && n < 9) n++;
        if (used['c' + n] || cards.length >= cc().max || ccCustoms() >= cc().max - 4) return;
        cards.push({k: 'c' + n, on: true, custom: true, icon: 'link', title: '', note: '', value: '', action: 'Open', link: '', shop: null, _open: true});
      } else {
        return;
      }
      ccRedraw();
    });
  }

  function picturePreview(url){
    url = String(url || '').trim();
    var safe = /^https?:\/\//i.test(url) || (url.charAt(0) === '/' && url.charAt(1) !== '/');
    return safe ? '<img src="' + esc(url) + '" alt="">' : 'No picture';
  }

  function wireHeader(){
    var h = header();
    var hero = $('#pg-hdr-hero');
    if (hero) hero.onchange = function(){ h.hero = hero.value; };
    var img = $('#pg-hdr-image');
    var setImg = function(v){
      h.image = v;
      if (img) img.value = v;
      var prev = $('#pg-hdr-prev');
      if (prev) prev.innerHTML = picturePreview(v);
    };
    if (img) img.oninput = function(){ setImg(img.value); };
    var clear = $('#pg-hdr-clear');
    if (clear) clear.onclick = function(){ setImg(''); };
    var pick = $('#pg-hdr-pick');
    if (pick && window.kbbPickMedia) pick.onclick = function(){
      kbbPickMedia({
        title: 'Choose the header picture',
        note: 'One wide picture, the background of this page\u2019s header in the brand-page design.',
        onPick: function(urls){ setImg((urls && urls[0]) || ''); }
      });
    };
    var title = $('#pg-hdr-title');
    if (title) title.oninput = function(){ h.title = title.value; };
    var sub = $('#pg-hdr-sub');
    if (sub) sub.oninput = function(){ h.sub = sub.value; };
  }

  function imagePreview(){
    var url = String((model.seo || {}).og_image || '').trim();
    var safe = /^https?:\/\//i.test(url) || (url.charAt(0) === '/' && url.charAt(1) !== '/');
    return safe ? '<img src="' + esc(url) + '" alt="">' : 'No image — the shop’s default is used';
  }

  /* ---------------------------------------------------------------- wire */

  function wire(){
    var back = $('#pg-back');
    if (back) back.onclick = function(){ list(); };

    var save = $('#pg-save');
    if (save) save.onclick = submit;

    var title = $('#pg-title');
    if (title) title.oninput = function(){ model.title = title.value; };

    bindRte($('#pg-content'));

    /* LANE S7 — the Google-result preview, kind 'page'. Guarded, so a package
       shipped without the preview partial draws the editor it drew before
       rather than throwing on open.

       No `fallback` box is sent: a content page has no excerpt, so an empty
       description box falls through to the shop-wide default, and the preview
       answers that by reading the page itself. Sending a wrong fallback would be
       worse than sending none. */
    if (typeof window.kbbSeoPreview === 'function') {
      window.kbbSeoPreview({
        mount: '#pg-seo-prev',
        kind: 'page',
        id: model.id,
        title: '#pg-seo-title',
        description: '#pg-seo-desc',
        name: '#pg-title'
      });
    }

    var status = $('#pg-status');
    if (status) status.onchange = function(){ model.status = status.value; };

    wireHeader();
    wireCards();

    var image = $('#pg-seo-image');
    if (image) image.oninput = function(){
      model.seo = model.seo || {};
      model.seo.og_image = image.value;
      var prev = $('#pg-img-prev');
      if (prev) prev.innerHTML = imagePreview();
    };

    var pick = $('#pg-img-pick');
    if (pick && window.kbbPickMedia) pick.onclick = function(){
      kbbPickMedia({
        title: 'Choose a social image',
        note: 'One image. It is what a shared link to this page shows, and nothing on the page '
          + 'itself.',
        onPick: function(urls){
          model.seo = model.seo || {};
          model.seo.og_image = (urls && urls[0]) || '';
          var box = $('#pg-seo-image');
          if (box) box.value = model.seo.og_image;
          var prev = $('#pg-img-prev');
          if (prev) prev.innerHTML = imagePreview();
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

    var bar = area.parentNode.querySelector('.pg-rte-bar');

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

        model.content = area.innerHTML;
        words();
      };
    });

    area.addEventListener('input', function(){
      model.content = area.innerHTML;
      words();
    });
  }

  /* A word count, not a measurement: it reads text, never geometry. */
  function words(){
    var area = $('#pg-content');
    var out = $('#pg-words');
    if (!area || !out) return;

    var text = (area.textContent || '').replace(/\s+/g, ' ').trim();
    var n = text ? text.split(' ').length : 0;
    out.textContent = n + (n === 1 ? ' word' : ' words');
  }

  /* --------------------------------------------------------------- save */

  async function submit(){
    if (busy) return;

    busy = true; note = null;
    var save = $('#pg-save');
    if (save) { save.disabled = true; save.textContent = 'Saving…'; }

    var payload = {
      title: model.title || '',
      content: model.content || '',
      status: model.status || 'draft',
      seo: {
        title: ($('#pg-seo-title') || {}).value || '',
        desc: ($('#pg-seo-desc') || {}).value || '',
        canonical: ($('#pg-seo-canonical') || {}).value || '',
        og_image: ($('#pg-seo-image') || {}).value || '',
        noindex: !!(($('#pg-seo-noindex') || {}).checked)
      },
      translations: (window.KBBArabic ? KBBArabic.collect(content()) : {}),
      /* Lane PH: the four keys the server keeps, and nothing else. */
      header_layout: {
        hero: header().hero || '',
        image: header().image || '',
        title: header().title || '',
        sub: header().sub || ''
      }
    };

    /* Lane CT2: the contact page's cards, the fields the server keeps and
       nothing else; sent only for the page that has them. */
    if (cc()) {
      payload.contact_cards = cc().cards.map(function(c){
        return {k: c.k, on: !!c.on, icon: c.icon || '', title: c.title || '', note: c.note || '',
          value: c.value || '', action: c.action || '', link: c.link || ''};
      });
    }

    try {
      var out = await api('/page-editor-save/' + model.id, 'POST', payload);

      model = out.page;
      busy = false;
      note = {ok:true, text:'Saved.'};
      render();
    } catch (e) {
      busy = false;
      var b = e && e.body;
      note = {ok:false, text: (b && (b.message || firstError(b)))
        || 'Could not save that page.'};
      render();
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

  /* No nav entry and no screen id of its own. 'pages-user' is already in NAV and
     in app.blade.php's own dispatch table, so a second row would show the owner
     two User pages entries.

     renderUserPages IS REPLACED HERE rather than left to the integrator, and
     that is a security decision rather than a convenience: app.blade.php's
     pagesTable() interpolates a page title straight into innerHTML, which was a
     latent hole while titles only came from a migration and is a live admin XSS
     the moment this editor lets one be typed. The dispatch table in go() reads
     the global by name on every call, so this takes effect for the sidebar row
     as well as for the button above.

     Assigned once and never wrapped: it does not call through to the function it
     replaces, so applying this twice cannot produce a chain. */
  window.KBBPageEditor = { list: list, open: open };
  window.renderUserPages = list;

})();
</script>
@endverbatim
