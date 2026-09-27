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

    ── THE 2–3 SECOND LOOP NEEDS NO SECOND UPLOAD, AND THE SCREEN SAYS SO ───

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

    HOW LONG IS A SETTING, so this screen reads it instead of printing 2.5:
    `teaser_ms` on Appearance → Shoppable video → Motion, default 2500 and
    ranged 1500–4000, and `teaser` beside it decides whether a tile loops at
    all. readMotion() fetches both once, clamps the number to that range, and
    falls back to the shipped 2500 — so the preview above and the prose beside
    it are the owner's own settings rather than a number that was true the day
    this was written.

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

/* ── the upload bar ────────────────────────────────────────────────────── */
.ugs-up{display:grid;gap:6px;border:1px solid var(--accent,#15a85a);border-radius:var(--r-sm,12px);
        padding:11px 12px;background:var(--accent-soft,#e7f7ee);min-width:0}
.ugs-uph{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
         font-size:11.5px;font-weight:650;min-width:0}
.ugs-uph span{white-space:nowrap;color:var(--accent-ink,#0b6e3a)}
.ugs-upn{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.ugs-prog{height:5px;border-radius:999px;background:var(--surface,#fff);overflow:hidden}
.ugs-progb{height:100%;width:0;background:var(--accent,#15a85a);transition:width .18s var(--ease)}

/* ── the media step ────────────────────────────────────────────────────── */
.ugs-media{display:grid;gap:11px;grid-template-columns:1fr;min-width:0}
@media (min-width:880px){ .ugs-media{grid-template-columns:minmax(0,1fr) minmax(0,1fr)} }
/* align-content:start IS LOAD-BEARING. The two media slots are grid items in a
   two-column row, so both stretch to the taller one -- and the cover slot,
   which has no preview until a cover exists, then spread its own rows to fill
   that height: a stretched "Choose from the Media Library" button with a void
   of white above it, beside a video preview that was 640px tall. It read as a
   broken panel. Packing the rows to the top leaves the slot as tall as its
   neighbour and its CONTENT its own size. */
.ugs-slot{display:grid;gap:8px;align-content:start;
          border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
          padding:11px;min-width:0;background:var(--surface,#fff)}
/* A button in a grid also stretches across its column by default, so it keeps
   the full width deliberately rather than by accident.

   NO ELLIPSIS IN A COMMENT ANYWHERE IN THIS STYLE BLOCK. UgcAdminScreenTest
   scans the whole block, comments included, for a dot followed by a word and
   proves every one of them is prefixed ugs- -- because a rule here named for
   a bare word would restyle every other screen in this console. An ellipsis
   before a word parses as exactly that shape, and this line is where it went
   red. Prose in here starts its sentences with a capital instead. */
.ugs-slot > .ugs-btn{justify-self:stretch}
.ugs-sloth{display:flex;justify-content:space-between;align-items:baseline;gap:9px;min-width:0}
.ugs-sloth b{font-size:12.5px}
.ugs-sloth span{font-size:10.5px;color:var(--ink-faint,#97a0b2);white-space:nowrap}
/* A fixed 9:16 box rather than the file's own shape: the preview then reserves
   its space before a byte of video arrives, which is the same reason the shop's
   tile reserves from width and height instead of measuring. */
.ugs-pv{border-radius:var(--r-sm,12px);overflow:hidden;background:#0b0f18;min-width:0;
        margin-inline:auto;width:min(100%,clamp(150px,18vw,230px));aspect-ratio:9/16}
.ugs-pv video,.ugs-pv img{display:block;width:100%;height:100%;object-fit:contain;background:#0b0f18}
.ugs-shots{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;min-width:0}
.ugs-loopbox{display:grid;gap:6px;justify-items:center;min-width:0}
/* 158px is the shop's own rail tile width, so this is the size it really is. */
.ugs-loop{width:min(100%,158px);aspect-ratio:9/16;border-radius:var(--r-sm,12px);overflow:hidden;
          background:#0b0f18;position:relative}
.ugs-loop video,.ugs-loop img{display:block;width:100%;height:100%;object-fit:cover}
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
  var banner = null, busy = false, seq = 0;
  var step = 1;                // which panel is on show
  var upState = null;          // {kind, name, pct} while a file is going up
  var loopEl = null;           // the mounted preview video, released before each repaint

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
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  function kb(n) {
    if (!n) return '—';
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  }

  function cap(kind) {
    if (kind === 'clip') return limits ? limits.clip_mb : 64;
    if (kind === 'teaser') return limits ? limits.teaser_mb : 8;
    return limits ? limits.poster_mb : 4;
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

    editing = null;
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
   * default 2500, range 1500–4000 — and so is `teaser`, the switch that decides
   * whether a tile loops at all. This screen used to hard-code 2500 into the
   * preview and into its own prose. An owner who had moved that slider to 4000
   * was then shown a preview that rewound at 2.5s and a sentence that said
   * "2.5 seconds", neither of which was what his shop does. The preview is
   * offered as EVIDENCE, so a preview that disagrees with the shop is worse
   * than none.
   *
   * Read ONCE per screen visit and never blocking: `load()` does not await it,
   * so a slow or forbidden read costs the library nothing and the preview falls
   * back to the shipped 2500 — which is also the value the shop uses when the
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
         used, and `range` means 1500–4000 here. A saved row outside it, or a
         string, becomes the shipped default rather than an attribute nothing
         validates. */
      var ms = parseInt(found, 10);
      if (!(ms >= 1500 && ms <= 4000)) ms = 2500;

      motion = motion || {};
      motion.ms = ms;
      if (motion.on === undefined) motion.on = true;
    } catch (e) {
      /* Silent on purpose: this is a nicety on a screen about clips, and the
         owner does not need a toast about the Appearance endpoint to add one. */
      motion = { ms: 2500, on: true };
    }

    render();
  }

  /** The loop settings, with the shipped values until the read lands. */
  function loop() {
    return { ms: (motion && motion.ms) || 2500, on: !motion || motion.on !== false };
  }

  /** 2500 -> "2.5", 3000 -> "3". The seconds an owner would say out loud. */
  function secs(ms) {
    var s = Math.round(ms / 100) / 10;
    return String(s);
  }

  async function load() {
    var mine = ++seq;
    busy = true; banner = null;
    render();

    /* Deliberately not awaited — see readMotion(). */
    readMotion();

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
    step = 1;
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

  /** Save, and answer whether it went through — the stepper waits on that. */
  async function save() {
    if (busy || !editing) return false;
    var payload = form();
    if (!payload) return false;

    keepTyped(payload);

    busy = true; render();

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

      say('Saved.');
      await load();
      await open(id);
      return true;
    } catch (e) {
      /* A publish refusal comes back with its reasons named. Shown as a list
         rather than a toast: "cannot be published yet" with no reasons is a
         message nobody can act on. */
      if (e && e.body && e.body.blockers) {
        banner = null;
        editing._blockers = e.body.blockers;
        step = 5;
        say(e.body.error || 'This video cannot be published yet.');
      } else if (e && e.status === 422) {
        say(explain(e, 'Some of that was not accepted — a title is required.'));
      } else {
        say(explain(e, 'That could not be saved.'));
      }
      return false;
    } finally {
      busy = false; render();
    }
  }

  async function goStep(next) {
    if (next < 1 || next > STEPS.length) return;

    /* Forward is always a save first, so nothing is ever left behind between
       two steps, and so step 1 has created the row the media steps need. */
    if (next > step) {
      var ok = await save();
      if (!ok) return;
    }

    step = next;
    render();
  }

  async function remove(id, title) {
    if (!window.confirm('Delete "' + title + '" and its files? This cannot be undone.')) return;
    busy = true; render();
    try {
      await api('/ugc-videos/' + encodeURIComponent(id), {}, 'DELETE');
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
    var bar = document.querySelector('[data-ugs-bar]');
    var pct = document.querySelector('[data-ugs-pct]');
    var n = upState ? upState.pct : 0;
    if (bar) bar.style.width = n + '%';
    if (pct) pct.textContent = n + '%';
  }

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

    var mb = cap(kind);
    if (file.size > mb * 1048576) {
      say('That file is ' + kb(file.size) + ', and the limit here is ' + mb + ' MB. '
        + 'It was not sent — re-encode it smaller.');
      return;
    }

    /* A friendly early refusal, not the check: the server reads the BYTES and
       is the only thing that decides. This only saves somebody a 64 MB round
       trip for a picture dropped into the wrong box. */
    if (file.type && file.type.indexOf('image/') === 0) {
      say('That is a picture. The cover image goes in the Cover box beside this one.');
      return;
    }

    var data = new FormData();
    data.append('kind', kind);
    data.append('file', file);

    upState = { kind: kind, name: file.name, pct: 0 };
    busy = true; render();

    var xhr = new XMLHttpRequest();
    xhr.open('POST', apiBase() + '/admin-api/ugc-videos/' + encodeURIComponent(editing.id) + '/media');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-XSRF-TOKEN', cookie('XSRF-TOKEN'));

    xhr.upload.onprogress = function (e) {
      if (!e.lengthComputable || !upState) return;
      upState.pct = Math.round((e.loaded / e.total) * 100);
      paintProgress();
    };

    xhr.onload = function () {
      var payload = null;
      try { payload = JSON.parse(xhr.responseText); } catch (e) { payload = null; }

      upState = null;

      if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
        (payload.notes || []).forEach(say);
        say(kind === 'clip' ? 'Video added.' : 'Teaser added.');
        var id = editing.id;
        load().then(function () { return open(id); });
        return;
      }

      var err = new Error('upload ' + xhr.status);
      err.status = xhr.status;
      err.body = payload;
      busy = false;
      say(explain(err, 'That file was not accepted.'));
      render();
    };

    xhr.onerror = function () {
      upState = null; busy = false;
      say('The upload did not reach the server. Check the connection and try again.');
      render();
    };

    xhr.send(data);
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

    var mb = cap('poster');
    if (file.size > mb * 1048576) {
      say('That picture is ' + kb(file.size) + ', and the limit here is ' + mb + ' MB.');
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

  async function derive() {
    if (!editing || !editing.id) return;
    busy = true; render();
    try {
      var body = await api('/ugc-videos/' + encodeURIComponent(editing.id) + '/derive', {});
      (body.notes || []).forEach(say);
      if (!(body.notes || []).length) say('Cover and teaser cut.');
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

  function icon(path) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }

  var ICON_UP = '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>';
  var ICON_PLUS = '<path d="M12 5v14"/><path d="M5 12h14"/>';
  var ICON_GRIP = '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/>'
    + '<circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>';

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
     question 4 has never been answered. Two sentences, not a wall. */
  function transcoderChip() {
    if (!transcoder) return '';

    if (transcoder.available) {
      return '<span class="ugs-chip is-live">Cuts the cover and a '
        + esc(transcoder.teaser_seconds) + 's teaser for you</span>';
    }

    return '<span class="ugs-chip is-warm">No ffmpeg here — you choose the cover</span>';
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
    return '<div class="ugs-steps" role="tablist">' + STEPS.map(function (s) {
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
    }).join('') + '</div>';
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

  function progressHTML() {
    if (!upState) return '';
    return '<div class="ugs-up">'
      + '<div class="ugs-uph"><span class="ugs-upn">' + esc(upState.name) + '</span>'
      +   '<span data-ugs-pct>' + esc(upState.pct) + '%</span></div>'
      + '<div class="ugs-prog"><div class="ugs-progb" data-ugs-bar '
      +   'style="width:' + esc(upState.pct) + '%"></div></div>'
      + '</div>';
  }

  /* ─────────────────────────────────────────────────────────── the panels */

  function detailsPanel(v) {
    return '<section class="ugs-panel"' + (step === 1 ? '' : ' hidden') + '>'
      + panelHead(1, 'Details', 'A title is all it takes to start. Saving here creates the clip, '
        + 'which is what the video and the cover then belong to.')
      + '<div class="ugs-fields">'
      +   '<div class="ugs-f"><label for="ugs-title">Title</label>'
      +     '<input id="ugs-title" type="text" maxlength="180" data-ugs-field="title" dir="auto"'
      +       ' placeholder="Glass skin in 6 steps" value="' + esc(v.title) + '">'
      +     arabicBox('title', 'Title')
      +   '</div>'
      +   '<div class="ugs-f"><label for="ugs-caption">Caption <span class="ugs-dim">· optional</span></label>'
      +     '<textarea id="ugs-caption" maxlength="2000" data-ugs-field="caption" dir="auto"'
      +       ' placeholder="The creator\'s own words, if you are using them.">' + esc(v.caption) + '</textarea>'
      +     arabicBox('caption', 'Caption', 'textarea')
      +   '</div>'
      + '</div>'
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

    html += progressHTML();

    /* ── the video ───────────────────────────────────────────────────── */
    html += '<div class="ugs-media">'
      + '<div class="ugs-slot">'
      +   '<div class="ugs-sloth"><b>The video</b><span>' + esc(kb(v.bytes)) + '</span></div>'
      +   (clip
          ? '<div class="ugs-pv"><video src="' + esc(clip) + '" controls playsinline preload="metadata"'
            + (poster ? ' poster="' + esc(poster) + '"' : '') + '></video></div>'
            + '<p class="ugs-path">' + esc(clip) + '</p>'
          : '')
      +   dropHTML('clip', clip ? 'Drop a different video here' : 'Drag your video here',
            'or press to choose one · MP4 or WebM, up to ' + esc(cap('clip')) + ' MB', locked)
      + '</div>';

    /* ── the cover ───────────────────────────────────────────────────── */
    html += '<div class="ugs-slot">'
      +   '<div class="ugs-sloth"><b>The cover</b><span>' + esc(kb(v.poster_bytes)) + '</span></div>'
      +   (poster
          ? '<div class="ugs-pv"><img src="' + esc(poster) + '" alt=""></div>'
            + '<p class="ugs-path">' + esc(poster) + ' · '
            + (v.width && v.height ? esc(v.width + '×' + v.height) : 'size unread') + '</p>'
          : '')
      +   '<button class="ugs-btn is-primary" data-ugs-poster="1"' + (locked ? ' disabled' : '') + '>'
      +     icon(ICON_PLUS) + 'Choose from the Media Library</button>'
      +   posterDropHTML(locked)
      +   (transcoder && transcoder.available && v.id && clip
          ? '<button class="ugs-btn" data-ugs-derive="1">Cut the cover and teaser from the video</button>'
          : '')
      + '</div>'
      + '</div>';

    /* ── the loop, as it really plays ────────────────────────────────── */
    html += '<div class="ugs-slot">'
      + '<div class="ugs-sloth"><b>The ' + esc(secs(loop().ms)) + ' second loop</b><span>'
      +   esc(stateWords(v)[0]) + '</span></div>';

    if (clip) {
      var src = teaser || clip;
      html += '<div class="ugs-shots">'
        + '<div class="ugs-loopbox">'
        +   '<div class="ugs-loop" data-ugs-loopsrc="' + esc(src) + '"'
        +     ' data-ugs-loopms="' + esc(loop().ms) + '"'
        +     ' data-ugs-loopfull="' + (teaser ? '0' : '1') + '">'
        +     (poster ? '<img src="' + esc(poster) + '" alt="">' : '')
        +   '</div>'
        +   '<p class="ugs-loopcap">158px wide — the tile\'s real size on the shop.</p>'
        + '</div>'
        + '<div style="flex:1 1 220px;min-width:0">'
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
      html += '<details class="ugs-more"' + (teaser ? ' open' : '') + '>'
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
      html += '<p class="ugs-help">Nothing to loop yet. Add the video above and it plays here, '
        + 'at the size it will be on the shop.</p>';
    }

    html += '</div>';

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
      + '<div class="ugs-fields">'
      +   '<div class="ugs-two">'
      +     '<div class="ugs-f"><label for="ugs-handle">Creator handle</label>'
      +       '<input id="ugs-handle" type="text" maxlength="120" data-ugs-field="creator_handle"'
      +         ' placeholder="@layla.skin" value="' + esc(v.creator_handle) + '"></div>'
      +     '<div class="ugs-f"><label for="ugs-curl">Creator link</label>'
      +       '<input id="ugs-curl" type="text" maxlength="512" data-ugs-field="creator_url"'
      +         ' placeholder="https://…" value="' + esc(v.creator_url) + '">'
      +       '<p class="ugs-help">http:// or https:// only. Anything else is dropped.</p></div>'
      +   '</div>'
      +   '<div class="ugs-two">'
      +     '<div class="ugs-f"><label for="ugs-plat">Where it came from</label>'
      +       selectHTML('source_platform', vocab ? vocab.platform : [], v.source_platform) + '</div>'
      +     '<div class="ugs-f"><label for="ugs-surl">Original post</label>'
      +       '<input id="ugs-surl" type="text" maxlength="512" data-ugs-field="source_url"'
      +         ' placeholder="https://…" value="' + esc(v.source_url) + '">'
      +       (post
                ? '<p class="ugs-help"><a href="' + esc(post) + '" target="_blank" rel="noopener nofollow">'
                  + 'Open the original post &#8599;</a> &mdash; a credit link, never an embed.</p>'
                : '<p class="ugs-help">A credit link, never an embed.</p>')
      +     '</div>'
      +   '</div>'
      +   '<div class="ugs-two">'
      +     '<div class="ugs-f"><label for="ugs-rights">Permission</label>'
      +       selectHTML('rights_status', vocab ? vocab.rights : [], v.rights_status) + '</div>'
      +     '<div class="ugs-f"><label for="ugs-ev">Where the permission is recorded</label>'
      +       '<textarea id="ugs-ev" maxlength="2000" data-ugs-field="rights_evidence"'
      +         ' placeholder="A DM, an email, a signed release.">' + esc(v.rights_evidence) + '</textarea>'
      +       '<p class="ugs-help">Never shown on the shop.</p></div>'
      +   '</div>'
      + '</div>'
      + '</section>';
  }

  function productsPanel() {
    return '<section class="ugs-panel"' + (step === 4 ? '' : ' hidden') + '>'
      + panelHead(4, 'Products in this clip',
        '<b>Optional</b>, and the whole point of the feature: as many as you like, which is the '
        + 'thing Instagram cannot do. The first is the one a tile shows before anyone taps.')
      + taggedHTML()
      + '<div class="ugs-f">'
      +   '<label for="ugs-search">Add a product</label>'
      /* The typed term is kept in `term` and written back here, because
         render() repaints #content wholesale: adding a product re-renders the
         list, and a box that emptied itself under a list of results nobody
         searched for reads as a bug. */
      +   '<input id="ugs-search" type="text" placeholder="Search by name" data-ugs-search="1" value="'
      +     esc(term) + '">'
      +   '<div class="ugs-results">' + resultsHTML() + '</div>'
      + '</div>'
      + '</section>';
  }

  function publishPanel(v) {
    var blockers = v._blockers || v.blockers || [];
    var done = function (ok, text) {
      return '<div class="ugs-ci ' + (ok ? 'is-ok' : 'is-no') + '">'
        + '<span class="ugs-cid">' + (ok ? '&#10003;' : '!') + '</span>'
        + '<span>' + text + '</span></div>';
    };

    return '<section class="ugs-panel"' + (step === 5 ? '' : ' hidden') + '>'
      + panelHead(5, 'Publish', 'Everything below has to be true before a clip can appear. '
        + 'The list is the server\'s answer, not this screen\'s guess.')
      + '<div class="ugs-check">'
      +   done(!!v.file_path, 'The video is uploaded')
      +   done(!!v.poster_path, 'The cover is set')
      +   done(v.rights_status === 'granted', 'The creator has granted permission')
      + '</div>'
      + (blockers.length
          ? '<div class="ugs-note is-bad"><b>The last save was refused:</b><ul>'
            + blockers.map(function (b) { return '<li>' + esc(b) + '</li>'; }).join('')
            + '</ul></div>'
          : '')
      + '<div class="ugs-fields">'
      +   '<div class="ugs-two">'
      +     '<div class="ugs-f"><label>Status</label>'
      +       selectHTML('status', vocab ? vocab.status : [], v.status) + '</div>'
      +     '<div class="ugs-f"><label>Show on</label>'
      +       selectHTML('locale', vocab ? vocab.locale : [], v.locale, 'Both shops')
      +       '<p class="ugs-help">A clip spoken in English is not automatically right for the '
      +         'Arabic shop.</p></div>'
      +   '</div>'
      +   '<div class="ugs-two">'
      +     '<div class="ugs-f"><label>Order</label>'
      +       '<input type="number" min="0" max="9999" data-ugs-field="position" value="'
      +         esc(v.position) + '">'
      +       '<p class="ugs-help">Lowest first. The good one, not the new one.</p></div>'
      +     '<div class="ugs-f"><label>Publish from</label>'
      +       '<input type="datetime-local" data-ugs-field="published_at" value="'
      +         esc(v.published_at) + '">'
      +       '<p class="ugs-help">Empty means as soon as the status says published.</p></div>'
      +   '</div>'
      + '</div>'
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

  function resultsHTML() {
    if (!results.length) return '';
    return results.map(function (p) {
      return '<button class="ugs-res" data-ugs-add="' + esc(p.id) + '" data-ugs-addname="' + esc(p.name)
        + '" data-ugs-addbrand="' + esc(p.brand || '') + '">' + esc(p.name)
        + (p.brand ? ' <span class="ugs-dim">· ' + esc(p.brand) + '</span>' : '') + '</button>';
    }).join('');
  }

  /** The one line under the step rail that says where this clip stands. */
  function stateLine(v) {
    if (!v.id) return 'Not saved yet.';

    var left = (v.blockers || []).length;
    if (v.status === 'publish' && !left) return 'Published.';
    if (!left) return 'Draft, and ready to publish on step 5.';
    return left + (left === 1 ? ' thing' : ' things') + ' still needed before this can be published.';
  }

  function editorHTML() {
    var v = editing;

    return '<div class="ugs-card">'
      + '<div class="ugs-hero" style="margin-bottom:12px"><div class="ugs-herotext">'
      +   '<div class="ugs-h">' + (v.id ? esc(v.title || '(untitled)') : 'A new clip') + '</div>'
      +   '<div class="ugs-pills" style="margin-top:6px">'
      +     pill(v.status === 'publish' ? 'published' : 'draft', v.status === 'publish' ? 'live' : '')
      +     (v.id ? pill(stateWords(v)[0], stateWords(v)[1]) : '')
      +     (v.id && v.rights_status !== 'granted' ? pill('no permission yet', 'hold') : '')
      +   '</div>'
      + '</div></div>'
      +   stepsHTML()
      +   '<div id="ugs-form" style="margin-top:14px">'
      +     detailsPanel(v)
      +     mediaPanel(v)
      +     creditPanel(v)
      +     productsPanel()
      +     publishPanel(v)
      +   '</div>'
      +   '<div class="ugs-foot">'
      +     '<div class="ugs-state">' + esc(stateLine(v)) + '</div>'
      +     '<div class="ugs-footb">'
      +       (v.id ? '<button class="ugs-btn is-danger" data-ugs-del="' + esc(v.id)
                + '" data-ugs-delname="' + esc(v.title || '(untitled)') + '">Delete</button>' : '')
      +       '<button class="ugs-btn" data-ugs-back="1">All clips</button>'
      +       (step > 1 ? '<button class="ugs-btn" data-ugs-step="' + (step - 1) + '">Back</button>' : '')
      +       (step < STEPS.length
                ? '<button class="ugs-btn is-primary" data-ugs-next="1"' + (busy ? ' disabled' : '')
                  + '>Save and continue</button>'
                : '<button class="ugs-btn is-primary" data-ugs-save="1"' + (busy ? ' disabled' : '')
                  + '>Save</button>')
      +     '</div>'
      +   '</div>'
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
    if (!(ms >= 500)) ms = 2500;
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
      v.addEventListener('timeupdate', function () {
        if (v.currentTime * 1000 >= ms) v.currentTime = 0;
      });
      v.addEventListener('ended', function () {
        v.currentTime = 0;
        var again = v.play();
        if (again && again.catch) again.catch(function () {});
      });
    }

    var p = v.play();
    if (p && p.catch) p.catch(function () {});
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host) return;
    /* Only paint when this screen is the one on show. The console swaps
       #content wholesale, so a late render from an in-flight request must not
       overwrite whichever screen the owner moved to. */
    var title = document.querySelector('#ptitle');
    if (!title || title.textContent !== 'All clips') return;

    releaseLoop();

    host.innerHTML = '<div class="wrap ugs-wrap">'
      + (window.kbbUgcTabs ? window.kbbUgcTabs(SCREEN) : '')
      + (editing ? editorHTML() : listHTML())
      + '</div>';

    if (editing && window.KBBArabic) window.KBBArabic.wire(host);
    if (editing && step === 2) wireLoop(host);

    /* The step rail is a scroll-snapped strip on a phone, so after "Save and
       continue" the step that is now current can be off to the right. This is a
       scroll COMMAND, not a measurement: it reads no rect, no offset and no
       scroll position, and rule 4 is about laying a page out in script. */
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
      + '[data-ugs-down],[data-ugs-add],[data-ugs-poster],[data-ugs-step],[data-ugs-next]') : null;
    if (!t) return;

    if (t.hasAttribute('data-ugs-new')) { e.preventDefault(); blank(); return; }
    if (t.hasAttribute('data-ugs-open')) { e.preventDefault(); step = 1; open(t.getAttribute('data-ugs-open')); return; }
    if (t.hasAttribute('data-ugs-save')) { e.preventDefault(); save(); return; }
    if (t.hasAttribute('data-ugs-next')) { e.preventDefault(); goStep(step + 1); return; }
    if (t.hasAttribute('data-ugs-step')) { e.preventDefault(); goStep(Number(t.getAttribute('data-ugs-step'))); return; }
    if (t.hasAttribute('data-ugs-back')) { e.preventDefault(); editing = null; step = 1; render(); return; }
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
      render();
      return;
    }

    var moved = tagged.splice(from, 1)[0];
    tagged.splice(to, 0, moved);
    /* Repaint, which also drops both drag classes: render() replaces the list
       wholesale, so there is nothing left holding them. */
    render();
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
