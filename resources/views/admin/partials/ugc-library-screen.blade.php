{{--
    Content → Shoppable video → All clips. (Lane V2 built the data model and the
    ingest; Lane V4 rebuilt the screen as a guided, stepped flow.)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry, window.KBBArabic and toast(). It
    wraps window.go, exactly as the nine screens beside it do, so that one
    include is the whole of the change to that file. It registers NO sidebar
    entry of its own — it is a tab of Content → Shoppable video.

    ── WHAT THIS SCREEN IS FOR ──────────────────────────────────────────────

    The owner's library of creator clips: add one, say who made it and whether
    they said yes, and tag SEVERAL PRODUCTS on it — which is the whole feature
    and the one thing the benchmark app cannot do, because somebody else owns
    its player.

    ── WHY IT IS FIVE STEPS AND NOT ONE FORM ────────────────────────────────

    The owner's complaint was that adding a clip threw everything at him raw,
    in one column, with a paragraph under every field and no way to tell what
    was still missing. The answer is not decoration: THE FIVE STEPS ARE THE
    PUBLISH GATE, read off UgcVideo::publishBlockers() and the controller's own
    rules rather than invented.

      1 · Details        the row cannot exist without a title, and the row has
                         to exist before a file can be attached to it — every
                         upload endpoint is /ugc-videos/{id}/…
      2 · Video & cover  the first two blockers, by name: "No video file has
                         been uploaded yet" and "No poster image yet".
      3 · Credit         the third: "The creator has not granted permission".
      4 · Products       the feature, and NOT a blocker. Marked optional.
      5 · Publish        status, locale, order, the date, and the live list of
                         whatever is still in the way.

    Steps 2 to 5 are LOCKED until step 1 has saved, because before that there
    is no row and nothing they do could be kept. That is also the defect this
    rebuild fixes: the old screen drew both file inputs on a brand-new clip and
    upload() returned early on `!editing.id`, so choosing a file there did
    nothing at all and said nothing about why.

    ── THE ONE-SECOND LOOP NEEDS NO SECOND UPLOAD, AND THE SCREEN SAYS SO ───

    The old copy on this screen said a tile without a teaser file "shows its
    poster instead of a short loop". That is wrong, and it sent the owner
    looking for a way to cut and upload a second, shorter video.

    What the storefront really does, in resources/views/ugc (playTeaser): it
    mounts `teaser || full`, and where there is no teaser file it sets loop off
    and rewinds the full clip every time playback passes the rail's own
    teaser-ms. So EVERY clip loops its first seconds from the one file that was
    uploaded. A separate teaser is a BANDWIDTH saving and nothing else — the
    plan measured 12.19 MB for a rail of eight full clips against 1.01 MB of
    teasers.

    HOW LONG IS A SETTING, so this screen reads it instead of printing a number:
    `teaser_ms` on Appearance → Shoppable video → Motion, default 1000 and
    ranged 1000–4000, and `teaser` beside it decides whether a tile loops at
    all. readMotion() fetches both once, clamps the number to that range, and
    falls back to the shipped 1000 — so the preview above and the prose beside
    it are the owner's own settings rather than a number that was true the day
    this was written.

    THE DEFAULT AND THE FLOOR BOTH MOVED, from 2500/1500 to 1000/1000, because
    the owner asked for a one-second clip by name. A shop that had already saved
    a value keeps it, so this screen can still be showing 4000 — which is the
    whole reason it reads the setting instead of printing a number.

    The one case where a tile really does stand still is the shopper's, not the
    clip's: data-saver mode, reduced motion, or the rail's own Loop switch
    turned off. Those stop every tile, teaser or no teaser, and the screen says
    that where it matters instead of blaming a missing file.

    Step 2 draws that loop AT THE REAL TILE WIDTH with the real mechanism, so
    the owner can watch the thing he was worried about.

    ── WHAT CAN BE PREVIEWED FROM WHICH SOURCE ──────────────────────────────

    A file this shop serves can be previewed: <video> plays it, and it is the
    same bytes the storefront will play. A platform link cannot, and this
    module deliberately embeds nothing — UgcVideo::published() requires a
    file_path, so a clip with only an Instagram URL is a credit line with no
    video and cannot be published at all. Step 2 says that in as many words
    rather than drawing an empty frame.

    ── TWO COLUMNS, AND SECTIONS THAT READ AS SECTIONS ──────────────────────

    The owner, about the screen next door and then about this one: "make the
    sections prominent and don't give me onwards any classic throw away looks",
    and "avoid long form page". A step panel used to be one tall column of
    fields with a small uppercase word over each group, which on a 1280px
    desktop is a form down the left and a dead half down the right.

    So every step that has enough in it to fill two columns is laid out as two:
    step 1 is the title beside the caption, step 2 is the video beside the
    cover, step 3 is who made it beside whether they said yes, step 4 is the
    tagged list beside the search, and step 5 is where it shows beside when.
    ONE COLUMN ON A PHONE, and the switch is `.ugs-cols` plus a media query at
    900px — no script, rule 4.

    A section is `.ugs-sec`: a bordered block whose head is a tinted bar with an
    icon, a real title, a sentence saying what the section is for, and a figure
    on the end (the file size, "required", "optional"). Not a label floating
    over a hairline.

    `.ugs-slot` SURVIVED THIS AS THE BODY of the two media sections rather than
    as a card of its own, and deliberately: its `align-content:start` is the
    rule that stops the cover button rendering as a 190px slab beside a 640px
    video, which a picture found and UgcAddClipFlowTest now pins. The section
    draws the border and the head; the slot still packs the rows.

    ── THE CUT IS OFFERED WHERE THE UPLOAD ENDS ─────────────────────────────

    "once upload the video complete, it should give option to cut teaser and
    poster." It used to be a button in the cover box, below a drop zone, that
    the owner had to notice. It is now `cutHTML()`: its own section, directly
    under the two file boxes, loud for as long as a video has just landed.

    IT IS ONLY OFFERED WHERE IT IS REAL. `transcoder.available` is this server's
    own answer — UgcTranscoder::available() is a `which ffmpeg`, read off the
    library payload — and there has to be a stored clip to cut FROM. Where
    either is missing the same place says what to do instead in as many words,
    rather than drawing a button whose only possible answer is a note saying it
    could not.

    AND IT DOES NOT TAKE CREDIT FOR WORK THE UPLOAD ALREADY DID. On a server
    that has ffmpeg, UgcVideoController::media() calls derive() ON THE UPLOAD
    REQUEST for a clip, so by the time this section is drawn both files usually
    exist already. It reads the row and says which of the two worlds it is in:
    "both were cut on the way in, cut them again from a different frame" or
    "cut them now".

    ── THE UPLOAD BAR IS ALL MEASURED AND IT HAS AN END ─────────────────────

    Every number in it came off `xhr.upload.onprogress`: the percentage, the
    bytes sent and the total, and now the speed and the time remaining too.
    There is no indeterminate mode, because a bar that sweeps while nothing is
    known is a bar that lies.

    TWO REAL STAGES, not a spinner, and the transport is now
    partials/upload-kit.blade.php rather than an XMLHttpRequest written out
    here. `upState.stage` is 'sending' until `xhr.upload.onload` fires and
    'server' after it.

    WHAT THAT EVENT MEANS WAS MEASURED AND IT IS NOT WHAT THIS SCREEN USED TO
    CLAIM. It fires when the last byte enters the KERNEL BUFFER, not when the
    server has the file: on a throttled 8.4 MB upload it fired 49 seconds before
    the server finished taking delivery. The sentence therefore says the file has
    left the browser and the server is still taking delivery — see the kit's
    docblock, which carries all four measurements and the reason the stall
    threshold is no longer a constant.

    AND BOTH ENDINGS STAY ON SCREEN. The old panel was thrown away the instant
    the request landed, so a 31 MB upload finished with no trace it had ever
    happened and a refused one left only a toast. `upDone` holds the terminal
    state — the name, the size and either a tick or the server's own reason —
    until the next upload replaces it.

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

    NO SCRIPT HERE MEASURES LAYOUT. Rule 4, and the same rule the rail itself
    is held to: the step rail becomes a scroll-snapped strip on a phone and a
    five-column grid on a desktop through a media query, the tile grid is
    auto-fill with a clamped track, the loop preview is aspect-ratio, and the
    upload bar is a percentage the server's own progress event handed us. There
    is no rect, no offset, no scroll handler and no animation frame.

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
    App\Services\UgcPath::link() — and is checked again here, because a second
    lock costs nothing; a media path that becomes a <video> src is re-checked
    against the same shape App\Services\UgcPath::stored() allows.
--}}
@verbatim
<style>
/* ── shell ─────────────────────────────────────────────────────────────── */
.ugs-wrap{display:grid;gap:12px;min-width:0}
.ugs-wrap > *{min-width:0}
.ugs-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
          border-radius:var(--r,18px);box-shadow:var(--sh-s,0 1px 2px rgba(16,24,40,.05));
          padding:14px;min-width:0}
.ugs-h{font-weight:680;font-size:15px;letter-spacing:-.01em;line-height:1.3}
.ugs-sub{color:var(--ink-soft,#626c80);font-size:12px;line-height:1.5;margin-top:3px;max-width:66ch}
.ugs-help{font-size:11px;color:var(--ink-faint,#97a0b2);line-height:1.45;margin:0;max-width:70ch}

/* ── the header bar of the library ─────────────────────────────────────── */
.ugs-hero{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;min-width:0}
.ugs-hero > *{min-width:0}
.ugs-herotext{flex:1 1 220px;min-width:0}
.ugs-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}
.ugs-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;
          padding:3px 9px;border-radius:999px;border:1px solid var(--border,#e6e9f2);
          background:var(--surface-2,#f2f4fb);color:var(--ink-soft,#626c80);white-space:nowrap}
.ugs-chip.is-warm{border-color:#f0dcb4;background:var(--amber-soft,#fdf2e2);color:#8a6212}
.ugs-chip.is-live{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee);
                  color:var(--accent-ink,#0b6e3a)}

/* ── buttons ───────────────────────────────────────────────────────────── */
.ugs-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 14px;
         border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
         background:var(--surface,#fff);color:inherit;font:inherit;font-size:12.5px;font-weight:600;
         cursor:pointer;max-width:100%;
         transition:border-color .16s var(--ease),background .16s var(--ease),color .16s var(--ease)}
.ugs-btn:hover{border-color:var(--ink-faint,#97a0b2)}
.ugs-btn.is-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.ugs-btn.is-primary:hover{background:var(--accent-strong,#0f8f4b);border-color:var(--accent-strong,#0f8f4b)}
.ugs-btn.is-danger{border-color:#f3c9c6;color:var(--red,#e3493f);background:transparent}
.ugs-btn.is-danger:hover{background:var(--red-soft,#fdeceb);border-color:var(--red,#e3493f)}
.ugs-btn[disabled]{opacity:.45;cursor:default}
.ugs-btn svg{width:15px;height:15px;flex:0 0 auto}
.ugs-mini{padding:4px 9px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
          background:var(--surface,#fff);color:inherit;font:inherit;font-size:11.5px;cursor:pointer;line-height:1.3}
.ugs-mini:hover{border-color:var(--ink-faint,#97a0b2)}
.ugs-mini[disabled]{opacity:.4;cursor:default}

/* ── the step rail ─────────────────────────────────────────────────────
   A scroll-snapped strip on a phone and five equal columns from 900px, and
   the switch is a media query rather than a script. Rule 4. */
.ugs-steps{display:flex;gap:7px;overflow-x:auto;scroll-snap-type:x proximity;
           padding-block-end:2px;scrollbar-width:none;min-width:0}
.ugs-steps::-webkit-scrollbar{display:none}
.ugs-step{flex:0 0 auto;scroll-snap-align:start;display:grid;grid-template-columns:22px 1fr;gap:9px;
          align-items:center;padding:9px 13px;border:1px solid var(--border,#e6e9f2);
          border-radius:999px;background:var(--surface,#fff);color:inherit;font:inherit;
          cursor:pointer;text-align:start;min-width:0;
          transition:border-color .16s var(--ease),background .16s var(--ease)}
.ugs-step:hover{border-color:var(--ink-faint,#97a0b2)}
.ugs-stepn{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;
           font-size:10.5px;font-weight:700;border:1px solid var(--border,#e6e9f2);
           color:var(--ink-soft,#626c80);background:var(--surface-2,#f2f4fb)}
.ugs-steplab{font-size:12px;font-weight:650;line-height:1.25;white-space:nowrap}
.ugs-stepsub{font-size:10px;font-weight:500;color:var(--ink-faint,#97a0b2);line-height:1.3;white-space:nowrap}
.ugs-step.is-on{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.ugs-step.is-on .ugs-stepn{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
.ugs-step.is-done .ugs-stepn{background:var(--accent-soft,#e7f7ee);
                             border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a)}
.ugs-step.is-locked{opacity:.45;cursor:not-allowed}
@media (min-width:900px){
  .ugs-steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));overflow:visible}
  .ugs-step{border-radius:var(--r-sm,12px)}
  .ugs-steplab,.ugs-stepsub{white-space:normal}
}

/* ── one step's panel ──────────────────────────────────────────────────── */
.ugs-panel{display:grid;gap:12px;min-width:0}
.ugs-panel[hidden]{display:none}
.ugs-phead{min-width:0}
.ugs-pk{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
        color:var(--accent-ink,#0b6e3a)}
.ugs-ptitle{font-size:15px;font-weight:680;letter-spacing:-.01em;line-height:1.3;margin-top:2px}
.ugs-pwhy{font-size:12px;color:var(--ink-soft,#626c80);line-height:1.5;margin-top:3px;max-width:70ch}

/* ── fields ────────────────────────────────────────────────────────────── */
.ugs-fields{display:grid;gap:11px;min-width:0}
.ugs-two{display:grid;gap:11px;grid-template-columns:1fr;min-width:0}
@media (min-width:820px){ .ugs-two{grid-template-columns:1fr 1fr} }

/* ── two columns at a desk, one on a phone ─────────────────────────────
   The owner's complaint, in his words: a tall form with a dead right half. One
   media query decides, so nothing is measured in script. Rule 4.

   align-items, NOT the row-packing declaration .ugs-slot owns: this packs the
   two SECTIONS to the top of the row, so a short one beside a tall one stays
   its own height instead of stretching. */
.ugs-cols{display:grid;gap:12px;grid-template-columns:1fr;align-items:start;min-width:0}
.ugs-cols > *{min-width:0}
@media (min-width:900px){ .ugs-cols{grid-template-columns:minmax(0,1fr) minmax(0,1fr)} }

/* ── THE VIDEO, THE COVER AND THE LOOP, SIDE BY SIDE ──────────────────
   "can u make the third column side by side along with existing two ... so i
   can see everything side by side."

   THREE TRACKS ONLY WHERE THREE TRACKS FIT. A 1280px console minus the sidebar
   leaves roughly 900px of content, so three columns there are ~290px each --
   which is narrower than the 9:16 preview each one holds and would shrink the
   very thing he wants to look at. So: one column on a phone, two from 900px
   with the loop spanning the full width underneath, and three only from 1440px,
   where each track is ~400px and the previews stay legible.

   `grid-column:1/-1` then `auto` rather than two different grids, so the
   sections keep their DOM order and nothing re-parents at a breakpoint. */
.ugs-cols3{display:grid;gap:12px;grid-template-columns:1fr;align-items:start;min-width:0}
.ugs-cols3 > *{min-width:0}
@media (min-width:900px){
  .ugs-cols3{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
  .ugs-cols3 > :nth-child(3){grid-column:1 / -1}
}
@media (min-width:1440px){
  .ugs-cols3{grid-template-columns:repeat(3,minmax(0,1fr))}
  .ugs-cols3 > :nth-child(3){grid-column:auto}
}

/* ── a section that reads as a section ─────────────────────────────────
   "make the sections prominent and don't give me onwards any classic throw away
   looks". So: a bordered block, a tinted head bar carrying an icon, a real
   title, a sentence saying what it is for, and a figure on the end. Not an
   uppercase word floating over a hairline. */
.ugs-sec{display:grid;grid-template-rows:auto minmax(0,1fr);min-width:0;
         border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
         background:var(--surface,#fff);overflow:hidden}
.ugs-sech{display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:10px;align-items:center;
          padding:10px 12px;min-width:0;background:var(--surface-2,#f2f4fb);
          border-block-end:1px solid var(--border,#e6e9f2)}
.ugs-sech > *{min-width:0}
.ugs-secn{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;
          background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
          color:var(--ink-2,#3c465c)}
.ugs-secn svg{width:16px;height:16px}
.ugs-sect{display:block;font-size:13px;font-weight:700;letter-spacing:-.01em;line-height:1.3}
.ugs-secs{display:block;font-size:11px;font-weight:500;color:var(--ink-soft,#626c80);
          line-height:1.45;margin-block-start:2px}
.ugs-secw{font-size:10px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;
          color:var(--ink-faint,#97a0b2);white-space:nowrap}
.ugs-secb{display:grid;gap:10px;padding:12px;min-width:0}
.ugs-secb > *{min-width:0}
.ugs-sec.is-live{border-color:var(--accent,#15a85a)}
.ugs-sec.is-live .ugs-sech{background:var(--accent-soft,#e7f7ee);
                           border-block-end-color:var(--accent,#15a85a)}
.ugs-sec.is-live .ugs-secn{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a)}
.ugs-sec.is-live .ugs-secw{color:var(--accent-ink,#0b6e3a)}
.ugs-sec.is-warm{border-color:#f0dcb4}
.ugs-sec.is-warm .ugs-sech{background:var(--amber-soft,#fdf2e2);border-block-end-color:#f0dcb4}
.ugs-sec.is-warm .ugs-secn{border-color:#f0dcb4;color:#8a6212}
.ugs-sec.is-warm .ugs-secw{color:#8a6212}
/* The buttons a section offers, wrapped rather than squeezed at 390px. */
.ugs-cutb{display:flex;flex-wrap:wrap;gap:8px;min-width:0}
.ugs-f{display:grid;gap:4px;min-width:0}
.ugs-f label{font-size:11.5px;font-weight:650;color:var(--ink-2,#3c465c);overflow-wrap:anywhere}
.ugs-f input[type=text],.ugs-f input[type=datetime-local],.ugs-f input[type=number],
.ugs-f select,.ugs-f textarea{width:100%;min-width:0;padding:9px 11px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:inherit;
  transition:border-color .16s var(--ease),box-shadow .16s var(--ease)}
.ugs-f input:focus,.ugs-f select:focus,.ugs-f textarea:focus{outline:none;
  border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px var(--accent-soft,#e7f7ee)}
.ugs-f textarea{resize:vertical;min-height:66px}

/* ── notes ─────────────────────────────────────────────────────────────── */
.ugs-note{border:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb);
          border-radius:var(--r-sm,12px);padding:10px 12px;font-size:11.5px;line-height:1.55;
          color:var(--ink-soft,#626c80);min-width:0}
.ugs-note b{color:var(--ink,#101729)}
.ugs-note.is-warm{border-color:#f0dcb4;background:var(--amber-soft,#fdf2e2)}
.ugs-note.is-cool{border-color:#cfdcf8;background:var(--blue-soft,#eaf0fd)}
.ugs-note.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb);color:#8c2f2c}
.ugs-note ul{margin:6px 0 0;padding-inline-start:18px}

/* ── the drop zones ────────────────────────────────────────────────────
   The real input is underneath and is what opens the picker: the label is its
   activator, natively, so nothing here forwards a click in script. It keeps a
   1px box rather than display:none so the keyboard can still reach it, and the
   ring is drawn on the zone through :focus-within. */
.ugs-drop{position:relative;display:grid;place-items:center;gap:4px;text-align:center;
          padding:18px 14px;border:1.5px dashed var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
          background:var(--surface-2,#f2f4fb);cursor:pointer;min-width:0;
          transition:border-color .16s var(--ease),background .16s var(--ease)}
.ugs-drop:hover{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.ugs-drop.is-over{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee);border-style:solid}
.ugs-drop:focus-within{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.ugs-drop.is-locked{opacity:.6;cursor:not-allowed;border-style:solid}
.ugs-drop.is-locked:hover{border-color:var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb)}
.ugs-file{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.ugs-dropi{width:24px;height:24px;color:var(--ink-faint,#97a0b2)}
.ugs-drop.is-over .ugs-dropi{color:var(--accent,#15a85a)}
.ugs-dropb{font-size:12.5px;font-weight:650}
.ugs-drops{font-size:10.5px;color:var(--ink-faint,#97a0b2);line-height:1.45}

/* ── the upload bar ────────────────────────────────────────────────────
   Every number in here is measured: the percentage, the bytes sent and the
   total all came off the upload's own progress event. There is NO indeterminate
   variant, on purpose -- a bar that sweeps while nothing is known is a bar that
   lies. is-bad is the refused ending, and it stays on screen. */
.ugs-up{display:grid;gap:7px;border:1px solid var(--accent,#15a85a);border-radius:var(--r-sm,12px);
        padding:11px 12px;background:var(--accent-soft,#e7f7ee);min-width:0}
.ugs-up.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb)}
/* A wait long enough to be worth remarking on, in colour as well as in words.
   The class is written by the clock, which counts seconds and reads no layout. */
.ugs-up.is-slow{border-color:#f0dcb4;background:var(--amber-soft,#fdf2e2)}
.ugs-up.is-slow .ugs-uph span,.ugs-up.is-slow .ugs-upm{color:#8a6212}
.ugs-uph{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
         font-size:11.5px;font-weight:650;min-width:0}
.ugs-uph span{white-space:nowrap;color:var(--accent-ink,#0b6e3a)}
.ugs-upn{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
/* The file name, its size and what the request is doing, on one wrapping row. */
.ugs-upm{display:flex;flex-wrap:wrap;gap:3px 12px;font-size:10.5px;line-height:1.5;
         color:var(--ink-soft,#626c80);min-width:0}
.ugs-upm > *{min-width:0;overflow-wrap:anywhere}
.ugs-up.is-bad .ugs-uph span,.ugs-up.is-bad .ugs-upm{color:#8c2f2c}
.ugs-prog{height:6px;border-radius:999px;background:var(--surface,#fff);overflow:hidden}
/* The panel's one control: Cancel while the request is in flight, Try again
   after a failure a second attempt could actually fix. A flex row rather than a
   grid child so the button is its own width instead of a slab across the panel,
   and it wraps on a phone. */
.ugs-upa{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0}
.ugs-progb{height:100%;width:0;background:var(--accent,#15a85a);transition:width .18s var(--ease)}

/* ── the media step ────────────────────────────────────────────────────
   .ugs-slot IS NOW THE BODY OF A .ugs-sec rather than a card of its own: the
   section draws the border, the tinted head and the padding, and this keeps the
   two declarations that were paid for.

   align-content:start IS LOAD-BEARING. The two media sections are grid items in
   a two-column row, so both used to stretch to the taller one -- and the cover
   slot, which has no preview until a cover exists, then spread its own rows to
   fill that height: a stretched "Choose from the Media Library" button with a
   void of white above it, beside a video preview that was 640px tall. It read
   as a broken panel. Packing the rows to the top leaves the slot as tall as its
   neighbour and its CONTENT its own size. .ugs-cols now also packs the sections
   themselves to the top, so the defect is shut twice. */
.ugs-slot{display:grid;gap:8px;align-content:start;min-width:0}
/* A button in a grid also stretches across its column by default, so it keeps
   the full width deliberately rather than by accident.

   NO ELLIPSIS IN A COMMENT ANYWHERE IN THIS STYLE BLOCK. UgcAdminScreenTest
   scans the whole block, comments included, for a dot followed by a word and
   proves every one of them is prefixed ugs- -- because a rule here named for
   a bare word would restyle every other screen in this console. An ellipsis
   before a word parses as exactly that shape, and this line is where it went
   red. Prose in here starts its sentences with a capital instead. */
.ugs-slot > .ugs-btn{justify-self:stretch}
/* A fixed 9:16 box rather than the file's own shape: the preview then reserves
   its space before a byte of video arrives, which is the same reason the shop's
   tile reserves from width and height instead of measuring. */
.ugs-pv{border-radius:var(--r-sm,12px);overflow:hidden;background:#0b0f18;min-width:0;
        margin-inline:auto;width:min(100%,clamp(150px,18vw,230px));aspect-ratio:9/16}
.ugs-pv video,.ugs-pv img{display:block;width:100%;height:100%;object-fit:contain;background:#0b0f18}
/* The loop tile beside the prose about it: one fixed track for the tile and one
   fluid one for the words, from 900px, and one column below that. In the
   STYLESHEET rather than in a style attribute, which is the one place a media
   query cannot reach. */
.ugs-loopcols{display:grid;gap:12px;grid-template-columns:1fr;align-items:start;min-width:0}
.ugs-loopcols > *{min-width:0}
@media (min-width:900px){ .ugs-loopcols{grid-template-columns:178px minmax(0,1fr)} }
.ugs-loopbox{display:grid;gap:6px;justify-items:center;min-width:0}
/* 158px is the shop's own rail tile width, so this is the size it really is. */
.ugs-loop{width:min(100%,158px);aspect-ratio:9/16;border-radius:var(--r-sm,12px);overflow:hidden;
          background:#0b0f18;position:relative}
/* ── THE PREVIEW SHOWED THE COVER AND NEVER THE CLIP ──────────────────────

   These two used to be `display:block;width:100%;height:100%` and NOTHING
   ELSE. Both are in normal flow, so in a box that is 158px wide and
   aspect-ratio 9/16 -- 281px tall -- the <img> took the whole 281px and the
   <video> wireLoop() appends AFTER it was laid out at 281px, below the box,
   where `overflow:hidden` clipped it. MEASURED in Chromium against these exact
   rules: box top 8 height 281, poster top 8 height 281, VIDEO TOP 289. The
   element was mounted, had its src, and was playing; it was simply not on
   screen, so the column headed "The 2.5 second loop" showed a still and the
   prose beside it said the tile was playing.

   The shop has never had this bug and the fix is its rule, not a new one: the
   rail's own stylesheet (resources/views/ugc/assets, line 129) stacks its
   poster and video with `position:absolute;inset:0`. This preview's whole
   claim is "what a shopper sees on the rail, at the size the tile really is",
   so it should stack the way the rail does.

   THE FILE NAME ABOVE IS WRITTEN WITHOUT ITS EXTENSION ON PURPOSE, and this
   sentence is too. UgcAdminScreenTest scrapes every class selector out of this
   <style> block to prove none of them would restyle another screen, and a
   Blade template's dotted extension inside a comment reads to that scanner as
   two more class rules. It went red on exactly that -- twice, because the
   first attempt to explain the trap spelled the extension out and re-armed
   it -- which is the scanner working.

   The poster stays UNDERNEATH rather than being removed, and that is the
   smooth part: a <video> with no decoded frame yet paints nothing, so the
   cover shows through until the first frame arrives and the swap has no black
   flash and no reflow. z-index is explicit rather than left to DOM order,
   because a repaint that re-inserts the <img> last would otherwise hide the
   clip again -- which is exactly the class of bug this comment is about. */
.ugs-loop video,.ugs-loop img{display:block;width:100%;height:100%;object-fit:cover;
                              position:absolute;inset:0}
.ugs-loop img{z-index:0}
.ugs-loop video{z-index:1}
.ugs-loopcap{font-size:10px;color:var(--ink-faint,#97a0b2);text-align:center;line-height:1.4;max-width:158px}
.ugs-path{font-size:10px;color:var(--ink-faint,#97a0b2);overflow-wrap:anywhere;line-height:1.4}
/* The optional extra, folded away rather than thrown at the owner beside the
   two files he actually has to provide. */
.ugs-more{border-block-start:1px solid var(--border,#e6e9f2);padding-block-start:9px}
.ugs-more > summary{font-size:11.5px;font-weight:650;cursor:pointer;color:var(--ink-2,#3c465c);
                    list-style:none;display:flex;align-items:center;gap:6px}
.ugs-more > summary::-webkit-details-marker{display:none}
.ugs-more > summary::before{content:"+";font-size:13px;line-height:1;color:var(--ink-faint,#97a0b2)}
.ugs-more[open] > summary::before{content:"\2212"}
.ugs-morebody{display:grid;gap:8px;margin-block-start:9px}

/* ── the publish checklist ─────────────────────────────────────────────── */
.ugs-check{display:grid;gap:7px;min-width:0}
.ugs-ci{display:grid;grid-template-columns:18px 1fr;gap:9px;align-items:start;
        font-size:12px;line-height:1.45;min-width:0}
.ugs-cid{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;
         font-size:10px;font-weight:700;background:var(--surface-3,#eef1f9);color:var(--ink-faint,#97a0b2)}
.ugs-ci.is-ok .ugs-cid{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.ugs-ci.is-no .ugs-cid{background:var(--red-soft,#fdeceb);color:var(--red,#e3493f)}

/* ── product tagging ───────────────────────────────────────────────────── */
.ugs-tagged{display:grid;gap:6px;min-width:0}
.ugs-tag{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center;
         border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);padding:7px 10px;min-width:0;
         background:var(--surface,#fff);
         transition:border-color .16s var(--ease),box-shadow .16s var(--ease),opacity .16s var(--ease)}
.ugs-tag > *{min-width:0}
/* The row being carried, and the row it would land on. Both are a CLASS the
   drag events add -- nothing here reads a coordinate. */
.ugs-tag.is-lifted{opacity:.4}
.ugs-tag.is-landing{border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px var(--accent-soft,#e7f7ee)}
/* The grip is the only draggable part on a touch-less mouse drag, and it says
   so: `cursor:grab` is the whole affordance. */
.ugs-grip{display:grid;place-items:center;width:20px;height:24px;cursor:grab;
          color:var(--ink-faint,#97a0b2);border-radius:var(--r-xs,9px)}
.ugs-grip:active{cursor:grabbing}
.ugs-grip svg{width:14px;height:14px}
.ugs-tagname{font-size:12px;line-height:1.35;overflow-wrap:anywhere}
.ugs-tagacts{display:flex;gap:4px;flex-wrap:wrap}
.ugs-results{display:grid;gap:5px;margin-top:8px;max-height:230px;overflow:auto;min-width:0}
.ugs-res{display:block;width:100%;text-align:start;padding:8px 10px;border:1px solid var(--border,#e6e9f2);
         border-radius:var(--r-xs,9px);background:var(--surface,#fff);color:inherit;font:inherit;
         font-size:12px;cursor:pointer;overflow-wrap:anywhere}
.ugs-res:hover{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.ugs-dim{opacity:.6}

/* ── the sticky footer of the editor ───────────────────────────────────── */
.ugs-foot{position:sticky;inset-block-end:-14px;display:flex;flex-wrap:wrap;gap:9px;
          align-items:center;justify-content:space-between;
          border-block-start:1px solid var(--border,#e6e9f2);
          background:var(--surface,#fff);padding-block:11px;margin-block-start:2px;min-width:0;z-index:2}
.ugs-footb{display:flex;flex-wrap:wrap;gap:8px;min-width:0}
.ugs-state{font-size:11.5px;color:var(--ink-soft,#626c80);line-height:1.4;min-width:0;flex:1 1 180px}

/* ── the library grid ──────────────────────────────────────────────────
   auto-fill with a clamped track: three columns on a 390px phone and five on a
   1280px desktop, from one rule and no measurement. auto-fill, not auto-fit,
   so a library of one does not stretch one tile across the screen. */
.ugs-grid{display:grid;gap:10px;min-width:0;
          grid-template-columns:repeat(auto-fill,minmax(clamp(106px,11vw,150px),1fr))}
.ugs-tile{position:relative;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
          background:var(--surface,#fff);overflow:hidden;min-width:0;
          transition:border-color .16s var(--ease),box-shadow .16s var(--ease)}
.ugs-tile:hover{border-color:var(--ink-faint,#97a0b2);box-shadow:var(--sh,0 1px 2px rgba(16,24,40,.05))}
.ugs-tilemain{display:block;width:100%;padding:0;border:0;background:transparent;color:inherit;
              font:inherit;text-align:start;cursor:pointer;min-width:0}
.ugs-tilemain:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:-2px}
.ugs-shot{aspect-ratio:3/4;background:var(--surface-3,#eef1f9);display:grid;place-items:center;
          color:var(--ink-faint,#97a0b2);font-size:10px;text-align:center;overflow:hidden}
.ugs-shot img{width:100%;height:100%;object-fit:cover;display:block}
.ugs-tb{padding:7px 8px 9px;display:grid;gap:4px;min-width:0}
.ugs-tn{font-size:11.5px;font-weight:650;line-height:1.3;overflow-wrap:anywhere;
        display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ugs-tm{font-size:10px;color:var(--ink-faint,#97a0b2);line-height:1.35;overflow-wrap:anywhere}
.ugs-x{position:absolute;inset-block-start:6px;inset-inline-end:6px;width:24px;height:24px;
       border-radius:50%;border:1px solid rgba(255,255,255,.45);background:rgba(11,15,24,.5);
       color:#fff;font-size:15px;line-height:1;cursor:pointer;display:grid;place-items:center;padding:0}
.ugs-x:hover{background:var(--red,#e3493f);border-color:var(--red,#e3493f)}

/* ── pills ─────────────────────────────────────────────────────────────── */
.ugs-pills{display:flex;flex-wrap:wrap;gap:4px}
.ugs-pill{font-size:9.5px;font-weight:700;letter-spacing:.02em;padding:2px 7px;border-radius:999px;
          border:1px solid var(--border,#e6e9f2);color:var(--ink-soft,#626c80);white-space:nowrap}
.ugs-pill.is-live{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a);
                  background:var(--accent-soft,#e7f7ee)}
.ugs-pill.is-hold{border-color:#f3c9c6;color:var(--red,#e3493f);background:var(--red-soft,#fdeceb)}
.ugs-pill.is-info{border-color:#cfdcf8;color:#2f52a8;background:var(--blue-soft,#eaf0fd)}
.ugs-pill.is-soft{border-color:#f0dcb4;color:#8a6212;background:var(--amber-soft,#fdf2e2)}

/* ── empty and loading ─────────────────────────────────────────────────── */
.ugs-empty{padding:26px 14px;text-align:center;color:var(--ink-soft,#626c80);font-size:12.5px;
           line-height:1.6;display:grid;gap:10px;justify-items:center}
.ugs-sk{border-radius:var(--r-sm,12px);aspect-ratio:3/4;
        background:linear-gradient(90deg,var(--surface-2,#f2f4fb) 25%,var(--surface-3,#eef1f9) 37%,
                   var(--surface-2,#f2f4fb) 63%);
        background-size:400% 100%;animation:ugs-shimmer 1.4s var(--ease) infinite}
@keyframes ugs-shimmer{0%{background-position:100% 0}100%{background-position:0 0}}
@media (prefers-reduced-motion:reduce){
  .ugs-sk{animation:none}
  .ugs-btn,.ugs-step,.ugs-drop,.ugs-tile,.ugs-progb{transition:none}
}
@media (max-width:640px){ .ugs-card{padding:12px} .ugs-foot{inset-block-end:-12px} }

/* ── the repaint regions, the draft bar and the save state ──────────────────
   A REGION IS AN ELEMENT THIS SCREEN REPAINTS ON ITS OWN, and the whole point
   of naming them is that a step change no longer replaces #content.

   .ugs-rg is display:contents because two of the regions live inside
   `.ugs-panel{display:grid;gap:12px}`. A wrapper div there is a grid item, so an
   EMPTY one -- the blockers region on a clip with no blockers, which is most of
   them -- would add a 12px gap that was never on the page before. display:contents
   makes the wrapper contribute nothing at all when it is empty and hand its
   children straight to the grid when it is not, so the layout is byte-identical
   either way. Rule 1. */
.ugs-rg{display:contents}

/* The draft bar. It is deliberately NOT is-bad or is-warm: an unsaved draft is
   not an error, and colouring it like one would teach the owner to dismiss it. */
.ugs-draft{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;min-width:0;
           margin-bottom:12px;padding:10px 12px;border-radius:var(--r-sm,12px);
           border:1px solid #cfd9ee;background:var(--surface-2,#f2f4fb);
           color:var(--ink,#1f2633);font-size:12.5px;line-height:1.55}
.ugs-drafttext{flex:1 1 240px;min-width:0}
.ugs-drafttext b{font-weight:650}
.ugs-draftacts{display:flex;flex-wrap:wrap;gap:7px}

/* What the footer says about a save that is happening or has failed. The failed
   state is the loud one on purpose: a background save that fails quietly is the
   swallowed-write defect this project has already paid for once, wearing
   different clothes. The script below names it in full. */
.ugs-savest{flex:1 1 220px;min-width:0;font-size:12px;line-height:1.5;
            color:var(--ink-soft,#626c80)}
.ugs-savest.is-bad{color:var(--red-ink,#9b1c1c);font-weight:600}
.ugs-savest.is-bad b{font-weight:700}

/* The search's three answers. Each is a sentence rather than an empty box --
   the defect this fixes is a screen that said nothing at all. */
.ugs-rnote{padding:12px 10px;text-align:center;color:var(--ink-soft,#626c80);
           font-size:12px;line-height:1.6}
.ugs-rnote b{color:var(--ink,#1f2633);font-weight:650}
.ugs-rhead{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 8px;
           padding:1px 2px 4px;color:var(--ink-soft,#626c80);font-size:11.5px;
           line-height:1.5;font-weight:600;letter-spacing:.01em}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'ugcvideo';

  /* The five steps, and each one is a clause of the publish gate. See the
     docblock: this order is UgcVideo::publishBlockers() plus the two things the
     controller needs before a row can hold a file at all. */
  var STEPS = [
    { n: 1, label: 'Details',       sub: 'Name the clip' },
    { n: 2, label: 'Video & cover', sub: 'The two files' },
    { n: 3, label: 'Credit',        sub: 'Who made it' },
    { n: 4, label: 'Products',      sub: 'What is in it' },
    { n: 5, label: 'Publish',       sub: 'Where and when' }
  ];

  var videos = null, transcoder = null, limits = null, vocab = null, translatable = null;
  var motion = null;           // {ms, on} — the rail's REAL loop settings, read once
  var editing = null;          // the full row being edited, or null for the list
  var tagged = [];             // [{id, name, brand, at_ms}] in order
  var results = [];            // the product search's last answer
  var term = '';               // ...and what was typed to get it
  /*
   * ── WHY THE SEARCH NEEDED THREE MORE PIECES OF STATE ──────────────────────
   *
   * THE DEFECT, measured in Chromium before it was touched: type "anua" and the
   * results area stays COMPLETELY EMPTY for 905 ms -- the 250 ms debounce plus
   * the round trip -- and then, if nothing published matches, stays empty
   * forever. resultsHTML() opened with `if (!results.length) return '';`, so
   * every one of those states rendered the same nothing: nothing typed yet,
   * still looking, and nothing found. The owner typed four characters, was shown
   * absolutely nothing, and reasonably reported the search as broken. A silent
   * empty state is indistinguishable from a dead one.
   *
   * Worse, the one case he is most likely to hit is the one nothing explained.
   * The endpoint filters Product::visible(), so a DRAFT or hidden product is
   * correctly not returned -- and the screen said nothing about that either.
   * Measured here on a catalogue seeded with a draft Anua row: the term "rice
   * 70" matched a real product he owns and drew a blank box.
   *
   *   `searching` is a request in flight OR a debounce waiting to fire, so the
   *              gap is never silent.
   *   `searched`  is whether a term has been answered at all, which is what
   *              tells "nothing found" apart from "nothing asked".
   *   `recent`    is the last few products, fetched once when step 4 is first
   *              shown, so there is something to press before anybody types --
   *              the owner asked for exactly this.
   */
  var searching = false;
  var searched = false;
  var recent = null;
  var recentAsked = false;
  var unpublishedHits = 0;     // matched the term but are not publishable
  var banner = null, busy = false, seq = 0;

  /*
   * What the server could not work out, in its own words.
   *
   * SEPARATE FROM `banner` ON PURPOSE. banner means "you have no library";
   * this means "you have your library, and one optional thing beside it did
   * not answer". Drawing them in the same red box would say the screen is
   * broken when the only casualty is the ffmpeg line or the upload ceiling.
   */
  var probeErrors = [];
  var step = 1;                // which panel is on show
  var loopEl = null;           // the mounted preview video, released before each repaint

  /*
   * ══ WHY A STEP CHANGE USED TO COST A ROUND TRIP, AND WHAT IT COSTS NOW ══════
   *
   * MEASURED FIRST, in Chromium against a clip with the owner's own file on it
   * (8.5 MB, a cover, no teaser) over a link shaped like his -- 300 KB/s, 250 ms
   * round trip. Click until the target panel was on screen AND the frame carrying
   * it had been composited:
   *
   *   forward  1->2  1170 ms   2->3  1185 ms   3->4  1137 ms   4->5  1135 ms
   *   back     5->4    21 ms   4->3    14 ms   3->2    19 ms   2->1    19 ms
   *
   * So the two halves had completely different diseases, and only one of them was
   * slow:
   *
   * FORWARD was FOUR SEQUENTIAL ROUND TRIPS, not one. goStep() awaited save(),
   * and save() is PUT /ugc-videos/{id}, then POST .../products, then load(),
   * then open() -- each waiting on the one before, every time anybody moved one
   * step to the right. That is the 1.1 s, and on a shop with more clips in the
   * library and a real database behind it, it is the several seconds the owner
   * reported.
   *
   * BACKWARD WAS ALREADY 14-21 ms and made NO request at all. The five panels are
   * all in the DOM and toggled with `hidden`, so the panel swap itself was always
   * free. A wholesale repaint of #content is NOT what made going back feel slow,
   * and it is worth writing down that it was measured rather than assumed.
   *
   * WHAT WAS REALLY WRONG WITH THE REPAINT, then. render() replaced
   * #content.innerHTML outright, which destroyed and rebuilt both <video>
   * elements on EVERY repaint in EVERY direction -- proved by stamping a property
   * on them and watching it never survive. The cost of that is not the paint, it
   * is everything around it: the loop preview restarted from zero each time step
   * 2 came back, the main player rewound to 0, and the browser re-issued range
   * requests against the 8.5 MB file -- up to three per transition on a throttled
   * link, and one per transition for the cover even on a step that shows neither.
   * With media served the way a stock Apache directory serves it (a validator and
   * no max-age) that is a conditional round trip per element per step; with no
   * caching headers at all it is a re-download. Which of those the live box does
   * is the one thing this could not see from here, so the fix removes the question
   * instead of answering it: a repaint no longer touches the media at all.
   *
   * ── SO THERE ARE TWO MECHANISMS BELOW, AND THEY ARE INDEPENDENT ────────────
   *
   * `mounted` is the first. It holds mountKey() for the editor DOM currently on
   * screen, and render() compares against it: same key, and the repaint is
   * SURGICAL -- only the named regions are rewritten and #ugs-form's panels are
   * left exactly where they are, <video> and all. Different key, and the editor is
   * built from scratch. This is Lane V5's fix for the clip-editor dialog
   * (modalHost/renderModal/renderModalBody in ugc-sections-screen.blade.php)
   * applied to a screen that cannot move the thing out to <body>, because the
   * video sits inside the layout rather than on top of it: the element stays put
   * and the repaint gets smaller instead.
   *
   * mountKey() carries the three media paths ON PURPOSE. A repaint that did not
   * change the video must not rebuild it, and a change that DID -- an upload
   * landing, a cover adopted from the Media Library, the cut -- must. Keying on
   * the id alone would have left a stale player on screen after an upload, which
   * is the opposite bug and a worse one.
   *
   * The draft is the second, and it deliberately does NOT take part in a step
   * change: a step change keeps what was typed because the fields are no longer
   * repainted, full stop. See the draft block further down for why that
   * separation is the thing that makes the draft safe.
   */
  var mounted = null;

  /*
   * THE BACKGROUND SAVE, AND WHY IT IS NOT ALLOWED TO BE QUIET.
   *
   * A forward step still saves -- step 1 has to create the row before
   * /ugc-videos/{id}/media has an {id}, and nothing may be left behind between
   * two steps. What changed is that the paint no longer waits for it.
   *
   * `saving` is the request in flight. There is at most one, ever: a second
   * forward step while the first save is still going sets `saveAgain` instead of
   * opening a parallel PUT of the same row, because two saves of one row racing
   * each other is how a field gets written back to the value it had before it was
   * edited.
   *
   * `saveFail` is the ending that MUST NOT VANISH. CLAUDE.md's
   * UpdateRunner::recordManifest() landmine is about exactly this shape -- a
   * write whose failure was swallowed -- and a save that now happens where nobody
   * is looking makes a toast the wrong instrument: a toast that appears while the
   * owner is reading step 4 and is gone before he looks up is indistinguishable
   * from no message at all. So a failed background save leaves a sentence in the
   * footer, with a Try again button, and it stays there until a save succeeds. The
   * draft is NOT cleared while it stands, so nothing typed is riding on one
   * request.
   *
   * `bgSave` is how goStep() tells the one save() writer not to lock the screen.
   * A flag rather than an argument because save() is the single writer for every
   * save on this screen and UgcAddClipFlowTest reads its body by name -- one
   * writer, the argument CLAUDE.md makes for update_releases.
   */
  var saving = false, saveAgain = false, bgSave = false, saveFail = null, savedOnce = false;

  /*
   * ══ THE QUICK DRAFT ════════════════════════════════════════════════════════
   *
   * The owner asked for it in those words -- "save temporary data / or make it
   * quick draft to avoid such long delays" -- and the instinct is right, but the
   * reason it is right is not the one the phrasing suggests, so this block says
   * what this draft is FOR and what it is deliberately not for.
   *
   * IT IS NOT ON THE PATH OF A STEP CHANGE. Typing survives moving between steps
   * because the fields are no longer repainted -- `mounted` above -- and that
   * needed no storage at all. If the draft were what carried typing across a step
   * change, then every step change would be a read of a copy of the row, and the
   * screen would be showing values whose provenance nobody could see. So the draft
   * covers exactly one thing the DOM cannot: a RELOAD, a closed tab, a crash.
   *
   * ── HOW IT CAN NEVER BE MISTAKEN FOR SAVED DATA ───────────────────────────
   *
   * This is the part that matters, and the rule is one sentence: A DRAFT IS NEVER
   * APPLIED WITHOUT BEING ASKED FOR. Opening a clip paints the SERVER'S values,
   * always. If a draft exists for that clip, a bar appears above the steps saying
   * so in as many words -- "You have unsaved changes to this clip", with when they
   * were typed -- and two buttons: Restore them, and Discard. Until one is
   * pressed the screen is showing the database and nothing else.
   *
   * WHAT WAS REJECTED, and why each one is worse:
   *
   *   - Merging the draft over the row on open, silently. This is the obvious
   *     implementation and it is the one the brief warns about: the owner would
   *     be looking at values that are not in the database, with no way to tell
   *     which were which, and the next partial save would write a mixture of the
   *     two. A draft that silently diverges from the server is worse than the
   *     delay it removes.
   *   - Treating the draft as the source of truth for the form. Same defect,
   *     arrived at from the other side.
   *   - sessionStorage. It dies with the tab, which is one of the three cases
   *     this exists for.
   *   - One draft for the whole screen. It would arrive on the next clip opened.
   *     Keyed per clip instead.
   *
   * ── AND IT REFUSES TO OVERWRITE SOMEBODY ELSE'S WORK BLIND ────────────────
   *
   * The draft records the row's `updated_at` as it was when the draft was taken.
   * If the row has been saved since -- by somebody else, or in another tab -- the
   * bar says THAT too, because restoring would then put back values that were
   * composed against a version of the row that no longer exists. It still offers
   * the restore; it just stops being a silent one.
   *
   * ── WHEN IT IS CLEARED ────────────────────────────────────────────────────
   *
   * On a save that succeeded, on delete, and on Discard. Never on a save that
   * failed -- that is the one moment the draft is the only copy of what was typed.
   *
   * Every read and write is wrapped: localStorage throws outright in a browser set
   * to block site data, and a screen that cannot store a draft must still work.
   */
  var DRAFT_KEY = 'kbb.ugs.draft.v1.';
  var DRAFT_MAX = 64 * 1024;   // a draft is short text; past this it is not stored
  var draftFound = null;       // {at, stale} offered on open, applied only on request
  var draftTimer = null;
  /*
   * THE ROW AS THE SERVER LAST HANDED IT OVER, fingerprinted.
   *
   * The draft records this alongside the typing so that reopening the clip can
   * tell "you have unsaved changes" from "you have unsaved changes AND the clip
   * has moved underneath them". There is no updated_at on any of these payloads
   * -- card() does not carry one -- and rather than assert staleness this screen
   * cannot see, it compares the row it was handed then with the row it is handed
   * now. `editing` is no good for this: keepTyped() mutates it, so by the time a
   * draft is written it is no longer the server's answer.
   */
  var serverPrint = null;

  /*
   * THE UPLOAD, WHILE IT IS HAPPENING AND AFTER IT HAS.
   *
   * `upState` is the request in flight: {kind, name, total, sent, pct, stage,
   * text, stalled}. Every number in it was handed over by the kit off
   * `xhr.upload.onprogress` — none is interpolated, guessed or animated — and
   * `stage` is 'sending' until `xhr.upload.onload` says the last byte has left
   * this browser, 'server' after. `text` is the kit's composed sentence, which
   * is where the speed and the time remaining reach the panel.
   *
   * `upDone` is the ENDING, which the old screen threw away: the panel vanished
   * the instant the request landed, so a 31 MB upload finished with no trace it
   * had happened and a refusal left only a toast. It holds
   * {ok, kind, name, bytes, message} until the next upload replaces it.
   *
   * `freshClip` is the id whose VIDEO has just arrived, which is what makes the
   * cut the next thing on screen rather than something to be noticed.
   */
  var upState = null;
  var upDone = null;
  var freshClip = null;

  /* The File the operator just chose, kept so the in-browser cover cut can read
     the bytes already in this tab instead of downloading the clip back. */
  var freshFile = null;

  /* True while the browser is decoding a frame. Its own flag and not `busy`:
     `busy` disables the whole form, and this is a few hundred milliseconds of
     work on one button. */
  var cutting = false;

  /* Where the cover cut has got to: {pct, words}. Its own state, and NOT the
     upload panel's -- the two can be on screen together, and a cut that
     borrowed the upload's bar would overwrite the "8.4 MB arrived whole" the
     owner is still reading. */
  var cutStage = null;

  /* The timer that takes the finished bar off screen. Held so a second cut
     cannot leave an earlier one's timeout to clear its bar out from under it. */
  var cutClear = null;

  /*
   * WHY THIS SERVER COULD NOT CUT, KEPT RATHER THAN FLASHED.
   *
   * THE DEFECT. The endpoint's explanation arrived in `payload.notes` and the
   * screen did `(payload.notes || []).forEach(say)` — so the one sentence that
   * says WHY there is no cover appeared as a toast for a couple of seconds and
   * was gone, while the thing that stayed on screen was a bare "No cover yet"
   * badge with nothing beside it. The owner is then looking at a clip that says
   * a file is missing and offers no reason and no remedy, which is how a fixable
   * server setting reads as a broken upload.
   *
   * The two reasons are kept APART on purpose, because the endpoint keeps them
   * apart on purpose: "this server has no ffmpeg" and "ffmpeg is installed but
   * PHP is not allowed to start it" have completely different remedies — install
   * a program, or change a PHP setting — and collapsing them into one sentence
   * sends half the readers to do the wrong thing. This screen never composes
   * either of them; it prints whichever the server sent, verbatim.
   *
   * It is per-clip state like the upload panel, dropped by forgetUpload() when
   * the screen moves to another clip — a reason about THIS upload must not sit
   * on somebody else's row. It survives a step change for free, because a step
   * change no longer rebuilds anything.
   */
  var cutNote = null;

  /*
   * THE REQUEST ITSELF.
   *
   * `upXhr` is the handle window.kbbUpload() returns, kept so Cancel can stop
   * the request the owner is looking at and for no other reason — it is nulled
   * on every ending.
   *
   * ── THE CLOCK, THE STALL THRESHOLD AND THE WORDING ALL MOVED OUT ──────────
   *
   * They used to be here: a one-second setInterval, a STALL_AFTER of 20, and a
   * stageWords() that composed the sentence. All three are now in
   * partials/upload-kit.blade.php, and that is a fix rather than a tidying.
   *
   * The fixed 20-second threshold was BELOW the legitimate quiet interval on a
   * slow link. Measured against a server reading the body at a fixed rate, the
   * gap between two progress events on the owner's own 8.4 MB file is 2,610 ms
   * at 600 KB/s, 5,624 ms at 300 KB/s and 16,764 ms at 100 KB/s — Chromium fires
   * the event when the socket buffer drains, in strides of about 1.6 MB, so the
   * bar is EXACTLY still between them. Twenty seconds therefore accused a
   * perfectly healthy upload of being stuck and told the owner to cancel it.
   * The kit's stallAfter() computes the threshold from the stride and the speed
   * this upload is actually producing.
   *
   * And "All of it has arrived. The server is checking the file" was measured to
   * be FALSE for up to 49 seconds: xhr.upload.onload fires when the last byte
   * enters the kernel buffer, not when the server has it. One copy of that
   * sentence, in the kit, is how it stops being wrong in one screen and right in
   * another. The kit's docblock carries every measurement.
   */
  var upXhr = null;

  /*
   * EVERY WAY AN UPLOAD CAN FAIL ENDS HERE, and that is the point rather than a
   * tidying.
   *
   * There are four of them — the pre-flight refusal, a non-2xx answer, a request
   * that never reached the server, and Cancel — and each one has to do the same
   * five things: stop the clock, drop the request, unlock the screen, leave a
   * panel on screen naming the file, and say it. Four copies of that is three
   * chances to forget the clock, which leaves a dead setInterval repainting a
   * panel that is no longer there. ONE writer, the same argument
   * UpdateRunner::recordManifest() records for update_releases.
   *
   * `retry` is a File and is set only where a second attempt could actually
   * work: a dropped connection, a throttle, a server fault, a cancel. A file
   * over this server's limit gets no Try again button, because pressing it would
   * fail in exactly the same way and the button would be a lie.
   */
  /**
   * Keep the server's explanation, and keep only one.
   *
   * The notes are still said out loud — a toast is the right instrument for
   * "here is what just happened" — and now they also STAY, which is the right
   * instrument for "here is why this clip looks like that". Whichever arrived
   * last is the current truth about this server.
   */
  function rememberNotes(notes) {
    var list = (notes || []).filter(function (n) { return typeof n === 'string' && n !== ''; });
    if (!list.length) return;
    cutNote = list[list.length - 1];
  }

  function failUpload(ending) {
    upState = null;
    upXhr = null;
    busy = false;
    upDone = { ok: false, kind: ending.kind, name: ending.name, bytes: ending.bytes,
               message: ending.message, retry: ending.retry || null,
               /*
                * WHETHER THE ROW SURVIVED THE FAILURE, straight from the server.
                *
                * THE DEFECT THIS EXISTS FOR, and it is the most expensive line
                * this screen ever printed: the panel below said "nothing on the
                * clip was changed" for EVERY failure, as a static sentence,
                * without knowing. When the transcode blew up after the file and
                * the row had already been committed, the owner was told his
                * 8.4 MB upload had failed when it had succeeded — so he uploaded
                * it again, minutes of a slow uplink at a time, orphaning a file
                * on every pass.
                *
                * `stored` is the endpoint's own answer to that question. THREE
                * states, not two: true, false, and — for a request that never
                * reached the server, or an older server that does not send the
                * key — undefined, which means NOBODY KNOWS and the panel must
                * say neither. Defaulting undefined to false would put the wrong
                * sentence back on exactly the path that has no evidence.
                */
               stored: ending.stored };
    say(upDone.message);
    render();
  }

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The console is served at <base>/admin, and every endpoint at
     <base>/admin-api/… — one place that knows it, for fetch and for the upload's
     XMLHttpRequest alike. */
  function apiBase() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
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

    var r = await fetch(apiBase() + '/admin-api' + path, opts);
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

  /* Rule 5, the second lock. The server stored these through UgcPath::link()
     and UgcPath::stored(), which is the real guard; both are re-read here
     because the next person to move this markup should not have to know. */
  function safeHref(u) {
    u = String(u == null ? '' : u);
    return /^https?:\/\//i.test(u) ? u : '';
  }

  function safeMedia(p) {
    p = String(p == null ? '' : p);
    return /^\/uploads\/ugc\/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:mp4|webm|jpg|jpeg|png|webp)$/.test(p)
      ? p : '';
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
    /*
     * 413, AND THIS IS THE ONE THE OWNER HIT. The whole request body was over
     * PHP's post_max_size, so PHP threw every byte of it away and Laravel's
     * global ValidatePostSize refused the request before this app's own handler
     * ran — which is why there may be no `error` key to print here. Without this
     * branch the panel fell through to "That file was not accepted", which blames
     * a file that never arrived.
     *
     * The server's own sentence wins where there is one: the endpoint composes it
     * with the real numbers when it gets the chance to answer at all.
     */
    if (e && e.status === 413) {
      if (e.body && e.body.error) return e.body.error;
      return 'That upload was too big for one request on this server. PHP here accepts at most '
           + serverIni('post_max_size') + ' per request and ' + serverIni('upload_max_filesize')
           + ' per file, and it discarded this one before the shop saw any of it — which is why '
           + 'the bar reached 100% and it still failed. Nothing was changed and the file itself '
           + 'is fine. Raise both on the server.';
    }
    /*
     * ── 401 / 419: THE SESSION WENT, NOT THE FEATURE ───────────────────────
     *
     * The console's shell is server-rendered at sign-in and then lives in the
     * tab for as long as it is left open. Every screen after that is XHR. So an
     * admin session that lapses does not bounce anybody to a login page — it
     * lets the console keep painting and refuses the DATA, which arrives here
     * as a 401 and, before this branch, as the same anonymous sentence a
     * crashed server gives. A reload is the entire remedy and nothing at all
     * was wrong with the shop, which is not a guess anybody could make from
     * "could not be read".
     *
     * 419 is the same family: Laravel's answer to a CSRF token that aged out
     * under a console left open overnight.
     */
    if (e && (e.status === 401 || e.status === 419)) {
      return 'Your admin session has expired — nothing is wrong with this screen or your clips. '
           + 'Reload the page and sign in again.';
    }
    /*
     * ── 5xx: SAY SO, AND SAY WHERE THE REASON IS ───────────────────────────
     *
     * A 500 here used to be indistinguishable from a dropped network: both
     * produced one sentence naming no cause, carrying no status, and offering
     * nothing to do next. The status was on the error object the whole time and
     * was thrown away one line later.
     *
     * Laravel answers a 500 with {"message": ...} and NOT {"error": ...}, so the
     * last line of this function — which reads `error` only — could never have
     * shown a server crash's own words. APP_DEBUG=false reduces that message to
     * "Server Error", which is why the log line matters more than the message
     * and why the command to read it is printed rather than described.
     */
    if (e && e.status >= 500) {
      return 'The server could not answer (HTTP ' + e.status + ').'
           + (e.body && (e.body.error || e.body.message)
               ? ' It said: “' + (e.body.error || e.body.message) + '”.' : '')
           + ' The reason is in the log — over SSH run:  tail -n 40 storage/logs/laravel.log';
    }
    if (e && e.body && e.body.error) { return e.body.error; }
    /*
     * ANYTHING LEFT CARRIES ITS NUMBER. A status is a fact the browser already
     * has; printing it costs nothing and is the difference between a report
     * somebody can act on and a shrug. A status of 0 is fetch's own answer for
     * a request that never completed — offline, or the tab suspended — so that
     * one is named rather than printed as a number nobody can look up.
     */
    if (e && e.status === 0) { return fallback + ' The request never reached the server — check the connection.'; }
    return e && e.status ? fallback + ' (HTTP ' + e.status + ')' : fallback;
  }

  function kb(n) {
    if (!n) return '—';
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  }

  /*
   * The same figure, but always a number.
   *
   * kb() answers an em dash for nothing, which is right beside "The video" on a
   * clip that has none. It is wrong inside the upload panel, where zero bytes
   * sent is a fact and "— of 31.0 MB" reads as a panel that has lost track.
   */
  function bytes(n) {
    n = Number(n) || 0;
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  }

  function cap(kind) {
    if (kind === 'clip') return limits ? limits.clip_mb : 64;
    if (kind === 'teaser') return limits ? limits.teaser_mb : 8;
    return limits ? limits.poster_mb : 4;
  }

  /*
   * ── THE CEILING THIS SERVER WILL REALLY HONOUR ──────────────────────────
   *
   * THE DEFECT, IN THE OWNER'S WORDS AND NUMBERS. He uploaded an 8.4 MB .mp4
   * here. The bar reached 100% and the panel said "That file was not accepted.
   * 8.4 MB — nothing on the clip was changed." His file was fine. That server
   * has upload_max_filesize=2M and post_max_size=8M, so PHP discarded the whole
   * request body on the way in, while this screen was advertising "up to 64 MB"
   * because 64 is what UgcMedia::MAX_BYTES says.
   *
   * cap() above now answers the EFFECTIVE number, because the payload's
   * clip_mb is now min(app cap, upload_max_filesize, post_max_size less the
   * multipart overhead) — see App\Support\ServerUploadLimits. The three below
   * are what that number cannot carry on its own:
   *
   *   capBytes()  the exact ceiling, for the pre-flight refusal. cap() is a
   *               FLOORED megabyte count, so refusing against cap() * 1048576
   *               would refuse a file the server would have taken.
   *   capWords()  the ceiling as an operator says it, which below a megabyte has
   *               to be "512 KB" and not "0 MB".
   *   cappedBy()  which of the three is doing the capping, bounded to the two
   *               ini names the server may send and '' for anything else — the
   *               screen switches on it and prints it, so rule 5 applies.
   */
  function capBytes(kind) {
    var d = limits && limits[kind];
    if (d && typeof d.effective_bytes === 'number' && d.effective_bytes >= 0) {
      return d.effective_bytes;
    }
    return cap(kind) * 1048576;
  }

  function capWords(kind) {
    var d = limits && limits[kind];
    return (d && typeof d.effective_label === 'string' && d.effective_label)
      ? d.effective_label : (cap(kind) + ' MB');
  }

  function appWords(kind) {
    var d = limits && limits[kind];
    return (d && typeof d.app_mb === 'number') ? (d.app_mb + ' MB') : (cap(kind) + ' MB');
  }

  function cappedBy(kind) {
    var d = limits && limits[kind];
    var by = d && d.capped_by;
    return (by === 'upload_max_filesize' || by === 'post_max_size') ? by : '';
  }

  /* One of the ini values, as the server spells it. Bounded to the two keys this
     screen names, so a payload that grows a third cannot be printed by accident. */
  function serverIni(name) {
    var srv = limits && limits.server;
    if (name !== 'upload_max_filesize' && name !== 'post_max_size') return '';
    return (srv && typeof srv[name] === 'string' && srv[name] !== '') ? srv[name] : 'not readable';
  }

  /* ------------------------------------------------------------- the sidebar */

  /*
   * ── NO SIDEBAR ENTRY, DELIBERATELY ──────────────────────────────────────
   *
   * This screen used to register its own row, labelled "Shoppable video". It is
   * now the "All clips" TAB of Content -> Shoppable video, whose single row is
   * registered by ugc-sections-screen.blade.php.
   *
   * THE WHOLE kbbAddNavEntry BLOCK IS GONE rather than merely uncalled, and
   * that is the point: AdminNavAndIdsTest discovers sidebar entries by PARSING
   * THIS SOURCE for a `label:` inside a kbbAddNavEntry({...}) call, not by
   * watching which calls run. A commented-out call left the test reading two
   * entries labelled "Shoppable video" and failing on the duplicate -- which is
   * the test doing its job, and how this comment came to be written.
   *
   * The screen id stays routable: #ugcvideo and ?go=ugcvideo still open it, the
   * same way `blog` and `rev-capsule` stay routable without rows of their own.
   */

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
    if (title) title.textContent = 'All clips';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    forgetUpload();
    editing = null;
    /* A fresh visit re-reads the newest products: one of them may have been
       published since, and a stale list here is a product he cannot find. */
    recent = null;
    recentAsked = false;
    draftFound = null;
    saveFail = null;
    savedOnce = false;
    mounted = null;
    step = 1;
    render();
    load();
    return undefined;
  };

  /* ------------------------------------------------------------------ reads */

  /*
   * ── THE LOOP'S REAL LENGTH, READ RATHER THAN ASSUMED ──────────────────────
   *
   * THE DEFECT THIS EXISTS FOR. `teaser_ms` is a SETTING — Appearance →
   * Shoppable video → Motion → "How long the loop runs before repeating",
   * default 1000, range 1000–4000 — and so is `teaser`, the switch that decides
   * whether a tile loops at all. This screen used to hard-code 2500 into the
   * preview and into its own prose. An owner who had moved that slider to 4000
   * was then shown a preview that rewound at 2.5s and a sentence that said
   * "2.5 seconds", neither of which was what his shop does. The preview is
   * offered as EVIDENCE, so a preview that disagrees with the shop is worse
   * than none.
   *
   * Read ONCE per screen visit and never blocking: `load()` does not await it,
   * so a slow or forbidden read costs the library nothing and the preview falls
   * back to the shipped 1000 — which is also the value the shop uses when the
   * owner has saved nothing.
   */
  async function readMotion() {
    if (motion) return;

    try {
      var body = await api('/ugc-appearance');
      var tabs = (body && body.tabs) || [];
      var found = null;

      tabs.forEach(function (t) {
        (t.fields || []).forEach(function (f) {
          if (f && f.key === 'teaser_ms' && f.value !== undefined) found = f.value;
          if (f && f.key === 'teaser' && f.value !== undefined) {
            motion = motion || {};
            motion.on = !!f.value;
          }
        });
      });

      /* CLAMPED TO THE SCHEMA'S OWN RANGE, because this is a setting on its way
         into the markup. Rule 5: a value from a setting is checked before it is
         used, and `range` means 1000–4000 here — the floor came down with the
         default when the owner asked for a one-second loop. A saved row outside
         it, or a string, becomes the shipped default rather than an attribute
         nothing validates. */
      var ms = parseInt(found, 10);
      if (!(ms >= 1000 && ms <= 4000)) ms = 1000;

      motion = motion || {};
      motion.ms = ms;
      if (motion.on === undefined) motion.on = true;
    } catch (e) {
      /* Silent on purpose: this is a nicety on a screen about clips, and the
         owner does not need a toast about the Appearance endpoint to add one. */
      motion = { ms: 1000, on: true };
    }

    render();
  }

  /** The loop settings, with the shipped values until the read lands. */
  function loop() {
    return { ms: (motion && motion.ms) || 1000, on: !motion || motion.on !== false };
  }

  /** 1000 -> "1", 2500 -> "2.5". The seconds an owner would say out loud. */
  function secs(ms) {
    var s = Math.round(ms / 100) / 10;
    return String(s);
  }

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; probeErrors = [];
    render();

    /* Deliberately not awaited — see readMotion(). */
    readMotion();

    try {
      var body = await api('/ugc-videos');
      if (mine !== seq) return;

      videos = body.videos || [];
      probeErrors = body.probe_errors || [];
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
      searching = false;
      searched = false;
      saveFail = null;
      savedOnce = false;

      /*
       * WHAT THE SERVER JUST SAID, remembered — and then the draft is CONSIDERED
       * but never applied. The fields on screen are this row's, always; if there
       * is unsaved typing for this clip the bar above the steps offers it, and
       * the owner decides. See the draft block for why anything else is worse
       * than the delay it would save.
       */
      serverPrint = rowPrint(editing);

      var d = readDraft(editing);
      if (!d) {
        draftFound = null;
      } else if (!draftDiffers(d, editing)) {
        /* Identical to the row it came from, so it is noise rather than work:
           dropped instead of offered, or the bar would cry wolf. */
        clearDraft(editing);
        draftFound = null;
      } else {
        draftFound = { at: d.at, stale: !!(d.basis && d.basis !== serverPrint) };
      }
    } catch (e) {
      say(explain(e, 'That video could not be opened.'));
    } finally {
      busy = false; render();
    }
  }

  /*
   * The upload panel and the cut offer belong to ONE clip, so both are dropped
   * whenever the screen moves to another one — opening a different tile, leaving
   * for the list, or starting a new clip. Left behind, a green "uploaded" panel
   * would sit on the next clip's step 2 describing a file that is not on it.
   */
  function forgetUpload() {
    upState = null;
    upDone = null;
    freshClip = null;
    freshFile = null;
    upXhr = null;
    /* The reason belongs to ONE clip's upload. Left behind, it would explain a
       missing cover on a clip that never had an upload attempted on it. */
    cutNote = null;
  }

  function blank() {
    forgetUpload();
    saveFail = null;
    savedOnce = false;
    searching = false;
    searched = false;
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
    step = 1;

    /* A clip that was being typed and never saved has exactly one draft, under
       the 'new' key. Offered the same way as any other — never applied. */
    serverPrint = rowPrint(editing);
    var d = readDraft(editing);
    draftFound = (d && draftDiffers(d, editing)) ? { at: d.at, stale: false } : null;
    if (d && !draftFound) clearDraft(editing);

    render();
  }

  /* ----------------------------------------------------------------- writes */

  /* ── the draft's four operations ──────────────────────────────────────── */

  /** A short, stable fingerprint of the fields a save writes. */
  function rowPrint(v) {
    if (!v) return '';
    return ['title', 'caption', 'status', 'rights_status', 'source_platform', 'source_url',
      'creator_handle', 'creator_url', 'rights_evidence', 'locale', 'position', 'published_at']
      .map(function (k) { return String(v[k] == null ? '' : v[k]); }).join('\u0001')
      + '\u0001' + (v.products || []).map(function (t) { return String(t.id); }).join(',');
  }

  /** Which clip a draft belongs to. A clip with no id yet has exactly one. */
  function draftKey(v) {
    if (!v) return null;
    return DRAFT_KEY + (v.id ? String(v.id) : 'new');
  }

  /**
   * Take a draft of what is on screen now. Called debounced from the input
   * listener and on every step change; never blocking, never a request.
   *
   * It reads the FORM, not `editing`, because the form is what the owner has
   * typed and `editing` only catches up when a save is attempted.
   */
  function writeDraft() {
    if (!editing) return;
    var payload = form();
    if (!payload) return;

    var body;
    try {
      body = JSON.stringify({
        v: 1,
        at: Date.now(),
        /* The server's row as it was when this draft was composed, so reopening
           the clip can say whether it has moved underneath the draft. */
        basis: serverPrint,
        fields: payload,
        tagged: tagged.map(function (t) {
          return { id: t.id, name: t.name, brand: t.brand, at_ms: t.at_ms };
        })
      });
    } catch (e) { return; }

    if (body.length > DRAFT_MAX) return;
    try { window.localStorage.setItem(draftKey(editing), body); } catch (e) {}
  }

  /** The stored draft for one clip, or null. Never throws, never half-reads. */
  function readDraft(v) {
    var raw = null;
    try { raw = window.localStorage.getItem(draftKey(v)); } catch (e) { return null; }
    if (!raw) return null;

    var d = null;
    try { d = JSON.parse(raw); } catch (e) { d = null; }
    /* A draft from an older shape is dropped rather than guessed at: the fields
       it names are the ones a save sends, and half of them is not a draft. */
    if (!d || d.v !== 1 || !d.fields || typeof d.fields !== 'object') {
      clearDraft(v);
      return null;
    }
    return d;
  }

  function clearDraft(v) {
    try { window.localStorage.removeItem(draftKey(v)); } catch (e) {}
  }

  /**
   * Is this draft worth offering at all? A draft identical to the row it came
   * from is noise -- it happens whenever somebody opens a clip, touches one box
   * and puts it back -- and a bar offering to restore what is already on screen
   * would teach the owner to ignore the bar that matters.
   */
  function draftDiffers(d, v) {
    if (!d || !v) return false;

    var f = d.fields;
    var same = ['title', 'caption', 'status', 'rights_status', 'source_platform',
      'source_url', 'creator_handle', 'creator_url', 'rights_evidence'].every(function (k) {
      return String(f[k] == null ? '' : f[k]) === String(v[k] == null ? '' : v[k]);
    });

    if (!same) return true;
    if (String(f.locale || '') !== String(v.locale || '')) return true;
    if (String(f.published_at || '') !== String(v.published_at || '')) return true;
    if (Number(f.position || 0) !== Number(v.position || 0)) return true;

    var were = (d.tagged || []).map(function (t) { return String(t.id); }).join(',');
    var now = (v.products || []).map(function (t) { return String(t.id); }).join(',');
    return were !== now;
  }

  /**
   * Put a draft back on screen. Writes the BOXES, because the boxes are what a
   * save reads -- and then folds the same values into `editing` so the hero, the
   * step ticks and the state line agree with them.
   *
   * It does NOT save. Restoring is the owner saying "put my typing back", not
   * "write it to the database"; the save is still his to press, or the next
   * forward step's.
   */
  function restoreDraft() {
    var d = readDraft(editing);
    if (!d) { draftFound = null; paintRegion('draft'); return; }

    var host = document.querySelector('#ugs-form');
    if (!host) return;

    Object.keys(d.fields).forEach(function (k) {
      if (k === 'translations') return;
      var el = host.querySelector('[data-ugs-field="' + k + '"]');
      if (!el) return;
      var val = d.fields[k];
      /* A SELECT TAKES ONE OF ITS OWN OPTIONS OR NOTHING -- rule 5, and a draft
         is untrusted input like any other stored value: it came out of a store
         the page cannot vouch for. */
      if (el.tagName === 'SELECT') {
        var ok = Array.prototype.some.call(el.options, function (o) { return o.value === String(val == null ? '' : val); });
        if (ok) el.value = String(val == null ? '' : val);
        return;
      }
      el.value = val == null ? '' : String(val);
    });

    /* The Arabic boxes, written back through the attribute KBBArabic.collect()
       reads them by -- data-kbbar-input -- so a draft restores exactly the set
       of fields a save would have sent. This screen draws only plain inputs and
       textareas through arabicBox(), never the rich pane, so there is no
       data-kbbar-rich case to answer here. */
    var L = (window.KBBArabic && window.KBBArabic.locale) || 'ar';
    var bag = d.fields.translations && d.fields.translations[L];
    if (bag) {
      Object.keys(bag).forEach(function (f) {
        var el = host.querySelector('[data-kbbar-input="' + f + '"]');
        if (el) el.value = bag[f] == null ? '' : String(bag[f]);
      });
    }

    if (Array.isArray(d.tagged)) {
      tagged = d.tagged.filter(function (t) { return t && t.id; }).map(function (t) {
        return { id: t.id, name: String(t.name == null ? '' : t.name),
                 brand: String(t.brand == null ? '' : t.brand), at_ms: t.at_ms == null ? null : t.at_ms };
      });
    }

    keepTyped(form() || d.fields);
    draftFound = { at: d.at, stale: false, restored: true };
    say('Your unsaved changes are back. Nothing has been saved yet.');
    paintRegions();
  }

  function discardDraft() {
    clearDraft(editing);
    draftFound = null;
    paintRegion('draft');
    say('Draft discarded.');
  }

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

  /*
   * WHAT WAS TYPED SURVIVES A REFUSED SAVE.
   *
   * The defect this exists for: press Save on step 5 with the status set to
   * published and a blocker still open, and the server answers 422 — at which
   * point render() repainted every field from `editing`, which still held the
   * values the last GET returned. Everything typed since was silently reverted,
   * on the one screen where somebody has just typed a creator's name, a caption
   * and a permission note.
   *
   * So the payload is folded back into the row BEFORE the request goes out, and
   * a repaint after a refusal draws what the operator is looking at.
   */
  function keepTyped(payload) {
    if (!editing) return;

    ['title', 'caption', 'status', 'rights_status', 'source_platform',
     'source_url', 'creator_handle', 'creator_url', 'rights_evidence'].forEach(function (k) {
      editing[k] = payload[k];
    });

    editing.locale = payload.locale || '';
    editing.position = payload.position;
    editing.published_at = payload.published_at || '';

    var L = (window.KBBArabic && window.KBBArabic.locale) || 'ar';
    var typed = payload.translations && payload.translations[L];
    if (!typed) return;

    /* Built off the blank shape the server handed down, not off an empty
       object: KBBArabic.boxIf draws a box only for a field the bag KNOWS, so a
       bag holding only what was typed would lose the other box on the repaint. */
    if (!editing.translations) {
      try { editing.translations = JSON.parse(JSON.stringify(translatable || {})); }
      catch (e) { editing.translations = {}; }
    }
    if (!editing.translations[L]) editing.translations[L] = {};

    Object.keys(typed).forEach(function (f) {
      var cell = editing.translations[L][f];
      if (cell && typeof cell === 'object') cell.value = typed[f];
      else editing.translations[L][f] = { value: typed[f], status: '', source: '', stale: false };
    });
  }

  /**
   * Save, and answer whether it went through.
   *
   * THE ONE WRITER for every save on this screen, foreground and background
   * alike -- the argument CLAUDE.md makes for update_releases, and the reason
   * `bgSave` is a flag read here rather than an argument passed in: a second
   * copy of this body is a second place for the ending to be forgotten.
   *
   * FOREGROUND is what the Save button does, and what a clip with no id yet
   * always does. Unchanged: it locks the screen, and it finishes with load() and
   * open() so the row on screen is the row the server now holds.
   *
   * BACKGROUND is what a forward step does once the clip exists. It does the two
   * writes and NOT the two reads -- the paint has already happened, and the PUT
   * answers with card(), which carries the blockers, the media state and the
   * three paths, so everything the screen shows can be brought up to date from
   * the reply it already has. Four round trips become two, and none of them is
   * in front of the owner.
   */
  async function save() {
    if (!editing) return false;

    /* Read and cleared at once, so a later save cannot inherit a stale flag. */
    var background = bgSave;
    bgSave = false;

    /*
     * AT MOST ONE SAVE OF ONE ROW IN FLIGHT, EVER. Stepping forward twice
     * quickly marks the row dirty instead of opening a second PUT of the same
     * id: two saves of one row racing each other is how a field gets written
     * back to the value it had before it was edited, and the loser of that race
     * wins the database.
     */
    if (saving) { saveAgain = true; return false; }
    if (busy && !background) return false;

    var payload = form();
    if (!payload) return false;

    keepTyped(payload);

    /* A background save must NOT lock the screen -- locking it is the wait this
       whole change exists to remove. It still says so in the footer. */
    saving = true;
    if (!background) { busy = true; }
    var creating = !editing.id;
    var wasKey = draftKey(editing);
    render();

    try {
      var body = editing.id
        ? await api('/ugc-videos/' + encodeURIComponent(editing.id), payload, 'PUT')
        : await api('/ugc-videos', payload);

      var id = body.video.id;

      /* The product list is a second call on purpose: the row has to exist
         before anything can be tagged on it, and a create plus a tag in one
         request would mean a half-created video when the second half failed. */
      await api('/ugc-videos/' + encodeURIComponent(id) + '/products', {
        products: tagged.map(function (t) { return { id: t.id, at_ms: t.at_ms }; })
      });

      saveFail = null;
      savedOnce = true;

      /* THE DRAFT IS DROPPED ONLY HERE, on a save that actually landed -- and by
         the key the draft was WRITTEN under, because a create moves the clip from
         'new' to its id and the old key would otherwise be left behind to be
         offered on the next new clip. */
      try { window.localStorage.removeItem(wasKey); } catch (e) {}

      if (background) {
        /* Fold the server's own answer in, rather than reading the row back.
           card() carries the blockers and the media state, which is everything
           the hero, the ticks and the state line read. */
        Object.keys(body.video).forEach(function (k) { editing[k] = body.video[k]; });
        editing._blockers = null;
        editing.products = tagged.map(function (t) {
          return { id: t.id, name: t.name, brand: t.brand, at_ms: t.at_ms };
        });
        serverPrint = rowPrint(editing);
        /* The library list is what "All clips" shows next, so the saved row is
           brought up to date in place -- no second GET for a title change. */
        if (videos) {
          videos = videos.map(function (x) {
            return String(x.id) === String(id) ? Object.assign({}, x, body.video) : x;
          });
        }
        say('Saved.');
        return true;
      }

      say('Saved.');
      await load();
      await open(id);
      return true;
    } catch (e) {
      /*
       * EVERY WAY THIS CAN FAIL LEAVES A MARK, and that is the whole difference
       * between a save the owner is watching and one he is not.
       *
       * CLAUDE.md: a guarded write that leaves state behind does not contain a
       * failure, it seeds one. A background save whose only trace was a toast
       * would be that defect exactly -- the toast appears while he is reading
       * step 4 and is gone before he looks up, and the next thing he does is
       * close the tab believing his work is saved. So the ending is written into
       * `saveFail`, the footer prints it until a save succeeds, and the draft is
       * NOT cleared: at that moment the draft is the only copy of what he typed.
       */
      if (e && e.body && e.body.blockers) {
        banner = null;
        editing._blockers = e.body.blockers;
        step = 5;
        saveFail = { message: e.body.error || 'This video cannot be published yet.' };
        say(e.body.error || 'This video cannot be published yet.');
      } else if (e && e.status === 422) {
        saveFail = { message: explain(e, 'Some of that was not accepted — a title is required.') };
        say(saveFail.message);
      } else {
        saveFail = { message: explain(e, 'That could not be saved.') };
        say(saveFail.message);
      }
      /* A create that failed must not leave a clip the owner thinks exists. */
      if (creating) { /* editing.id is still null, which is already the truth */ }
      return false;
    } finally {
      saving = false;
      if (!background) busy = false;
      render();

      /*
       * THE COALESCED SAVE. Something was edited while this one was in flight, so
       * one more goes out -- but only if this one WORKED. Retrying automatically
       * into a standing failure would hammer the endpoint and keep replacing the
       * message that tells the owner what went wrong.
       */
      if (saveAgain) {
        saveAgain = false;
        if (!saveFail) { bgSave = true; save(); }
      }
    }
  }

  /*
   * A STEP CHANGE, AND THE WHOLE POINT OF THIS LANE.
   *
   * It paints FIRST, from state already in memory, and makes no request to show
   * a panel. Moving forward still saves -- that guarantee is not negotiable, and
   * the docblock on the old version says why -- but the save now happens behind
   * the paint instead of in front of it.
   *
   * THE ONE CASE THAT STILL WAITS, and it is deliberate: a clip that has never
   * been saved has no id, and /ugc-videos/{id}/media has nowhere to put a file.
   * Painting step 2 for it instantly would mean drawing the locked "nothing can
   * be uploaded yet" panel and then swapping it out underneath him, which is a
   * worse screen than a short wait. So the CREATE blocks, exactly once per clip,
   * and every step change after it is instant. One wait per clip instead of one
   * per step.
   *
   * It stays `async` because the create is still awaited.
   */
  async function goStep(next) {
    if (next < 1 || next > STEPS.length) return;
    if (next === step) return;

    var forward = next > step;

    /* The create, and only the create, is worth waiting for. */
    if (forward && editing && !editing.id) {
      var ok = await save();
      if (!ok) return;
      step = next;
      render();
      return;
    }

    /* Everything else: paint now, save behind it. */
    step = next;
    paintStep();
    writeDraft();

    if (forward) { bgSave = true; save(); }
  }

  async function remove(id, title) {
    if (!window.confirm('Delete "' + title + '" and its files? This cannot be undone.')) return;
    busy = true; render();
    try {
      await api('/ugc-videos/' + encodeURIComponent(id), {}, 'DELETE');
      /* The row is gone, so a draft of it is an offer to restore something that
         cannot be saved anywhere. Cleared by the id rather than off `editing`,
         which is about to be null. */
      try { window.localStorage.removeItem(DRAFT_KEY + String(id)); } catch (e) {}
      draftFound = null;
      saveFail = null;
      forgetUpload();
      editing = null;
      step = 1;
      say('Deleted.');
      await load();
    } catch (e) {
      say(explain(e, 'That could not be deleted.'));
    } finally {
      busy = false; render();
    }
  }

  /* ---------------------------------------------------------------- uploads */

  /* The bar is written straight into the DOM rather than through render(),
     because a repaint per progress event would restart the preview video and
     throw away the caret of anything being typed. Writing a width is not
     measuring one. */
  function paintProgress() {
    if (!upState) return;

    var bar = document.querySelector('[data-ugs-bar]');
    var pct = document.querySelector('[data-ugs-pct]');
    var sent = document.querySelector('[data-ugs-sent]');
    var stage = document.querySelector('[data-ugs-stage]');

    if (bar) bar.style.width = upState.pct + '%';
    if (pct) pct.textContent = upState.pct + '%';
    if (sent) sent.textContent = bytes(upState.sent) + ' of ' + bytes(upState.total) + ' sent';
    /* The kit composed this sentence, numbers and all — see its docblock for why
       there is exactly one copy of it in the console. */
    if (stage) stage.textContent = upState.text || '';
    /* The one class the clock toggles, so a panel that has been waiting a long
       time looks different as well as reading differently. A class write is not
       a measurement. */
    /* data-ugs-upbox and NOT data-ugs-up, which this screen already uses for the
       "move this product up" button. The click listener matches on the nearest
       ancestor carrying any of its attributes, so a panel named data-ugs-up would
       turn every click on the upload bar into a reorder of the tagged product
       list with an index of NaN. */
    var box = document.querySelector('[data-ugs-upbox]');
    if (box) box.classList.toggle('is-slow', !!upState.stalled);
  }

  /*
   * THE QUIET INTERVAL, THE STALL THRESHOLD AND THE SENTENCE ARE THE KIT'S NOW.
   *
   * stalledFor() and stageWords() used to live here. They are GONE rather than
   * moved, because both were wrong in ways only measurement found, and a second
   * copy of either is how a fix reaches one screen and not the other:
   *
   *   - the threshold was a fixed 20 seconds, which is BELOW the 16,764 ms that
   *     two progress events legitimately sit apart at 100 KB/s, so the panel
   *     accused a perfectly healthy upload of being stuck and advised the owner
   *     to cancel it;
   *   - "All of it has arrived. The server is checking the file" was printed from
   *     xhr.upload.onload, which fires when the last byte reaches the KERNEL
   *     BUFFER -- measured up to 49 seconds before the server had the file.
   *
   * partials/upload-kit.blade.php owns both now, computes the threshold from the
   * stride and the speed THIS upload is producing, and hands the composed
   * sentence over on every callback as `text`, which paintProgress() prints.
   */

  /**
   * Send one video file, with a real progress bar.
   *
   * XMLHttpRequest and not fetch, for one reason: fetch reports nothing about
   * an upload in flight, and this is the one place in the console that moves up
   * to 64 MB. A 64 MB clip on a hotel connection with no bar is a screen the
   * owner reloads halfway through.
   */
  function sendFile(kind, file) {
    if (!file) return;

    /*
     * THE DEFECT THIS GUARD EXISTS FOR. The old screen drew both file inputs on
     * a brand-new clip and returned early on `!editing.id` — silently. Choosing
     * a file there did nothing at all, and nothing said why. The media steps are
     * locked before the row exists now; this is the second lock.
     */
    if (!editing || !editing.id) {
      say('Save step 1 first — the file needs a clip to belong to.');
      return;
    }

    /*
     * THE PRE-FLIGHT, AGAINST THE CEILING THIS SERVER WILL REALLY HONOUR.
     *
     * Two defects in the four lines this replaces. It refused against cap() —
     * the app's own 64 MB — so the owner's 8.4 MB file sailed past it and was
     * thrown away by PHP thirty seconds later. And it refused with a TOAST and
     * nothing else, so the one refusal on this screen that costs no time at all
     * was also the only one that left no panel behind: the same defect
     * UgcEditorColumnsTest records for the endings, in the one place it was
     * still open.
     *
     * capBytes() and not cap() * 1048576, because cap() is a floored megabyte
     * count and refusing against the floor refuses files the server would take.
     */
    var ceiling = capBytes(kind);

    if (file.size > ceiling) {
      var by = cappedBy(kind);

      failUpload({
        kind: kind,
        name: file.name,
        bytes: file.size,
        message: 'That file is ' + kb(file.size) + ' and the most that can be uploaded here is '
          + capWords(kind) + '. It was not sent, so nothing on the clip was changed.'
          + (by === ''
              ? ' Re-encode it smaller and try again.'
              : ' The limit is PHP on this server and not Shoppable video, which allows '
                + appWords(kind) + ' for a ' + kind + ': ' + by + ' is ' + serverIni(by)
                + '. Raise it on the server and this box will take the bigger file.'),
        /* No Try again: the same file would be refused in the same way, and a
           button whose only outcome is the message above is a lie. */
        retry: null
      });
      return;
    }

    /* A friendly early refusal, not the check: the server reads the BYTES and
       is the only thing that decides. This only saves somebody a 64 MB round
       trip for a picture dropped into the wrong box. */
    if (file.type && file.type.indexOf('image/') === 0) {
      say('That is a picture. The cover image goes in the Cover box beside this one.');
      return;
    }

    /*
     * THE KIT HAS TO BE ON THE PAGE, and saying so beats throwing.
     *
     * window.kbbUpload is defined by partials/upload-kit.blade.php. If that
     * @include is ever dropped from app.blade.php — or a stale compiled view
     * survives a package, which is exactly what this release's clear_caches
     * migration exists to prevent — calling it raises "kbbUpload is not a
     * function" INSIDE this handler. Nothing catches that: `busy` stays true, the
     * screen stays locked, and the only symptom is a dead dialog. The same shape
     * as the swallowed write CLAUDE.md records for UpdateRunner.
     *
     * So it is checked the way this console already checks for the Media Library
     * picker, and it names the fix rather than the symptom.
     */
    if (typeof window.kbbUpload !== 'function') {
      say('The uploader is not loaded on this page. The admin console needs the upload kit '
        + 'partial — clear the view cache and reload; if it persists the package did not land.');
      busy = false; render();
      return;
    }

    upState = { kind: kind, name: file.name, total: file.size, sent: 0, pct: 0,
                stage: 'sending', text: '', stalled: false };
    /* The last ending is cleared before this one starts, so a red panel from a
       refused attempt never sits under a fresh bar. */
    upDone = null;
    if (kind === 'clip') { freshClip = null; freshFile = null; }
    busy = true; render();

    /*
     * THE CLIP THIS UPLOAD BELONGS TO, READ ONCE.
     *
     * THE DEFECT, AND IT SURVIVES THE MOVE TO THE KIT UNCHANGED. Every handler
     * below used to read `editing.id`, and `editing` is whatever clip is open
     * when the response lands rather than the one the file was sent to. Pressing
     * Back mid-upload sets `editing` to null, and the success handler then ran
     * `freshClip = editing.id` -- a TypeError inside a callback, which unlocks
     * nothing and leaves the screen with busy still true. Opening a DIFFERENT
     * clip instead was quieter and worse: the new clip's step 2 drew a green
     * "arrived whole" panel for a file that is on another row.
     */
    var target = editing.id;

    /*
     * THE TRANSPORT IS THE KIT'S, AND SO IS EVERY NUMBER IN THE PANEL.
     *
     * window.kbbUpload() is XMLHttpRequest for the one reason that has no
     * workaround -- fetch reports nothing at all about a request body in flight
     * -- and it owns the two stages, the two clocks, the self-calibrating stall
     * threshold, the sliding-window speed and the sentence. See
     * partials/upload-kit.blade.php for the measurements behind each.
     *
     * WHAT STAYS HERE is this screen's own bookkeeping, which the kit has no
     * business knowing: which clip the file belongs to, the ending panel, the
     * cut offer, and the ceiling refresh below.
     */
    upXhr = window.kbbUpload({
      url: apiBase() + '/admin-api/ugc-videos/' + encodeURIComponent(target) + '/media',
      file: file,
      field: 'file',
      extra: { kind: kind },
      /*
       * The ceiling is already enforced above with this screen's own much fuller
       * sentence, so passing `max` as well would refuse twice and print the
       * kit's shorter message instead. Deliberately omitted.
       */
      /* A CONSTANT, not a setting -- rule 5. It is appended to the kit's
         server-stage sentence, and on a box with ffmpeg it is the truth: the
         upload request cuts the cover and the teaser before it answers. */
      serverNote: 'and cutting what it can',
      onProgress: function (s) {
        if (!upState) return;
        upState.sent = s.loaded;
        upState.total = s.total;
        upState.pct = s.pct;
        upState.stage = s.stage;
        upState.text = s.text;
        upState.stalled = s.stalled;
        paintProgress();
      },
      onStage: function (st) {
        if (upState) upState.stage = st;
      },
      onDone: function (payload) {
        var name = upState ? upState.name : file.name;
        /* file.size and not the transferred total: e.total is the whole
           multipart body, a couple of hundred bytes more than the file --
           measured at 289 for this exact request -- and "8.4 MB arrived whole"
           should be the size of the thing that arrived. */
        var size = file.size;

        /*
         * A SERVER THAT ANSWERS WITH ITS OWN LIMITS IS BELIEVED ABOUT THEM. The
         * upload endpoint sends `limits` back with every refusal it composes, so
         * a 413 or a size refusal updates the ceiling this screen advertises in
         * the same round trip that proved it wrong.
         */
        if (payload && payload.limits) limits = payload.limits;

        if (!payload || !payload.ok) {
          /* 2xx with ok:false. The kit cannot judge this -- it is this endpoint's
             own shape -- so it is judged here, and not retryable, because the
             server accepted the request and declined the file on its merits. */
          failUpload({
            kind: kind, name: name, bytes: size,
            message: (payload && payload.error) ? payload.error : 'That file was not accepted.',
            /* A 2xx with ok:false is the server judging the FILE, so the row was
               not written — but it is still the server's word rather than this
               screen's guess. */
            stored: payload ? payload.stored : undefined,
            retry: null
          });
          return;
        }

        upState = null;
        upXhr = null;

        /* Still on the clip this file was sent to? If the owner pressed Back or
           opened another tile while it was in flight, the file is still correctly
           on `target` -- but this screen must not draw a panel about it on
           somebody else's row. */
        var here = editing && editing.id === target;

        if (here) {
          upDone = { ok: true, kind: kind, name: name, bytes: size, message: '' };
          /* WHICH CLIP JUST TOOK A VIDEO, so cutHTML() can offer the cut as the
             next thing rather than leaving it to be found. */
          if (kind === 'clip') {
            freshClip = target;
            /*
             * THE BYTES, KEPT. cutCoverHere() reads the frame out of this File
             * rather than fetching the clip back off the server -- they are
             * already in the tab, and on a 2 MB-per-request host the round trip
             * is the slowest part of the whole operation. Dropped again the
             * moment another clip is opened, so a long session does not hold
             * a video per clip visited.
             */
            freshFile = file;
          }
        }

        /* SAID, AND ALSO KEPT. The toast is what happened; cutNote is why the
           clip looks the way it does, and that question outlives a toast. */
        rememberNotes(payload.notes);
        (payload.notes || []).forEach(say);
        say(kind === 'clip' ? 'Video added.' : 'Teaser added.');
        load().then(function () { return here ? open(target) : undefined; })
          .then(function () {
            /*
             * ── AND TAKE THE COVER, WITHOUT BEING ASKED ────────────────────
             *
             * The owner's words, after being shown a panel that explained the
             * server's limitation and offered a button: "i need the permanent
             * solution or any other method to solve this super reliable."
             *
             * He is right, and the button was the wrong shape. A panel that
             * announces "this server cannot cut a cover" is an EXPLANATION, not
             * a solution -- it puts the work back on him every single time, for
             * a thing the browser can do in under a second without being told.
             *
             * So it happens here instead: the upload lands, and if the server
             * did not manage a cover, the browser takes one immediately from
             * the bytes still in this tab. He uploads a video and a cover
             * appears. There is nothing to press and nothing to read.
             *
             * ONLY WHEN THE SERVER DID NOT. A host that CAN run ffmpeg has
             * already cut a better cover (and the teaser with it) by the time
             * this runs, and overwriting it with a canvas frame would be a
             * downgrade. `editing.poster_path` after the reload above is the
             * honest answer to "is there one", whichever way it got there.
             *
             * SILENT ON FAILURE, DELIBERATELY. If the browser cannot decode the
             * clip there is still a manual button and the Media Library behind
             * it; a toast here would be a second thing to read about a cover
             * that nobody asked for yet. cutCoverHere() writes its own banner
             * when the owner presses the button, which is when he is waiting
             * for an answer.
             */
            if (kind !== 'clip' || !here) return;
            if (!editing || String(editing.id) !== String(target)) return;
            if (editing.poster_path) return;
            if (typeof window.kbbPosterFromVideo !== 'function') return;

            autoCutCover();
          });
      },
      onFail: function (f) {
        /*
         * ONE ENDING WRITER, still. The kit distinguishes a cancel from a
         * refusal and composes the sentence for both -- including the 413 that
         * Laravel's global ValidatePostSize answers with a body carrying
         * `message` and NO `error` key, which is why every screen in this console
         * used to print "That file was not accepted" for a file that was fine.
         */
        if (f.body && f.body.limits) limits = f.body.limits;
        /* A 5xx from this endpoint now carries the reason the cut failed as well
           as whether the row committed; both are kept. */
        if (f.body && f.body.notes) rememberNotes(f.body.notes);
        failUpload({
          kind: kind,
          name: upState ? upState.name : file.name,
          bytes: file.size,
          message: f.message,
          /* undefined on a request that never arrived, which is the point. */
          stored: f.body ? f.body.stored : undefined,
          /* The kit already decided this: 429 and 5xx and a dropped connection
             and a cancel, never 413 or 422. */
          retry: f.retryable ? file : null
        });
      }
    });
  }

  /*
   * The cover, from the Media Library and from nowhere else.
   *
   * THE OWNER'S RULE, twice in the same words: "on any upload media on the
   * whole backend, the media library is a must to show."
   * AdminMediaPickerEverywhereTest enforces it by scanning these files for a
   * raw <input type="file"> with an image accept, and it caught this screen's
   * first draft. So there is no image file input here at all — the picker is
   * the button, and a file DRAGGED onto the cover zone goes through the
   * library's own upload endpoint, which is exactly what the console's shared
   * image field does and is why nothing is lost by having no input.
   */
  function choosePoster() {
    if (!editing || !editing.id) { say('Save step 1 first.'); return; }
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
      title: 'Choose a cover',
      note: 'The still a tile holds its shape with, and what a data-saver phone sees instead of motion.',
      onPick: function (urls) {
        /* onPick always receives an ARRAY, even for a single-select call —
           media-picker.blade.php says so in its own docblock, so a caller
           cannot be written against the wrong shape and work by accident. */
        if (!urls || !urls.length) return;
        adoptPoster(urls[0]);
      }
    });
  }

  /** A picture dragged onto the cover zone: into the library, then onto the clip. */
  function dropPoster(file) {
    if (!editing || !editing.id) { say('Save step 1 first — the file needs a clip to belong to.'); return; }

    /* The same ceiling and the same ending as the video zone — see sendFile(). A
       cover refused for its size used to leave a toast and nothing else, which is
       the one refusal on this screen with no panel behind it. */
    if (file.size > capBytes('poster')) {
      var pby = cappedBy('poster');

      failUpload({
        kind: 'poster',
        name: file.name,
        bytes: file.size,
        message: 'That picture is ' + kb(file.size) + ' and the most that can be uploaded here is '
          + capWords('poster') + '. It was not sent, so nothing on the clip was changed.'
          + (pby === ''
              ? ' Save it smaller and try again.'
              : ' The limit is PHP on this server and not Shoppable video, which allows '
                + appWords('poster') + ' for a cover: ' + pby + ' is ' + serverIni(pby) + '.'),
        retry: null
      });
      return;
    }

    var data = new FormData();
    data.append('file', file);
    data.append('folder', 'posters');

    busy = true; render();

    api('/media/upload', data)
      .then(function (body) {
        if (!body || !body.url) throw new Error('no url');
        return adoptPoster(body.url);
      })
      .catch(function (e) {
        busy = false;
        say(explain(e, 'That picture could not be added to the Media Library.'));
        render();
      });
  }

  async function adoptPoster(url) {
    busy = true; render();
    try {
      await api('/ugc-videos/' + encodeURIComponent(editing.id) + '/poster', { url: url });
      say('Cover set.');
      await load();
      await open(editing.id);
    } catch (e) {
      say(explain(e, 'That picture could not be used as a cover.'));
    } finally {
      busy = false; render();
    }
  }

  /* ═══════════════════════════════════════════════════════════════════════
   * CUT THE COVER HERE, IN THIS BROWSER.
   * ═══════════════════════════════════════════════════════════════════════
   *
   * The permanent answer for a host whose PHP may not start ffmpeg. The
   * argument for it is in upload-kit.blade.php beside kbbPosterFromVideo();
   * the short version is that the browser has already decoded this video in
   * order to play it back three inches further up the screen, so the frame is
   * on the client already and needs nothing from the server.
   *
   * IT REUSES dropPoster() RATHER THAN POSTING ANYTHING ITSELF, which is the
   * whole reason this is eight lines. That path already enforces the size cap
   * this server will really take, registers the file in the Media Library,
   * adopts it on to the clip and reports its own failures. A canvas Blob is a
   * File with a name, so it can walk in through the same door as a picture the
   * operator chose by hand -- and the server sniffs the real MIME with finfo,
   * so it is checked identically too.
   */
  /**
   * The cover, taken automatically after an upload.
   *
   * SHARES cutCoverHere()'s BODY through one flag rather than being a second
   * copy of it, because the two differ in exactly one respect: what happens
   * when it fails. Pressed by hand, a failure is an answer the owner is waiting
   * for and belongs in the banner. Run on its own, a failure is a cover he did
   * not ask for yet, and the manual button is still there.
   */
  /**
   * The cover cut's own bar. Empty unless a cut is running.
   *
   * Same shape as the upload panel's bar so the two read as one idea, and it
   * carries the percentage AND the words, because "62%" on its own does not say
   * whether the browser is decoding or sending.
   */
  function cutBarHTML() {
    if (!cutStage) return '';

    return '<div class="ugs-up" data-ugs-cutbar style="margin-top:10px">'
      + '<div class="ugs-uph"><span class="ugs-upn">Taking the cover</span>'
      + '<span data-ugs-cutpct>' + esc(String(cutStage.pct)) + '%</span></div>'
      + '<div class="ugs-prog"><div class="ugs-progb" data-ugs-cutfill '
      + 'style="width:' + esc(String(cutStage.pct)) + '%"></div></div>'
      + '<div class="ugs-upm"><span data-ugs-cutwords>' + esc(cutStage.words) + '</span></div>'
      + '</div>';
  }

  /** Moves the bar without rebuilding the step -- see the onStage comment. */
  function paintCutBar() {
    var box = document.querySelector('[data-ugs-cutbar]');

    if (!box) { render(); return; }

    var fill = box.querySelector('[data-ugs-cutfill]');
    var pct = box.querySelector('[data-ugs-cutpct]');
    var words = box.querySelector('[data-ugs-cutwords]');

    if (fill) fill.style.width = cutStage.pct + '%';
    if (pct) pct.textContent = cutStage.pct + '%';
    if (words) words.textContent = cutStage.words;
  }

  function autoCutCover() {
    return cutCoverHere(true);
  }

  function cutCoverHere(quiet) {
    if (!editing || !editing.id) { say('Save step 1 first.'); return; }

    /*
     * THE LOCAL FILE IF THIS UPLOAD IS STILL ON SCREEN, otherwise the copy the
     * shop is serving. The local one is free -- the bytes are already in the
     * tab -- and it also works in the seconds before the server has finished
     * writing the file. safeMedia() is the same scheme check the <video> above
     * goes through, so a hand-edited column cannot point this at another host.
     */
    var source = (freshFile && String(freshClip) === String(editing.id))
      ? freshFile
      : safeMedia(editing.file_path);

    if (!source) { say('There is no video on this clip to take a frame from.'); return; }

    cutting = true;
    if (cutClear) { clearTimeout(cutClear); cutClear = null; }
    cutStage = { pct: 0, words: 'Starting' };
    render();

    window.kbbPosterFromVideo(source, {
      /*
       * REPAINTS THE BAR ALONE, not the whole editor. render() on every stage
       * would rebuild the step's markup five times in under a second and throw
       * away the <video> element mid-decode -- which is the very thing being
       * read from.
       */
      onStage: function (pct, words) {
        cutStage = { pct: pct, words: words };
        paintCutBar();
      }
    })
      .then(function (blob) {
        cutting = false;
        cutStage = { pct: 80, words: 'Saving the cover' };
        paintCutBar();

        // A name, because the Media Library lists files by one and "blob"
        // is not something to find again in a month.
        var stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '');
        var name = 'cover-' + (editing.slug || editing.id) + '-' + stamp + '.jpg';

        dropPoster(new File([blob], name, { type: 'image/jpeg' }));

        /*
         * ── THE BAR HOLDS AT 100 FOR A MOMENT ──────────────────────────────
         *
         * It used to be cleared by the reload that follows, which meant it
         * vanished the instant the work finished. On a 4-second WebM the whole
         * cut takes about 300ms, so in practice the owner saw nothing at all --
         * I could not catch it in automation either, sampling every 50ms.
         *
         * A progress bar that disappears the moment it completes has told
         * nobody anything. It finishes at 100 saying "Cover set", stays long
         * enough to read, and then goes. Cleared on a timer rather than by the
         * reload so the reload cannot race it.
         */
        cutStage = { pct: 100, words: 'Cover set' };
        paintCutBar();

        if (cutClear) clearTimeout(cutClear);
        cutClear = setTimeout(function () {
          cutStage = null;
          cutClear = null;
          render();
        }, 2200);
      })
      .catch(function (e) {
        cutting = false;
        cutStage = null;

        // See autoCutCover(): a failure nobody asked about is not worth a
        // sentence, and the manual button is still on the screen.
        if (quiet) { render(); return; }

        /*
         * The reason, in the sentence kbbPosterFromVideo chose -- it knows
         * which of the four things went wrong and this does not. Said in the
         * banner rather than a toast because it is the answer to a question
         * the owner deliberately asked.
         */
        say((e && e.message) ? e.message : 'The cover could not be taken from this video here.');
        render();
      });
  }

  async function derive() {
    if (!editing || !editing.id) return;
    busy = true; render();
    try {
      var body = await api('/ugc-videos/' + encodeURIComponent(editing.id) + '/derive', {});
      /* Pressing the cut and being told nothing is the same silence the upload
         had: the reason is kept here too, and for the same reason. */
      rememberNotes(body.notes);
      (body.notes || []).forEach(say);
      if (!(body.notes || []).length) { cutNote = null; say('Cover and teaser cut.'); }
      var keep = cutNote;
      await load();
      await open(editing.id);
      /* open() is a fresh clip payload and forgetUpload() may have run on the
         way; the server's reason is about the SERVER and outlives both. */
      cutNote = keep;
    } catch (e) {
      say(explain(e, 'Nothing could be cut from that clip.'));
    } finally {
      busy = false; render();
    }
  }

  /*
   * One search. `seq`-style guarding of its own, because a slow answer for
   * "an" must not overwrite a fast one for "anua" -- the old version had no
   * such guard and the last request to LAND won rather than the last one sent.
   */
  var searchSeq = 0;

  async function search() {
    var mine = ++searchSeq;
    var asked = term;

    searching = true;
    paintRegion('results');

    try {
      var body = await api('/ugc-videos/products?q=' + encodeURIComponent(asked));
      if (mine !== searchSeq) return;              // a later keystroke owns the box now
      results = body.products || [];
      /*
       * HOW MANY MATCHED BUT CANNOT BE TAGGED. The server counts these only when
       * the visible answer is empty, and it is the difference between "the search
       * is broken" and "that product is a draft" -- which is the confusion that
       * produced this bug report in the first place.
       */
      unpublishedHits = Number(body.unpublished || 0) || 0;
    } catch (e) {
      if (mine !== searchSeq) return;
      results = [];
      unpublishedHits = 0;
      say(explain(e, 'Products could not be searched.'));
    } finally {
      if (mine === searchSeq) {
        searching = false;
        searched = asked !== '';
        /* THE RESULTS REGION ONLY. render() would have replaced #content and
           with it the box being typed into, which is what the old caret dance
           existed to paper over. */
        paintRegion('results');
      }
    }
  }

  /**
   * The last few products, so step 4 offers something to press before a single
   * character is typed. The owner asked for the recent ten.
   *
   * Asked for ONCE per screen visit and never awaited by anything: a slow or
   * refused read costs the step nothing and simply leaves the box as the only
   * way in, exactly as it was before.
   */
  async function loadRecent() {
    if (recentAsked) return;
    recentAsked = true;

    try {
      var body = await api('/ugc-videos/products?recent=10');
      recent = body.products || [];
    } catch (e) {
      /* Silent on purpose: this is a convenience on a screen about clips, and a
         toast about the product endpoint helps nobody tag a product. */
      recent = [];
    }
    if (editing && step === 4) paintRegion('results');
  }

  /* ------------------------------------------------------------------ paint */

  function icon(path) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }

  var ICON_UP = '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>';
  var ICON_PLUS = '<path d="M12 5v14"/><path d="M5 12h14"/>';
  var ICON_GRIP = '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/>'
    + '<circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>';

  /* One glyph per section head. Constants, every one of them, and each is drawn
     through icon() so the stroke attributes are written in exactly one place. */
  var ICON_TITLE = '<path d="M4 6h16"/><path d="M4 12h11"/><path d="M4 18h7"/>';
  var ICON_QUOTE = '<path d="M9 7H5v5h4c0 2-1 3-3 3"/><path d="M19 7h-4v5h4c0 2-1 3-3 3"/>';
  var ICON_FILM = '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9.5h18"/>'
    + '<path d="M3 14.5h18"/><path d="M8.5 4v16"/><path d="M15.5 4v16"/>';
  var ICON_IMAGE = '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.4"/>'
    + '<path d="m4 18.5 5-5 3.5 3.5L16 14l4 4"/>';
  var ICON_CUT = '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/>'
    + '<path d="M20 4 8 16"/><path d="M8 8l12 12"/>';
  var ICON_LOOP = '<path d="m17 2 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/>'
    + '<path d="m7 22-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>';
  var ICON_USER = '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>';
  var ICON_SHIELD = '<path d="M12 3l8 3v6c0 5-3.4 8.2-8 9-4.6-.8-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>';
  var ICON_TAG = '<path d="M20.5 13.5 12 22 3 13V3h10z"/><circle cx="7.5" cy="7.5" r="1.2"/>';
  var ICON_SEARCH = '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.5-4.5"/>';
  var ICON_SEND = '<path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>';
  var ICON_CLOCK = '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3.5 2"/>';

  /*
   * A SECTION HEAD THAT READS AS A SECTION.
   *
   * `title`, `sub` and `note` are CONSTANTS at every call site — never a
   * setting — and each goes through esc() anyway. `body` is markup the caller
   * has already escaped, the same contract panelHead() has.
   *
   * `tone` is one of two words this file writes itself, and `bodyClass` is one
   * of this file's own classes. Neither is ever read from a payload.
   *
   * @param {{glyph:string,title:string,sub:string,body:string,
   *          tone?:string,note?:string,bodyClass?:string}} o
   */
  /*
   * `o.region` names a section this screen repaints ON ITS OWN, and the
   * attribute goes on the <section> rather than on a wrapper around it
   * DELIBERATELY: `.ugs-cols` is a two-column grid whose children carry
   * `min-width:0`, and an extra div between the grid and the section would take
   * that rule for itself and leave the section free to overflow at 390px --
   * which is how one of the four screens in AdminMobileOverflowTest broke
   * before. No new node, no new layout, nothing to get wrong.
   */
  function secHTML(o) {
    return '<section class="ugs-sec' + (o.tone ? ' is-' + o.tone : '') + '"'
      + (o.region ? ' data-ugs-region="' + o.region + '"' : '') + '>'
      + secInnerHTML(o)
      + '</section>';
  }

  /** A section's head and body without the <section> around them, so a region
      can rewrite exactly what changed and leave the element itself in place. */
  function secInnerHTML(o) {
    return '<header class="ugs-sech">'
      +   '<span class="ugs-secn">' + icon(o.glyph) + '</span>'
      +   '<span><span class="ugs-sect">' + esc(o.title) + '</span>'
      +     (o.sub ? '<span class="ugs-secs">' + esc(o.sub) + '</span>' : '')
      +   '</span>'
      +   '<span class="ugs-secw">' + esc(o.note || '') + '</span>'
      + '</header>'
      + '<div class="ugs-secb' + (o.bodyClass ? ' ' + o.bodyClass : '') + '">' + o.body + '</div>';
  }

  /** Two sections side by side at a desk, stacked on a phone. CSS decides. */
  /**
   * The loop panel, as a string, so it can be the third column.
   *
   * It used to append straight into `html` and run the full width beneath
   * the cut panel. The owner asked for the video, the cover and the loop
   * side by side -- "so i can see everything side by side" -- and a block
   * that appends cannot be placed. Extracting it changes no markup.
   */
  /*
   * `locked` and `poster` are parameters rather than closure reads because this
   * function was LIFTED out of mediaPanel(), where both were locals. Two runs
   * in a real browser found them one at a time -- "poster is not defined", then
   * "locked is not defined" -- which is the cost of extracting a 72-line block
   * by hand and the reason it was checked in a browser rather than only by the
   * suite: neither is reachable from a test that does not execute the page.
   */
  function loopSectionHTML(v, clip, teaser, poster, locked) {
    var loopHtml = '';

      loopHtml += '<section class="ugs-sec"><header class="ugs-sech">'
        + '<span class="ugs-secn">' + icon(ICON_LOOP) + '</span>'
        + '<span><span class="ugs-sect">The ' + esc(secs(loop().ms)) + ' second loop</span>'
        +   '<span class="ugs-secs">What a shopper sees on the rail, at the size the tile really '
        +     'is on the shop.</span></span>'
        + '<span class="ugs-secw">' + esc(stateWords(v)[0]) + '</span>'
        + '</header><div class="ugs-secb">';

      if (clip) {
        var src = teaser || clip;
        /* The tile beside the prose about it: a 178px track and one fluid one from
           900px, one column below that. A GRID IN THE STYLESHEET, not the
           `style="flex:1 1 220px"` this used to carry -- an inline declaration is
           the one place a media query cannot reach, which is exactly how one of
           the four screens in AdminMobileOverflowTest came to overflow. */
        loopHtml += '<div class="ugs-loopcols">'
          + '<div class="ugs-loopbox">'
          +   '<div class="ugs-loop" data-ugs-loopsrc="' + esc(src) + '"'
          +     ' data-ugs-loopms="' + esc(loop().ms) + '"'
          +     ' data-ugs-loopfull="' + (teaser ? '0' : '1') + '">'
          +     (poster ? '<img src="' + esc(poster) + '" alt="">' : '')
          +   '</div>'
          +   '<p class="ugs-loopcap">158px wide — the tile\'s real size on the shop.</p>'
          + '</div>'
          + '<div>'
          +   '<div class="ugs-note is-cool">'
          +     (teaser
                ? '<b>This clip has its own teaser file</b>, so the tile downloads about 130 KB '
                  + 'instead of the whole video. The loop above is that file, played end to end.'
                : '<b>The loop needs no second file.</b> The tile plays the first '
                  + esc(secs(loop().ms)) + ' seconds of this '
                  + 'very video and rewinds, which is exactly what you are watching. Uploading a '
                  + 'separate teaser is a bandwidth saving and nothing else &mdash; a rail of eight '
                  + 'full clips measured 12.19 MB against 1.01 MB of teasers.')
          +   '</div>'
          /* Said only when it is TRUE, and read off the setting rather than
             guessed: an owner who has switched the rail's loop off is watching a
             preview of something his shop is not doing, and that is the one case
             where this preview could mislead him. */
          +   (loop().on ? '' :
                '<div class="ugs-note is-warm" style="margin-top:8px"><b>The loop is switched off '
                + 'for the whole rail.</b> Every tile on the shop shows its cover and stands still, '
                + 'however many clips have teasers. The preview above is what a shopper would see '
                + 'with it on.</div>')
          +   '<p class="ugs-help" style="margin-top:8px">A shopper in data-saver mode, or with '
          +     'reduced motion switched on, sees the cover and no movement at all &mdash; with or '
          +     'without a teaser. The loop\'s length'
          +     (motion ? ', now ' + esc(secs(loop().ms)) + ' seconds,' : '')
          +     ' and whether it runs at all are set in '
          +     '<b>Appearance &rarr; Shoppable video &rarr; Motion</b>.</p>'
          + '</div>'
          + '</div>';

        /* Folded away: it is the one file on this screen that nothing waits for,
           and drawn open beside the two required ones it reads as a third thing
           to do. */
        loopHtml += '<details class="ugs-more"' + (teaser ? ' open' : '') + '>'
          + '<summary>A separate teaser file ' + (teaser ? '&mdash; ' + esc(kb(v.teaser_bytes))
              : '&mdash; optional, saves bandwidth') + '</summary>'
          + '<div class="ugs-morebody">'
          +   (teaser ? '<p class="ugs-path">' + esc(teaser) + '</p>' : '')
          +   dropHTML('teaser', teaser ? 'Drop a different teaser here' : 'Drag a teaser here',
                '2&ndash;3 seconds, 360&times;640, no sound. Up to ' + esc(cap('teaser')) + ' MB. '
                + 'Nothing waits for it.', locked)
          + '</div>'
          + '</details>';
      } else {
        loopHtml += '<p class="ugs-help">Nothing to loop yet. Add the video above and it plays here, '
          + 'at the size it will be on the shop.</p>';
      }

      loopHtml += '</div></section>';

    return loopHtml;
  }

  function colsHTML(left, right) {
    return '<div class="ugs-cols">' + left + right + '</div>';
  }

  /** The video, the cover and the loop in one row -- see .ugs-cols3. */
  function cols3HTML(a, b, c) {
    return '<div class="ugs-cols3">' + a + b + c + '</div>';
  }

  function pill(text, tone) {
    return '<span class="ugs-pill' + (tone ? ' is-' + tone : '') + '">' + esc(text) + '</span>';
  }

  /*
   * WHAT THE MEDIA STATE IS CALLED, AND WHY IT IS NOT "POSTER ONLY".
   *
   * UgcVideo::mediaState() answers MEDIA_POSTER_ONLY for a clip with a video and
   * a cover and no separate teaser file — which is EVERY clip on a server with
   * no ffmpeg. Badged "Poster only" it read as a defect and sent the owner
   * hunting for a second file to upload. It is not a defect: that tile loops the
   * first two and a half seconds of the full clip, which is what the shop draws.
   * So the badge says what the shopper will see.
   *
   * THE WORDS ARE ugc-sections-screen.blade.php's WORDS. That screen badges the
   * same three states beside the same clips, one tab away, and two tabs that
   * disagree about what a clip is doing are worse than either wording alone. So
   * MEDIA_POSTER_ONLY reads "Loops from full video" in both places, and if one
   * of them is ever reworded the other moves with it.
   *
   * ── AND "none" IS TWO DIFFERENT THINGS ──────────────────────────────────
   *
   * THE SECOND DEFECT. mediaState() answers MEDIA_NONE when EITHER the video or
   * the cover is missing — read it: `file_path === '' || poster_path === ''`.
   * Badged "No video yet" from that one state, a clip whose video had uploaded
   * perfectly and was only missing a cover told the owner his video was not
   * there. The next thing anybody does with that sentence is upload the video
   * again, over a 64 MB round trip, and watch the badge not change.
   *
   * The two are told apart from the row's own columns, which the card payload
   * carries for exactly this kind of question.
   */
  function stateWords(v) {
    /* NO NUMBER ON THIS ONE, and that is not an oversight. `teaser_ms` governs
       the full-clip fallback ONLY — the schema's own help says "Only used when a
       clip has no separate teaser file" — and a teaser FILE is played on the
       native loop for exactly as long as the file is. ffmpeg cuts them to
       UgcTranscoder::TEASER_SECONDS, but an owner may drop one in by hand, and
       this screen does not read durations. So it claims no length it cannot
       stand behind. */
    /*
     * ── THE FILE ITSELF, BEFORE ANYTHING ABOUT ITS DERIVATIVES ────────────
     *
     * THE THIRD DEFECT, and the one the owner hit. media_state reads three
     * COLUMNS and nothing else, so a clip whose video file had been deleted off
     * the server went on badging "Loops from full video" — a clip described as
     * looping with nothing left to loop, on the one screen whose job is to say
     * what each clip is doing. His own cut run named two of them.
     *
     * `file_state` is the server's answer to the question the columns cannot
     * answer (App\Services\Ugc\ClipFile), and it is asked FIRST because a
     * missing file outranks every statement about a teaser cut from it. The
     * full sentence, with the path to search for, is already in `warnings` and
     * is drawn in the editor panel; this is the badge that makes it findable in
     * a list of forty.
     */
    if (v.file_state === 'gone') {
      return ['Video file is gone', 'hold'];
    }

    if (v.file_state === 'unservable') {
      return ['Video path unusable', 'hold'];
    }

    if (v.media_state === 'ready') {
      return ['Loops its own teaser', 'live'];
    }

    if (v.media_state === 'poster_only') {
      return ['Loops from full video', 'info'];
    }

    return v.file_path ? ['No cover yet', 'hold'] : ['No video yet', 'hold'];
  }

  function tileHTML(v) {
    var state = stateWords(v);
    var poster = safeMedia(v.poster_path);
    var name = esc(v.title || '(untitled)');

    return '<div class="ugs-tile">'
      + '<button class="ugs-tilemain" data-ugs-open="' + esc(v.id) + '">'
      +   '<div class="ugs-shot">'
      +     (poster ? '<img src="' + esc(poster) + '" alt="" loading="lazy">' : 'no cover')
      +   '</div>'
      +   '<div class="ugs-tb">'
      +     '<div class="ugs-tn">' + name + '</div>'
      /* bdi, not span: "@layla.skin" inside an Arabic title renders as
         "layla.skin@" without it. §5 of the plan, and it broke in the
         previews before it was fixed. */
      +     '<div class="ugs-tm">'
      +       (v.creator_handle ? '<bdi>' + esc(v.creator_handle) + '</bdi> · ' : '')
      +       esc(v.products_count) + ' product' + (v.products_count === 1 ? '' : 's')
      +     '</div>'
      +     '<div class="ugs-pills">'
      +       pill(v.status === 'publish' ? 'published' : 'draft', v.status === 'publish' ? 'live' : '')
      +       (v.rights_status === 'granted' ? '' : pill('no permission yet', 'hold'))
      +       pill(state[0], state[1])
      +       (v.locale ? pill(v.locale === 'ar' ? 'Arabic only' : 'English only', 'soft') : '')
      +     '</div>'
      +   '</div>'
      + '</button>'
      + '<button class="ugs-x" data-ugs-del="' + esc(v.id) + '" data-ugs-delname="' + name + '"'
      +   ' aria-label="Delete ' + name + '" title="Delete">&times;</button>'
      + '</div>';
  }

  /* What this server can do for the owner, asked rather than assumed — §8
     question 4 has never been answered. Two sentences, not a wall.

     ── AND IT USED TO GUESS, WRONGLY, ON THIS VERY SHOP ────────────────────

     The last line of this function read, hard-coded:

         'No ffmpeg here — you choose the cover'

     On the owner's Cloudways box that is FALSE. /usr/bin/ffmpeg is installed;
     PHP-FPM is not allowed to START it, because `proc_open` is in that pool's
     disable_functions — reproduced and written up in docs/SERVER-PROC-OPEN.md
     §1. So the first thing on the first screen of this feature has been telling
     him to install a program he already has, while the setting actually in the
     way went unnamed. It is exactly the mistake UgcTranscoder::blocker() was
     written to end, surviving one layer further out because this function had a
     bool and no way to ask which of the two it meant.

     It asks now. `transcoder.reason` is one of a closed set the server owns
     (UgcTranscoder::REASON_*), and the words for each are THIS screen's,
     chosen from the map below — the server never sends a label and this never
     invents a diagnosis. */
  var CUT_CHIP = {
    no_ffmpeg: 'No ffmpeg here — the cover is taken in your browser',
    no_spawn: 'ffmpeg is here, but PHP may not start it'
  };

  function transcoderChip() {
    if (!transcoder) return '';

    if (transcoder.available) {
      return '<span class="ugs-chip is-live">Cuts the cover and a '
        + esc(transcoder.teaser_seconds) + 's teaser for you</span>';
    }

    /* The fallback is the honest one: a box whose reason did not arrive is a box
       this screen knows cannot cut and does not know why, and saying so beats
       naming the more likely of two. */
    return '<span class="ugs-chip is-warm">'
      + esc(CUT_CHIP[transcoder.reason] || 'The cover is taken in your browser')
      + '</span>';
  }

  /**
   * THE REMEDY, ONCE, ON THE SCREEN HE IS ALREADY LOOKING AT.
   * ═════════════════════════════════════════════════════════════════════════
   *
   * The owner: *"the 2-3 seconds clip is not generating automatically when
   * upload the video."* It is not, on his server, and until now nothing on this
   * screen said why or what to do — the reason lived one clip and one step
   * deep, in the editor, and the way out lived only in docs/SERVER-PROC-OPEN.md,
   * which is a file on a branch and not a thing he can read.
   *
   * THE FIRST PARAGRAPH IS THE ONE THAT MATTERS, and it is the one nobody had
   * told him: THE RAIL DOES NOT NEED THE TEASER FILE. resources/views/ugc/
   * assets.blade.php mounts `teaser || full` and, where there is no teaser,
   * plays the first `teaser_ms` of the clip itself and rewinds — measured in
   * Chromium on a clip with no teaser file: currentTime ran 0.90 → 1.61 → 2.31
   * → 0.32 → 1.03, a clean 2.5s sawtooth. So a shop with no ffmpeg loops
   * exactly as one with it; the teaser is a BANDWIDTH saving and nothing else.
   * A screen that leads with the fault instead of that fact sends its reader to
   * fix something that is not broken.
   *
   * The second is the fix, in the words of the panel he would type it into.
   * NO SERVER PATH IS PRINTED: HealthApiController and this controller's own
   * probeNote() both strip base_path() out of anything that reaches a screen,
   * and one remedy note is not a reason to start leaking the machine's layout.
   * Cloudways shows the application folder on the page the cron box is on.
   *
   * SHOWN ONLY WHERE IT IS TRUE — `available === false` — so a shop that can cut
   * never sees it, and it says nothing on the screen the owner uses every day.
   */
  function cutRemedyHTML() {
    if (!transcoder || transcoder.available) return '';

    var length = secs(loop().ms);

    var why = transcoder.reason === 'no_spawn'
      ? 'ffmpeg <b>is</b> installed on this server, but PHP is not allowed to start a program '
        + '(<code>proc_open</code> is switched off in the PHP-FPM pool that serves the shop), so the '
        + 'cut cannot happen inside an upload.'
      : transcoder.reason === 'no_ffmpeg'
        ? 'ffmpeg is not installed on this server, so there is nothing here to cut with.'
        : 'This server cannot cut the derived files during an upload.';

    return '<div class="ugs-card"><div class="ugs-note is-cool" data-ugs-cutremedy="1">'
      + '<b>Every tile still loops. Nothing is broken.</b> '
      + 'The rail plays the first ' + esc(length) + ' seconds of the video you uploaded and '
      + 'rewinds, so a clip loops whether or not a separate ' + esc(length) + '-second file was ever cut. '
      + 'The cover is taken in your browser the moment an upload lands.'
      /*
       * AND THEN THE HONEST SIZE OF WHAT IS MISSING, because "a bandwidth
       * saving" reads as small and it is not. Measured in Chromium against this
       * branch, at 390, on a 6.1 MB clip: with no teaser file the tile fetched
       * 5,959 KB on a fast link and 10,955 KB over twenty seconds on a throttled
       * 3 Mbit one — MORE than the file, because the loop rewinds past what the
       * browser has already dropped and it fetches it again. The same clip with
       * a 2.5-second teaser fetched 97 KB, once, and buffered exactly 2.50s.
       * That was measured when teasers were 2.5 seconds long. They are ONE
       * second now, and the same argv against the same 1080x1920 source cuts
       * 100,975 B at 2.5s against 30,847 B at 1s -- so the figure below is a
       * CEILING on what a cut clip costs today, not an estimate of it. The
       * sentence says which, because a number measured at a length the shop no
       * longer cuts at is exactly the kind of stale figure this screen was
       * rewritten to stop printing.
       *
       * HTTP Range does not bound this and cannot: the shop controls what it
       * serves, and the browser alone decides how far ahead of the playhead to
       * buffer. The teaser file is the only thing that puts a ceiling on it.
       */
      + '<div style="margin-top:10px"><b>It is worth cutting them, though.</b> '
      + 'Measured on a 6.1 MB clip at phone width: a tile with no teaser file fetched '
      + '<b>10.7 MB</b> in twenty seconds on a 3 Mbit connection — it re-fetches what the loop has '
      + 'rewound past — while the same clip with a teaser file fetched <b>97 KB</b>, once, back when '
      + 'teasers were cut at two and a half seconds. They are cut at ' + esc(length) + ' now, and the same clip '
      + 'measured 98.6 KB at 2.5 seconds against 30.1 KB at one — so 97 KB is the ceiling and not the '
      + 'figure. That is well over a hundred times the data, per tile, and up to four tiles play at once.</div>'
      + '<div style="margin-top:10px">' + why + '</div>'
      + '<div style="margin-top:10px"><b>If you want the teasers cut anyway</b>, this shop can do it '
      + 'on a schedule instead — the command line on this same machine is allowed to start ffmpeg '
      + 'even when the web server is not. Add <b>one</b> cron entry and every clip, including the '
      + 'ones already here, gets its cover and its ' + esc(length) + '-second teaser within a minute of being '
      + 'uploaded:'
      + '<div style="margin-top:8px"><code>* * * * * cd /path/to/your/application &amp;&amp; '
      + 'php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></div>'
      + '<div style="margin-top:8px">On Cloudways that is '
      + '<b>Application Settings → Cron Job Management → Add New Cron → Advanced</b>'
      + ', and the application folder to put after '
      + '<code>cd</code> is the one shown on that same Application Settings page. Add this one '
      + 'entry only — do not add a second for the cover cut itself, or the work runs twice.</div>'
      + '</div>'
      + '</div></div>';
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

  /* ─────────────────────────────────────────────────────────── the stepper */

  function stepState(n) {
    var v = editing || {};
    if (!v.id && n > 1) return 'locked';
    if (n === 1) return (v.id && v.title) ? 'done' : 'todo';
    if (n === 2) return (v.file_path && v.poster_path) ? 'done' : 'todo';
    if (n === 3) return v.rights_status === 'granted' ? 'done' : 'todo';
    if (n === 4) return tagged.length ? 'done' : 'todo';
    return v.status === 'publish' ? 'done' : 'todo';
  }

  function stepsHTML() {
    return '<div class="ugs-steps" role="tablist" data-ugs-region="steps">'
      + stepButtonsHTML() + '</div>';
  }

  /** The rail's buttons alone, so a step change can rewrite them and nothing else. */
  function stepButtonsHTML() {
    return STEPS.map(function (s) {
      var state = stepState(s.n);
      var cls = 'ugs-step'
        + (s.n === step ? ' is-on' : '')
        + (state === 'done' ? ' is-done' : '')
        + (state === 'locked' ? ' is-locked' : '');

      return '<button class="' + cls + '" data-ugs-step="' + s.n + '"'
        + (state === 'locked' ? ' disabled' : '')
        + ' aria-current="' + (s.n === step ? 'step' : 'false') + '">'
        + '<span class="ugs-stepn">' + (state === 'done' ? '&#10003;' : s.n) + '</span>'
        + '<span><span class="ugs-steplab">' + esc(s.label) + '</span><br>'
        +   '<span class="ugs-stepsub">' + esc(state === 'locked' ? 'after step 1' : s.sub) + '</span></span>'
        + '</button>';
    }).join('');
  }

  /* `why` is written into the markup unescaped and `title` is not, and the
     difference is rule 5 rather than an inconsistency: both are CONSTANTS
     declared a few lines below, and `why` is the only one that carries <b>.
     Neither ever holds a setting. */
  function panelHead(n, title, why) {
    return '<div class="ugs-phead">'
      + '<div class="ugs-pk">Step ' + n + ' of ' + STEPS.length + '</div>'
      + '<div class="ugs-ptitle">' + esc(title) + '</div>'
      + '<p class="ugs-pwhy">' + why + '</p>'
      + '</div>';
  }

  /* ───────────────────────────────────────────────────── the drop zones */

  /*
   * A drop zone with the REAL <input type="file"> inside it.
   *
   * The label is the input's activator, natively — nothing here calls click()
   * on an input from script — and the input keeps a 1px box rather than
   * display:none so it stays in the tab order. The `accept` is written out as a
   * LITERAL, never concatenated from a variable, because
   * AdminMediaPickerEverywhereTest reads it out of this source text: an input it
   * cannot see an accept on is classified as an image picker that should have
   * used the Media Library.
   */
  function dropHTML(kind, lead, sub, locked) {
    return '<label class="ugs-drop' + (locked ? ' is-locked' : '') + '" data-ugs-drop="' + esc(kind) + '">'
      + '<input type="file" accept="video/mp4,video/webm" class="ugs-file"'
      +   ' data-ugs-upload="' + esc(kind) + '"' + (locked ? ' disabled' : '') + '>'
      + '<span class="ugs-dropi">' + icon(ICON_UP) + '</span>'
      + '<span class="ugs-dropb">' + esc(lead) + '</span>'
      + '<span class="ugs-drops">' + sub + '</span>'
      + '</label>';
  }

  /* The cover zone takes a DROP but has no file input, so the Media Library
     stays the only way to browse for one. A dropped picture is uploaded into
     the library first and adopted from there, which is what the console's own
     image field does. */
  function posterDropHTML(locked) {
    return '<label class="ugs-drop' + (locked ? ' is-locked' : '') + '" data-ugs-drop="poster">'
      + '<span class="ugs-dropi">' + icon(ICON_UP) + '</span>'
      + '<span class="ugs-dropb">Drag a picture here</span>'
      + '<span class="ugs-drops">JPG, PNG or WebP, up to ' + esc(cap('poster')) + ' MB. '
      +   'It joins the Media Library on the way in.</span>'
      + '</label>';
  }

  /*
   * THE UPLOAD PANEL: in flight, then finished, then refused.
   *
   * The percentage, the bytes sent and the total are the upload event's own
   * numbers — see paintProgress(), which writes them into these three nodes
   * without a repaint. There is no indeterminate variant anywhere here, because
   * a bar that sweeps while nothing is known is a bar that lies.
   *
   * AND IT HAS AN END. Both endings are drawn, and both stay until the next
   * upload: a tick with the size that arrived, or the server's own reason in
   * red. The old panel simply disappeared, which is what a stalled upload looks
   * like too.
   */
  function progressHTML() {
    if (upState) {
      return '<div class="ugs-up" data-ugs-upbox>'
        + '<div class="ugs-uph"><span class="ugs-upn">' + esc(upState.name) + '</span>'
        +   '<span data-ugs-pct>' + esc(upState.pct) + '%</span></div>'
        + '<div class="ugs-prog"><div class="ugs-progb" data-ugs-bar '
        +   'style="width:' + esc(upState.pct) + '%"></div></div>'
        + '<div class="ugs-upm">'
        +   '<span data-ugs-sent>' + esc(bytes(upState.sent)) + ' of '
        +     esc(bytes(upState.total)) + ' sent</span>'
        +   '<span data-ugs-stage>' + esc(upState.text || '') + '</span>'
        + '</div>'
        /* Drawn from the first byte rather than appearing at some threshold: a
           control that materialises once a screen has decided things are going
           badly is a control nobody finds. It is also what makes Try again
           reachable, since the File cannot be recovered after the request ends. */
        + '<div class="ugs-upa"><button type="button" class="ugs-btn ugs-mini" '
        +   'data-ugs-upcancel="1">Cancel this upload</button></div>'
        + '</div>';
    }

    if (!upDone) return '';

    if (upDone.ok) {
      return '<div class="ugs-up">'
        + '<div class="ugs-uph"><span class="ugs-upn">' + esc(upDone.name) + '</span>'
        +   '<span>&#10003; 100%</span></div>'
        + '<div class="ugs-prog"><div class="ugs-progb" style="width:100%"></div></div>'
        + '<div class="ugs-upm">'
        +   '<span>' + esc(bytes(upDone.bytes)) + ' arrived whole</span>'
        +   '<span>' + (upDone.kind === 'clip'
              ? 'This is the video the tile plays.'
              : 'This is the teaser the tile loops.') + '</span>'
        + '</div>'
        + '</div>';
    }

    return '<div class="ugs-up is-bad">'
      + '<div class="ugs-uph"><span class="ugs-upn">' + esc(upDone.name) + '</span>'
      +   '<span>not accepted</span></div>'
      + '<div class="ugs-upm">'
      +   '<span>' + esc(upDone.message) + '</span>'
      /*
       * THE SENTENCE IS THE SERVER'S ANSWER NOW, or there is no sentence.
       *
       * `stored === false` is the only case that may claim the clip is untouched,
       * and `stored === true` is the case that cost the owner the re-uploads: the
       * file IS on the clip and saying otherwise sends him to do it all again. An
       * `undefined` — a request that never arrived — says neither, because
       * nothing here knows.
       */
      +   '<span>' + esc(bytes(upDone.bytes))
      +     (upDone.stored === false ? ' &mdash; nothing on the clip was changed.'
          :  upDone.stored === true
             ? ' &mdash; the file IS on the clip and is being served. Do not upload it again.'
             : '')
      +   '</span>'
      + '</div>'
      /* Offered only where failUpload() kept the File, which is only where a
         second press could really work. A Try again beside "this server accepts
         at most 2 MB" would be a button that cannot succeed. */
      + (upDone.retry
          ? '<div class="ugs-upa"><button type="button" class="ugs-btn ugs-mini" '
            + 'data-ugs-upretry="1">Try again</button>'
            + '<span>The file is still on your computer &mdash; nothing needs choosing again.</span>'
            + '</div>'
          : '')
      + '</div>';
  }

  /*
   * ── WHEN THE SERVER IS THE CEILING, SAY SO WHERE THE FILE IS CHOSEN ──────
   *
   * THE DEFECT THIS EXISTS FOR, and it is the second half of the 8.4 MB one.
   * Making cap() honest on its own turns "up to 64 MB" into "up to 2 MB", which
   * is a smaller number with no explanation attached — the owner would read it
   * as the shop having got worse, and nothing on the screen would tell him the
   * number is PHP's and that he can change it.
   *
   * Drawn only when the server really is the binding limit, so a properly
   * configured host sees nothing at all and nobody is warned about a problem
   * they do not have. Every value in it is escaped; the two ini NAMES are
   * constants in this file and the values come through serverIni(), which is
   * bounded to those two keys.
   */
  function serverCapHTML() {
    if (cappedBy('clip') === '') return '';

    var nothing = capBytes('clip') < 1048576;

    return '<div class="ugs-note is-warm">'
      + '<b>' + (nothing
            ? 'This server will not accept a video of any useful size yet.'
            : 'The size limit on this step is this server, not the shop.') + '</b> '
      + 'Shoppable video allows a video of ' + esc(appWords('clip')) + ', but PHP on this server '
      + 'accepts at most <b>' + esc(serverIni('upload_max_filesize')) + '</b> per file '
      + '(upload_max_filesize) and <b>' + esc(serverIni('post_max_size')) + '</b> per whole '
      + 'request (post_max_size), so <b>' + esc(capWords('clip')) + '</b> is the real ceiling '
      + 'today. The cover and the teaser are held to the same two numbers.'
      + '<ul>'
      +   '<li>A bigger file is thrown away by PHP <b>before this shop sees any of it</b>. That '
      +     'is why an upload can reach 100% and still fail: the bytes leave your browser and '
      +     'never arrive.</li>'
      +   '<li>Raise it on the server, not here. On Cloudways: <b>Servers &rarr; Settings &amp; '
      +     'Packages &rarr; Basic &rarr; Upload Size</b>, or put upload_max_filesize and '
      +     'post_max_size in a user ini file in the web root over SSH.</li>'
      +   '<li>Then reload this screen. This note disappears and the box goes back to saying '
      +     esc(appWords('clip')) + '.</li>'
      + '</ul>'
      + '</div>';
  }

  /*
   * THE CUT, OFFERED WHERE THE UPLOAD ENDS.
   *
   * The owner's ask, verbatim: "once upload the video complete, it should give
   * option to cut teaser and poster." It used to be a button at the bottom of
   * the cover box that he had to notice. It is now a section of its own,
   * directly under the two file boxes, and it wears the loud tone for as long as
   * a video has just landed on this clip.
   *
   * ── IT IS ONLY OFFERED WHERE IT IS REAL ─────────────────────────────────
   *
   * `transcoder.available` is this SERVER's answer, not a hope:
   * UgcTranscoder::available() is a `which ffmpeg` and the library payload
   * carries it. And there has to be a stored clip to cut FROM. Where either is
   * missing this same place says what to do instead, in as many words, rather
   * than drawing a button whose only possible answer is a note saying it could
   * not — which is what /derive really returns on a box with no ffmpeg.
   *
   * ── AND IT TAKES NO CREDIT FOR WHAT THE UPLOAD ALREADY DID ──────────────
   *
   * On a server that HAS ffmpeg the cutting has already happened by the time
   * this is drawn: UgcVideoController::media() calls derive() on the upload
   * request for a clip. So the section reads the row and says which of the two
   * worlds the owner is in, instead of offering to do work that is done.
   */
  function cutHTML(v, clip) {
    if (!clip) return '';       // nothing to cut from, and step 2 says so above

    /* THE SERVER'S OWN CONSTANT, and the fallback moved with it. This screen
       prints the cut length in four sentences and must never print a number the
       shop has stopped cutting at -- which is what '2.5' here became the day
       UgcTranscoder::TEASER_SECONDS changed. */
    var length = (transcoder && transcoder.teaser_seconds) || '1';
    var fresh = freshClip !== null && String(freshClip) === String(v.id);

    if (!(transcoder && transcoder.available && v.id)) {
      /*
       * THE SERVER'S OWN SENTENCE, NOT THIS SCREEN'S GUESS.
       *
       * This used to read "It answered that it has no ffmpeg", which the server
       * had never said: all it sent was a bool, and the two ways of being unable
       * to cut — no ffmpeg at all, or an ffmpeg PHP is not allowed to start —
       * have different remedies. On the box this episode was about, the guess was
       * the wrong one, and it sent the owner to install a program he already had.
       * `blocker` is the reason in the server's words, so there is nothing left
       * here to guess with.
       */
      var why = (transcoder && transcoder.blocker) ? String(transcoder.blocker) : '';

      /*
       * THE TITLE NO LONGER SAYS "NOTHING CAN BE CUT", because that stopped
       * being true the moment the cover could be cut in the browser. It named
       * the SERVER's limitation and the owner read it, correctly, as the
       * screen's. What cannot be cut here is the teaser; the cover can, and
       * offering it is the whole point of this panel now.
       */
      return secHTML({
        glyph: ICON_CUT,
        /*
         * ── IT LEADS WITH WHAT HAPPENS, NOT WITH WHAT THE SERVER CANNOT DO ──
         *
         * Two titles ago this said "Nothing can be cut on this server". Then
         * "This server cannot cut a cover — your browser can". The owner read
         * the second and answered: "i need the permanent solution or any other
         * method to solve this super reliable."
         *
         * He was right both times, and the fault was not the wording. A panel
         * whose first line is the server's limitation reads as a fault report
         * however it ends, and a button underneath puts the work back on him
         * for something that now happens by itself. The cover IS taken
         * automatically on upload -- see autoCutCover() -- so this panel is no
         * longer where the job gets done. It says so, and keeps the button for
         * re-cutting and for clips uploaded before this version.
         */
        title: 'The cover is taken here, in your browser',
        sub: 'Automatically, the moment a video finishes uploading. Nothing to press.',
        note: 'automatic',
        tone: 'live',
        body: '<div class="ugs-note is-cool"><b>This is already done for you.</b> '
          + 'Your browser has decoded the clip in order to play it back, so it takes the '
          + 'frame at 0.6 seconds &mdash; the same moment this shop\'s own cutter uses &mdash; '
          + 'and sets it as the cover as soon as the upload lands. It needs nothing from the '
          + 'server, no ffmpeg and no setting changed, and it will keep working on any host '
          + 'this shop is ever moved to.'
          + (why ? ' <br><br><span style="opacity:.75">Why it is not done on the server: '
              + esc(String(why)) + '</span>' : '')
          + '</div>'
          + '<div class="ugs-slotrow" style="margin-top:10px">'
          + '<button class="ugs-btn" data-ugs-cuthere="1"'
          + ((busy || cutting) ? ' disabled' : '') + '>'
          + (cutting ? 'Taking the frame…' : 'Take the cover again') + '</button>'
          + '</div>'
          + '<div class="ugs-note is-warm" style="margin-top:10px"><b>Or choose a still yourself.</b> '
          + 'Press <b>Choose from the Media Library</b> in the cover box and pick a frame exported '
          + 'from wherever you edited the video. <b>The loop needs no second file</b>: the tile '
          + 'plays the first ' + esc(secs(loop().ms)) + ' seconds of this very video and rewinds, '
          + 'so a separate teaser is a bandwidth saving you can skip entirely.</div>'
      });
    }

    var has = !!(v.poster_path && v.teaser_path);
    var body = '';

    if (fresh) {
      body += '<div class="ugs-note is-cool"><b>Your video is uploaded.</b> '
        + (has
           ? 'The cover and a ' + esc(length) + ' second teaser were cut from it on the way in, '
             + 'and both are on the clip now &mdash; there is nothing left to upload. Cut them '
             + 'again if you would rather they came from a different moment.'
           : 'Cut the cover and the teaser out of it here, and there is nothing left to upload.')
        + '</div>';
    }

    body += '<p class="ugs-help">The cover is one frame taken at 0.6 seconds, because frame zero '
      + 'of a phone video is very often half-exposed. The teaser is the first ' + esc(length)
      + ' seconds scaled to 360&times;640 with the sound dropped. Both replace whatever is on '
      + 'the clip now.</p>'
      + '<div class="ugs-cutb">'
      +   '<button class="ugs-btn is-primary" data-ugs-derive="1"' + (busy ? ' disabled' : '') + '>'
      +     icon(ICON_CUT)
      +     (has ? 'Cut them again from the video' : 'Cut the cover and teaser from the video')
      +   '</button>'
      + '</div>';

    return secHTML({
      glyph: ICON_CUT,
      title: has ? 'The cover and the teaser are cut' : 'Cut the cover and teaser from the video',
      sub: has
        ? 'Both came out of the video itself, so neither has to be made by hand.'
        : 'This server has ffmpeg, so neither file has to be made by hand.',
      note: fresh ? 'just uploaded' : (has ? 'done' : 'one press'),
      tone: fresh ? 'live' : '',
      body: body
    });
  }

  /* ─────────────────────────────────────────────────────────── the panels */

  /*
   * TWO COLUMNS, AND THE ARABIC BOX STAYS WITH ITS OWN FIELD.
   *
   * The title on the left and the caption on the right, each with its Arabic box
   * under it. The other way round — every English field on the left and every
   * Arabic box on the right — reads tidier and is worse: the two boxes for one
   * field end up a column apart, and a column that only exists when the Arabic
   * shop is on is a column that is empty half the time.
   *
   * KBBArabic.collect() and wire() both walk the whole form with
   * querySelectorAll, so where a box SITS is this screen's business and not
   * theirs. Nothing about the payload changes.
   */
  function detailsPanel(v) {
    var titleBody = '<div class="ugs-f"><label for="ugs-title">Title</label>'
      + '<input id="ugs-title" type="text" maxlength="180" data-ugs-field="title" dir="auto"'
      +   ' placeholder="Glass skin in 6 steps" value="' + esc(v.title) + '">'
      + arabicBox('title', 'Title')
      + '</div>';

    var captionBody = '<div class="ugs-f"><label for="ugs-caption">Caption</label>'
      + '<textarea id="ugs-caption" maxlength="2000" data-ugs-field="caption" dir="auto"'
      +   ' placeholder="The creator\'s own words, if you are using them.">' + esc(v.caption) + '</textarea>'
      + arabicBox('caption', 'Caption', 'textarea')
      + '</div>';

    return '<section class="ugs-panel"' + (step === 1 ? '' : ' hidden') + '>'
      + panelHead(1, 'Details', 'A title is all it takes to start. Saving here creates the clip, '
        + 'which is what the video and the cover then belong to.')
      + colsHTML(
          secHTML({
            glyph: ICON_TITLE,
            title: 'What the clip is called',
            sub: 'Shown under the tile on the shop, and everywhere in this console.',
            note: 'required',
            body: titleBody
          }),
          secHTML({
            glyph: ICON_QUOTE,
            title: 'The caption',
            sub: 'The creator\'s own words, if you are using them. Nothing waits for it.',
            note: 'optional',
            body: captionBody
          })
        )
      + '</section>';
  }

  /** The clip, the cover, the optional teaser, and the loop as it really plays. */
  function mediaPanel(v) {
    var locked = !v.id;
    var clip = safeMedia(v.file_path);
    var teaser = safeMedia(v.teaser_path);
    var poster = safeMedia(v.poster_path);

    var html = '<section class="ugs-panel"' + (step === 2 ? '' : ' hidden') + '>'
      + panelHead(2, 'Video & cover', 'Two files, and both are needed before a clip can go live: '
        + 'the video itself, and a still to hold the tile\'s shape while it loads.');

    if (locked) {
      html += '<div class="ugs-note is-warm"><b>Nothing can be uploaded yet.</b> '
        + 'Give the clip a title on step 1 and save — a file needs a clip to belong to.</div>';
    }

    /* Before the drop zones rather than after them: the number in the zone's own
       sub-line is now the server's, and an unexplained small number is the thing
       this note exists to stop the owner reading. */
    html += serverCapHTML();

    html += progressHTML();

    /*
     * THE CUT'S BAR LIVES HERE, NOT INSIDE THE CUT PANEL.
     *
     * It was inside two of cutHTML()'s arms, and a browser run showed the cost:
     * once a cover EXISTS that panel draws a different arm, so pressing "Take
     * the cover again" moved a bar that had nowhere to render. The button was
     * found and pressed and not one stage was observable.
     *
     * Beside the upload panel instead -- drawn on every arm of this step, and
     * the right neighbour anyway: an upload and a cut are the two things on
     * this screen that take time and report progress.
     */
    html += cutBarHTML();

    /* ── the two files, side by side at a desk and stacked on a phone ──
       Each is a .ugs-sec whose BODY is the old .ugs-slot: the section draws the
       border, the tinted head and the size on the end, and the slot still packs
       its rows to the top so the cover button is never a slab. */
    var videoBody = (clip
        ? '<div class="ugs-pv"><video src="' + esc(clip) + '" controls playsinline preload="metadata"'
          + (poster ? ' poster="' + esc(poster) + '"' : '') + '></video></div>'
          + '<p class="ugs-path">' + esc(clip) + '</p>'
        : '')
      + dropHTML('clip', clip ? 'Drop a different video here' : 'Drag your video here',
          'or press to choose one · MP4 or WebM, up to ' + esc(cap('clip')) + ' MB', locked);

    var coverBody = (poster
        ? '<div class="ugs-pv"><img src="' + esc(poster) + '" alt=""></div>'
          + '<p class="ugs-path">' + esc(poster) + ' · '
          + (v.width && v.height ? esc(v.width + '×' + v.height) : 'size unread') + '</p>'
        : '')
      /*
       * RE-CUT, AND IT IS A CONTROL RATHER THAN SOMETHING THAT ONLY EVER
       * HAPPENS BY ITSELF.
       *
       * The cover is taken automatically the moment an upload finishes, which
       * is right and is what the panel below says. But automatic-only means
       * there is no way back: swap the video for a different one through the
       * Media Library, decide the frame at 0.6s caught a blink, or arrive at a
       * clip somebody else uploaded before this shop could cut anything, and
       * the screen offered nothing to press. The owner asked for exactly this:
       * *"i need option to re-generate the clip from the video button there
       * somewhere. so i will have always control for it."*
       *
       * It is the SAME function the automatic path calls -- cutCoverHere() --
       * so there is one cutter and one progress bar, not a second copy that
       * can drift. Passing `false` makes it loud: it draws the bar and says
       * what it did, where the automatic call passes `true` and stays quiet.
       *
       * Shown only when there is a video to cut from, because a button that
       * can only ever answer "there is no video on this clip" is a button that
       * teaches people not to press buttons.
       */
      + (clip
          ? '<button class="ugs-btn" data-ugs-recut="1"' + (locked ? ' disabled' : '') + '>'
            + icon(ICON_CUT) + 'Re-cut the cover from the video</button>'
          : '')
      + '<button class="ugs-btn is-primary" data-ugs-poster="1"' + (locked ? ' disabled' : '') + '>'
      +   icon(ICON_PLUS) + 'Choose from the Media Library</button>'
      + posterDropHTML(locked);

    /*
     * THE REASON THERE IS NO COVER, BESIDE THE THING THAT HAS NO COVER.
     *
     * It used to be a toast and nothing else, so the persistent signal was a bare
     * "No cover yet" with no explanation and no remedy — and the owner, looking
     * at a clip that says a file is missing, does the only thing that screen
     * suggests and uploads the video again. The sentence is the SERVER's, printed
     * verbatim and escaped: it is the one thing that knows whether this box has
     * no ffmpeg or has one it is not allowed to start, and those have different
     * remedies.
     *
     * Above the two file boxes rather than inside the cover one, because on a
     * server that cannot cut, it is the reason BOTH derived files are absent.
     */
    if (cutNote) {
      /*
       * The reason, and then the way out of it. This used to end at the reason,
       * which on this host reads as a dead end -- and it was one until the
       * browser could cut the frame itself. The button is offered only when
       * there IS a video to take a frame from; without one the sentence is
       * still worth printing and the button would do nothing.
       */
      html += '<div class="ugs-note is-warm" data-ugs-cutnote="1"><b>Why there is no cover here.</b> '
        + esc(cutNote)
        + (clip
            ? '<div style="margin-top:9px"><button class="ugs-btn is-primary" '
              + 'data-ugs-cuthere="1"' + ((busy || cutting) ? ' disabled' : '') + '>'
              + (cutting ? 'Taking the frame…' : 'Cut the cover here, in your browser')
              + '</button></div>'
            : '')
        + '</div>';
    }

    /*
     * BUILT INTO A VARIABLE, NOT APPENDED, so it can become the third column.
     * It used to run the full width under the cut panel; the owner asked for
     * all three side by side, and a section that appends to `html` cannot be
     * placed.
     */
    html += cols3HTML(
      secHTML({
        glyph: ICON_FILM,
        title: 'The video',
        sub: 'The clip itself. These are the bytes a tile plays.',
        note: kb(v.bytes),
        tone: clip ? 'live' : '',
        bodyClass: 'ugs-slot',
        body: videoBody
      }),
      secHTML({
        glyph: ICON_IMAGE,
        title: 'The cover',
        sub: 'The still that holds the tile\'s shape while the video loads.',
        note: kb(v.poster_bytes),
        tone: poster ? 'live' : '',
        bodyClass: 'ugs-slot',
        body: coverBody
      }),
      loopSectionHTML(v, clip, teaser, poster, locked)
    );

    /* ── and the cut, where the upload ends rather than where it fits ── */
    html += cutHTML(v, clip);

    /* ── what can be previewed from where ───────────────────────────── */
    html += '<div class="ugs-note"><b>What can be previewed, and what cannot.</b> '
      + 'A file uploaded here plays above, and it is the same bytes the shop serves. '
      + 'An Instagram, TikTok or YouTube address is a <b>credit line only</b> &mdash; this module '
      + 'embeds nothing from anybody else\'s player, so there is nothing to preview and a clip '
      + 'with only a link cannot be published. Save the original, with the creator\'s written yes, '
      + 'and drop the file in.</div>';

    return html + '</section>';
  }

  function creditPanel(v) {
    var post = safeHref(v.source_url);

    return '<section class="ugs-panel"' + (step === 3 ? '' : ' hidden') + '>'
      + panelHead(3, 'Credit and permission', 'This shop re-hosts the creator\'s video, and the '
        + 'creator owns the copyright in it. One written yes is all it takes &mdash; and until it is '
        + 'recorded here the clip cannot be published.')
      /* WHO MADE IT on the left and WHETHER THEY SAID YES on the right: the two
         halves of this step are two different questions, and only the second one
         holds the clip back from publishing. */
      + colsHTML(
          secHTML({
            glyph: ICON_USER,
            title: 'Who made it',
            sub: 'The credit line a shopper sees under the clip.',
            note: 'credit',
            body: '<div class="ugs-f"><label for="ugs-handle">Creator handle</label>'
              + '<input id="ugs-handle" type="text" maxlength="120" data-ugs-field="creator_handle"'
              +   ' placeholder="@layla.skin" value="' + esc(v.creator_handle) + '"></div>'
              + '<div class="ugs-f"><label for="ugs-curl">Creator link</label>'
              + '<input id="ugs-curl" type="text" maxlength="512" data-ugs-field="creator_url"'
              +   ' placeholder="https://…" value="' + esc(v.creator_url) + '">'
              + '<p class="ugs-help">http:// or https:// only. Anything else is dropped.</p></div>'
              + '<div class="ugs-f"><label for="ugs-plat">Where it came from</label>'
              + selectHTML('source_platform', vocab ? vocab.platform : [], v.source_platform) + '</div>'
              + '<div class="ugs-f"><label for="ugs-surl">Original post</label>'
              + '<input id="ugs-surl" type="text" maxlength="512" data-ugs-field="source_url"'
              +   ' placeholder="https://…" value="' + esc(v.source_url) + '">'
              + (post
                 ? '<p class="ugs-help"><a href="' + esc(post) + '" target="_blank" rel="noopener nofollow">'
                   + 'Open the original post &#8599;</a> &mdash; a credit link, never an embed.</p>'
                 : '<p class="ugs-help">A credit link, never an embed.</p>')
              + '</div>'
          }),
          secHTML({
            glyph: ICON_SHIELD,
            title: 'Whether they said yes',
            sub: 'The one thing on this step that stops a clip being published.',
            note: v.rights_status === 'granted' ? 'granted' : 'required',
            tone: v.rights_status === 'granted' ? 'live' : 'warm',
            body: '<div class="ugs-f"><label for="ugs-rights">Permission</label>'
              + selectHTML('rights_status', vocab ? vocab.rights : [], v.rights_status) + '</div>'
              + '<div class="ugs-f"><label for="ugs-ev">Where the permission is recorded</label>'
              + '<textarea id="ugs-ev" maxlength="2000" data-ugs-field="rights_evidence"'
              +   ' placeholder="A DM, an email, a signed release.">' + esc(v.rights_evidence) + '</textarea>'
              + '<p class="ugs-help">Never shown on the shop.</p></div>'
          })
        )
      + '</section>';
  }

  function productsPanel() {
    return '<section class="ugs-panel"' + (step === 4 ? '' : ' hidden') + '>'
      + panelHead(4, 'Products in this clip',
        '<b>Optional</b>, and the whole point of the feature: as many as you like, which is the '
        + 'thing Instagram cannot do. The first is the one a tile shows before anyone taps.')
      /* THE LIST ON THE LEFT AND THE SEARCH ON THE RIGHT, so a clip with six
         products tagged no longer pushes the only way to add a seventh off the
         bottom of the screen. */
      + colsHTML(
          secHTML({
            glyph: ICON_TAG,
            title: 'Tagged on this clip',
            sub: 'Drag to reorder. The first one is what a tile shows before anybody taps it.',
            note: tagged.length ? String(tagged.length) : 'none yet',
            tone: tagged.length ? 'live' : '',
            region: 'tagged',
            body: taggedHTML()
          }),
          secHTML({
            glyph: ICON_SEARCH,
            title: 'Add a product',
            sub: 'Published products only, searched by name as you type.',
            note: 'optional',
            body: '<div class="ugs-f">'
              + '<label for="ugs-search">Search the catalogue</label>'
              /*
               * THE BOX IS DRAWN ONCE AND NEVER REPAINTED AFTERWARDS, which is
               * what finally fixes the caret.
               *
               * It used to be repainted with every result, every tag and every
               * reorder, because render() replaced #content wholesale -- so the
               * element being typed into was a new node by the time the answer
               * landed. Two workarounds grew out of that: `term` was written back
               * into the value here, and the input listener re-focused the box and
               * pushed the caret to the end after each search. Both are gone. The
               * results are their own region now, so a repaint of them cannot
               * touch this input at all, and the caret stays wherever the owner
               * put it -- including in the MIDDLE of a word, which the old
               * setSelectionRange(length, length) could not preserve even when it
               * worked.
               *
               * `term` is still written here, and still has to be: a WHOLESALE
               * mount -- opening the clip again, an upload landing -- does rebuild
               * this box, and it must come back with what was typed in it.
               */
              + '<input id="ugs-search" type="text" placeholder="Search by name" data-ugs-search="1"'
              +   ' value="' + esc(term) + '" autocomplete="off">'
              + '<div class="ugs-results" data-ugs-region="results">' + resultsHTML() + '</div>'
              + '</div>'
          })
        )
      + '</section>';
  }

  /** The three clauses of the publish gate, ticked. Its own function so the
      region can repaint it without rebuilding the panel around it. */
  function checkHTML(v) {
    var done = function (ok, text) {
      return '<div class="ugs-ci ' + (ok ? 'is-ok' : 'is-no') + '">'
        + '<span class="ugs-cid">' + (ok ? '&#10003;' : '!') + '</span>'
        + '<span>' + text + '</span></div>';
    };

    return '<div class="ugs-check">'
      + done(!!v.file_path, 'The video is uploaded')
      + done(!!v.poster_path, 'The cover is set')
      + done(v.rights_status === 'granted', 'The creator has granted permission')
      + '</div>';
  }

  /** The server's refusal, listed. Its own function so the region can repaint it. */
  function blockersHTML(blockers) {
    if (!blockers || !blockers.length) return '';
    return '<div class="ugs-note is-bad"><b>The last save was refused:</b><ul>'
      + blockers.map(function (b) { return '<li>' + esc(b) + '</li>'; }).join('')
      + '</ul></div>';
  }

  /**
   * The costs that no longer stop a publish, listed anyway.
   *
   * A DIFFERENT COLOUR AND A DIFFERENT SENTENCE from blockersHTML, because they
   * are different facts: red means the save did not happen, amber means it did
   * and something is worse for it. Drawing them the same would teach the owner
   * to read past both.
   *
   * These stay on screen while the clip is published -- that is the whole of
   * "warnings should remains there". They are not a save result, so they are
   * not cleared by a successful one.
   */
  function warningsHTML(warnings) {
    if (!warnings || !warnings.length) return '';
    return '<div class="ugs-note is-warm"><b>Published, with these still open:</b><ul>'
      + warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('')
      + '</ul></div>';
  }

  function publishPanel(v) {
    var blockers = v._blockers || v.blockers || [];
    /* How many of the three are still open, counted off the row's own columns —
       the same three the checklist draws, so the figure on the section head and
       the ticks under it can never disagree. */
    var left = (v.file_path ? 0 : 1) + (v.poster_path ? 0 : 1)
             + (v.rights_status === 'granted' ? 0 : 1);

    return '<section class="ugs-panel"' + (step === 5 ? '' : ' hidden') + '>'
      + panelHead(5, 'Publish', 'Everything below has to be true before a clip can appear. '
        + 'The list is the server\'s answer, not this screen\'s guess.')
      + secHTML({
          glyph: ICON_SHIELD,
          title: 'What has to be true',
          sub: 'Read off UgcVideo::publishBlockers() and publishWarnings(). Only the video '
            + 'is required; the other two are strongly advised and do not stop a publish.',
          note: left === 0 ? 'all three' : (3 - left) + ' of 3',
          tone: left === 0 ? 'live' : 'warm',
          region: 'check',
          body: checkHTML(v)
        })
      /* The blockers live in a display:contents wrapper so that the usual case --
         no blockers, nothing drawn -- adds no grid item and therefore no 12px
         gap to `.ugs-panel`. See the .ugs-rg note in the stylesheet. */
      + '<div class="ugs-rg" data-ugs-region="blockers">' + blockersHTML(blockers) + '</div>'
      + '<div class="ugs-rg" data-ugs-region="warnings">'
      +   warningsHTML(v.warnings || []) + '</div>'
      /* WHERE on the left, WHEN on the right. Four fields in one column was the
         longest stretch of dead right half on this screen. */
      + colsHTML(
          secHTML({
            glyph: ICON_SEND,
            title: 'Where it shows',
            sub: 'Whether it is live at all, and on which of the two shops.',
            note: v.status === 'publish' ? 'published' : 'draft',
            tone: v.status === 'publish' ? 'live' : '',
            body: '<div class="ugs-f"><label>Status</label>'
              + selectHTML('status', vocab ? vocab.status : [], v.status) + '</div>'
              + '<div class="ugs-f"><label>Show on</label>'
              + selectHTML('locale', vocab ? vocab.locale : [], v.locale, 'Both shops')
              + '<p class="ugs-help">A clip spoken in English is not automatically right for the '
              +   'Arabic shop.</p></div>'
          }),
          secHTML({
            glyph: ICON_CLOCK,
            title: 'When, and in what order',
            sub: 'Where it sits on the rail, and the earliest it may appear.',
            note: 'optional',
            body: '<div class="ugs-f"><label>Order</label>'
              + '<input type="number" min="0" max="9999" data-ugs-field="position" value="'
              +   esc(v.position) + '">'
              + '<p class="ugs-help">Lowest first. The good one, not the new one.</p></div>'
              + '<div class="ugs-f"><label>Publish from</label>'
              + '<input type="datetime-local" data-ugs-field="published_at" value="'
              +   esc(v.published_at) + '">'
              + '<p class="ugs-help">Empty means as soon as the status says published.</p></div>'
          })
        )
      + '<div class="ugs-note">A published clip still needs a <b>section</b> before a shopper sees '
      +   'it. Sections are the tab beside this one.</div>'
      + '</section>';
  }

  function taggedHTML() {
    if (!tagged.length) {
      return '<div class="ugs-empty">No products tagged yet.</div>';
    }

    /*
     * DRAGGABLE, AND THE ARROWS STAY.
     *
     * The order decides which product a tile shows before anybody taps, so it
     * is worth dragging -- the owner asked for "a nice drag n drop etc
     * everywhere where it needed". But a drag is a mouse-only gesture, so the
     * up and down buttons are NOT replaced by it: they are how this list is
     * reordered from a keyboard, and removing them would trade one input method
     * for another rather than adding one.
     *
     * The row is the draggable element and the grip is the handle a mouse
     * looks for. No coordinate is read anywhere in the reorder -- the drop
     * target is whichever row the pointer ENTERED, which the browser tells us.
     * Rule 4 holds.
     */
    return '<div class="ugs-tagged">' + tagged.map(function (t, i) {
      return '<div class="ugs-tag" draggable="true" data-ugs-drag="' + esc(i) + '">'
        + '<span class="ugs-grip" aria-hidden="true" title="Drag to reorder">'
        +   icon(ICON_GRIP) + '</span>'
        + '<div class="ugs-tagname">' + esc(i + 1) + '. ' + esc(t.name)
        +   (t.brand ? ' <span class="ugs-dim">· ' + esc(t.brand) + '</span>' : '')
        +   (i === 0 ? ' <span class="ugs-pill is-info">shown on the tile</span>' : '')
        + '</div>'
        + '<div class="ugs-tagacts">'
        +   '<button class="ugs-mini" data-ugs-up="' + esc(i) + '"' + (i === 0 ? ' disabled' : '')
        +     ' aria-label="Move up">&uarr;</button>'
        +   '<button class="ugs-mini" data-ugs-down="' + esc(i) + '"'
        +     (i === tagged.length - 1 ? ' disabled' : '') + ' aria-label="Move down">&darr;</button>'
        +   '<button class="ugs-mini" data-ugs-untag="' + esc(i) + '">Remove</button>'
        + '</div>'
        + '</div>';
    }).join('') + '</div>';
  }

  /** One pressable product row. The name and brand are the only things on it. */
  function resRowHTML(p) {
    return '<button class="ugs-res" data-ugs-add="' + esc(p.id) + '" data-ugs-addname="' + esc(p.name)
      + '" data-ugs-addbrand="' + esc(p.brand || '') + '">' + esc(p.name)
      + (p.brand ? ' <span class="ugs-dim">· ' + esc(p.brand) + '</span>' : '') + '</button>';
  }

  /*
   * ══ THE SEARCH ALWAYS SAYS SOMETHING ═══════════════════════════════════════
   *
   * THE DEFECT. This function used to begin `if (!results.length) return '';`,
   * which drew an empty box for three completely different situations: nothing
   * typed yet, a request still in flight, and a term that matched nothing. The
   * owner typed "anua", was shown a blank space for the better part of a second
   * and then a blank space forever, and reported the product search as broken.
   * It was not broken; it was silent, and from outside those are the same thing.
   *
   * There are now four answers and each one names itself.
   *
   * MUTATION NOTE. Put `if (!results.length) return '';` back at the top and
   * UgcProductSearchStatesTest goes red on every one of them.
   */
  function resultsHTML() {
    /* 1. Still looking — including the debounce, which is most of the wait. */
    if (searching) {
      return '<div class="ugs-rnote">Searching&hellip;</div>';
    }

    /* 2. Something matched. */
    if (results.length) {
      return '<div class="ugs-rhead">' + esc(results.length)
        + (results.length === 1 ? ' match' : ' matches') + '</div>'
        + results.map(resRowHTML).join('');
    }

    /* 3. A term was answered and nothing published matched it.
          WHETHER UNPUBLISHED ROWS WOULD HAVE MATCHED IS THE WHOLE POINT: the
          section's subtitle already says "Published products only", but it sits
          above the box rather than where the answer is, and it cannot know that
          THIS term hit a draft. */
    if (searched) {
      return '<div class="ugs-rnote">'
        + '<b>Nothing published matches &ldquo;' + esc(term) + '&rdquo;.</b>'
        + (unpublishedHits
            ? '<br>' + esc(unpublishedHits)
              + (unpublishedHits === 1 ? ' product matches that name but is a draft or hidden'
                                       : ' products match that name but are drafts or hidden')
              + ', so it cannot be tagged on a clip yet. Publish it in '
              + '<b>Catalogue &rarr; Products</b> and it will appear here.'
            : '<br>Only published products can be tagged. Check the spelling, or publish the '
              + 'product first in <b>Catalogue &rarr; Products</b>.')
        + '</div>';
    }

    /* 4. Nothing typed yet — so offer the newest products rather than a void.
          Headed, so it can never read as the answer to a search nobody ran. */
    if (recent && recent.length) {
      return '<div class="ugs-rhead">The ' + esc(recent.length) + ' newest products'
        + '<span class="ugs-dim">&mdash; or search above</span></div>'
        + recent.map(resRowHTML).join('');
    }
    if (recent) {
      return '<div class="ugs-rnote">No published products yet. '
        + 'Add one in <b>Catalogue &rarr; Products</b> and it can be tagged here.</div>';
    }
    return '<div class="ugs-rnote">Loading the newest products&hellip;</div>';
  }

  /** The one line under the step rail that says where this clip stands. */
  function stateLine(v) {
    if (!v.id) return 'Not saved yet.';

    var left = (v.blockers || []).length;
    if (v.status === 'publish' && !left) return 'Published.';
    if (!left) return 'Draft, and ready to publish on step 5.';
    return left + (left === 1 ? ' thing' : ' things') + ' still needed before this can be published.';
  }

  /** The hero's contents, repainted on its own when the row's state moves. */
  function heroHTML(v) {
    return '<div class="ugs-herotext">'
      + '<div class="ugs-h">' + (v.id ? esc(v.title || '(untitled)') : 'A new clip') + '</div>'
      + '<div class="ugs-pills" style="margin-top:6px">'
      +   pill(v.status === 'publish' ? 'published' : 'draft', v.status === 'publish' ? 'live' : '')
      +   (v.id ? pill(stateWords(v)[0], stateWords(v)[1]) : '')
      +   (v.id && v.rights_status !== 'granted' ? pill('no permission yet', 'hold') : '')
      + '</div>'
      + '</div>';
  }

  /*
   * WHAT THE FOOTER SAYS ABOUT A SAVE NOBODY IS WATCHING.
   *
   * A forward step now paints first and saves afterwards, so the save's ending
   * has to land somewhere the owner will still be looking. This is that place,
   * and the failed state is the reason the whole region exists: CLAUDE.md's
   * swallowed-write landmine says a guarded write that leaves no trace does not
   * contain a failure, it seeds one. A toast would be exactly that -- it appears
   * while he is reading step 4 and is gone before he looks up.
   *
   * So: a failure is a SENTENCE THAT STAYS, with the reason the server gave and
   * a Try again button, and it stays until a save succeeds.
   */
  function saveStateHTML() {
    if (saveFail) {
      return '<div class="ugs-savest is-bad" data-ugs-savest="1" role="alert">'
        + '<b>Not saved.</b> ' + esc(saveFail.message)
        + ' Your typing is still here and a draft of it is stored.'
        + '</div>';
    }
    if (saving) {
      return '<div class="ugs-savest" data-ugs-savest="1">Saving&hellip;</div>';
    }
    if (savedOnce) {
      return '<div class="ugs-savest" data-ugs-savest="1">All changes saved.</div>';
    }
    return '<div class="ugs-savest" data-ugs-savest="1"></div>';
  }

  /** The footer's contents: where the clip stands, what a save is doing, and the buttons. */
  function footHTML(v) {
    return '<div class="ugs-state">' + esc(stateLine(v)) + '</div>'
      + saveStateHTML()
      + '<div class="ugs-footb">'
      +   (saveFail ? '<button class="ugs-btn is-primary" data-ugs-retrysave="1">Try again</button>' : '')
      +   (v.id ? '<button class="ugs-btn is-danger" data-ugs-del="' + esc(v.id)
            + '" data-ugs-delname="' + esc(v.title || '(untitled)') + '">Delete</button>' : '')
      +   '<button class="ugs-btn" data-ugs-back="1">All clips</button>'
      +   (step > 1 ? '<button class="ugs-btn" data-ugs-step="' + (step - 1) + '">Back</button>' : '')
      +   (step < STEPS.length
            ? '<button class="ugs-btn is-primary" data-ugs-next="1"' + (busy ? ' disabled' : '')
              + '>Save and continue</button>'
            : '<button class="ugs-btn is-primary" data-ugs-save="1"' + (busy ? ' disabled' : '')
              + '>Save</button>')
      + '</div>';
  }

  /*
   * THE DRAFT BAR, and every word of it is chosen so the owner can tell a draft
   * from the database at a glance. It appears only when a draft exists AND
   * differs from the row, it never applies itself, and it says WHEN the typing
   * happened rather than merely that it did.
   */
  function draftBarHTML() {
    if (!draftFound) return '';

    if (draftFound.restored) {
      return '<div class="ugs-draft" data-ugs-draft="1">'
        + '<div class="ugs-drafttext"><b>Your unsaved changes are back on screen.</b> '
        + 'Nothing has been saved yet &mdash; press Save, or Save and continue, to keep them.</div>'
        + '</div>';
    }

    return '<div class="ugs-draft" data-ugs-draft="1">'
      + '<div class="ugs-drafttext">'
      +   '<b>You have unsaved changes to this clip</b> from ' + esc(ago(draftFound.at)) + '. '
      +   'What is on screen now is what the server has. '
      +   (draftFound.stale
          ? 'This clip has also been saved somewhere else since you typed them, so restoring '
            + 'will put your version back over that one.'
          : '')
      + '</div>'
      + '<div class="ugs-draftacts">'
      +   '<button class="ugs-btn is-primary" data-ugs-draftrestore="1">Restore them</button>'
      +   '<button class="ugs-btn" data-ugs-draftdiscard="1">Discard</button>'
      + '</div>'
      + '</div>';
  }

  /** "4 minutes ago". Plain words, and no clock beyond Date.now(). */
  function ago(at) {
    var s = Math.max(0, Math.round((Date.now() - Number(at || 0)) / 1000));
    if (s < 60) return 'a moment ago';
    var m = Math.round(s / 60);
    if (m < 60) return m === 1 ? 'a minute ago' : m + ' minutes ago';
    var h = Math.round(m / 60);
    if (h < 24) return h === 1 ? 'an hour ago' : h + ' hours ago';
    var d = Math.round(h / 24);
    return d === 1 ? 'yesterday' : d + ' days ago';
  }

  function editorHTML() {
    var v = editing;

    return '<div class="ugs-card">'
      + '<div class="ugs-hero" style="margin-bottom:12px" data-ugs-region="hero">'
      +   heroHTML(v)
      + '</div>'
      + '<div class="ugs-rg" data-ugs-region="draft">' + draftBarHTML() + '</div>'
      +   stepsHTML()
      +   '<div id="ugs-form" style="margin-top:14px">'
      +     detailsPanel(v)
      +     mediaPanel(v)
      +     creditPanel(v)
      +     productsPanel()
      +     publishPanel(v)
      +   '</div>'
      +   '<div class="ugs-foot" data-ugs-region="foot">' + footHTML(v) + '</div>'
      + '</div>';
  }

  function listHTML() {
    var count = videos ? videos.length : 0;

    var html = '<div class="ugs-card">'
      + '<div class="ugs-hero">'
      +   '<div class="ugs-herotext">'
      +     '<div class="ugs-h">All clips</div>'
      +     '<p class="ugs-sub">Creator videos with products tagged on them. A clip reaches the shop '
      +       'through a section &mdash; the tab beside this one.</p>'
      +     '<div class="ugs-chips">'
      +       '<span class="ugs-chip">' + esc(count) + (count === 1 ? ' clip' : ' clips') + '</span>'
      +       transcoderChip()
      +     '</div>'
      +   '</div>'
      +   '<button class="ugs-btn is-primary" data-ugs-new="1">' + icon(ICON_PLUS) + 'Add a clip</button>'
      + '</div>'
      + '</div>';

    if (banner) {
      html += '<div class="ugs-card"><div class="ugs-note is-bad">' + esc(banner) + '</div></div>';
    }

    /* Under the hero and above the library: the first thing after the chip that
       raised the question. Empty on a server that can cut, which is most of
       them. */
    html += cutRemedyHTML();

    /*
     * A WARM note, not a red one, and below the library rather than instead of
     * it: every clip is on the screen and something optional beside it did not
     * answer. The server's own sentence is printed because the whole reason
     * this exists is that "Server Error" was not something anybody could act
     * on; it is escaped like every other server string.
     */
    if (probeErrors.length) {
      html += '<div class="ugs-card"><div class="ugs-note is-warm">'
        + '<b>Your clips are all here.</b> These parts of the screen could not be worked out '
        + 'on this server, so they are showing their safe defaults:'
        // No inline style: `.ugs-note ul` already carries it, and it uses
        // padding-inline-start, which a hardcoded padding-left would break on
        // the Arabic side of this console.
        + '<ul>'
        + probeErrors.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('')
        + '</ul></div></div>';
    }

    if (videos === null) {
      html += '<div class="ugs-grid">'
        + '<div class="ugs-sk"></div><div class="ugs-sk"></div><div class="ugs-sk"></div>'
        + '</div>';
    } else if (!videos.length) {
      html += '<div class="ugs-card"><div class="ugs-empty">'
        + '<b>No clips yet.</b><span>Five short steps: name it, drop the video in, say who made it '
        + 'and that they agreed, tag the products, publish.</span>'
        + '<button class="ugs-btn is-primary" data-ugs-new="1">' + icon(ICON_PLUS)
        + 'Add your first clip</button>'
        + '</div></div>';
    } else {
      html += '<div class="ugs-grid">' + videos.map(tileHTML).join('') + '</div>';
    }

    return html;
  }

  /* ─────────────────────────────────────────────── the loop preview mount */

  /* Give the decoder back rather than merely dropping the element: the storefront
     learned this one the expensive way, and a screen that repaints on every
     keystroke would otherwise accumulate them. */
  function releaseLoop() {
    if (!loopEl) return;
    /* The wrap timer first: it holds a reference to the element and would fire
       a seek at something that has just had its src taken away. */
    try { if (loopEl.ugsClearWrap) loopEl.ugsClearWrap(); } catch (e) {}
    try { loopEl.pause(); loopEl.removeAttribute('src'); loopEl.load(); } catch (e) {}
    loopEl = null;
  }

  /*
   * THE SAME MECHANISM THE SHOP USES, and it is deliberately the same shape as
   * playTeaser() in resources/views/ugc: mount `teaser || full`, and where there
   * is no teaser turn the native loop OFF and rewind on the first timeupdate
   * past the rail's own teaser-ms. That is why a clip with one file loops, and
   * why this preview is evidence rather than decoration.
   */
  function wireLoop(host) {
    var box = host.querySelector('[data-ugs-loopsrc]');
    if (!box) return;

    var src = safeMedia(box.getAttribute('data-ugs-loopsrc'));
    if (!src) return;

    var ms = parseInt(box.getAttribute('data-ugs-loopms'), 10);
    if (!(ms >= 500)) ms = 1000;
    var full = box.getAttribute('data-ugs-loopfull') === '1';

    var v = document.createElement('video');
    /* MUTED AND INLINE OR NOTHING PLAYS ANYWHERE. The property AND the
       attribute: the property is what the element honours, the attribute is
       what older WebKit reads. */
    v.muted = true;
    v.playsInline = true;
    v.setAttribute('playsinline', '');
    v.setAttribute('webkit-playsinline', '');
    v.setAttribute('muted', '');
    v.preload = 'metadata';
    v.disableRemotePlayback = true;
    v.setAttribute('aria-hidden', 'true');
    v.loop = !full;
    v.src = src;

    box.appendChild(v);
    loopEl = v;

    if (full) {
      /*
       * ── THE SHOP'S MECHANISM, NOT A SKETCH OF IT ────────────────────────
       *
       * This preview is offered to the owner as EVIDENCE of what his rail does,
       * so a preview that loops differently from the rail is worse than none.
       * It had the older shape — a bare `timeupdate` assigning zero — which is
       * the one resources/views/ugc/assets.blade.php stopped using, and for two
       * reasons that both show on screen:
       *
       *   STACKED SEEKS. `timeupdate` keeps firing WHILE a seek is in flight,
       *     so the handler assigned zero two and three times per wrap and the
       *     seeks queued. That is the hang the owner reported on the shop, and
       *     this screen was still demonstrating it.
       *   A LATE WRAP. `timeupdate` fires about every 250ms, so the wrap lands
       *     0-250ms past the mark. At the old 2500 that was 10%; at 1000 it is
       *     25%, and the preview would have shown a limp the shop no longer
       *     has.
       *
       * So: one seek in flight at a time, the wrap ARMED for the time actually
       * left, and `timeupdate` kept as the backstop for a throttled timer.
       * Transcribed from playTeaser(), which is the original.
       */
      var rewinding = false;
      var timer = null;

      var rewind = function () {
        if (rewinding || v.seeking) return;

        rewinding = true;

        if (typeof v.fastSeek === 'function') {
          try { v.fastSeek(0); return; } catch (e) { /* fall through */ }
        }

        v.currentTime = 0;
      };

      var armWrap = function () {
        if (timer) clearTimeout(timer);

        var left = ms - v.currentTime * 1000;
        if (!(left > 16)) left = 16;

        timer = setTimeout(rewind, left);
      };

      /* The screen repaints on every keystroke and releaseLoop() is what keeps
         decoders from piling up; a timer armed for a released element would
         seek something nothing can see, so it goes the same way. */
      v.ugsClearWrap = function () { if (timer) { clearTimeout(timer); timer = null; } };

      v.addEventListener('seeked', function () { rewinding = false; armWrap(); });
      v.addEventListener('playing', armWrap);

      v.addEventListener('timeupdate', function () {
        if (v.currentTime * 1000 >= ms) rewind();
      });

      v.addEventListener('ended', function () {
        rewinding = false;
        v.currentTime = 0;
        var again = v.play();
        if (again && again.catch) again.catch(function () {});
      });
    }

    var p = v.play();
    if (p && p.catch) p.catch(function () {});
  }

  /*
   * WHAT THE EDITOR ON SCREEN WAS BUILT FOR.
   *
   * render() compares this against `mounted`: equal, and the repaint is
   * surgical; different, and the editor is rebuilt from scratch. So every term
   * in it is a thing whose change MUST rebuild the panels, and nothing else may
   * be added:
   *
   *   the clip        a different row is a different editor, obviously
   *   the three paths the <video>, its poster and the teaser are built from
   *                   these. An upload landing, a cover adopted from the Media
   *                   Library or the cut changes one, and the player must be
   *                   rebuilt -- keying on the id alone would leave a stale
   *                   player on screen after an upload, which is the opposite
   *                   bug and the worse one
   *   the loop length readMotion() arrives late and un-awaited, and it writes
   *                   data-ugs-loopms and the prose beside the preview
   *   the upload      the progress panel and the ending panel are inside the
   *                   media panel. The PERCENTAGE is not in here on purpose:
   *                   paintProgress() writes the bar straight into the DOM, so a
   *                   progress event must not remount anything
   *
   * The step is NOT in here, which is the entire point.
   */
  function mountKey(v) {
    if (!v) return null;
    return (v.id ? 'id:' + v.id : 'new')
      + '|f:' + (v.file_path || '')
      + '|p:' + (v.poster_path || '')
      + '|t:' + (v.teaser_path || '')
      + '|loop:' + loop().ms + ':' + (loop().on ? '1' : '0')
      + '|up:' + (upState ? 'live:' + upState.kind + ':' + upState.name
                : (upDone ? 'done:' + (upDone.ok ? '1' : '0') + ':' + upDone.name : ''))
      + '|fresh:' + (freshClip === null ? '' : String(freshClip))
      /* The cut reason is drawn inside the media panel, so a note arriving has
         to rebuild it. It changes about once per upload, never per keystroke. */
      + '|note:' + (cutNote === null ? '' : cutNote);
  }

  /** Repaint one named region, if it is on screen. Never rebuilds anything else. */
  function paintRegion(name) {
    var host = document.querySelector('#content');
    if (!host || !editing) return;

    var el = host.querySelector('[data-ugs-region="' + name + '"]');
    if (!el) return;

    if (name === 'hero') { el.innerHTML = heroHTML(editing); return; }
    if (name === 'draft') { el.innerHTML = draftBarHTML(); return; }
    if (name === 'steps') { el.innerHTML = stepButtonsHTML(); return; }
    if (name === 'foot') { el.innerHTML = footHTML(editing); return; }
    if (name === 'results') { el.innerHTML = resultsHTML(); return; }
    if (name === 'tagged') {
      /* The head carries a count and a tone that both move with the list, so the
         whole section's contents are rewritten -- there is no input inside it. */
      el.className = 'ugs-sec' + (tagged.length ? ' is-live' : '');
      el.innerHTML = secInnerHTML({
        glyph: ICON_TAG,
        title: 'Tagged on this clip',
        sub: 'Drag to reorder. The first one is what a tile shows before anybody taps it.',
        note: tagged.length ? String(tagged.length) : 'none yet',
        body: taggedHTML()
      });
      return;
    }
    if (name === 'blockers') {
      el.innerHTML = blockersHTML((editing._blockers || editing.blockers || []));
    }

    el = document.querySelector('[data-ugs-region="warnings"]');
    if (el) {
      el.innerHTML = warningsHTML((editing && editing.warnings) || []);
      return;
    }
    if (name === 'check') {
      var v = editing;
      var left = (v.file_path ? 0 : 1) + (v.poster_path ? 0 : 1)
               + (v.rights_status === 'granted' ? 0 : 1);
      el.className = 'ugs-sec ' + (left === 0 ? 'is-live' : 'is-warm');
      el.innerHTML = secInnerHTML({
        glyph: ICON_SHIELD,
        title: 'What has to be true',
        sub: 'Read off UgcVideo::publishBlockers(), which is what the save really checks.',
        note: left === 0 ? 'all three' : (3 - left) + ' of 3',
        body: checkHTML(v)
      });
    }
  }

  /**
   * Everything that can change without the media changing, repainted.
   *
   * The list is deliberately short and deliberately excludes every panel that
   * holds an input the owner might be typing into -- steps 1, 3 and the two
   * columns of step 5. Those are drawn once per mount and then left alone, which
   * is what makes typing survive a repaint without any storage being involved.
   */
  function paintRegions() {
    ['hero', 'draft', 'steps', 'tagged', 'results', 'blockers', 'check', 'foot']
      .forEach(paintRegion);
  }

  /*
   * A STEP CHANGE, AND NOTHING ELSE.
   *
   * Show one of five sections that are all already in the document, mark the
   * rail, and redraw the footer's two buttons. No HTML is built for any panel,
   * no request is made, and #ugs-form is not touched -- so the <video> inside it
   * is the same element it was a moment ago, still holding its buffer and still
   * at the same currentTime.
   */
  function paintStep() {
    var host = document.querySelector('#content');
    if (!host || !editing) return;

    var panels = host.querySelectorAll('#ugs-form > .ugs-panel');
    /* Not mounted the way this expects -- fall back to a full paint rather than
       leave the owner on a screen with no visible panel. */
    if (panels.length !== STEPS.length) { render(); return; }

    for (var i = 0; i < panels.length; i += 1) {
      if (i + 1 === step) panels[i].removeAttribute('hidden');
      else panels[i].setAttribute('hidden', '');
    }

    paintRegion('steps');
    paintRegion('foot');

    /*
     * THE LOOP PREVIEW IS MOUNTED ONCE AND THEN PAUSED, NEVER TORN DOWN.
     *
     * It used to be mounted by render() whenever the repaint happened to land on
     * step 2, which was fine only because every step change WAS a repaint. Now
     * that a step change is not, it has to be mounted the first time step 2 is
     * shown -- and, much more importantly, it must NOT be released on the way
     * out. Releasing it is what made coming back to step 2 re-fetch the clip and
     * restart the loop from zero, which on a clip with no teaser is the whole
     * 8.5 MB file.
     *
     * So: pause on the way out, play on the way back. Neither touches the src,
     * so neither costs a byte, and currentTime is where he left it. The element
     * is given up only by releaseLoop(), on a wholesale mount -- which is
     * exactly when the media really has changed.
     */
    /* Step 4 wants something to press before anybody types. Asked for once, and
       never awaited — the step is already on screen. */
    if (step === 4) loadRecent();

    if (step === 2) {
      if (!loopEl) wireLoop(host);
      else if (loopEl.paused) { var again = loopEl.play(); if (again && again.catch) again.catch(function () {}); }
    } else if (loopEl && !loopEl.paused) {
      try { loopEl.pause(); } catch (e) {}
    }

    /* The step rail is a scroll-snapped strip on a phone, so the step that is now
       current can be off to the right. This is a scroll COMMAND, not a
       measurement: it reads no rect, no offset and no scroll position, and rule 4
       is about laying a page out in script. */
    var current = host.querySelector('.ugs-step.is-on');
    if (current && current.scrollIntoView) {
      try { current.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) {}
    }
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host) return;
    /* Only paint when this screen is the one on show. The console swaps
       #content wholesale, so a late render from an in-flight request must not
       overwrite whichever screen the owner moved to. */
    var title = document.querySelector('#ptitle');
    if (!title || title.textContent !== 'All clips') return;

    /*
     * THE SURGICAL PATH, and the reason the <video> stops being thrown away.
     *
     * Lane V5 fixed the same class of bug on the Sections tab by lifting the
     * clip dialog out of the wholesale string into a host of its own, so screen
     * repaints and dialog repaints stopped being the same event. The video here
     * cannot be lifted anywhere -- it sits inside the media panel's layout, not
     * on top of it -- so the other half of V5's fix is the one that applies:
     * renderModal()'s `openModalId === editingVideo.id` check, which updates a
     * dialog that is already open instead of rebuilding it. `mounted` is that
     * check, and the regions are renderModalBody().
     */
    if (editing && mounted !== null && mounted === mountKey(editing)
        && host.querySelector('#ugs-form')) {
      paintRegions();
      return;
    }

    releaseLoop();

    host.innerHTML = '<div class="wrap ugs-wrap">'
      + (window.kbbUgcTabs ? window.kbbUgcTabs(SCREEN) : '')
      + (editing ? editorHTML() : listHTML())
      + '</div>';

    mounted = editing ? mountKey(editing) : null;

    if (editing && window.KBBArabic) window.KBBArabic.wire(host);
    if (editing && step === 2) wireLoop(host);
    if (editing && step === 4) loadRecent();

    /* See the note in paintStep(): a scroll command, not a measurement. */
    if (editing) {
      var current = host.querySelector('.ugs-step.is-on');
      if (current && current.scrollIntoView) {
        try { current.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) {}
      }
    }
  }

  /* ------------------------------------------------------------- listeners */

  var searchTimer = null;

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-ugs-open],[data-ugs-del],[data-ugs-new],'
      + '[data-ugs-save],[data-ugs-back],[data-ugs-derive],[data-ugs-untag],[data-ugs-up],'
      + '[data-ugs-down],[data-ugs-add],[data-ugs-poster],[data-ugs-step],[data-ugs-next],'
      + '[data-ugs-upcancel],[data-ugs-upretry],[data-ugs-draftrestore],[data-ugs-draftdiscard],'
      + '[data-ugs-retrysave],[data-ugs-cuthere],[data-ugs-recut]') : null;
    if (!t) return;

    if (t.hasAttribute('data-ugs-new')) { e.preventDefault(); blank(); return; }
    if (t.hasAttribute('data-ugs-open')) {
      e.preventDefault();
      /* A different clip, so the last clip's upload panel goes with it. */
      forgetUpload();
      step = 1;
      open(t.getAttribute('data-ugs-open'));
      return;
    }
    if (t.hasAttribute('data-ugs-save')) { e.preventDefault(); save(); return; }
    if (t.hasAttribute('data-ugs-draftrestore')) { e.preventDefault(); restoreDraft(); return; }
    if (t.hasAttribute('data-ugs-draftdiscard')) { e.preventDefault(); discardDraft(); return; }
    if (t.hasAttribute('data-ugs-retrysave')) {
      e.preventDefault();
      /* The same one writer, in the foreground this time: the owner pressed it,
         so he is watching, and locking the screen is the honest thing to do. */
      saveFail = null;
      paintRegion('foot');
      save();
      return;
    }
    if (t.hasAttribute('data-ugs-next')) { e.preventDefault(); goStep(step + 1); return; }
    if (t.hasAttribute('data-ugs-step')) { e.preventDefault(); goStep(Number(t.getAttribute('data-ugs-step'))); return; }
    if (t.hasAttribute('data-ugs-back')) {
      e.preventDefault();
      /* Anything typed and not saved is written down on the way out — this is
         the one moment the DOM that was holding it is about to be thrown away.
         It is NOT saved to the server: leaving a screen is not consent to
         publish, and the bar on the way back in is how it is offered. */
      writeDraft();
      forgetUpload();
      editing = null;
      draftFound = null;
      step = 1;
      render();
      return;
    }
    if (t.hasAttribute('data-ugs-upcancel')) {
      e.preventDefault();
      /* The kit's handle. cancel() aborts the request, which fires its onabort
         and lands in onFail with cancelled set — one ending writer, so a cancel
         cannot forget the clock the way a second copy would. */
      if (upXhr) { try { upXhr.cancel(); } catch (err) {} }
      return;
    }
    if (t.hasAttribute('data-ugs-upretry')) {
      e.preventDefault();
      if (upDone && upDone.retry) sendFile(upDone.kind, upDone.retry);
      return;
    }
    if (t.hasAttribute('data-ugs-derive')) { e.preventDefault(); derive(); return; }
    if (t.hasAttribute('data-ugs-cuthere')) { e.preventDefault(); cutCoverHere(); return; }
    /* The always-available control in the cover box. Same function, same
       progress bar; `false` is the loud mode, so a press the owner made says
       what it did rather than finishing in silence like the automatic one. */
    if (t.hasAttribute('data-ugs-recut')) { e.preventDefault(); cutCoverHere(false); return; }
    if (t.hasAttribute('data-ugs-poster')) { e.preventDefault(); choosePoster(); return; }

    if (t.hasAttribute('data-ugs-del')) {
      e.preventDefault();
      remove(t.getAttribute('data-ugs-del'), t.getAttribute('data-ugs-delname') || '');
      return;
    }

    if (t.hasAttribute('data-ugs-untag')) {
      e.preventDefault();
      tagged.splice(Number(t.getAttribute('data-ugs-untag')), 1);
      afterTagChange();
      return;
    }

    if (t.hasAttribute('data-ugs-up') || t.hasAttribute('data-ugs-down')) {
      e.preventDefault();
      var from = Number(t.getAttribute('data-ugs-up') || t.getAttribute('data-ugs-down'));
      var to = t.hasAttribute('data-ugs-up') ? from - 1 : from + 1;
      if (to < 0 || to >= tagged.length) return;
      var moved = tagged.splice(from, 1)[0];
      tagged.splice(to, 0, moved);
      afterTagChange();
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
      afterTagChange();
      return;
    }
  });

  /*
   * THE LIST CHANGED, SO THE LIST IS REPAINTED — AND NOTHING ELSE IS.
   *
   * THE DEFECT THIS FIXES, measured before it was touched: pressing a search
   * result did tag the product (the row really did appear in the list, so
   * "selection is not working" was not reproducible as stated) — but render()
   * replaced #content wholesale, so the search box became a NEW element and
   * document.activeElement stopped being it. Measured: focused=true before the
   * press, focused=false after, with the caret gone. Anybody adding a second
   * product then types into nothing and watches the screen ignore them, which
   * is a fair description of "selection is not working".
   *
   * Three regions move when the tagged list moves and the search box is in none
   * of them, so the caret now stays exactly where it was.
   */
  function afterTagChange() {
    paintRegion('tagged');
    paintRegion('steps');    // step 4's tick follows the count
    paintRegion('foot');     // ...and so does the state line
    writeDraft();
  }

  document.addEventListener('input', function (e) {
    if (!e.target || !e.target.hasAttribute) return;

    if (e.target.hasAttribute('data-ugs-search')) {
      term = e.target.value;
      if (searchTimer) clearTimeout(searchTimer);

      /*
       * THE CARET DANCE IS GONE, and its absence is the fix rather than a
       * tidying. It used to read:
       *
       *     search().then(function () {
       *       var box = document.querySelector('[data-ugs-search]');
       *       if (box) { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }
       *     });
       *
       * — a workaround for render() replacing #content, and therefore the very
       * box being typed into, on every answer. It could only ever put the caret
       * at the END, so editing the middle of a term was impossible, and it
       * fought anybody who kept typing while an answer was in flight. The
       * results are their own region now and the input is not in it, so the box
       * is never replaced and there is nothing to restore.
       *
       * The empty term is answered WITHOUT a request: clearing the box goes back
       * to the newest products, which are already in hand.
       */
      if (term === '') {
        searchSeq += 1;                 // abandon any answer still on its way
        searching = false;
        searched = false;
        results = [];
        unpublishedHits = 0;
        paintRegion('results');
        return;
      }

      /* Said straight away, so the debounce is never a silent second. */
      searching = true;
      paintRegion('results');
      searchTimer = setTimeout(search, 250);
      return;
    }

    /* ── every other box on the form feeds the draft ──────────────────────
       Debounced so a long caption is not serialised on every keystroke, and
       never a request: this writes to localStorage and nothing else. */
    if (!editing) return;
    if (!e.target.closest || !e.target.closest('#ugs-form')) return;
    if (draftTimer) clearTimeout(draftTimer);
    draftTimer = setTimeout(writeDraft, 400);
  });

  /* A <select> and a date box answer `change` rather than `input` in some
     browsers, and a draft that missed the status field would be a draft that
     silently dropped it on restore. */
  document.addEventListener('change', function (e) {
    if (!editing || !e.target || !e.target.closest) return;
    if (!e.target.closest('#ugs-form')) return;
    if (e.target.hasAttribute && e.target.hasAttribute('data-ugs-upload')) return;
    if (draftTimer) clearTimeout(draftTimer);
    draftTimer = setTimeout(writeDraft, 400);
  });

  document.addEventListener('change', function (e) {
    if (!e.target || !e.target.hasAttribute || !e.target.hasAttribute('data-ugs-upload')) return;
    var input = e.target;
    var file = input.files && input.files[0];
    /* Cleared before the send, so choosing the SAME file again still fires a
       change event — the browser does not fire one for an unchanged value, and
       re-trying a failed upload is the commonest thing to do next. */
    input.value = '';
    sendFile(input.getAttribute('data-ugs-upload'), file);
  });

  /* ── drag and drop ─────────────────────────────────────────────────────
     Delegated on the document like every other listener in this file, and
     inert outside a zone this screen drew: preventDefault is called only once
     a zone has been found, so a file dropped anywhere else behaves exactly as
     it did before this screen existed. */
  function zoneOf(e) {
    return e.target && e.target.closest ? e.target.closest('[data-ugs-drop]') : null;
  }

  /* ── reordering the tagged products by drag ────────────────────────────
     A SECOND KIND OF DRAG ON THE SAME DOCUMENT, and the two must never meet:
     the zones above carry files from the desktop, these rows carry an index
     from inside the page. Every handler below refuses a drag that carries
     FILES, and the file handlers refuse anything that is not a [data-ugs-drop]
     zone, so a video dropped on the product list does nothing and a row
     dragged onto the video zone does nothing. */
  var dragFrom = null;

  function rowOf(e) {
    return e.target && e.target.closest ? e.target.closest('[data-ugs-drag]') : null;
  }

  /** True when the pointer is carrying files rather than one of our rows. */
  function carriesFiles(e) {
    var types = e.dataTransfer && e.dataTransfer.types;
    if (!types) return false;
    return Array.prototype.indexOf.call(types, 'Files') !== -1;
  }

  document.addEventListener('dragstart', function (e) {
    var row = rowOf(e);
    if (!row) return;

    dragFrom = Number(row.getAttribute('data-ugs-drag'));
    row.classList.add('is-lifted');

    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      /* Firefox starts no drag at all unless something is set. The payload is
         never read back -- dragFrom is the source of truth, because a page can
         only be dragging one of its own rows at a time. */
      try { e.dataTransfer.setData('text/plain', String(dragFrom)); } catch (err) {}
    }
  });

  document.addEventListener('dragend', function () {
    dragFrom = null;
    document.querySelectorAll('.ugs-tag.is-lifted,.ugs-tag.is-landing').forEach(function (r) {
      r.classList.remove('is-lifted');
      r.classList.remove('is-landing');
    });
  });

  document.addEventListener('dragover', function (e) {
    if (dragFrom === null || carriesFiles(e)) return;
    var row = rowOf(e);
    if (!row) return;
    /* preventDefault is what makes a row a valid drop target at all. */
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

    document.querySelectorAll('.ugs-tag.is-landing').forEach(function (r) {
      r.classList.remove('is-landing');
    });
    if (!row.classList.contains('is-lifted')) row.classList.add('is-landing');
  });

  document.addEventListener('drop', function (e) {
    if (dragFrom === null || carriesFiles(e)) return;
    var row = rowOf(e);
    if (!row) return;

    e.preventDefault();

    var to = Number(row.getAttribute('data-ugs-drag'));
    var from = dragFrom;
    dragFrom = null;

    if (!(from >= 0 && to >= 0) || from === to || from >= tagged.length || to >= tagged.length) {
      paintRegion('tagged');
      return;
    }

    var moved = tagged.splice(from, 1)[0];
    tagged.splice(to, 0, moved);
    /* Repaint the list, which also drops both drag classes: the region replaces
       every row, so there is nothing left holding them. */
    afterTagChange();
  });

  document.addEventListener('dragover', function (e) {
    /* A PRODUCT ROW BEING DRAGGED IS NOT A FILE. Without this the upload zones
       lit up green while somebody reordered the product list, promising a drop
       that would then do nothing. The two drags share one document and each
       refuses the other's payload. */
    if (dragFrom !== null) return;
    var zone = zoneOf(e);
    if (!zone) return;
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
    if (!zone.classList.contains('is-locked')) zone.classList.add('is-over');
  });

  document.addEventListener('dragleave', function (e) {
    var zone = zoneOf(e);
    if (zone) zone.classList.remove('is-over');
  });

  document.addEventListener('drop', function (e) {
    if (dragFrom !== null) return;          // ...and the same on the drop.
    var zone = zoneOf(e);
    if (!zone) return;
    e.preventDefault();
    zone.classList.remove('is-over');

    if (zone.classList.contains('is-locked')) {
      say('Save step 1 first — the file needs a clip to belong to.');
      return;
    }

    var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (!file) return;

    var kind = zone.getAttribute('data-ugs-drop');
    if (kind === 'poster') dropPoster(file);
    else sendFile(kind, file);
  });

  /*
   * NO SIDEBAR ROW ANY MORE — see ugcTabsHTML() in ugc-sections-screen. This is
   * the "All clips" tab of Content → Shoppable video. The id stays routable, so
   * #ugcvideo and ?go=ugcvideo still open it, exactly as `blog` and
   * `rev-capsule` do for screens that share a row.
   */
  /* No addNavEntry() here — see the note above where the block used to be. */
})();
</script>
@endverbatim
