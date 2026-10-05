{{--
    THE UPLOAD KIT — one uploader and one drop zone for the whole console.
    ======================================================================

    Two globals, in the same style as window.kbbPickMedia and
    window.kbbAddNavEntry, because a partial cannot call a global that a later
    partial defines:

        window.kbbUpload({url, file, field, extra, max,
                          onProgress, onStage, onDone, onFail})   -> {cancel()}
        window.kbbDropZone(el, {accept, multiple, onFiles})       -> teardown()

    ── WHY THIS FILE EXISTS: FOUR MEASURED DEFECTS ────────────────────────────

    The owner photographed an upload panel reading

        anua mist spray 2.mp4                                    72%
        6.0 MB of 8.4 MB sent    Sending to the server. 20s so far.
        [Cancel this upload]

    and reported that the bar was STICKING at 72%. Everything below was measured
    against a real Chromium and a server that reads the request body at a fixed
    byte rate, not reasoned about. The numbers are in docs/UPLOAD-LIMITS.md §8.

    1. THE FILE WAS NEVER TOO BIG, so this is not the post_max_size fault that
       App\Support\ServerUploadLimits was written for. His 8,808,038-byte file
       becomes an 8,808,327-byte multipart body — MEASURED, with his exact field
       name and filename — against a post_max_size of 10 MB. It fits with 1.6 MB
       to spare. ServerUploadLimits::MULTIPART_OVERHEAD is 4096 and the real
       overhead is 289 bytes for that request and 1,008 bytes for a pathological
       one (a 255-character filename plus four fields), so the allowance is
       correct with four times the margin it needs and the arithmetic is sound.

    2. `xhr.upload.onprogress` DOES NOT TICK SMOOTHLY. Chromium fires it when the
       socket write buffer drains, which happens in steps of roughly 1.6 MB.
       Measured, same 8.4 MB file, three server read rates:

           600 KB/s   longest gap between progress events   2,610 ms
           300 KB/s   longest gap                           5,624 ms
           100 KB/s   longest gap                          16,764 ms

       Each event jumps the bar 16–19 percentage points at once. Between events
       the bar is EXACTLY still. At the owner's ~300 KB/s that is a dead interval
       of five and a half seconds, over and over. THAT is the "sticking at 72%",
       and nothing was wrong with the upload at all.

    3. `loaded` COUNTS BYTES HANDED TO THE KERNEL, NOT BYTES THE SERVER TOOK.
       Measured with a server that accepts the connection, reads the request
       headers and then never reads the body: the browser credited 4,079,616
       bytes — 46% of the file — while the server application had consumed ZERO.
       In every throttled run above, the bar reached 46% within 104 ms whatever
       the rate, because 4 MB vanished into the socket buffer instantly.

       TWO CONSEQUENCES, both handled below. The bar runs AHEAD of reality, and
       any speed averaged from the start of the upload is nonsense — see
       speedBps(), which is why the first sample is thrown away.

    4. SO `xhr.upload.onload` IS NOT "IT HAS ARRIVED", AND SAYING SO WAS A LIE.
       It fires when the last byte enters the kernel buffer. Measured on the
       8.4 MB file: onload at 12.6 s when the server had taken about 4.3 MB of
       it, and at 100 KB/s onload at 37.2 s for a transfer the server did not
       finish receiving until roughly 86 s. The panel this kit replaces said
       "All of it has arrived. The server is checking the file" up to FORTY-NINE
       SECONDS before that was true. stageText() says "has left your browser"
       and "is still taking delivery", which is what the event actually means.

    ── AND THE OLD STALL DETECTOR CRIED WOLF, WHICH IS WORSE THAN SILENCE ─────

    A fixed twenty-second threshold is below the LEGITIMATE 16,764 ms gap at
    100 KB/s and far below it at 50 KB/s, so on an ordinary bad mobile uplink the
    panel announced "nothing has moved for 20s — if it is stuck, Cancel and try
    again" during a perfectly healthy upload. It advised the owner to destroy
    working work.

    stallAfter() is therefore SELF-CALIBRATING rather than a constant. The gap is
    set by one buffer-sized step at the current speed, and both are observable:
    the step is the bytes the last event added and the speed is the sliding
    window. A quiet interval is only suspicious once it is several times the gap
    this very upload has been producing. STALL_FLOOR keeps it from firing early
    on a fast link; STALL_CEIL keeps it from never firing at all.

    ── WHAT THIS KIT WILL NOT DO ──────────────────────────────────────────────

    No faked percentage, no easing toward 100%, no indeterminate bar wearing a
    number, and no interpolation between progress events. Every figure it reports
    came off an upload event. Where it does not know a number — the speed before
    two usable samples exist, the time remaining on a stage it cannot predict —
    it reports null and the screen says nothing rather than guessing.

    NOTHING HERE MEASURES LAYOUT. Rule 4. There is no getBoundingClientRect, no
    offsetWidth, no clientWidth, no scroll read and no animation frame. The one
    number written into a style is a percentage the upload event handed over,
    and writing a width is not reading one. UploadKitTest greps this file for
    every one of those API names.

    ── THE PANEL IS THE CALLER'S, THE TRUTH IS THIS FILE'S ────────────────────

    Deliberately no markup is rendered here. Two screens draw this panel with
    their own prefixed classes (.ugs- and .ugx-) and neither should have to
    inherit the other's look. What they must NOT each own is the wording and the
    arithmetic, because that is where all four defects above lived. So every
    callback is handed a `text` already composed, and a screen that prints it is
    correct by construction.
--}}
<style>
/* ── the drop zone ─────────────────────────────────────────────────────
   Shared by every screen that takes a file, so the hover state, the refusal
   state and the focus ring are the same control everywhere.

   SIZED WITH calc() AND A MEDIA QUERY, never from script. is-over is written by
   a dragenter/dragleave counter and is-bad by a rejected drop; both are class
   writes, which read no geometry. */
.kbbu-zone{position:relative;display:grid;gap:6px;justify-items:center;text-align:center;
           border:1.5px dashed var(--line,#e3e7ee);border-radius:var(--r-sm,12px);
           padding:calc(10px + 0.6vw) 12px;background:var(--surface-2,#fafbfc);
           min-width:0;cursor:pointer;
           transition:border-color .15s var(--ease,ease),background .15s var(--ease,ease)}
.kbbu-zone:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.kbbu-zone.is-over{border-color:var(--accent,#15a85a);border-style:solid;
                   background:var(--accent-soft,#e7f7ee)}
.kbbu-zone.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb)}
/* The zone's own sentence, which is where a refusal is STATED rather than the
   drop being silently ignored. */
.kbbu-zmsg{font-size:10.5px;line-height:1.45;color:var(--ink-faint,#97a0b2);min-width:0;
           overflow-wrap:anywhere}
.kbbu-zone.is-bad .kbbu-zmsg{color:#8c2f2c;font-weight:600}
@media (prefers-reduced-motion:reduce){.kbbu-zone{transition:none}}
</style>
<script>
(function () {
  'use strict';

  /* ------------------------------------------------------------- the numbers */

  /*
   * HOW MANY PROGRESS SAMPLES THE SPEEDOMETER LOOKS AT.
   *
   * Measured: at 100 KB/s the events are 16.8 s apart, so a window expressed in
   * SECONDS would frequently hold one sample and be unable to answer at all. A
   * window of samples always has something to say the moment a second usable one
   * arrives, at any speed.
   */
  var SPEED_SAMPLES = 5;

  /* Two samples less than this far apart are one buffer drain seen twice, not a
     rate. 400 ms is well under the 2,610 ms shortest measured gap. */
  var SPEED_MIN_SPAN_MS = 400;

  /*
   * THE STALL FLOOR AND CEILING, BOTH MEASURED RATHER THAN CHOSEN.
   *
   * FLOOR 25 s: the longest legitimate gap measured at 600 KB/s is 2,610 ms, so
   * 25 s is nearly ten times the quiet interval a healthy fast upload produces,
   * and it is also above the 16,764 ms measured at 100 KB/s — so even before
   * this upload has produced a gap of its own, a slow link is not accused.
   *
   * CEILING 120 s: without it, a very slow link computes a threshold so large
   * that a genuinely dead connection is never remarked on, and silence is what
   * the owner complained about in the first place.
   */
  var STALL_FLOOR = 25;
  var STALL_CEIL = 120;

  /* How many times the gap THIS upload is producing a quiet interval must
     exceed before it is worth remarking on. Three, because the measured gaps
     vary by about 5% run to run and a factor of two would be inside the noise
     of a link whose rate is changing. */
  var STALL_MULT = 3;

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* A byte count as an operator says it. Mirrors ServerUploadLimits::label()
     deliberately: the same file must not be "8.4 MB" on the screen and "8.39 MB"
     in the refusal underneath it. Floored, never rounded up. */
  function bytes(n) {
    n = Number(n) || 0;
    if (n < 1048576) return Math.floor(n / 1024) + ' KB';
    return (Math.floor(n / 1048576 * 10) / 10).toFixed(1).replace(/\.0$/, '') + ' MB';
  }

  /* A rate, in the same units. Per second, because that is how a connection is
     sold and how the owner will sanity-check it. */
  function rate(bps) {
    if (bps === null) return '';
    return bytes(bps) + '/s';
  }

  /*
   * A DURATION, ROUNDED THE WAY A WAIT IS SPOKEN ABOUT.
   *
   * Deliberately coarse above a minute. An ETA computed from a sliding window is
   * good to a few per cent (measured: 1.6% to 7% against a known rate), which is
   * nowhere near good enough to print "3 minutes and 41 seconds" — a number that
   * precise reads as a promise, and the next repaint contradicts it.
   */
  function secs(s) {
    if (s === null) return '';
    s = Math.max(1, Math.round(s));
    /* SINGULAR AT ONE, both here and for minutes. "about 1 seconds left to send"
       was on screen in the first browser run of this kit; a panel that cannot
       count to one reads as carelessly written, which is the opposite of what a
       number is doing there. */
    if (s < 60) return 'about ' + s + (s === 1 ? ' second' : ' seconds');
    var m = Math.round(s / 60);
    return 'about ' + m + (m === 1 ? ' minute' : ' minutes');
  }

  /* ------------------------------------------------------------ one transfer */

  function Transfer(o) {
    this.o = o;
    this.samples = [];          // {t, loaded} — every progress event, in order
    this.step = null;           // bytes the last event added: the buffer's stride
    this.maxGap = 0;            // longest quiet interval this upload has produced, ms
    this.lastAt = 0;            // when the last progress event landed, ms
    this.startedAt = 0;
    this.handoverAt = null;     // when xhr.upload.onload fired
    this.loaded = 0;
    this.total = Number(o.file && o.file.size) || 0;
    this.stage = 'sending';
    this.over = false;          // terminal, so a late event cannot revive it
  }

  /*
   * THE SPEED, FROM A SLIDING WINDOW THAT THROWS THE FIRST SAMPLE AWAY.
   *
   * THE FIRST SAMPLE IS THE SOCKET BUFFER, NOT THE NETWORK. Measured: 4,046,848
   * bytes credited 104 ms after send() in the 300 KB/s run, and 4,046,848 again
   * at 103 ms in the 100 KB/s run — identical, because it is the same buffer and
   * has nothing to do with the link. Averaged from the start of the upload that
   * is 39 MB/s and an ETA of zero. From the second sample on it is 279–309 KB/s
   * against a true 300 KB/s, and 101.6 KB/s against a true 100 KB/s: between
   * 1.6% and 7% out, which is a speedometer worth printing.
   *
   * null until there are two usable samples far enough apart. A screen with no
   * speed says nothing about speed; it does not print a guess.
   */
  Transfer.prototype.speedBps = function () {
    /* From index 1: index 0 is the buffer burst. */
    var usable = this.samples.slice(1);
    if (usable.length < 2) return null;

    var win = usable.slice(-SPEED_SAMPLES);
    var a = win[0], b = win[win.length - 1];
    var span = b.t - a.t;
    if (span < SPEED_MIN_SPAN_MS) return null;

    var moved = b.loaded - a.loaded;
    if (moved <= 0) return null;

    return moved / (span / 1000);
  };

  /*
   * SECONDS LEFT TO SEND, and only to send.
   *
   * NOT seconds until the upload is finished, which this cannot know: measured,
   * the server stage took a further 19 s at 300 KB/s and 52 s at 100 KB/s on the
   * same file, because the server is still reading a body the kernel has only
   * half delivered (defect 3) and then does its own work. stageText() says
   * "to send" in as many words for that reason.
   */
  Transfer.prototype.etaSeconds = function () {
    if (this.stage !== 'sending') return null;
    var bps = this.speedBps();
    if (!bps) return null;
    var left = this.total - this.loaded;
    if (left <= 0) return null;
    return left / bps;
  };

  /* Seconds since this upload started. The honest number while bytes are
     moving — "20s so far" — as against the quiet interval, which is 0 or 1 by
     definition whenever anything is happening. */
  Transfer.prototype.elapsed = function () {
    return Math.max(0, Math.round((Date.now() - this.startedAt) / 1000));
  };

  /*
   * HOW LONG THE CURRENT WAIT HAS BEEN, in seconds, and it is the WAIT and not
   * the total.
   *
   * While bytes are moving this is the time since the last progress event. After
   * the handover nothing moves again by design, so it becomes the time since the
   * handover — the interval that used to read identically at two seconds and at
   * four minutes.
   */
  Transfer.prototype.quietFor = function () {
    var since = this.stage === 'server'
      ? (this.handoverAt === null ? this.lastAt : this.handoverAt)
      : this.lastAt;
    return Math.max(0, Math.round((Date.now() - since) / 1000));
  };

  /*
   * WHEN A QUIET INTERVAL BECOMES WORTH REMARKING ON, computed from what this
   * upload is actually doing.
   *
   * The expected gap is one buffer stride at the current speed, and both are
   * observed: `step` is the bytes the last progress event added and speedBps()
   * is the sliding window. Worked through with the measurements above — a
   * 1.65 MB stride — this returns 25 s at 600 KB/s, 49 s at 100 KB/s and 99 s at
   * 50 KB/s, so the 16,764 ms gap that a fixed 20 s threshold accused is never
   * accused here, and a dead link on a fast connection is still called out
   * within half a minute.
   */
  Transfer.prototype.stallAfter = function () {
    var bps = this.speedBps();

    /* Before the stride and the speed are both known, the floor is the only
       honest answer — and it is above every legitimate gap measured. */
    if (!bps || !this.step) return STALL_FLOOR;

    var expected = this.step / bps;                 // seconds per progress event
    var n = Math.ceil(STALL_MULT * expected);

    return Math.min(STALL_CEIL, Math.max(STALL_FLOOR, n));
  };

  Transfer.prototype.stalled = function () {
    return this.quietFor() >= this.stallAfter();
  };

  /*
   * WHAT THE REQUEST IS DOING, IN ONE SENTENCE THE SCREEN CAN PRINT AS IT IS.
   *
   * Composed here and not in either screen, because the false claim in defect 4
   * was a sentence and duplicating it is how it came to be wrong in one place
   * and not the other.
   */
  Transfer.prototype.stageText = function () {
    var quiet = this.quietFor();

    if (this.stage === 'server') {
      /*
       * "HAS LEFT YOUR BROWSER", NOT "HAS ARRIVED". Defect 4: this event means
       * the last byte entered the kernel's buffer, and the server was measured
       * still taking delivery for up to 49 s afterwards. The old sentence
       * claimed the transfer was over and the wait was the server thinking.
       */
      var t = 'All of it has left your browser. The server is still taking delivery and '
            + 'checking it' + (this.o.serverNote ? ', ' + this.o.serverNote : '') + '.';

      if (quiet > 0) t += ' ' + quiet + 's so far.';

      if (quiet >= this.stallAfter()) {
        t += ' That is longer than usual. The last part of a big file can still be arriving '
           + 'after the bar fills, so waiting is usually right — Cancel stops it and leaves '
           + 'the file on your computer.';
      }

      return t;
    }

    if (this.stage === 'sending' && quiet >= this.stallAfter()) {
      /*
       * SAID AS AN OBSERVATION AND NOT AS A VERDICT. The bar legitimately sits
       * still for one buffer stride at a time — 16.8 s at 100 KB/s, measured —
       * so even past a self-calibrated threshold the honest sentence names what
       * is known and leaves the decision with the owner. The panel this replaces
       * told him to Cancel a working upload.
       */
      return 'Nothing has moved for ' + quiet + 's. A big file does move in jumps on a slow '
           + 'connection, so this can still be normal. If it stays like this, Cancel and try '
           + 'again — nothing has been changed.';
    }

    var parts = [];
    var bps = this.speedBps();
    var eta = this.etaSeconds();

    /*
     * THE TWO NUMBERS THE OWNER ASKED THE BAR FOR AND IT COULD NOT ANSWER.
     * "6.0 MB of 8.4 MB · 300 KB/s · about 8 seconds left to send" tells him
     * whether it is slow or stuck; a percentage alone cannot, which is the whole
     * of this ticket. Each is omitted rather than guessed when it is not known.
     */
    if (bps) parts.push(rate(bps));
    if (eta !== null) parts.push(secs(eta) + ' left to send');

    var head = 'Sending to the server.';
    var el = this.elapsed();
    if (el > 0) head += ' ' + el + 's so far.';

    return parts.length ? head + ' ' + parts.join(' · ') + '.' : head;
  };

  /* Everything a screen needs, in one shape, on every callback. `text` is
     already composed; the raw numbers are there for a screen that wants to lay
     them out itself. */
  Transfer.prototype.snapshot = function () {
    var bps = this.speedBps();
    return {
      loaded: this.loaded,
      total: this.total,
      pct: this.total > 0 ? Math.round(this.loaded / this.total * 100) : 0,
      stage: this.stage,
      bps: bps,
      speedText: bps ? rate(bps) : '',
      etaSeconds: this.etaSeconds(),
      etaText: this.etaSeconds() === null ? '' : secs(this.etaSeconds()),
      elapsed: this.elapsed(),
      quietFor: this.quietFor(),
      stallAfter: this.stallAfter(),
      stalled: this.stalled(),
      loadedText: bytes(this.loaded),
      totalText: bytes(this.total),
      text: this.stageText()
    };
  };

  /* ------------------------------------------------------------ the uploader */

  /*
   * ONE UPLOAD. Returns a handle with cancel(); everything else arrives through
   * the callbacks.
   *
   * XMLHttpRequest AND NOT fetch, for one reason that has no workaround: fetch
   * reports nothing whatsoever about a request body in flight. There is no
   * upload-progress event on it, so a fetch-based uploader can only ever show a
   * spinner — which is how the screens this replaces came to have no bar at all.
   */
  /* (2.60.388) Every upload says what became of it as WebP: converted, with
     the bytes saved, or why it stayed JPG/PNG. One place, so every screen that
     uploads -- Media page, brands, categories, banners, products -- says it. */
  function webpSay(body) {
    if (!body || typeof window.toast !== 'function') return;
    try {
      var w = body.webp;
      var kb = function (n) { return Math.max(1, Math.round((Number(n) || 0) / 1024)) + ' KB'; };
      if (w && w.converted) window.toast('Saved as WebP · ' + kb(w.bytes_before) + ' → ' + kb(w.bytes_after));
      else if (w && w.reason) window.toast('Kept as uploaded: ' + String(w.reason).replace(/_/g, ' '));
      else if (body.webp_note) window.toast('Not converted to WebP: ' + String(body.webp_note));
    } catch (e) {}
  }

  function kbbUpload(o) {
    o = o || {};

    var t = new Transfer(o);
    var fired = false;                 // no ending may be written twice

    function stage(s) {
      t.stage = s;
      if (typeof o.onStage === 'function') o.onStage(s, t.snapshot());
    }

    function progress() {
      if (typeof o.onProgress === 'function') o.onProgress(t.snapshot());
    }

    /*
     * `body` IS CARRIED THROUGH, and it is not decoration.
     *
     * The upload endpoint returns its `limits` block WITH every refusal it
     * composes, so a screen proved wrong about the ceiling corrects itself in the
     * same round trip. ugc-library-screen.blade.php has done that since
     * ServerUploadLimits was written, and dropping the body here would have
     * quietly taken it away — rule 1: nothing that already works may change.
     * It is null for the endings that never saw a response (a pre-flight
     * refusal, a dropped connection, a cancel).
     */
    function fail(status, message, retryable, body) {
      if (fired) return;
      fired = true;
      t.over = true;
      stopTick();
      stage('failed');
      if (typeof o.onFail === 'function') {
        o.onFail({
          status: status, message: message, retryable: !!retryable,
          body: body || null, snapshot: t.snapshot(),
        });
      }
    }

    /*
     * THE PRE-FLIGHT, BEFORE A BYTE IS SENT.
     *
     * `max` is the EFFECTIVE ceiling — App\Support\ServerUploadLimits answers
     * min(app cap, upload_max_filesize, post_max_size less the multipart
     * overhead) and that is the number to pass. Refusing here saves the owner
     * sending a file the server will throw away, which is what the panel in the
     * photograph had already done to him once.
     *
     * NOT retryable: the same file over the same ceiling fails identically, and
     * a Try again whose only outcome is the message above it is a lie.
     */
    if (!o.file) {
      fail(0, 'No file was chosen, so nothing was sent.', false);
      return { cancel: function () {} };
    }

    if (typeof o.max === 'number' && o.max > 0 && o.file.size > o.max) {
      fail(0, 'That file is ' + bytes(o.file.size) + ' and the most this server will take here '
            + 'is ' + bytes(o.max) + '. It was not sent, so nothing was changed.', false);
      return { cancel: function () {} };
    }

    var data = new FormData();

    if (o.extra) {
      Object.keys(o.extra).forEach(function (k) { data.append(k, o.extra[k]); });
    }

    data.append(o.field || 'file', o.file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', o.url);
    xhr.setRequestHeader('Accept', 'application/json');
    /*
     * THE CSRF HEADER, FROM THE COOKIE. There is NO csrf-token meta tag in this
     * console — reading one returns null and the request comes back 419, which
     * reads on screen as a mysterious refusal of a good file. Laravel's
     * VerifyCsrfToken accepts X-XSRF-TOKEN carrying the decrypted cookie value,
     * which is what every other call in this console already sends.
     */
    xhr.setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'));

    /* The one-second repaint. It does nothing but re-issue the snapshot so the
       elapsed and quiet counters advance and the sentence keeps up. A timer is
       not a measurement: nothing here reads geometry. */
    var tick = null;
    function startTick() {
      stopTick();
      tick = setInterval(function () {
        if (t.over) { stopTick(); return; }
        progress();
      }, 1000);
    }
    function stopTick() { if (tick) { clearInterval(tick); tick = null; } }

    xhr.upload.onprogress = function (e) {
      if (t.over || !e.lengthComputable) return;

      var now = Date.now();
      var gap = now - t.lastAt;
      if (t.samples.length > 0 && gap > t.maxGap) t.maxGap = gap;

      /* The buffer's stride, which stallAfter() needs. Bytes this event added. */
      if (t.samples.length > 0) t.step = e.loaded - t.samples[t.samples.length - 1].loaded;

      t.loaded = e.loaded;
      /* e.total is the whole multipart body, a couple of hundred bytes more than
         the file — measured at 289 for the owner's request. Believed over
         file.size because it is what is actually being transferred. */
      if (e.total) t.total = e.total;
      t.lastAt = now;
      t.samples.push({ t: now, loaded: e.loaded });

      progress();
    };

    /*
     * THE HANDOVER, AS ITS OWN EVENT AND NOT AS 99%.
     *
     * Read off the event rather than inferred from a percentage, because the
     * percentage cannot tell anybody which of the two stages it is in. What it
     * does NOT mean is that the file has arrived — see defect 4 at the top of
     * this file, where it was measured firing 49 seconds early.
     */
    xhr.upload.onload = function () {
      if (t.over) return;
      t.loaded = t.total;
      t.handoverAt = Date.now();
      stage('server');
      progress();
    };

    xhr.onload = function () {
      if (t.over) return;

      var body = null;
      try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }

      if (xhr.status >= 200 && xhr.status < 300) {
        fired = true;
        t.over = true;
        stopTick();
        stage('done');
        webpSay(body);
        if (typeof o.onDone === 'function') o.onDone(body, t.snapshot());
        return;
      }

      fail(xhr.status, explain(xhr.status, body), retryable(xhr.status), body);
    };

    xhr.onerror = function () {
      /*
       * A REQUEST THAT NEVER LANDED. Retryable: this is a dropped connection or
       * a refused one, and the file is still on the owner's computer.
       */
      fail(0, 'The upload did not reach the server. Check the connection and try again — '
            + 'nothing was changed.', true);
    };

    xhr.ontimeout = function () {
      fail(0, 'The upload timed out before the server answered. Nothing was changed.', true);
    };

    /* Without this the aborted request leaves the panel frozen at its last
       percentage and the screen locked, which is the exact shape a stall has. */
    xhr.onabort = function () {
      if (fired) return;
      fired = true;
      t.over = true;
      stopTick();
      stage('cancelled');
      if (typeof o.onFail === 'function') {
        o.onFail({
          status: 0,
          message: 'You stopped that upload, so nothing was sent and nothing was changed.',
          retryable: true,
          cancelled: true,
          /* Null, and present, so every onFail gets the same shape and a caller
             can read `.body` without checking which ending it came from. */
          body: null,
          snapshot: t.snapshot()
        });
      }
    };

    t.startedAt = Date.now();
    t.lastAt = t.startedAt;
    startTick();
    stage('sending');
    xhr.send(data);

    return {
      cancel: function () { if (!t.over) xhr.abort(); },
      snapshot: function () { return t.snapshot(); }
    };
  }

  /*
   * WHERE A SECOND PRESS COULD ACTUALLY WORK, AND NOWHERE ELSE.
   *
   * 429 is a throttle that clears on its own and 5xx is a server that fell over;
   * both are worth one more try. 413 and 422 are not — the request was too big
   * for this server, or the bytes were refused on their merits — and both fail
   * identically the second time. A Try again beside "this server accepts at most
   * 9.9 MB" is a button that cannot succeed.
   */
  function retryable(status) {
    return status === 429 || status >= 500;
  }

  /*
   * THE SERVER'S OWN SENTENCE WHERE IT COMPOSED ONE, AND AN HONEST FALLBACK.
   *
   * ── 413 IS HANDLED FIRST AND SEPARATELY, AND THAT IS THE POINT ───────────
   *
   * Laravel 11's Illuminate\Http\Middleware\ValidatePostSize is in the GLOBAL
   * stack and throws PostTooLargeException before the router runs, so no
   * controller composes the body and there is no `error` key in it — only
   * `message`. Every screen in this console read `body.error`, found undefined
   * and printed its fallback, which is why a file that was perfectly fine came
   * back as "That file was not accepted."
   *
   * App\Support\UploadArrival composes a real sentence for the same condition
   * where the request does reach the controller, so `error` is preferred where
   * it exists; this branch is what to say when the middleware answered instead.
   */
  function explain(status, body) {
    if (body && typeof body.error === 'string' && body.error !== '') return body.error;

    if (status === 413) {
      return 'That file was too big for this server to accept in one request, so it was '
           + 'thrown away before the shop saw any of it and nothing was changed. The file '
           + 'itself is fine. PHP’s post_max_size is the limit — raise it on the server and '
           + 'this box will take the bigger file.';
    }

    if (status === 419) {
      return 'This page’s session expired while the file was uploading. Reload the page and '
           + 'try again — nothing was changed.';
    }

    if (status === 429) {
      return 'Too many uploads in one minute. Wait a moment and try again — the file was fine.';
    }

    if (status === 403) return 'Your account does not hold the permission for this upload.';

    if (status === 404) {
      return 'That upload endpoint is not in this server’s compiled route table yet. Clear the '
           + 'route cache and reload.';
    }

    if (status === 422 && body && body.errors) {
      return Object.keys(body.errors).map(function (k) { return body.errors[k][0]; }).join(' ');
    }

    if (body && typeof body.message === 'string' && body.message !== '') return body.message;

    if (status >= 500) {
      return 'The server failed while taking the file (error ' + status + '). Nothing was '
           + 'changed and the file is fine — this one is worth trying again.';
    }

    return 'That file was not accepted (error ' + status + '). Nothing was changed.';
  }

  /* ------------------------------------------------------------ the drop zone */

  /*
   * DOES THIS FILE MATCH AN `accept` STRING?
   *
   * The same vocabulary the attribute uses, so a zone and the input beside it
   * cannot disagree: a bare extension, a full type, or a type/* wildcard.
   *
   * AN EMPTY `accept` MATCHES EVERYTHING, which is what the attribute means, and
   * an UNKNOWN TYPE IS ACCEPTED rather than refused: a browser that reports no
   * MIME type for a .mov — which Chromium does — must not have the file rejected
   * on this side, because the server reads the BYTES and is the only thing that
   * really decides. Refusing here is a courtesy to save a long upload, never the
   * check.
   */
  function matches(file, accept) {
    accept = String(accept || '').trim();
    if (accept === '') return true;

    var name = String(file.name || '').toLowerCase();
    var type = String(file.type || '').toLowerCase();

    return accept.split(',').some(function (raw) {
      var want = raw.trim().toLowerCase();
      if (want === '') return false;
      if (want.charAt(0) === '.') return name.slice(-want.length) === want;
      if (want.slice(-2) === '/*') return type !== '' && type.indexOf(want.slice(0, -1)) === 0;
      return type === want;
    });
  }

  /*
   * TURN AN ELEMENT INTO A DROP TARGET. Returns a teardown function.
   *
   * ── IT DOES NOT TOUCH THE CLICK PATH ───────────────────────────────────────
   *
   * No click handler is bound here at all. Every caller's zone is already a
   * <label for> or carries the console's own delegated attribute, and hijacking
   * the click would break the one way in that already works. Dragging is an
   * ADDITION.
   *
   * ── KEYBOARD REACHABLE, WITHOUT INVENTING A SECOND BEHAVIOUR ───────────────
   *
   * A zone that is not already focusable is given tabindex="0" and a role, and
   * Enter or Space is forwarded as a click — which lands on whatever the caller
   * had already wired, so the keyboard path and the mouse path are the same path
   * by construction. A <label> is left alone: it is focusable through its own
   * control and forwarding a click to it would open the file dialog twice.
   *
   * ── WHY A COUNTER AND NOT A BOOLEAN ────────────────────────────────────────
   *
   * dragenter and dragleave fire for every descendant the pointer crosses, so a
   * zone with a button and two lines of text inside it flickers its hover state
   * the whole way across. The counter is the standard answer and the reason the
   * highlight is steady.
   */
  function kbbDropZone(el, opts) {
    if (!el) return function () {};
    opts = opts || {};

    var depth = 0;
    var msg = null;

    /* The zone's own line, where a refusal is said out loud. Created only if the
       caller has not provided one, so a screen that wants the sentence somewhere
       specific keeps control of its own layout. */
    function msgNode() {
      if (msg) return msg;
      msg = el.querySelector('[data-kbbu-zmsg]');
      if (!msg) {
        msg = document.createElement('span');
        msg.className = 'kbbu-zmsg';
        msg.setAttribute('data-kbbu-zmsg', '1');
        el.appendChild(msg);
      }
      return msg;
    }

    function said(text, bad) {
      var n = msgNode();
      n.textContent = text || '';
      el.classList.toggle('is-bad', !!bad);
    }

    function over(on) {
      el.classList.toggle('is-over', !!on);
      if (on) el.classList.remove('is-bad');
    }

    function stop(e) { e.preventDefault(); e.stopPropagation(); }

    /*
     * IS THIS DRAG CARRYING FILES AT ALL?
     *
     * A DEFECT THIS REPO HAS ALREADY PAID FOR ONCE. The clip editor's tagged
     * product list is reorderable by dragging, and it shares a document with the
     * upload zones: without this check the zones lit up green while somebody was
     * reordering products, promising a drop that would then do nothing at all.
     * ugc-library-screen.blade.php guards its own delegated handlers against it
     * by tracking the row being dragged; a reusable zone cannot know about that
     * variable, so it asks the drag what it is carrying instead.
     *
     * `types` is the only thing readable during a dragover — the files
     * themselves are deliberately not exposed until the drop — and it contains
     * 'Files' for a file drag and 'text/plain' for the row. An absent or
     * unreadable `types` is treated as a file drag, because refusing a drop the
     * owner meant is worse than lighting up for one he did not.
     */
    function carriesFiles(e) {
      var types = e.dataTransfer && e.dataTransfer.types;
      if (!types) return true;
      for (var i = 0; i < types.length; i++) {
        if (String(types[i]).toLowerCase() === 'files') return true;
      }
      return false;
    }

    function onEnter(e) {
      if (!carriesFiles(e)) return;
      stop(e); depth++; over(true);
    }

    function onOver(e) {
      if (!carriesFiles(e)) return;
      stop(e);
      /* 'copy' rather than the default 'move', so the cursor says the file is
         being copied in and not dragged out of wherever it came from. */
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      over(true);
    }

    function onLeave(e) {
      stop(e);
      depth = Math.max(0, depth - 1);
      if (depth === 0) over(false);
    }

    function onDrop(e) {
      /* The same refusal on the drop as on the dragover, so a reorder released
         over a zone is not treated as an upload of nothing. */
      if (!carriesFiles(e)) return;
      stop(e);
      depth = 0;
      over(false);

      var all = (e.dataTransfer && e.dataTransfer.files) ? e.dataTransfer.files : null;

      if (!all || all.length === 0) {
        /* A dragged link or a selection, not a file. Said, because a drop that
           does nothing at all reads as a broken zone. */
        said('That was not a file. Drag a file from your computer, or use the button.', true);
        return;
      }

      var ok = [], bad = [];
      for (var i = 0; i < all.length; i++) {
        (matches(all[i], opts.accept) ? ok : bad).push(all[i]);
      }

      /* WITHOUT `multiple`, THE FIRST FILE AND NOT THE LAST. A drop of three
         files where one is expected is the owner's mistake, not a reason to pick
         arbitrarily — the first is the one under the pointer. */
      if (!opts.multiple && ok.length > 1) ok = ok.slice(0, 1);

      if (bad.length > 0) {
        /* STATED, NAMING THE FILE, rather than silently ignored. A zone that
           swallows a wrong file is indistinguishable from a zone that is broken,
           and the owner re-drops it. */
        var names = bad.map(function (f) { return f.name; }).join(', ');
        said(bad.length === 1
          ? '“' + names + '” is not a file this box takes. ' + (opts.rejectHint || '')
          : names + ' are not files this box takes. ' + (opts.rejectHint || ''), true);

        if (typeof opts.onReject === 'function') opts.onReject(bad, opts.accept);
        if (ok.length === 0) return;
      } else {
        said('', false);
      }

      if (ok.length > 0 && typeof opts.onFiles === 'function') {
        /* A real FileList cannot be built, and the contract is (FileList) — an
           array indexes and has .length the same way, which is everything a
           caller does with it. */
        opts.onFiles(ok);
      }
    }

    function onKey(e) {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      /* Forwarded to whatever the caller already wired, so there is one
         behaviour and not two. */
      e.preventDefault();
      el.click();
    }

    el.addEventListener('dragenter', onEnter);
    el.addEventListener('dragover', onOver);
    el.addEventListener('dragleave', onLeave);
    el.addEventListener('drop', onDrop);

    var keyed = false;
    if (el.tagName !== 'LABEL' && !el.hasAttribute('tabindex')) {
      el.setAttribute('tabindex', '0');
      if (!el.hasAttribute('role')) el.setAttribute('role', 'button');
      el.addEventListener('keydown', onKey);
      keyed = true;
    }

    el.classList.add('kbbu-zone');

    return function teardown() {
      el.removeEventListener('dragenter', onEnter);
      el.removeEventListener('dragover', onOver);
      el.removeEventListener('dragleave', onLeave);
      el.removeEventListener('drop', onDrop);
      if (keyed) el.removeEventListener('keydown', onKey);
      el.classList.remove('kbbu-zone', 'is-over', 'is-bad');
    };
  }

  /*
   * ON `window`, LIKE kbbPickMedia AND kbbAddNavEntry, and for the same reason:
   * a partial cannot call a function another partial declared, so the console's
   * shared helpers all live here.
   */
  /* ═══════════════════════════════════════════════════════════════════════
   * CUT A COVER OUT OF A VIDEO — IN THE BROWSER.
   * ═══════════════════════════════════════════════════════════════════════
   *
   * ── WHY THIS EXISTS, AND WHY IT IS THE PERMANENT ANSWER ─────────────────
   *
   * The shop this was written for runs on a host whose PHP-FPM pool has
   * proc_open switched off. ffmpeg is installed and PHP is not allowed to
   * start it, so the server cannot cut a cover in the request that uploads
   * the video. Two routes out of that were shipped before this one and BOTH
   * ask the owner to administer a server: re-enable proc_open (2.60.293's
   * guide), or add a crontab line so the CLI cuts it a minute later
   * (2.60.295). He asked for a permanent fix, and neither is one: the first
   * may be refused by the host, the second is a second moving part, and on
   * BOTH the admin screen still says "this server cannot cut" because, in
   * the request, it cannot.
   *
   * THE BROWSER ALREADY DECODED THIS VIDEO. It has to -- the screen plays it
   * back in a preview, and the shop plays it to shoppers in a <video>. A
   * frame is therefore already available on the client, and `drawImage` plus
   * `toBlob` turns it into a JPEG without asking the server for anything.
   *
   * So this needs: no ffmpeg, no proc_open, no cron, no host that allows any
   * of it. It works on this shop today and on every host it is ever moved to.
   *
   * ── WHAT IT DOES NOT DO ────────────────────────────────────────────────
   *
   * The TEASER. A 2.5-second re-encoded MP4 is not something to build out of
   * MediaRecorder on an admin screen, and it does not need building: a teaser
   * is a bandwidth saving, and UgcVideo::publishBlockers() does not list one.
   * The POSTER is the blocker, and the poster is what this cuts.
   *
   * ── THE DETAILS THAT DECIDE WHETHER IT WORKS ───────────────────────────
   *
   * -- 0.6 SECONDS IN, because that is `-ss 0.6` in UgcTranscoder::
   *    posterCommand(). The same moment as the server's own cut, so a clip
   *    covered here and one covered by ffmpeg look the same. It is also past
   *    the black first frame most editors leave.
   * -- SAME ORIGIN, SO THE CANVAS IS NOT TAINTED. A blob: URL from the File
   *    the operator just chose is same-origin by construction; an existing
   *    clip is read from /uploads/ugc/ on this very host. A tainted canvas
   *    throws on toBlob(), which is caught below and reported rather than
   *    left as a dead button.
   * -- MUTED AND playsInline, or a mobile browser refuses to decode at all
   *    without a gesture.
   * -- A HARD TIMEOUT. `seeked` is not guaranteed to fire for every container
   *    a browser will half-open, and a promise that never settles is a button
   *    that spins forever.
   * -- THE LONG EDGE IS CAPPED AT 1440. UgcMedia caps a poster at 4 MB and a
   *    4K frame as JPEG can pass that; the cap is applied before encoding
   *    rather than by retrying at lower quality.
   *
   * Resolves with a Blob. Rejects with an Error whose message is printable.
   */
  function kbbPosterFromVideo(source, opts) {
    opts = opts || {};

    /*
     * ── PROGRESS, FROM REAL EVENTS AND NEVER FROM A TIMER ──────────────────
     *
     * "i want a real time progress bar type upon pressing on cut cover button
     * ... anything which run in the background, should have proper real time
     * progress bar type."
     *
     * Taking a frame has no percentage of its own -- a decoder does not report
     * one -- so a bar driven by a setInterval here would be a FICTION, and this
     * project has a rule against exactly that kind of reassurance. What it does
     * have is four real milestones, each an event the browser fires when that
     * work is genuinely finished:
     *
     *     loadedmetadata  the container is parsed and the size is known
     *     loadeddata      there is a decoded frame to seek from
     *     seeked          the decoder has landed on 0.6s
     *     toBlob          the JPEG exists
     *
     * So the bar moves four times, each move meaning something, and the fifth
     * stretch -- sending it -- is the upload's own byte count, which IS a real
     * percentage. `onStage(pct, words)` is called with both so a caller can
     * draw a bar and say what is happening at the same time.
     */
    var stage = typeof opts.onStage === 'function' ? opts.onStage : function () {};

    var AT = typeof opts.at === 'number' ? opts.at : 0.6;
    var MAX_EDGE = 1440;
    var TIMEOUT = 20000;

    return new Promise(function (resolve, reject) {
      var objectUrl = (typeof source === 'string') ? null : URL.createObjectURL(source);
      var src = objectUrl || source;

      var video = document.createElement('video');
      var settled = false;
      var timer = null;

      function cleanup() {
        if (timer) { clearTimeout(timer); timer = null; }
        video.removeAttribute('src');
        try { video.load(); } catch (e) {}
        if (objectUrl) URL.revokeObjectURL(objectUrl);
      }

      function fail(message) {
        if (settled) return;
        settled = true;
        cleanup();
        reject(new Error(message));
      }

      function done(blob) {
        if (settled) return;
        settled = true;
        cleanup();
        resolve(blob);
      }

      timer = setTimeout(function () {
        fail('The browser did not finish reading this video in time.');
      }, TIMEOUT);

      stage(4, 'Opening the video');

      video.onloadedmetadata = function () { stage(22, 'Reading the video'); };

      video.muted = true;
      video.defaultMuted = true;
      video.playsInline = true;
      video.preload = 'auto';
      // Harmless for a blob: URL and correct for the same-origin file case;
      // without it a future move of /uploads to a CDN would taint the canvas
      // silently rather than loudly.
      video.crossOrigin = 'anonymous';

      video.onerror = function () {
        fail('This browser cannot decode this video, so it cannot take a frame from it.');
      };

      video.onloadeddata = function () {
        stage(44, 'Finding the frame at 0.6s');

        var d = video.duration;
        // A stream with no readable duration still has a frame at 0.
        var target = (isFinite(d) && d > 0) ? Math.min(AT, Math.max(0, d - 0.05)) : 0;

        // Seeking to 0 when already at 0 fires no `seeked` event in some
        // browsers, so that case draws immediately instead of waiting.
        if (target <= 0.001 && video.currentTime <= 0.001) { draw(); return; }

        video.onseeked = function () { stage(62, 'Taking the frame'); draw(); };
        try { video.currentTime = target; } catch (e) { draw(); }
      };

      function draw() {
        if (settled) return;

        var w = video.videoWidth;
        var h = video.videoHeight;

        if (!w || !h) {
          fail('The browser read this video but reported no picture size.');

          return;
        }

        var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
        var cw = Math.max(1, Math.round(w * scale));
        var ch = Math.max(1, Math.round(h * scale));

        var canvas = document.createElement('canvas');
        canvas.width = cw;
        canvas.height = ch;

        try {
          canvas.getContext('2d').drawImage(video, 0, 0, cw, ch);
        } catch (e) {
          fail('This video is served from somewhere this page may not read pixels from.');

          return;
        }

        try {
          canvas.toBlob(function (blob) {
            if (!blob) {
              fail('The browser could not turn that frame into an image.');

              return;
            }

            stage(72, 'Cover taken');
            done(blob);
          }, 'image/jpeg', 0.85);
        } catch (e) {
          // Chiefly the tainted-canvas SecurityError, which toBlob throws
          // synchronously rather than passing to the callback.
          fail('This video is served from somewhere this page may not read pixels from.');
        }
      }

      video.src = src;
      // Not awaited and the rejection is swallowed on purpose: some browsers
      // will not decode a frame until playback is attempted, and every
      // browser rejects this promise when the element is muted-autoplay
      // blocked. The frame still arrives through onloadeddata.
      var played = video.play();
      if (played && typeof played.catch === 'function') played.catch(function () {});
    });
  }

  window.kbbUpload = kbbUpload;
  window.kbbDropZone = kbbDropZone;
  window.kbbPosterFromVideo = kbbPosterFromVideo;

  /* The kit's own formatters, exposed so a screen's panel and the refusal
     underneath it cannot disagree about what 8,808,038 bytes is called. */
  window.kbbUploadFormat = { bytes: bytes, rate: rate, seconds: secs };
})();
</script>
