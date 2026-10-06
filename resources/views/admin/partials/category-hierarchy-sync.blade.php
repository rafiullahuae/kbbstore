{{--
    Catalog → Categories → "Copy hierarchy from {{ host }}"  (Lane CH)

    The owner: "i got all the categories, but parent and sub categories are
    seperated. i want the exact hirarchy which i have at kbeautybliss.com".

    A dry run first -- the tree as it would be, and how many rows get a parent,
    are already right, are not on the old site, or are on it but not here --
    then Apply. The server fetches; this screen never names a URL. If the
    server cannot reach the site, the same dry run takes the JSON saved from
    the browser or the categories CSV export.

    Every string from the source or the database goes through esc(). The only
    Blade output is the configured host, a validated hostname.
--}}
<style>
.chs-box{width:min(780px,100%)!important;max-height:calc(100vh - 32px);overflow:auto}
.chs-counts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:0 0 12px}
.chs-count{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:9px 11px;min-width:0}
.chs-count b{display:block;font-size:20px;line-height:1.1}
.chs-count span{font-size:12px;color:var(--ink-soft,#6b7280)}
.chs-tree{border:1px solid var(--border,#e6e6e6);border-radius:10px;max-height:min(52vh,520px);overflow:auto;padding:4px 0}
.chs-row{display:flex;gap:8px;align-items:baseline;padding:4px 10px;font-size:13px;min-width:0}
.chs-row .n{min-width:0;overflow-wrap:anywhere}
.chs-tag{flex:0 0 auto;font-size:10.5px;border-radius:999px;padding:1px 7px;border:1px solid currentColor}
.chs-moves{color:#047857}.chs-right{color:#6b7280}.chs-unmatched{color:#b45309}
.chs-list{font-size:12.5px;margin:6px 0 0;padding-left:18px;max-height:180px;overflow:auto;overflow-wrap:anywhere}
.chs-box details{margin-top:10px}.chs-box summary{cursor:pointer;font-size:13px;font-weight:600}
.chs-err{background:#fef2f2;color:#991b1b;border-radius:10px;padding:10px 12px;font-size:13px;margin:0 0 10px;overflow-wrap:anywhere}
.chs-ok{background:#ecfdf5;color:#065f46;border-radius:10px;padding:10px 12px;font-size:13px;margin:0 0 10px}
.chs-p{font-size:13px;line-height:1.5;margin:0 0 10px;overflow-wrap:anywhere}
.chs-file{display:block;max-width:100%;margin:6px 0 0;font-size:13px}
</style>
<script>
(function(){
  var HOST = @json(\App\Services\CategoryHierarchy\HierarchySource::host());
  var box = null, token = null, onDone = null;

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function cookie(n){ var m = document.cookie.match('(?:^|; )' + n + '=([^;]*)'); return m ? decodeURIComponent(m[1]) : ''; }
  function endpoint(p){ return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api' + p; }

  async function post(path, body){
    var headers = {'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN')};
    if (!(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    var r = await fetch(endpoint(path), {method: 'POST', credentials: 'same-origin', headers: headers, body: body});
    var j = null; try { j = await r.json(); } catch (e) {}
    if (!r.ok || !j || !j.ok) {
      var msg = (j && (j.error || j.message)) || ('Request failed (' + r.status + ')');
      if (r.status === 403) msg = 'Your account cannot change categories.';
      /* A 404 with no message of its own is the server's compiled route table
         predating this screen's two routes, not a missing category list. */
      if (r.status === 404 && !(j && (j.error || j.message))) {
        msg = 'This server is still using an old compiled route table, so it does not know this screen yet. Open Platform → Cache, press Clear, then try again.';
      }
      throw new Error(msg);
    }
    return j;
  }

  function close(){ if (box && box.parentNode) box.parentNode.removeChild(box); box = null; token = null; }

  function show(html){
    if (!box) {
      box = document.createElement('div');
      box.className = 'ct-modal';
      box.addEventListener('click', function(ev){ if (ev.target === box) close(); });
      document.body.appendChild(box);
    }
    box.innerHTML = '<div class="ct-modal-box chs-box" role="dialog" aria-label="Copy category hierarchy">'
      + '<div class="ct-modal-h"><b>Copy hierarchy from ' + esc(HOST) + '</b><button class="ct-x" data-chs-close aria-label="Close">✕</button></div>'
      + html + '</div>';
    box.querySelectorAll('[data-chs-close]').forEach(function(b){ b.onclick = close; });
  }

  function uploadBlock(){
    return '<details' + (token ? '' : ' open') + '><summary>Or use a saved file</summary>'
      + '<p class="chs-p" style="margin-top:8px">Open <b>https://' + esc(HOST) + '/wp-json/wp/v2/product_cat?per_page=100</b> in your browser and save the page (add <b>&amp;page=2</b>, <b>&amp;page=3</b>… if you have more than 100 categories), '
      + 'or use the categories CSV export. Up to 10 files, 2 MB each, .json or .csv.</p>'
      + '<input type="file" id="chs-file" class="chs-file" accept=".json,.csv,.txt,application/json,text/csv" multiple>'
      + '<div class="ct-modal-f" style="justify-content:flex-start"><button class="ct-btn" id="chs-upload">Dry run from file</button></div></details>';
  }

  function intro(err){
    show((err ? '<div class="chs-err">' + esc(err) + '</div>' : '')
      + '<p class="chs-p">This reads the category tree from <b>' + esc(HOST) + '</b>, matches each of your categories by its slug (or its exact name), and shows what would change. '
      + 'Nothing is written until you press Apply. Category addresses stay exactly as they are; products, order and slugs are not touched.</p>'
      + '<div class="ct-modal-f" style="justify-content:flex-start"><button class="ct-btn is-primary" id="chs-fetch">Dry run from ' + esc(HOST) + '</button></div>'
      + uploadBlock());
    wire();
  }

  function count(n, label){ return '<div class="chs-count"><b>' + esc(n) + '</b><span>' + esc(label) + '</span></div>'; }

  function names(list){
    return '<ul class="chs-list">' + list.map(function(r){ return '<li>' + esc(r.name) + ' <span style="opacity:.6">' + esc(r.slug) + '</span>' + (r.text ? ' — ' + esc(r.text) : '') + '</li>'; }).join('') + '</ul>';
  }

  function plan(d, applied){
    var c = d.counts || {};
    var moves = (c.get_parent || 0) + (c.to_top || 0);
    var rows = (d.tree || []).map(function(r){
      var tag = r.state === 'moves' ? '<span class="chs-tag chs-moves">' + (applied ? 'moved' : 'gets this parent') + '</span>'
        : r.state === 'right' ? '<span class="chs-tag chs-right">already right</span>'
        : '<span class="chs-tag chs-unmatched">not on ' + esc(HOST) + '</span>';
      return '<div class="chs-row" style="padding-left:' + (10 + Math.min(r.depth, 6) * 18) + 'px">'
        + '<span class="n">' + (r.depth ? '└ ' : '') + esc(r.name) + '</span>' + tag + '</div>';
    }).join('');

    var html = (applied
        ? '<div class="chs-ok">Applied. ' + esc(applied.moved) + ' categories moved' + (applied.redirects ? ', ' + esc(applied.redirects) + ' old addresses now redirect' : '') + '. Running it again changes nothing.</div>'
        : '<p class="chs-p">Source: ' + esc(d.source) + '. Nothing has been written yet.</p>')
      + '<div class="chs-counts">'
      + count(c.get_parent || 0, applied ? 'got a parent' : 'will get a parent')
      + (c.to_top ? count(c.to_top, applied ? 'moved to the top level' : 'will move to the top level') : '')
      + count(c.already_right || 0, 'already right')
      + count(c.not_on_source || 0, 'here but not on ' + HOST)
      + count(c.not_here || 0, 'on ' + HOST + ' but not here')
      + count(c.conflicts || 0, 'conflicts (left as they are)')
      + '</div>'
      + (c.matched_by_name ? '<p class="chs-p">' + esc(c.matched_by_name) + ' matched by exact name because the slugs differ.</p>' : '')
      + '<div class="chs-tree" aria-label="Resulting tree">' + (rows || '<div class="chs-row">No categories.</div>') + '</div>'
      + ((d.conflicts || []).length ? '<details open><summary>Conflicts (' + d.conflicts.length + ')</summary>' + names(d.conflicts) + '</details>' : '')
      + ((d.not_here || []).length ? '<details><summary>On ' + esc(HOST) + ' but not here (' + d.not_here.length + ')</summary>' + names(d.not_here) + '</details>' : '')
      + ((d.not_on_source || []).length ? '<details><summary>Here but not on ' + esc(HOST) + ' (' + d.not_on_source.length + ')</summary>' + names(d.not_on_source) + '</details>' : '')
      + '<div class="ct-modal-f">'
      + (applied ? '<button class="ct-btn is-primary" data-chs-close>Done</button>'
        : '<button class="ct-btn" data-chs-close>Cancel</button>'
          + '<button class="ct-btn is-primary" id="chs-apply"' + (moves ? '' : ' disabled') + '>' + (moves ? 'Apply — move ' + moves + ' categories' : 'Nothing to change') + '</button>')
      + '</div>';
    show(html);
    wire();
  }

  async function run(fn, label){
    show('<p class="chs-p">' + esc(label) + '</p>');
    try { await fn(); } catch (e) { intro(e.message); }
  }

  function wire(){
    var f = document.getElementById('chs-fetch');
    if (f) f.onclick = function(){ run(async function(){ var d = await post('/categories/hierarchy/preview', {source: 'site'}); token = d.token; plan(d); }, 'Reading ' + HOST + '…'); };
    var u = document.getElementById('chs-upload');
    if (u) u.onclick = function(){
      var input = document.getElementById('chs-file');
      if (!input || !input.files.length) { intro('Choose a file first.'); return; }
      var fd = new FormData();
      for (var i = 0; i < input.files.length; i++) fd.append('file[]', input.files[i]);
      run(async function(){ var d = await post('/categories/hierarchy/preview', fd); token = d.token; plan(d); }, 'Reading the file…');
    };
    var a = document.getElementById('chs-apply');
    if (a) a.onclick = function(){
      var t = token;
      run(async function(){ var d = await post('/categories/hierarchy/apply', {token: t}); plan(d, d); if (onDone) onDone(); }, 'Applying…');
    };
  }

  window.kbbCatHierarchy = { open: function(done){ onDone = done || null; token = null; intro(''); } };
})();
</script>
