{{--
    STORE → IMPORT / EXPORT — an export bigger than the server will accept.
    =======================================================================

    ▲ MOUNTED IN 2.60.337. The integrator added both halves: this partial
    is included by the console, and the route file is required inside the
    admin-api group. Written in the past tense because a header describing its
    own mounting in the present tense goes false the day the integrator acts.

    ── WHY IT EXISTS, STATED HONESTLY ─────────────────────────────────────────

    It was built on a measurement that turned out to be this BUILD MACHINE's,
    not the shop's: a 2M upload limit against a 2.18 MB Orders export. The
    owner then checked his own server and it reports post_max_size = 10M and
    upload_max_filesize = 100M -- the smaller of the two is the real ceiling,
    so his export fits as it is today.

    It is kept anyway, for the reason it was right to build: the limit is a
    server setting the owner does not control from this application. A hosting
    panel can reset it, a move to another host can halve it, and a catalogue
    that grows will outgrow whatever it is. When a file no longer fits, this
    cuts it up instead of the upload failing with no number in the message.
    When it does fit -- the normal case on his shop today -- it is never used.

    ── WHAT THIS DOES ─────────────────────────────────────────────────────────

    It wraps `window.impUpload`, which app.blade.php declares at the top level
    of its first <script> block. Files that fit go to the original, untouched.
    Files that do not are sliced with `Blob.slice()` and posted a piece at a
    time to /admin-api/import/part; the server joins them and puts the result
    through `ImportWorkspace::acceptUpload()` — the same door, so a zip still
    unpacks and every refusal sentence is the one it always was.

    ── THE PIECE SIZE COMES FROM THE SERVER, EVERY TIME ───────────────────────

    /import/part/begin answers `part_bytes`, which is
    min(upload_max_filesize, post_max_size − multipart overhead) less a margin,
    read through App\Support\ServerUploadLimits at the moment of the request.

    NOTHING HERE COMPUTES IT. A constant in this file would be the same bug in
    a different place: right on this box today, wrong on his server, and wrong
    again after a host panel change. Being one byte over post_max_size and
    being a megabyte over are the same event — PHP discards the whole body and
    there is nothing left to report — so the arithmetic is done once, on the
    server, where the directives are.

    ── IT FEELS FOR THE ENDPOINT RATHER THAN ASSUMING IT ──────────────────────

    `begin` is called only when a file is actually too big, and a 404 or any
    other non-answer falls straight back to the one-request upload. So this
    partial included without its route file, or on a server where the routes
    are cached from before the package, degrades to exactly the behaviour that
    was there before — rather than to a screen that cannot upload at all.

    ── NO LAYOUT MEASURING, NO NEW MARKUP ─────────────────────────────────────

    Nothing here reads `getBoundingClientRect`, `offsetWidth` or
    `getComputedStyle`; two tests in this repository forbid those by name. The
    progress line is written into `#impUploadMsg`, which the import screen
    already owns and already styles — so this adds no element to the page, no
    stylesheet and nothing for StorefrontEnglishUnchangedTest to notice. The
    storefront is not touched at all.
--}}
<script>
(function () {
  'use strict';

  /* The screen this attaches to is Store → Import / Export, whose JavaScript
     lives in app.blade.php's first <script> block. A top-level `function` in a
     classic script is a property of window, so the original is genuinely here
     to be called — but only once that block has run, which is why this waits
     rather than reading it now. */
  function attach() {
    if (typeof window.impUpload !== 'function' || window.impUploadSlicedInstalled) return;
    window.impUploadSlicedInstalled = true;

    var original = window.impUpload;

    /* ───────────────────────────── one piece at a time ──────────────────── */

    function api(path, opts) {
      /* impApi is app.blade.php's, and using it rather than a second fetch
         wrapper is deliberate: it carries the XSRF token, resolves the
         admin-api base from the current path (the console is served under a
         secret admin path, so a hard-coded prefix would 404 on his shop), and
         it already turns a non-JSON error page into a readable snippet instead
         of throwing. */
      return window.impApi(path, opts);
    }

    function say(html) {
      var box = document.getElementById('impUploadMsg');
      if (box) box.innerHTML = html;
    }

    function esc(s) { return window.impEsc(s); }
    function bytes(b) { return window.impBytes(b); }

    /* Upload one file in pieces. Resolves with the server's upload result, or
       with null meaning "this route is not available — use the ordinary one". */
    async function sliced(file, position, count) {
      var opened = await api('/import/part/begin', {
        method: 'POST',
        body: JSON.stringify({ name: file.name, size: file.size })
      });

      /* A 404 (route file not mounted, or a stale compiled route cache) has no
         `ok` key at all. That is the fallback signal and it must not be
         confused with a 422, which is a real refusal with a sentence in it. */
      if (!opened.data) return null;
      if (opened.data.ok !== true) {
        /* THE HONEST DEGRADE. The server has measured that not even a small
           piece fits and has put the numbers and the directive in the message;
           printing it is the whole point, because the alternative is the
           browser error with nothing in it that this feature exists to end. */
        return { refused: [{ message: opened.data.message }], accepted: [] };
      }

      var id = opened.data.id;
      var size = opened.data.part_bytes;
      var parts = opened.data.parts;

      var label = (count > 1 ? '(' + position + ' of ' + count + ') ' : '') + esc(file.name);

      try {
        for (var i = 0; i < parts; i++) {
          var chunk = file.slice(i * size, Math.min((i + 1) * size, file.size));

          var body = new FormData();
          body.append('id', id);
          body.append('index', String(i));
          /* A filename on the piece so PHP files it under $_FILES rather than
             $_POST. It is never used for anything: the server knows the real
             name from begin() and would reduce this one to a basename anyway. */
          body.append('chunk', chunk, 'part');

          var put = await api('/import/part', { method: 'POST', body: body });

          if (!put.data || put.data.ok !== true) {
            /* A piece that did not land. Everything staged is thrown away
               rather than left on his disk, and the sentence is the server's
               where there is one — it names the directive that refused it. */
            await api('/import/part/abandon', { method: 'POST', body: JSON.stringify({ id: id }) });

            return {
              accepted: [],
              refused: [{
                message: (put.data && put.data.message)
                  || ('The server stopped accepting this upload after ' + (i) + ' of ' + parts
                      + ' pieces. Nothing was kept.')
              }]
            };
          }

          say('<div class="impnote">' + label + ' — sending in pieces this server will accept: '
              + (i + 1) + ' of ' + parts + ' (' + bytes(put.data.bytes) + ' of '
              + bytes(file.size) + ').</div>');
        }

        say('<div class="impnote">' + label + ' — joining ' + parts + ' pieces…</div>');

        var done = await api('/import/part/finish', { method: 'POST', body: JSON.stringify({ id: id }) });

        if (!done.data) {
          return { accepted: [], refused: [{ message: 'The server could not join the pieces of ' + file.name + '.' }] };
        }

        return { accepted: done.data.accepted || [], refused: done.data.refused || [], status: done.data.status };
      } catch (e) {
        await api('/import/part/abandon', { method: 'POST', body: JSON.stringify({ id: id }) });
        throw e;
      }
    }

    /* ───────────────────────────── the wrapper ─────────────────────────────
       Files the server will take in one request go to the original function
       untouched, which keeps the ordinary path — nine loose CSVs at once, the
       drag-and-drop, the entity hint — exactly as it was and exactly as
       AdminImportScreenTest drives it.

       "Will take in one request" is not guessed here either: `begin` is asked
       about every file, and its refusal or its absence is what decides. The
       only thing measured in the browser is `file.size`, which is a fact about
       the file rather than about the server. */

    window.impUpload = async function (fileList) {
      var files = Array.prototype.slice.call(fileList || []);
      if (!files.length) return;

      /* One ask for the whole selection rather than one per file: the piece
         size is a property of the SERVER, not of the file. `part/limits` opens
         nothing and writes nothing, so asking it costs one read of two ini
         directives. */
      var limits = null;

      try {
        var probe = await api('/import/part/limits');

        if (probe.data && probe.data.ok === true) limits = probe.data.limits;
      } catch (e) { limits = null; }

      /* No endpoint, or a server that cannot slice at all: the original, which
         is what this screen did before this file existed. */
      if (!limits || !limits.ok || !limits.part_bytes) return original(fileList);

      var big = files.filter(function (f) { return f.size > limits.part_bytes; });

      if (!big.length) return original(fileList);

      var small = files.filter(function (f) { return f.size <= limits.part_bytes; });

      var accepted = [];
      var refused = [];
      var status = null;

      for (var i = 0; i < big.length; i++) {
        var result;

        try {
          result = await sliced(big[i], i + 1, big.length);
        } catch (e) {
          result = { accepted: [], refused: [{ message: big[i].name + ': the upload stopped part way. Nothing was kept.' }] };
        }

        /* null means the route answered nothing at all, which can only happen
           if it went away between the probe and now. Hand the file back to the
           original so the owner still gets the old behaviour and the old
           message rather than silence. */
        if (result === null) { await original([big[i]]); continue; }

        accepted = accepted.concat(result.accepted || []);
        refused = refused.concat(result.refused || []);
        if (result.status) status = result.status;
      }

      if (status) window.impState = status;

      /* The files that always fitted go through the original, unchanged — one
         request with all of them, as before. It runs LAST so its repaint is
         the one that stands, and its own status refresh covers the sliced ones
         too. */
      if (small.length) await original(small);

      if (refused.length) {
        window.impMsg = refused.map(function (x) { return x.message; }).join('  ·  ');
        window.impMsgKind = 'bad';
      } else if (!small.length) {
        window.impMsg = '';
        window.impMsgKind = '';
        if (typeof window.toast === 'function') window.toast('Uploaded');
      }

      if (typeof window.impRefresh === 'function') await window.impRefresh();
      if (typeof window.impPaint === 'function') window.impPaint();
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attach);
  } else {
    attach();
  }
})();
</script>
