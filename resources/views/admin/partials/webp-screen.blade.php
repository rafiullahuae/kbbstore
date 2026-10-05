{{--
    Content -> Media Library -> WebP images. (Lane WP)

    Included ONCE, from the end of admin/partials/media-library-screen, and
    opened by that screen's "WebP images" button. It registers no sidebar row
    and no screen id of its own, so app.blade.php is not touched: the panel
    draws into #content and its Back button returns to go('media').

    What it drives, all through /admin-api/media/webp (media.optimize):
      - the four settings: convert uploads (ON as shipped — the owner asked),
        quality, max width, keep the uploaded original;
      - the dry run, one bounded batch per request, summed here;
      - the run, the same way, until the server says `done`;
      - Undo and Remove originals, each behind a typed word.
    No timer and no polling: a batch loop runs only after a click and stops on
    `done`, on an error, or at a hard step ceiling.

    Every class is prefixed wpx- and appears nowhere else in the console, and so
    is every id anything clicks, because app.blade.php binds delegated listeners
    to bare attribute names on `document`.

    Nothing wider than its column at 390px: grids carry min-width:0 and the two
    tables scroll inside their own box.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment.
--}}
@verbatim
<style>
.wpx-wrap{display:grid;gap:16px;min-width:0}
.wpx-wrap > *{min-width:0}
.wpx-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:16px;min-width:0}
.wpx-head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;min-width:0}
.wpx-title{font-weight:650;font-size:15px}
.wpx-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin-top:3px;max-width:72ch}
.wpx-banner{border:1px solid #b4443c;color:#b4443c;border-radius:10px;padding:11px 13px;font-size:13px;margin-top:12px}
.wpx-ok{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a)}
.wpx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:10px;min-width:0;margin-top:13px}
.wpx-stat{border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:11px 12px;min-width:0}
.wpx-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.05em}
.wpx-stat b{display:block;font-size:16px;line-height:1.3;margin-top:4px;overflow-wrap:anywhere}
.wpx-fields{display:grid;gap:13px;margin-top:13px;min-width:0}
.wpx-field{display:grid;gap:4px;min-width:0}
.wpx-field label{font-size:11.5px;font-weight:650;color:var(--ink-soft,#6b7280);text-transform:uppercase;letter-spacing:.04em}
.wpx-field input,.wpx-field select{padding:8px 10px;font:inherit;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;max-width:220px;min-width:0}
.wpx-check{display:flex;gap:10px;align-items:flex-start;min-width:0}
.wpx-check input{margin-top:3px;flex:none;width:16px;height:16px}
.wpx-check span{font-size:13.5px;font-weight:600}
.wpx-note{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.5;margin-top:2px}
.wpx-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px;min-width:0}
.wpx-btn{padding:8px 13px;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;max-width:100%}
.wpx-btn.is-primary{border-color:var(--accent,#15a85a);color:var(--accent,#15a85a);font-weight:650}
.wpx-btn.is-danger{border-color:#b4443c;color:#b4443c}
.wpx-btn[disabled]{opacity:.45;cursor:default}
.wpx-table{margin-top:12px;border:1px solid var(--border,#e6e6e6);border-radius:10px;overflow-x:auto;min-width:0}
.wpx-table table{border-collapse:collapse;width:100%;font-size:12.5px}
.wpx-table th,.wpx-table td{text-align:left;padding:7px 10px;border-bottom:1px solid var(--border,#e6e6e6);white-space:nowrap}
.wpx-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft,#6b7280)}
.wpx-table td.wpx-path{white-space:normal;overflow-wrap:anywhere;min-width:180px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px}
.wpx-confirm{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}
.wpx-confirm input{padding:7px 10px;font:inherit;border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit;width:120px}
@media (max-width:640px){ .wpx-card{padding:13px} }
</style>
<script>
(function(){
  'use strict';

  var BASE = window.location.pathname.replace(/\/+$/, '');
  var state = {status: null, plan: null, busy: '', note: '', error: '', progress: ''};
  var STEP_CEILING = 4000;

  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body){
    var opts = {headers: {'Accept': 'application/json'}, credentials: 'same-origin'};
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) {
      var err = new Error((payload && payload.message) || ('Request failed (' + r.status + ')'));
      err.status = r.status;
      throw err;
    }
    return payload;
  }

  function explain(e){
    if (e && e.status === 403) return 'Your role cannot run WebP conversion. Ask the owner (it needs the "Convert images to WebP" permission).';
    if (e && e.status === 404) return 'The WebP endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    return (e && e.message) || 'Something went wrong.';
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }

  function bytes(n){
    n = Number(n) || 0;
    var a = Math.abs(n);
    if (a >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (a >= 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
  }

  function pct(before, after){
    return before > 0 ? Math.round((1 - after / before) * 100) + '%' : '—';
  }

  var REASONS = {
    webp_not_smaller: 'WebP would be larger',
    not_jpeg_or_png: 'not really a JPEG/PNG',
    too_many_pixels: 'too many pixels',
    not_enough_memory: 'too big for this server\'s memory',
    unreadable: 'unreadable',
    could_not_decode: 'could not be read',
    encode_failed: 'could not be encoded',
    write_failed: 'could not be written',
    no_free_name: 'no free file name',
    still_referenced: 'still used somewhere'
  };

  function say(msg){ try { window.toast(msg); } catch (e) {} }

  /* ------------------------------------------------------------- render */
  function root(){ return document.getElementById('wpx-root'); }

  function render(){
    var el = root();
    if (!el) return;
    var s = state.status;
    var busy = !!state.busy;

    var html = '<div class="wpx-wrap">'
      + '<div class="wpx-card"><div class="wpx-head"><div>'
      + '<div class="wpx-title">WebP images</div>'
      + '<div class="wpx-sub">WebP is the format your old shop\'s pictures are already in: the same picture, usually 25–35% smaller than a JPEG and far smaller than a PNG photo, which is faster pages and better Core Web Vitals. New JPEG and PNG uploads are converted automatically. The tools below convert the pictures already on the shop and update every place they are used.</div>'
      + '</div><button class="wpx-btn" id="wpx-back">← Media Library</button></div>';

    if (state.error) html += '<div class="wpx-banner">' + esc(state.error) + '</div>';

    if (s && !s.available) {
      html += '<div class="wpx-banner">' + esc(s.reason) + '</div>';
    }
    html += '</div>';

    if (!s) {
      el.innerHTML = html + '<div class="wpx-card"><div class="wpx-sub">Loading…</div></div></div>';
      wire();
      return;
    }

    var st = s.settings;
    var widths = [[0, 'No limit'], [1600, '1600 px'], [2000, '2000 px'], [2400, '2400 px'], [3000, '3000 px'], [4000, '4000 px']];
    if (!widths.some(function(w){ return w[0] === st.max_width; })) widths.push([st.max_width, st.max_width + ' px']);

    html += '<div class="wpx-card"><div class="wpx-title">On upload</div>'
      + '<div class="wpx-fields">'
      + '<label class="wpx-check"><input type="checkbox" id="wpx-enabled"' + (st.enabled ? ' checked' : '') + (s.available ? '' : ' disabled') + '>'
      + '<div><span>Convert JPEG and PNG uploads to WebP</span><div class="wpx-note">Only when the WebP is smaller. GIF, SVG and WebP uploads are never changed. Photos are turned upright and their camera data (location included) is removed.</div></div></label>'
      + '<div class="wpx-field"><label for="wpx-quality">Quality (40–100)</label><input type="number" id="wpx-quality" min="40" max="100" value="' + esc(st.quality) + '">'
      + '<div class="wpx-note">82 is the usual sweet spot: no visible difference on a product photo.</div></div>'
      + '<div class="wpx-field"><label for="wpx-width">Largest width</label><select id="wpx-width">'
      + widths.map(function(w){ return '<option value="' + w[0] + '"' + (w[0] === st.max_width ? ' selected' : '') + '>' + esc(w[1]) + '</option>'; }).join('')
      + '</select><div class="wpx-note">A 6000 px phone photo is shrunk to this. Smaller pictures are never enlarged.</div></div>'
      + '<label class="wpx-check"><input type="checkbox" id="wpx-keep"' + (st.keep_original ? ' checked' : '') + '>'
      + '<div><span>Also keep the uploaded JPEG/PNG</span><div class="wpx-note">Off: the original was never published, so keeping it only uses disk.</div></div></label>'
      + '</div><div class="wpx-actions"><button class="wpx-btn is-primary" id="wpx-save"' + (busy ? ' disabled' : '') + '>Save</button></div></div>';

    var c = s.counts || {};
    html += '<div class="wpx-card"><div class="wpx-title">Pictures already on the shop</div>'
      + '<div class="wpx-sub">Check first: nothing changes until you press Convert. Originals are kept, so old links (Google Images, emails already sent) keep working until you remove them.</div>'
      + '<div class="wpx-grid">'
      + stat('JPEG / PNG left to check', s.remaining + (s.truncated ? '+' : ''))
      + stat('Converted', (c.converted || 0) + (c.removed || 0))
      + stat('Left as they were', c.skipped || 0)
      + stat('Space saved', bytes(s.bytes_saved))
      + '</div>'
      + '<div class="wpx-actions">'
      + '<button class="wpx-btn" id="wpx-plan"' + (busy || !s.available || !s.remaining ? ' disabled' : '') + '>' + (state.busy === 'plan' ? 'Checking…' : 'Check what would change') + '</button>'
      + '<button class="wpx-btn is-primary" id="wpx-run"' + (busy || !s.available || !s.remaining ? ' disabled' : '') + '>' + (state.busy === 'run' ? 'Converting…' : 'Convert now') + '</button>'
      + '</div>'
      + (state.progress ? '<div class="wpx-note" style="margin-top:8px">' + esc(state.progress) + '</div>' : '')
      + planHTML()
      + '</div>';

    html += '<div class="wpx-card"><div class="wpx-title">Originals</div>'
      + '<div class="wpx-sub">' + (c.converted || 0) + ' original' + ((c.converted || 0) === 1 ? '' : 's') + ' kept beside their WebP, ' + bytes(s.originals_bytes) + '. '
      + 'Remove them when the shop looks right — after that, old .jpg/.png links stop working. Anything a review, a sent campaign or a shoppable video still uses is kept.</div>'
      + '<div class="wpx-confirm"><input id="wpx-confirm-word" placeholder="type REMOVE" autocomplete="off">'
      + '<button class="wpx-btn is-danger" id="wpx-remove"' + (busy || !c.converted ? ' disabled' : '') + '>' + (state.busy === 'remove' ? 'Removing…' : 'Remove originals') + '</button>'
      + '<input id="wpx-undo-word" placeholder="type UNDO" autocomplete="off">'
      + '<button class="wpx-btn" id="wpx-undo"' + (busy || !c.converted ? ' disabled' : '') + '>' + (state.busy === 'undo' ? 'Undoing…' : 'Undo conversion') + '</button></div>'
      + '</div>';

    html += '<div class="wpx-card"><div class="wpx-title">Log</div>' + logHTML(s.recent || []) + '</div>';

    el.innerHTML = html + '</div>';
    wire();
  }

  function stat(label, value){
    return '<div class="wpx-stat"><span>' + esc(label) + '</span><b>' + esc(value) + '</b></div>';
  }

  function planHTML(){
    var p = state.plan;
    if (!p) return '';
    var cols = Object.keys(p.columns).sort(function(a, b){ return p.columns[b] - p.columns[a]; });
    return '<div class="wpx-banner wpx-ok">Dry run' + (p.done ? '' : ' (stopped early)') + ' — nothing was changed.</div>'
      + '<div class="wpx-grid">'
      + stat('Files looked at', p.files)
      + stat('Would convert', p.convert)
      + stat('Size', bytes(p.before) + ' → ' + bytes(p.after))
      + stat('Saves', bytes(p.before - p.after) + ' (' + pct(p.before, p.after) + ')')
      + stat('References to update', p.refs + ' in ' + p.rows + ' rows')
      + '</div>'
      + (cols.length ? '<div class="wpx-table"><table><thead><tr><th>Where</th><th>References</th></tr></thead><tbody>'
        + cols.map(function(k){ return '<tr><td>' + esc(k) + '</td><td>' + esc(p.columns[k]) + '</td></tr>'; }).join('')
        + '</tbody></table></div>' : '')
      + (p.sample.length ? '<div class="wpx-table"><table><thead><tr><th>File</th><th>Now</th><th>WebP</th><th>Uses</th></tr></thead><tbody>'
        + p.sample.map(function(f){
            return '<tr><td class="wpx-path">' + esc(f.path) + '</td><td>' + bytes(f.bytes_before) + '</td><td>'
              + (f.ok ? bytes(f.bytes_after) : esc(REASONS[f.reason] || f.reason)) + '</td><td>' + esc(f.refs) + '</td></tr>';
          }).join('')
        + '</tbody></table></div>' : '');
  }

  function logHTML(rows){
    if (!rows.length) return '<div class="wpx-sub">Nothing converted yet.</div>';
    return '<div class="wpx-table"><table><thead><tr><th>File</th><th>Result</th><th>Before</th><th>After</th><th>Uses moved</th></tr></thead><tbody>'
      + rows.map(function(r){
          var result = r.status === 'skipped' || r.status === 'failed' ? (REASONS[r.reason] || r.reason || r.status)
            : (r.status === 'removed' ? 'WebP, original removed' : (r.status === 'converted' ? 'WebP' + (r.reason === 'still_referenced' ? ', original still used' : '') : r.status));
          return '<tr><td class="wpx-path">' + esc(r.to_path || r.from_path) + '</td><td>' + esc(result) + (r.origin === 'upload' ? ' · upload' : '')
            + '</td><td>' + bytes(r.bytes_before) + '</td><td>' + (r.bytes_after ? bytes(r.bytes_after) : '—') + '</td><td>' + esc(r.refs) + '</td></tr>';
        }).join('')
      + '</tbody></table></div>';
  }

  /* -------------------------------------------------------------- wiring */
  function on(id, ev, fn){ var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }

  function wire(){
    on('wpx-back', 'click', function(){ if (window.go) window.go('media'); });
    on('wpx-save', 'click', save);
    on('wpx-plan', 'click', plan);
    on('wpx-run', 'click', run);
    on('wpx-remove', 'click', remove);
    on('wpx-undo', 'click', undo);
  }

  async function load(){
    try { state.status = await api('/media/webp'); state.error = ''; }
    catch (e) { state.error = explain(e); }
    render();
  }

  async function save(){
    var body = {
      enabled: document.getElementById('wpx-enabled').checked,
      keep_original: document.getElementById('wpx-keep').checked,
      quality: parseInt(document.getElementById('wpx-quality').value, 10) || 82,
      max_width: parseInt(document.getElementById('wpx-width').value, 10) || 0
    };
    state.busy = 'save'; render();
    try { var r = await api('/media/webp/settings', body); state.status.settings = r.settings; say('Saved.'); state.error = ''; }
    catch (e) { state.error = explain(e); }
    state.busy = ''; render();
  }

  async function plan(){
    state.busy = 'plan'; state.plan = null; state.error = ''; render();
    var t = {files: 0, convert: 0, before: 0, after: 0, refs: 0, rows: 0, columns: {}, sample: [], done: false};
    var after = '', steps = 0;
    try {
      for (;;) {
        var r = await api('/media/webp/plan', {after: after});
        after = r.cursor;
        t.files += r.files.length; t.convert += r.convert;
        t.before += r.bytes_before; t.after += r.bytes_after;
        t.refs += r.references.replacements; t.rows += r.references.rows;
        Object.keys(r.references.columns).forEach(function(k){ t.columns[k] = (t.columns[k] || 0) + r.references.columns[k]; });
        if (t.sample.length < 30) t.sample = t.sample.concat(r.files).slice(0, 30);
        state.progress = 'Checked ' + t.files + ' files…'; render();
        if (r.done || !r.files.length) { t.done = true; break; }
        if (++steps > STEP_CEILING) break;
      }
    } catch (e) { state.error = explain(e); }
    state.plan = t; state.busy = ''; state.progress = ''; render();
  }

  async function run(){
    state.busy = 'run'; state.error = ''; render();
    var converted = 0, saved = 0, refs = 0, steps = 0;
    try {
      for (;;) {
        var r = await api('/media/webp/run', {});
        converted += r.converted; saved += r.bytes_before - r.bytes_after;
        refs += (r.references && r.references.replacements) || 0;
        state.progress = 'Converted ' + converted + ' so far, saved ' + bytes(saved) + ', updated ' + refs + ' references — leave this tab open.';
        render();
        if (r.done || !r.files.length) break;
        if (++steps > STEP_CEILING) break;
      }
      say('Converted ' + converted + ' pictures and updated ' + refs + ' references.');
    } catch (e) { state.error = explain(e); }
    state.busy = ''; state.progress = ''; state.plan = null;
    await load();
  }

  async function remove(){
    var word = (document.getElementById('wpx-confirm-word').value || '').trim();
    if (word !== 'REMOVE') { state.error = 'Type REMOVE in the box to confirm.'; render(); return; }
    state.busy = 'remove'; state.error = ''; render();
    var after = 0, removed = 0, kept = 0, freed = 0, steps = 0;
    try {
      for (;;) {
        var r = await api('/media/webp/remove-originals', {confirm: 'REMOVE', after: after});
        after = r.cursor; removed += r.removed; kept += r.kept; freed += r.bytes_freed;
        if (r.done || ++steps > STEP_CEILING) break;
      }
      say('Removed ' + removed + ' originals (' + bytes(freed) + ')' + (kept ? ', kept ' + kept + ' still in use.' : '.'));
    } catch (e) { state.error = explain(e); }
    state.busy = '';
    await load();
  }

  async function undo(){
    var word = (document.getElementById('wpx-undo-word').value || '').trim();
    if (word !== 'UNDO') { state.error = 'Type UNDO in the box to confirm.'; render(); return; }
    state.busy = 'undo'; state.error = ''; render();
    var restored = 0, steps = 0;
    try {
      for (;;) {
        var r = await api('/media/webp/restore', {confirm: 'UNDO'});
        restored += r.restored;
        if (r.done || !r.restored || ++steps > STEP_CEILING) break;
      }
      say('Put ' + restored + ' pictures back to their originals.');
    } catch (e) { state.error = explain(e); }
    state.busy = '';
    await load();
  }

  /* --------------------------------------------------------------- entry */
  window.kbbWebpOpen = function(){
    var content = document.getElementById('content');
    if (!content) return;
    content.innerHTML = '<div class="wrap"><div id="wpx-root"></div></div>';
    content.scrollTop = 0;
    var title = document.querySelector('#ptitle');
    var crumb = document.querySelector('#crumb');
    if (crumb) crumb.textContent = 'Content · Media Library';
    if (title) title.textContent = 'WebP images';
    state.plan = null; state.error = ''; state.progress = '';
    render();
    load();
  };

})();
</script>
@endverbatim
