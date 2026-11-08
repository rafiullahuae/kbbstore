{{--
    THE IMAGE FIELD -- one photo box for every admin form (the owner, 8 Nov 2026:
    "i want the image upload function same like other, with drag n drop and with
    media selection too. keep this function everywhere same onwards too").

        window.kbbImageField({ value, folder, width, label, onChange }) -> element
        window.kbbImageDropOn(el, { folder, onStart, onProgress, onUrl, onFail })

    Built ON the upload kit (window.kbbDropZone, window.kbbUpload) and the Media
    Library (window.kbbPickMedia), never beside them: one upload endpoint
    (/admin-api/media/upload), no raw file picker. It lives in its own file, not
    in the kit, because the kit binds no click handler of its own (UploadKitTest)
    -- this component's box and buttons are its own elements, so it may.
    Included once in admin/app.blade.php, after the upload kit.
--}}
<style>
/* ── window.kbbImageField: THE image box for any form (the owner, 8 Nov:
   "i want the image upload function same like other, with drag n drop and with
   media selection too. keep this function everywhere same onwards"). A square
   drop zone showing the picture, and Upload / Library under it. */
.kbbif{display:grid;gap:5px;justify-items:center;width:var(--kbbif-w,88px);min-width:0}
.kbbif .kbbu-zone{width:100%;aspect-ratio:1;padding:4px;gap:2px;align-content:center;overflow:hidden}
.kbbif .kbbu-zone img{position:absolute;inset:4px;width:calc(100% - 8px);height:calc(100% - 8px);object-fit:contain;display:block;border-radius:8px}
.kbbif-ph{font-size:11px;font-weight:700;line-height:1.25;color:var(--accent-ink,#0f7a42)}
.kbbif-ph small{display:block;font-weight:500;color:var(--ink-faint,#97a0b2);font-size:10px}
.kbbif-bar{position:absolute;left:0;bottom:0;height:3px;background:var(--accent,#15a85a);transition:width .2s linear}
.kbbif-acts{display:flex;gap:4px;justify-content:center;flex-wrap:nowrap}
.kbbif-acts button{font:inherit;font-size:10.5px;font-weight:600;padding:2px 5px;white-space:nowrap;border:1px solid var(--line,#e3e7ee);border-radius:7px;background:var(--surface,#fff);color:var(--ink,#1a1f2b);cursor:pointer}
.kbbif-acts button:hover{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0f7a42)}
.kbbif-err{font-size:10.5px;color:#8c2f2c;text-align:center;line-height:1.3}
</style>
<script>
/*
  window.kbbImageField({ value, folder, width, label, onChange }) -> element

  THE image box for every admin form from now on (the owner, 8 Nov 2026: "i want
  the image upload function same like other, with drag n drop and with media
  selection too. keep this function everywhere same onwards too").

    - drop a photo on the box   -> it goes into the shared Media Library through
                                   the ONE upload endpoint (/admin-api/media/upload,
                                   kbbUpload, progress bar), then onChange(url)
    - Upload                    -> the Media Library opens on the computer's file
                                   chooser and picks what was uploaded
    - Library                   -> the Media Library, to pick an existing photo

  No second upload path, no raw file picker (MediaUploadTest,
  AdminMediaPickerEverywhereTest). No timers, nothing measured.
*/
(function () {
  function apiBase() {
    var p = window.location.pathname.replace(/\/+$/, '');
    return p.replace(/\/[^\/]*$/, '') + '/admin-api';
  }
  function mk(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  /* window.kbbImageDropOn(el, { folder, onStart, onProgress(pct), onUrl(url), onFail(msg) }) -> teardown
     Any element becomes a photo drop target: the dropped file goes into the
     Media Library through the one upload endpoint, and onUrl gets its address. */
  window.kbbImageDropOn = function (el, o) {
    o = o || {};
    if (!el || typeof window.kbbDropZone !== 'function' || typeof window.kbbUpload !== 'function') return function () {};
    var busy = false;
    return window.kbbDropZone(el, { accept: 'image/*', multiple: false, onFiles: function (files) {
      var f = files && files[0];
      if (!f || busy) return;
      busy = true;
      if (typeof o.onStart === 'function') o.onStart();
      window.kbbUpload({ url: apiBase() + '/media/upload', file: f, field: 'file', extra: { folder: o.folder || 'products' },
        onProgress: function (p) { if (typeof o.onProgress === 'function') o.onProgress(Math.round((p && p.pct) || 0)); },
        onDone: function (body) { busy = false; if (body && body.url) { if (typeof o.onUrl === 'function') o.onUrl(String(body.url)); } else if (typeof o.onFail === 'function') o.onFail('No picture came back. Try again.'); },
        onFail: function (e) { busy = false; if (typeof o.onFail === 'function') o.onFail((e && e.message) || 'Not uploaded. Try again.'); }
      });
    } });
  };

  window.kbbImageField = function (opts) {
    opts = opts || {};
    var value = opts.value || '';
    var folder = opts.folder || 'products';
    var wrap = mk('div', 'kbbif');
    if (opts.width) wrap.style.setProperty('--kbbif-w', opts.width + 'px');
    var zone = mk('div', 'kbbu-zone');
    zone.setAttribute('role', 'button');
    zone.setAttribute('tabindex', '0');
    zone.setAttribute('aria-label', (opts.label || 'Photo') + ': drop a photo here, or press to choose one from the Media Library');
    var acts = mk('div', 'kbbif-acts');
    var up = mk('button', '', 'Upload'); up.type = 'button';
    var lib = mk('button', '', 'Library'); lib.type = 'button';
    acts.appendChild(up); acts.appendChild(lib);
    var err = mk('div', 'kbbif-err'); err.hidden = true;
    wrap.appendChild(zone); wrap.appendChild(acts); wrap.appendChild(err);
    var busy = false;

    function paint(pct) {
      zone.textContent = '';
      if (value) {
        var im = document.createElement('img');
        im.src = value; im.alt = ''; im.width = 80; im.height = 80; im.decoding = 'async';
        zone.appendChild(im);
      } else {
        var ph = mk('span', 'kbbif-ph', busy ? 'Uploading…' : '+ Photo');
        if (!busy) ph.appendChild(mk('small', '', 'or drop here'));
        zone.appendChild(ph);
      }
      if (busy) { var bar = mk('i', 'kbbif-bar'); bar.style.width = (pct || 0) + '%'; zone.appendChild(bar); }
      up.disabled = lib.disabled = busy;
    }
    function set(url) {
      value = url || ''; err.hidden = true; busy = false; paint();
      if (typeof opts.onChange === 'function') opts.onChange(value);
    }
    function fail(m) { busy = false; paint(); err.textContent = m || 'Not uploaded.'; err.hidden = false; }
    function pick(upload) {
      if (busy || typeof window.kbbPickMedia !== 'function') return;
      window.kbbPickMedia({ folder: folder, upload: !!upload, pickUploaded: !!upload, title: opts.label || 'Choose a photo',
        onPick: function (urls) { if (urls && urls[0]) set(String(urls[0])); } });
    }
    up.addEventListener('click', function () { pick(true); });
    lib.addEventListener('click', function () { pick(false); });
    zone.addEventListener('click', function () { pick(false); });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(false); } });

    window.kbbImageDropOn(zone, { folder: folder,
      onStart: function () { busy = true; err.hidden = true; paint(0); },
      onProgress: function (pct) { if (busy) paint(pct); },
      onUrl: set, onFail: fail });
    paint();
    wrap.kbbSet = function (url) { value = url || ''; paint(); };
    return wrap;
  };
})();
</script>
