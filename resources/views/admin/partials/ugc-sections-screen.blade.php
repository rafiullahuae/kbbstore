{{--
    Content → Video sections. (Lane V3 — Phase 20, the rail itself)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go, window.kbbAddNavEntry, window.kbbPickMedia and toast(). It
    registers its own sidebar entry and wraps window.go, exactly as the screens
    beside it do, so that one include is the whole of the change to that file.

    ── WHAT THE OWNER ASKED FOR, IN HIS OWN WORDS ───────────────────────────

    "i need a full module, where i can create multiple sections of videos contain,
    and can insert anywhere in the site, products and pages etc via short code.
    when i create new section, it should give me proper list of inside videos, and
    each videos will have popup edit options with full controls, products
    selection, and upload videos or enter instagram, tiktok urls."

    So: sections, each with the ordered list of clips inside it, each clip opening
    a POPUP with the whole editor in it, and a shortcode to copy at the top.

    ── THE POPUP POSTS TO THE ENDPOINTS THAT ALREADY EXISTED ────────────────

    Deliberately. UgcVideoController shipped last round with the validation, the
    five-step upload check, the publish gate and the product tagging already in it,
    and a second video editor would be a second set of rules that drift apart from
    the first. This screen is the sections above those clips plus a different shape
    of window onto the same endpoints.

    ── THE POSTER HAS NO FILE INPUT, AND THAT IS ENFORCED ELSEWHERE ─────────

    The owner's rule — "on any upload media on the whole backend, the media library
    is a must to show" — is checked by AdminMediaPickerEverywhereTest, which scans
    every admin Blade for a raw file input and allows one only when its `accept`
    attribute is not an image type. So the clip and the teaser keep their inputs
    (they accept video, and a 64MB clip has no business in an image library) and the
    poster is chosen through window.kbbPickMedia and nothing else.

    ── NOTHING BELOW MAY NAME BLADE'S RAW-BLOCK DIRECTIVES ──────────────────

    Not in the code and not in this comment either. Blade pairs the first such
    opening directive it finds anywhere in the file — inside a comment included —
    with the next closing one, so writing the word in prose swallows everything
    between them and serves the whole docblock to the browser as visible text.

    ── THE LAYOUT AND ESCAPING RULES ────────────────────────────────────────

    Nothing here may be wider than its column at 390px: the owner reviews on a
    phone. Every grid and flex child that can hold something wide carries
    min-width:0, because a grid item's default min-width is auto. Every horizontal
    offset is a LOGICAL property, and every creator handle is wrapped in <bdi> so an
    Arabic title beside "@layla.skin" does not render it "layla.skin@".

    EVERY CLASS IS PREFIXED ugx- AND EVERY data- ATTRIBUTE data-ugx-, and both
    appear nowhere else in the console: app.blade.php binds delegated listeners to
    `document` itself, each claiming a bare attribute name.

    esc() ON EVERY INTERPOLATION of a title, a handle, a caption, a product name, a
    blocker sentence and a server error. CLAUDE.md rule 5: anything printed
    unescaped is a constant, never a setting.
--}}
@verbatim
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   THE SHOPPABLE-VIDEO SCREENS.

   Rebuilt on the console's OWN tokens, which is most of what was wrong with
   the first pass: it hard-coded #e6e6e6 and 12px radii while :root already
   defines --border, --r/--r-sm/--r-xs, --sh-s/--sh/--sh-l, --surface-2/3, a
   four-step ink scale and --ease. A screen that names literals cannot follow
   the console theme and cannot match the panel around it -- which is exactly
   what "classic" looked like.

   The other half is density. Every field had a paragraph under it and every
   section stacked, so the clip editor was one very long scroll. It is a tabbed
   panel now with a fixed header and a sticky action bar, so the thing being
   edited is always on screen and Save never scrolls away.
   ═══════════════════════════════════════════════════════════════════════════ */

.ugx-wrap{display:grid;gap:16px;min-width:0}
.ugx-wrap > *{min-width:0}
.ugx-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
          border-radius:var(--r,18px);padding:18px 18px 16px;min-width:0;box-shadow:var(--sh-s)}
.ugx-title{font-weight:680;font-size:15.5px;letter-spacing:-.01em;color:var(--ink,#101729)}
.ugx-sub{color:var(--ink-soft,#626c80);font-size:12.5px;line-height:1.55;margin-top:4px;max-width:68ch}
.ugx-note{border:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb);
          border-radius:var(--r-sm,12px);padding:11px 13px;font-size:12.5px;line-height:1.55;
          color:var(--ink-soft,#626c80);min-width:0}
.ugx-note b{color:var(--ink,#101729);font-weight:650}
.ugx-note.is-warm{border-color:#f0dcb4;background:var(--amber-soft,#fdf2e2);color:#7a5a1e}
.ugx-note.is-warm b{color:#5d4416}
.ugx-note.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb);color:#9d332c}
.ugx-note.is-bad b{color:#7d2822}

/* ── controls ───────────────────────────────────────────────────────────── */
.ugx-actions{display:flex;flex-wrap:wrap;gap:8px;min-width:0}
.ugx-btn{padding:8px 14px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
         background:var(--surface,#fff);color:var(--ink-2,#3c465c);font:inherit;font-size:12.5px;
         font-weight:600;cursor:pointer;max-width:100%;
         transition:background .16s var(--ease),border-color .16s var(--ease),
                    color .16s var(--ease),box-shadow .16s var(--ease),transform .12s var(--ease)}
.ugx-btn:hover{background:var(--surface-2,#f2f4fb);border-color:var(--ink-faint,#97a0b2)}
.ugx-btn:active{transform:translateY(.5px)}
.ugx-btn.is-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff;
                    box-shadow:0 1px 2px rgba(16,24,40,.06),0 8px 18px -10px rgba(21,168,90,.7)}
.ugx-btn.is-primary:hover{background:var(--accent-strong,#0f8f4b);border-color:var(--accent-strong,#0f8f4b)}
.ugx-btn.is-danger{color:var(--red,#e3493f)}
.ugx-btn.is-danger:hover{background:var(--red-soft,#fdeceb);border-color:#f3c9c6}
.ugx-btn[disabled]{opacity:.45;cursor:default;transform:none}
.ugx-mini{padding:5px 9px;border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
          background:var(--surface,#fff);color:var(--ink-2,#3c465c);font:inherit;font-size:11.5px;
          font-weight:600;cursor:pointer;line-height:1.2;
          transition:background .16s var(--ease),border-color .16s var(--ease),color .16s var(--ease)}
.ugx-mini:hover{background:var(--surface-2,#f2f4fb)}
.ugx-mini[disabled]{opacity:.35;cursor:default}
.ugx-mini.is-danger{color:var(--red,#e3493f)}
.ugx-mini.is-danger:hover{background:var(--red-soft,#fdeceb);border-color:#f3c9c6}
.ugx-btn:focus-visible,.ugx-mini:focus-visible,.ugx-x:focus-visible,.ugx-seg button:focus-visible,
.ugx-tab:focus-visible,.ugx-drop:focus-within{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

/* ── the segmented tab strip, shared by all three screens ───────────────── */
.subtabs.ugx-seg{display:inline-flex;gap:2px;padding:3px;background:var(--surface-2,#f2f4fb);
                 border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);flex-wrap:wrap}
.subtabs.ugx-seg .subtab{border:0;background:transparent;color:var(--ink-soft,#626c80);
        font:inherit;font-size:12.5px;font-weight:620;padding:7px 14px;border-radius:9px;cursor:pointer;
        transition:background .18s var(--ease),color .18s var(--ease),box-shadow .18s var(--ease)}
.subtabs.ugx-seg .subtab:hover{color:var(--ink,#101729)}
.subtabs.ugx-seg .subtab.on{background:var(--surface,#fff);color:var(--ink,#101729);box-shadow:var(--sh-s)}

/* ── the section list ───────────────────────────────────────────────────── */
.ugx-rows{display:grid;gap:10px;min-width:0}
.ugx-row{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;
         border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);padding:13px 14px;
         min-width:0;background:var(--surface,#fff);
         transition:border-color .16s var(--ease),box-shadow .16s var(--ease)}
.ugx-row:hover{border-color:var(--ink-faint,#97a0b2);box-shadow:var(--sh-s)}
.ugx-row > *{min-width:0}
.ugx-rowname{font-weight:660;font-size:13.5px;line-height:1.35;overflow-wrap:anywhere;
             color:var(--ink,#101729)}
.ugx-rowmeta{font-size:11.5px;color:var(--ink-soft,#626c80);margin-top:3px;line-height:1.5;
             overflow-wrap:anywhere}
.ugx-code{font-family:var(--mono,ui-monospace,monospace);font-size:11px;
          background:var(--surface-2,#f2f4fb);border:1px solid var(--border,#e6e9f2);
          border-radius:8px;padding:4px 8px;display:inline-block;margin-top:7px;
          overflow-wrap:anywhere;max-width:100%;color:var(--ink-2,#3c465c)}
.ugx-pills{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.ugx-pill{font-size:10.5px;font-weight:680;padding:3px 8px;border-radius:999px;letter-spacing:.01em;
          border:1px solid var(--border,#e6e9f2);background:var(--surface-2,#f2f4fb);
          color:var(--ink-soft,#626c80);white-space:nowrap}
.ugx-pill.is-live{border-color:#bfe7cf;background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.ugx-pill.is-hold{border-color:#f3c9c6;background:var(--red-soft,#fdeceb);color:#9d332c}
.ugx-pill.is-soft{border-color:#f0dcb4;background:var(--amber-soft,#fdf2e2);color:#7a5a1e}
.ugx-rowacts{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end}

/* A screen's own header: a back link on its own line, then the title. */
/* ── UPLOAD A NEW VIDEO, IN THE TOP-RIGHT OF THE CARD HEAD ────────────────
   The owner drew the box there, so the head becomes a row with the title on the
   left and the control on the right. FLEX WITH WRAP, and the wrap is the mobile
   answer: at 390px the control drops under the title and spans it, with no media
   query needed and nothing measured in script. min-width:0 on both children so a
   long title shrinks rather than pushing the control off the card. */
.ugx-ehtop{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
           flex-wrap:wrap;min-width:0;width:100%}
.ugx-ehtop > .ugx-ehtitle{flex:1 1 min(260px,100%);min-width:0}
/* The control is a real <label> wrapping a real file input -- the input is what
   the change handler listens to and what makes it keyboard-operable, and a label
   is a genuine activator for its input in every browser, so no script forwards
   the click. Sized with calc() off the viewport like every other control here. */
.ugx-newup{flex:0 1 auto;display:inline-grid;gap:2px;justify-items:start;cursor:pointer;
           border:1.5px dashed var(--line,#e3e7ee);border-radius:var(--r-sm,12px);
           padding:calc(6px + 0.2vw) calc(10px + 0.4vw);background:var(--surface-2,#fafbfc);
           min-width:0;max-width:100%;
           transition:border-color .15s var(--ease,ease),background .15s var(--ease,ease)}
.ugx-newup:focus-within{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.ugx-newup.is-over{border-color:var(--accent,#15a85a);border-style:solid;
                   background:var(--accent-soft,#e7f7ee)}
.ugx-newup.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb)}
.ugx-newup input[type=file]{position:absolute;width:1px;height:1px;opacity:0;
                            clip:rect(0 0 0 0);overflow:hidden;white-space:nowrap}
.ugx-newupname{font-size:12.5px;font-weight:680;color:var(--ink,#101729);overflow-wrap:anywhere}
.ugx-newupmeta{font-size:10.5px;line-height:1.45;color:var(--ink-faint,#97a0b2);overflow-wrap:anywhere}
/* The preview of what just arrived. A fixed 9:16 box, the same shape a rail tile
   reserves, so the panel does not resize around whatever the file's own aspect
   ratio happens to be. */
.ugx-newpv{margin-top:10px;width:min(180px,44vw);aspect-ratio:9 / 16;border-radius:var(--r-sm,12px);
           overflow:hidden;background:#0b0f18}
.ugx-newpv video{display:block;width:100%;height:100%;object-fit:contain;background:#0b0f18}

.ugx-eh{display:grid;gap:7px;justify-items:start;margin-bottom:16px;min-width:0}
.ugx-eh > *{min-width:0;max-width:100%}
.ugx-eh .ugx-code{margin-top:0}
.ugx-back-link{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;
               padding:0;font:inherit;font-size:12px;font-weight:620;cursor:pointer;
               color:var(--ink-soft,#626c80);transition:color .16s var(--ease)}
.ugx-back-link:hover{color:var(--accent,#15a85a)}
.ugx-back-link svg{width:14px;height:14px;display:block}
.ugx-back-link:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:3px;border-radius:5px}

/* ── fields ─────────────────────────────────────────────────────────────── */
.ugx-fields{display:grid;gap:14px;min-width:0}
.ugx-two{display:grid;gap:14px;grid-template-columns:1fr;min-width:0}
@media (min-width:760px){ .ugx-two{grid-template-columns:1fr 1fr} }
.ugx-f{display:grid;gap:6px;min-width:0;align-content:start}
.ugx-f label{font-size:12px;font-weight:660;color:var(--ink-2,#3c465c);overflow-wrap:anywhere}
.ugx-f input[type=text],.ugx-f input[type=number],.ugx-f input[type=datetime-local],
.ugx-f select,.ugx-f textarea{width:100%;min-width:0;padding:9px 11px;font:inherit;font-size:13px;
  border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
  background:var(--surface,#fff);color:var(--ink,#101729);
  transition:border-color .16s var(--ease),box-shadow .16s var(--ease)}
.ugx-f input:focus,.ugx-f select:focus,.ugx-f textarea:focus{outline:none;
  border-color:var(--accent,#15a85a);box-shadow:0 0 0 3px var(--accent-soft,#e7f7ee)}
.ugx-f input::placeholder,.ugx-f textarea::placeholder{color:var(--ink-faint,#97a0b2)}
.ugx-f textarea{resize:vertical;min-height:70px;line-height:1.5}
.ugx-help{font-size:11px;color:var(--ink-soft,#626c80);line-height:1.5;margin:0;max-width:66ch}
.ugx-sec{font-size:11px;font-weight:720;letter-spacing:.08em;text-transform:uppercase;
         color:var(--ink-faint,#97a0b2)}

/* ── a clip's poster, wherever one is shown ─────────────────────────────── */
.ugx-thumb{width:56px;aspect-ratio:9/16;border-radius:10px;background:var(--surface-3,#eef1f9);
           overflow:hidden;display:grid;place-items:center;color:var(--ink-faint,#97a0b2);
           font-size:9.5px;text-align:center;flex:0 0 auto;border:1px solid var(--border,#e6e9f2)}
.ugx-thumb img{width:100%;height:100%;object-fit:cover;display:block}
/* A src that 404s otherwise draws the browser's broken-image glyph inside the
   tile, which looks like a defect in the panel rather than a missing file. */
.ugx-thumb img[alt='']{font-size:0;color:transparent}
.ugx-vid{display:grid;grid-template-columns:56px 1fr auto;gap:12px;align-items:center;
         border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);padding:11px 12px;
         min-width:0;background:var(--surface,#fff);
         transition:border-color .16s var(--ease),box-shadow .16s var(--ease)}
.ugx-vid:hover{border-color:var(--ink-faint,#97a0b2);box-shadow:var(--sh-s)}
.ugx-vid > *{min-width:0}

/* ── FILE PICKERS, AND THE NATIVE ONE IS GONE ────────────────────────────
   `<input type=file>` renders as the browser's own grey "Choose File / No file
   chosen" button, which is the single most dated thing a panel can contain and
   cannot be styled. The input is still THERE -- it is what the change handler
   listens to, and it is what keeps the picker accessible and keyboard-operable
   -- it is just visually hidden behind a <label>, which is a real activator for
   it in every browser. No JavaScript click-forwarding. */
.ugx-drop{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;
          border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);padding:12px 13px;
          min-width:0;background:var(--surface,#fff);cursor:pointer;margin:0;
          transition:border-color .16s var(--ease),background .16s var(--ease),box-shadow .16s var(--ease)}
.ugx-drop:hover{border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
.ugx-drop input[type=file]{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.ugx-dropicon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;
              background:var(--surface-2,#f2f4fb);color:var(--ink-soft,#626c80);flex:0 0 auto}
.ugx-drop:hover .ugx-dropicon{background:#fff;color:var(--accent,#15a85a)}
.ugx-dropicon svg{width:17px;height:17px;display:block}
.ugx-dropname{display:block;font-size:12.5px;font-weight:660;color:var(--ink,#101729);line-height:1.3}
.ugx-dropmeta{display:block;font-size:11px;color:var(--ink-soft,#626c80);margin-top:3px;
              line-height:1.45;max-width:58ch}
.ugx-dropcta{font-size:11.5px;font-weight:650;color:var(--accent,#15a85a);white-space:nowrap;
             border:1px solid var(--border,#e6e9f2);border-radius:8px;padding:5px 10px;
             background:var(--surface,#fff)}
.ugx-drop:hover .ugx-dropcta{border-color:var(--accent,#15a85a)}
.ugx-drop.is-empty .ugx-dropname{color:var(--ink-soft,#626c80);font-weight:600}
/* ── the drop affordance ───────────────────────────────────────────────
   The hint that says dragging works. It is a LINE OF TEXT rather than a dashed
   slab, because these three rows sit in a dialog body on a 390px phone and a
   full drop panel each would push Products and Placement below the fold.

   The hover and drag states come from the kit's own .kbbu-zone rules, which
   window.kbbDropZone adds the class for -- one hover state and one refusal state
   for every drop target in the console, rather than a third opinion here. The two
   rules below are only what a ROW needs that a standalone zone does not: the
   kit's grid would otherwise centre these three columns. */
.ugx-drophint{display:block;font-size:10.5px;color:var(--ink-faint,#97a0b2);margin-top:4px;
              line-height:1.4}
.ugx-drop.kbbu-zone{display:grid;grid-template-columns:auto 1fr auto;justify-items:stretch;
                    text-align:start;padding:12px 13px}
.ugx-drop.kbbu-zone.is-over .ugx-drophint{color:var(--accent-ink,#0b6e3a);font-weight:650}
/* ── A REFUSED ZONE MUST LOOK REFUSED EVEN UNDER THE POINTER ────────────
   A picture caught this. `.ugx-drop:hover` and `.kbbu-zone.is-bad` are both two
   selectors deep, so they tie on specificity and the LATER one wins -- and this
   file is included after the kit, so hover won. A zone that had just refused a
   file showed the green hover background with the red refusal sentence sitting
   inside it, which reads as an accepted file with an odd note attached.

   Stating both states again here, with :hover spelled out so the tie cannot be
   decided by source order. The refusal is the more important of the two: it is
   the only thing on screen telling the owner why nothing happened. */
.ugx-drop.kbbu-zone.is-over,.ugx-drop.kbbu-zone.is-over:hover{
  border-color:var(--accent,#15a85a);background:var(--accent-soft,#e7f7ee)}
/* The cover-cut button and its one line of explanation, under the poster row. */
.ugx-cutrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;min-width:0}
.ugx-cuthint{font-size:11.5px;color:var(--ink-soft,#6b7280);line-height:1.45;min-width:0;
             overflow-wrap:anywhere;flex:1 1 220px}

.ugx-drop.kbbu-zone.is-bad,.ugx-drop.kbbu-zone.is-bad:hover{
  border-color:#f3c9c6;background:var(--red-soft,#fdeceb)}
/* The kit writes its refusal into a span it appends, which must not become a
   fourth grid column on these rows. */
.ugx-drop [data-kbbu-zmsg]{grid-column:1 / -1}

/* ── the upload panel ──────────────────────────────────────────────────
   Every number in here is measured: the percentage, the bytes, the speed and the
   time remaining all came off the upload's own progress event -- see
   window.kbbUpload in partials/upload-kit.blade.php. There is NO indeterminate
   variant, on purpose.

   is-bad is the refused ending and is-slow the long wait, and BOTH STAY ON
   SCREEN: this screen used to throw the panel away the instant the request
   landed, which is exactly what a stall looks like too. */
.ugx-up{display:grid;gap:7px;border:1px solid var(--accent,#15a85a);border-radius:var(--r-sm,12px);
        padding:11px 12px;background:var(--accent-soft,#e7f7ee);min-width:0}
.ugx-up.is-bad{border-color:#f3c9c6;background:var(--red-soft,#fdeceb)}
/* A wait long enough to be worth remarking on, in colour as well as in words.
   The class is written by the kit's one-second tick, which counts seconds and
   reads no layout. */
.ugx-up.is-slow{border-color:#f0dcb4;background:var(--amber-soft,#fdf2e2)}
.ugx-up.is-slow .ugx-uph span,.ugx-up.is-slow .ugx-upm{color:#8a6212}
.ugx-uph{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
         font-size:11.5px;font-weight:650;min-width:0}
.ugx-uph span{white-space:nowrap;color:var(--accent-ink,#0b6e3a)}
.ugx-upn{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.ugx-upm{display:flex;flex-wrap:wrap;gap:3px 12px;font-size:10.5px;line-height:1.5;
         color:var(--ink-soft,#626c80);min-width:0}
.ugx-upm > *{min-width:0;overflow-wrap:anywhere}
.ugx-up.is-bad .ugx-uph span,.ugx-up.is-bad .ugx-upm{color:#8c2f2c}
.ugx-prog{height:6px;border-radius:999px;background:var(--surface,#fff);overflow:hidden}
.ugx-progb{height:100%;width:0;background:var(--accent,#15a85a);transition:width .18s var(--ease)}
/* A flex row rather than a grid child, so Cancel is its own width instead of a
   slab across the panel, and it wraps on a phone. */
.ugx-upa{display:flex;flex-wrap:wrap;gap:8px;align-items:center;min-width:0}
.ugx-footnote{font-size:10.5px;color:var(--ink-faint,#97a0b2);line-height:1.45;min-width:0}

/* ── the popup ───────────────────────────────────────────────────────────
   position:fixed + inset:0, and the BODY scrolls rather than the page behind
   it: a phone keyboard opening inside a dialog that scrolls the document puts
   the field under the keyboard. The header and the action bar do not scroll, so
   Save is always reachable -- which the single long scroll could not promise. */
.ugx-back{position:fixed;inset:0;z-index:900;background:rgba(16,23,41,.52);
          backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);
          display:grid;place-items:center;padding:16px;overflow:auto;
          animation:ugxFade .18s var(--ease)}
@keyframes ugxFade{from{opacity:0}to{opacity:1}}
@keyframes ugxRise{from{opacity:0;transform:translateY(8px) scale(.985)}
                   to{opacity:1;transform:none}}
.ugx-modal{background:var(--surface,#fff);border:1px solid var(--border,#e6e9f2);
           border-radius:var(--r,18px);width:100%;max-width:820px;
           max-height:min(760px, calc(100vh - 32px));min-width:0;
           box-shadow:var(--sh-l,0 24px 60px -22px rgba(16,24,40,.30));
           display:grid;grid-template-rows:auto auto 1fr auto;overflow:hidden;
           animation:ugxRise .22s var(--ease)}
@media (prefers-reduced-motion:reduce){
  .ugx-back,.ugx-modal{animation:none}
  .ugx-btn,.ugx-mini,.ugx-row,.ugx-vid,.ugx-drop,.ugx-tab,.subtabs.ugx-seg .subtab{transition:none}
  /* The bar still fills -- it is the one honest indicator of progress -- it just
     jumps to each measured percentage instead of easing to it. */
  .ugx-progb{transition:none}
}
.ugx-mh{display:grid;grid-template-columns:auto 1fr auto;gap:13px;align-items:center;
        padding:15px 16px 13px;border-bottom:1px solid var(--border-2,#eef0f6);min-width:0}
.ugx-mh > *{min-width:0}
.ugx-x{width:30px;height:30px;display:grid;place-items:center;
       border:1px solid var(--border,#e6e9f2);border-radius:9px;background:var(--surface,#fff);
       color:var(--ink-soft,#626c80);font:inherit;font-size:16px;line-height:1;cursor:pointer;
       flex:0 0 auto;transition:background .16s var(--ease),color .16s var(--ease)}
.ugx-x:hover{background:var(--surface-2,#f2f4fb);color:var(--ink,#101729)}

/* The tab rail inside the dialog. */
.ugx-tabs{display:flex;gap:2px;padding:10px 16px 0;overflow-x:auto;min-width:0;
          border-bottom:1px solid var(--border-2,#eef0f6);scrollbar-width:none}
.ugx-tabs::-webkit-scrollbar{display:none}
.ugx-tab{border:0;background:transparent;color:var(--ink-soft,#626c80);font:inherit;font-size:12.5px;
         font-weight:640;padding:8px 12px 11px;cursor:pointer;white-space:nowrap;position:relative;
         border-bottom:2px solid transparent;margin-bottom:-1px;
         transition:color .16s var(--ease),border-color .16s var(--ease)}
.ugx-tab:hover{color:var(--ink,#101729)}
.ugx-tab.on{color:var(--accent-ink,#0b6e3a);border-bottom-color:var(--accent,#15a85a)}
.ugx-tab .ugx-count{display:inline-block;margin-inline-start:6px;font-size:10.5px;font-weight:700;
                    padding:1px 6px;border-radius:999px;background:var(--surface-2,#f2f4fb);
                    color:var(--ink-soft,#626c80)}
.ugx-tab.on .ugx-count{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}

.ugx-body{padding:16px;overflow:auto;min-width:0;display:grid;gap:14px;align-content:start}
.ugx-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;
          padding:12px 16px;border-top:1px solid var(--border-2,#eef0f6);
          background:var(--surface-2,#f2f4fb);min-width:0}
.ugx-foot .ugx-footnote{margin-inline-end:auto;font-size:11.5px;color:var(--ink-soft,#626c80);
                        line-height:1.45;min-width:0}

/* The live tile, so the thing being edited is visible while it is edited. */
.ugx-preview{display:grid;grid-template-columns:auto 1fr;gap:13px;align-items:center;min-width:0}
.ugx-preview .ugx-thumb{width:46px;border-radius:9px}
.ugx-previewname{display:block;font-size:13.5px;font-weight:670;color:var(--ink,#101729);
                 line-height:1.3;overflow-wrap:anywhere}
.ugx-previewmeta{display:block;font-size:11.5px;color:var(--ink-soft,#626c80);margin-top:3px;
                 line-height:1.45;overflow-wrap:anywhere}

/* ── numbered steps ──────────────────────────────────────────────────────
   "every step etc must be prominent, so user can understand better" — so a
   step is a numbered badge, a title and one line of why, not a small-caps
   label lost above a field. */
.ugx-step{display:grid;grid-template-columns:auto 1fr;gap:11px;align-items:start;margin-bottom:14px;
          min-width:0}
.ugx-step > *{min-width:0}
.ugx-stepno{width:25px;height:25px;border-radius:50%;display:grid;place-items:center;flex:0 0 auto;
            font-size:12px;font-weight:720;background:var(--accent-soft,#e7f7ee);
            color:var(--accent-ink,#0b6e3a);border:1px solid #bfe7cf}
.ugx-steptitle{display:block;font-size:14px;font-weight:670;color:var(--ink,#101729);
               line-height:1.3;letter-spacing:-.005em}
.ugx-stephint{display:block;font-size:12px;color:var(--ink-soft,#626c80);line-height:1.5;
              margin-top:3px;max-width:70ch}
/* A step that FOLLOWS a block of fields needs air, or its number collides with
   the help line of the field above it -- which is what the first render did. */
.ugx-fields + .ugx-step{margin-top:26px;padding-top:20px;
                        border-top:1px solid var(--border-2,#eef0f6)}

.ugx-listhead{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start;
              margin-bottom:14px;min-width:0}
.ugx-listhead > *{min-width:0}

.ugx-hero{text-align:start}
.ugx-steps-mini{margin:14px 0 0;padding:0 0 0 20px;display:grid;gap:7px;
                font-size:12.5px;color:var(--ink-soft,#626c80);line-height:1.55;max-width:66ch}
.ugx-steps-mini b{color:var(--ink,#101729);font-weight:650}

/* The shortcode, at a size somebody can read and copy. */
.ugx-shortcode{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;min-width:0;
               border:1px solid var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
               background:var(--surface-2,#f2f4fb);padding:11px 12px}
.ugx-shortcode code{font-family:var(--mono,ui-monospace,monospace);font-size:13px;
                    color:var(--ink,#101729);overflow-wrap:anywhere;min-width:0}

/* ── the library picker: a search, and rows a third of the old height ─────
   The old list drew each clip as a full .ugx-vid card with a 56px poster and
   14px of padding, so six clips filled a screen and sixty would have been
   unusable. These rows are 44px tall, so roughly three fit where one did, and
   the list is capped and scrolls rather than pushing the page down. */
.ugx-search{display:grid;grid-template-columns:auto 1fr auto;gap:9px;align-items:center;
            border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);
            padding:8px 11px;background:var(--surface,#fff);min-width:0;
            transition:border-color .16s var(--ease),box-shadow .16s var(--ease)}
.ugx-search:focus-within{border-color:var(--accent,#15a85a);
                         box-shadow:0 0 0 3px var(--accent-soft,#e7f7ee)}
.ugx-search svg{width:16px;height:16px;display:block;color:var(--ink-faint,#97a0b2);flex:0 0 auto}
.ugx-search input{border:0;background:transparent;font:inherit;font-size:13px;width:100%;min-width:0;
                  color:var(--ink,#101729);padding:0}
.ugx-search input:focus{outline:none}
.ugx-search input::placeholder{color:var(--ink-faint,#97a0b2)}

.ugx-picks{display:grid;gap:4px;margin-top:10px;min-width:0;
           max-height:min(52vh, 420px);overflow:auto;padding-inline-end:3px}
.ugx-pick{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;
          padding:5px 8px;border-radius:var(--r-xs,9px);min-width:0;border:1px solid transparent;
          transition:background .14s var(--ease),border-color .14s var(--ease)}
.ugx-pick:hover{background:var(--surface-2,#f2f4fb);border-color:var(--border,#e6e9f2)}
.ugx-pick > *{min-width:0}
.ugx-pick .ugx-thumb{width:24px;border-radius:5px;aspect-ratio:9/16;border:0}
.ugx-pickbody{display:grid;min-width:0}
.ugx-pickname{display:block;font-size:12.5px;font-weight:620;color:var(--ink,#101729);
              line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ugx-pickmeta{display:block;font-size:11px;color:var(--ink-soft,#626c80);line-height:1.35;
              white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ugx-pick.is-in{opacity:.62}

/* ── tagged products ────────────────────────────────────────────────────── */
.ugx-tagged{display:grid;gap:7px;min-width:0}
.ugx-tag{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;
         border:1px solid var(--border,#e6e9f2);border-radius:var(--r-xs,9px);padding:8px 11px;
         min-width:0;background:var(--surface,#fff);
         transition:border-color .16s var(--ease),background .16s var(--ease)}
.ugx-tag:hover{border-color:var(--ink-faint,#97a0b2);background:var(--surface-2,#f2f4fb)}
.ugx-tag > *{min-width:0}
.ugx-tagno{width:20px;height:20px;border-radius:6px;display:grid;place-items:center;flex:0 0 auto;
           font-size:10.5px;font-weight:700;background:var(--surface-2,#f2f4fb);
           color:var(--ink-soft,#626c80)}
.ugx-tag:first-child .ugx-tagno{background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a)}
.ugx-tagname{display:block;font-size:12.5px;line-height:1.35;overflow-wrap:anywhere;
             color:var(--ink,#101729);font-weight:620}
.ugx-tagbrand{display:block;font-size:11px;color:var(--ink-soft,#626c80);font-weight:500;margin-top:1px}
.ugx-results{display:grid;gap:6px;margin-top:8px;max-height:220px;overflow:auto;min-width:0;
             padding-inline-end:2px}
.ugx-empty{border:1px dashed var(--border,#e6e9f2);border-radius:var(--r-sm,12px);
           padding:18px;text-align:center;color:var(--ink-soft,#626c80);font-size:12.5px;
           background:var(--surface-2,#f2f4fb)}
</style>
<script>
(function () {
  'use strict';

  var SCREEN = 'ugcsections';

  var sections = [], library = [], vocab = null, maxTiles = 48, moduleOn = false;

  /* What is typed into the "Add from the library" search. Its own variable and
     not `term`, which belongs to the product search inside the clip dialog. */
  var libTerm = '';
  var editing = null;      /* the section open in the editor */
  var editingVideo = null; /* the clip open in the POPUP */

  /* The browser-side cover cut, on this screen too. Same two pieces of state
     the All-clips screen carries, for the same reason and with the same
     meanings -- see cutCoverHere() below. */
  var cutting = false;
  var cutStage = null;

  /* The timer that takes the finished bar off screen; held so a second cut
     cannot leave an earlier one's timeout to clear its bar out from under it. */
  var cutClear = null;
  var tagged = [], results = [], term = '';
  var banner = null, busy = false, seq = 0, searchTimer = null;

  /*
   * ── WHAT THIS SERVER WILL REALLY TAKE, AND WHY IT IS A VARIABLE ──────────
   *
   * THE DEFECT THE OWNER PHOTOGRAPHED. The Files tab printed "Up to 64MB" as a
   * string literal. 64 MB is UgcMedia::MAX_BYTES[KIND_CLIP] — the APP's own cap —
   * and on his server the real ceiling is 9.9 MB, because post_max_size is 10M
   * while upload_max_filesize is already 100M. The line was wrong by a factor of
   * six and a half, in the one place he reads before choosing a file.
   *
   * It is filled from the server's own answer — App\Support\ServerUploadLimits
   * computes min(app cap, upload_max_filesize, post_max_size less the multipart
   * overhead) and UgcVideoController::show() now returns it — and it is REFRESHED
   * from any refusal that carries a newer one, so a screen proved wrong about the
   * ceiling corrects itself in the same round trip.
   *
   * null means "not asked yet", and capNote() says nothing about a limit rather
   * than guessing one. There is deliberately no 64 fallback anywhere below.
   */
  var limits = null;

  /* The upload in flight, and the ending it leaves behind. See uploadHTML():
     BOTH endings stay on screen, because a panel that vanishes when the request
     lands is indistinguishable from a stall. */
  var upState = null, upDone = null, upHandle = null;

  /*
   * THE SECTION-LEVEL UPLOAD'S OWN THREE, SEPARATE FROM THE DIALOG'S ABOVE.
   *
   * Not shared, and that is deliberate: the dialog can be closed while this
   * upload is in flight, and one shared `upState` would mean the section panel's
   * bar and the clip editor's bar overwriting each other's percentage. Separate
   * data attributes too (data-ugx-nbar, not data-ugx-bar), so paintUpload() and
   * paintNewUpload() cannot find each other's nodes.
   */
  var newUp = null, newDone = null, newHandle = null;
  /* Teardowns for the drop zones mounted on the last repaint, so a re-render
     does not leave listeners on detached nodes. */
  var zoneOffs = [];

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(m) { try { window.toast(m); } catch (e) {} }

  /**
   * A stored media path, or ''. The same allowlist safeMedia() applies on the
   * All-clips screen, and the same argument: the server stores these through
   * UgcPath::stored(), which is the real guard, and this is the SECOND lock so
   * that a column edited by hand on the box cannot point a <video> or a canvas
   * at another host. Rule 5, at both ends.
   */
  function ugxStored(p) {
    p = String(p == null ? '' : p);

    return /^\/uploads\/ugc\/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:mp4|webm|jpg|jpeg|png|webp)$/.test(p)
      ? p : '';
  }

  /*
   * ── THE TOKEN THIS CONSOLE ACTUALLY USES ────────────────────────────────
   *
   * `X-XSRF-TOKEN`, read from the `XSRF-TOKEN` COOKIE that Laravel sets on
   * every response. That is what app.blade.php's own api() has always sent and
   * what ugc-library-screen.blade.php sends.
   *
   * THIS USED TO READ A <meta name="csrf-token"> TAG, AND THE ADMIN HAS NO SUCH
   * TAG. So the token was '' on every call, every write from this screen was
   * refused with 419 "CSRF token mismatch", and the screen reported it as
   * "That section could not be saved." -- a sentence that names the symptom and
   * hides the cause. Nobody could create a section: this path had never worked
   * on any shop, and the owner found it before any test did.
   */
  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  async function api(path, body, method) {
    var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined || method) {
      options.method = method || 'POST';
      options.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      if (body !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
      }
    }
    var response = await fetch(base() + path, options);
    var payload = null;
    try { payload = await response.json(); } catch (e) {}
    if (!response.ok) { throw { status: response.status, body: payload }; }
    return payload || {};
  }

  function explain(e, fallback) {
    if (e && e.status === 404) {
      return 'The Shoppable video endpoints are not in this server\'s compiled route table yet. '
           + 'Clear the route cache and reload.';
    }
    if (e && e.status === 403) { return 'Your account does not hold the Shoppable video permission.'; }
    if (e && e.status === 429) { return 'Too many uploads in one minute. Wait a moment — the file was fine.'; }
    if (e && e.status === 422 && e.body && e.body.errors) {
      return Object.keys(e.body.errors).map(function (k) { return e.body.errors[k][0]; }).join(' ');
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

  /* ------------------------------------------------ what this server will take */

  /* The described block for one kind, or null before the server has answered. */
  function capOf(kind) {
    return (limits && limits[kind] && typeof limits[kind].effective_bytes === 'number')
      ? limits[kind] : null;
  }

  /* The effective ceiling in BYTES, or 0 for "not known". The pre-flight refuses
     against this and never against the app's own cap — refusing against 64 MB is
     what let the owner's file sail past and be thrown away by PHP thirty seconds
     later. */
  function capBytes(kind) {
    var c = capOf(kind);
    return c ? c.effective_bytes : 0;
  }

  /*
   * THE SENTENCE UNDER A FILE ROW, AND IT NAMES THE SERVER WHERE THE SERVER IS
   * THE LIMIT.
   *
   * Three shapes, because the owner needs a different thing in each:
   *   - no answer yet: say nothing about size at all;
   *   - the app is the limit: one number, nothing to explain;
   *   - the SERVER is the limit: both numbers and the directive to raise, so he
   *     can see that the shop allows 64 MB and PHP is what stops him. That is
   *     what turns "why can I only upload 9.9 MB" into one line in a panel.
   */
  function capNote(kind) {
    var c = capOf(kind);
    if (!c) return '';

    if (!c.capped) return 'Up to ' + c.effective_label + '.';

    var ini = (limits && limits.server) ? limits.server[c.capped_by] : null;

    return 'Up to ' + c.effective_label + ' on this server. Shoppable video itself allows '
      + c.app_mb + ' MB — PHP’s ' + c.capped_by + ' is what caps it'
      + (ini ? ' (currently ' + ini + ')' : '') + ', and raising it on the server lifts this box '
      + 'with it.';
  }

  /*
   * THE UPLOAD PANEL: in flight, then finished, then refused.
   *
   * Every number is the upload event's own — see window.kbbUpload in
   * partials/upload-kit.blade.php, which owns the transport, the two stages, the
   * speed, the time remaining and the self-calibrating stall threshold, and hands
   * the composed sentence over as `text`. There is no indeterminate variant here,
   * because a bar that sweeps while nothing is known is a bar that lies.
   *
   * AND BOTH ENDINGS STAY. This screen used to throw the whole thing away the
   * instant the request landed — no bar, no tick, no reason, just a toast — so a
   * refused upload and a finished one looked identical, and a stalled one looked
   * like both.
   */
  function uploadHTML() {
    if (upState) {
      return '<div class="ugx-up' + (upState.stalled ? ' is-slow' : '') + '" data-ugx-upbox>'
        + '<div class="ugx-uph"><span class="ugx-upn">' + esc(upState.name) + '</span>'
        + '<span data-ugx-pct>' + esc(String(upState.pct)) + '%</span></div>'
        + '<div class="ugx-prog"><div class="ugx-progb" data-ugx-bar '
        + 'style="width:' + esc(String(upState.pct)) + '%"></div></div>'
        + '<div class="ugx-upm">'
        + '<span data-ugx-sent>' + esc(upState.sentText) + '</span>'
        + '<span data-ugx-stage>' + esc(upState.text || '') + '</span>'
        + '</div>'
        /* Drawn from the first byte rather than appearing once the screen has
           decided things are going badly: a control that materialises at a
           threshold is a control nobody finds. */
        + '<div class="ugx-upa"><button type="button" class="ugx-mini" '
        + 'data-ugx-upcancel="1">Cancel this upload</button></div>'
        + '</div>';
    }

    if (!upDone) return '';

    if (upDone.ok) {
      return '<div class="ugx-up">'
        + '<div class="ugx-uph"><span class="ugx-upn">' + esc(upDone.name) + '</span>'
        + '<span>&#10003; saved</span></div>'
        + '<div class="ugx-prog"><div class="ugx-progb" style="width:100%"></div></div>'
        + '<div class="ugx-upm"><span>' + esc(kb(upDone.bytes)) + ' arrived whole.</span>'
        + '<span>' + esc(upDone.note || '') + '</span></div>'
        + '</div>';
    }

    return '<div class="ugx-up is-bad">'
      + '<div class="ugx-uph"><span class="ugx-upn">' + esc(upDone.name) + '</span>'
      + '<span>not accepted</span></div>'
      + '<div class="ugx-upm"><span>' + esc(upDone.message) + '</span>'
      + '<span>' + esc(kb(upDone.bytes)) + ' — nothing on the clip was changed.</span></div>'
      /* Offered only where the kit kept the file, which is only where a second
         press could really work: 429, 5xx, a dropped connection, a cancel. A Try
         again beside "this server accepts at most 9.9 MB" is a button that cannot
         succeed, and a button that cannot succeed is a lie. */
      + (upDone.retry
          ? '<div class="ugx-upa"><button type="button" class="ugx-mini" data-ugx-upretry="1">'
            + 'Try again</button><span class="ugx-footnote">The file is still on your computer '
            + '— nothing needs choosing again.</span></div>'
          : '')
      + '</div>';
  }

  function forgetUpload() {
    if (upHandle) { try { upHandle.cancel(); } catch (e) {} }
    upState = null; upDone = null; upHandle = null;
  }

  /* ══════════════════════════ UPLOAD A NEW VIDEO INTO THIS SECTION ══════════ */

  /**
   * The control in the top-right of the "Add from the library" card head.
   *
   * A REAL <input type="file"> INSIDE A <label>, exactly as ugxFileRow() does it
   * and for the same three reasons: the input is what the change handler listens
   * to, a <label> is a genuine activator for its input in every browser so no
   * script forwards the click, and it stays keyboard-operable. The input is
   * visually hidden rather than removed — the browser's own "Choose File / No
   * file chosen" chrome is the one part of a file input that cannot be styled.
   *
   * THE `accept` IS A LITERAL IN THIS SOURCE, not a variable and not
   * concatenated. AdminMediaPickerEverywhereTest reads this FILE rather than the
   * rendered page: it sweeps for raw file inputs and treats one whose accept it
   * cannot see as an image picker that should have gone through the shared Media
   * Library. This one genuinely is a video picker — the library is an image
   * library and a 64 MB clip has no business in it — and it says so where the
   * guard can read it. ugxFileRow's own comment records that concatenating the
   * attribute made two of these invisible to the guard once already.
   */
  function newUploadControlHTML() {
    var note = capNote('clip');

    return '<label class="ugx-newup" data-ugx-zone="newclip" '
      + 'data-ugx-accept="video/mp4,video/webm">'
      + '<input type="file" accept="video/mp4,video/webm" data-ugx-newupload'
      + (busy ? ' disabled' : '') + '>'
      + '<span class="ugx-newupname">&#8593; Upload a new video</span>'
      + '<span class="ugx-newupmeta">Drop one here, or press to choose. It joins this section, '
      + 'the Clips tab and the Media Library.' + (note ? ' ' + esc(note) : '') + '</span>'
      + '</label>';
  }

  /**
   * The panel under that head: in flight, then what arrived, then refused.
   *
   * THE SAME THREE SHAPES uploadHTML() draws for the clip editor, because they
   * are the shapes that were measured and argued for — see the header of
   * partials/upload-kit.blade.php for the four defects behind them. What is added
   * here is the PREVIEW and the DRAFT NOTICE, which are the two things a clip
   * created out of nothing needs and a clip being edited does not: he has not
   * seen this file on this shop yet, and he has not been told it cannot go live.
   */
  function newUploadHTML() {
    if (newUp) {
      return '<div class="ugx-up' + (newUp.stalled ? ' is-slow' : '') + '" data-ugx-nupbox '
        + 'style="margin-top:12px">'
        + '<div class="ugx-uph"><span class="ugx-upn">' + esc(newUp.name) + '</span>'
        + '<span data-ugx-npct>' + esc(String(newUp.pct)) + '%</span></div>'
        + '<div class="ugx-prog"><div class="ugx-progb" data-ugx-nbar '
        + 'style="width:' + esc(String(newUp.pct)) + '%"></div></div>'
        + '<div class="ugx-upm">'
        + '<span data-ugx-nsent>' + esc(newUp.sentText) + '</span>'
        + '<span data-ugx-nstage>' + esc(newUp.text || '') + '</span>'
        + '</div>'
        + '<div class="ugx-upa"><button type="button" class="ugx-mini" '
        + 'data-ugx-nupcancel="1">Cancel this upload</button></div>'
        + '</div>';
    }

    if (!newDone) return '';

    if (newDone.ok) {
      /*
       * THE PREVIEW IS THE FILE THIS SHOP IS NOW SERVING, not the one on his
       * computer. That distinction is the whole value of it: a preview read out
       * of the local File object proves the browser can play the file, and a
       * preview read back off /uploads/ugc/ proves the SHOP can — which is the
       * thing that was in doubt. `preload="metadata"` and no autoplay: enough to
       * paint the first frame, and nothing that starts making noise in a back
       * office. The poster rides along where ffmpeg cut one.
       *
       * A URL FROM THE SERVER IS STILL SCHEME-CHECKED BEFORE IT BECOMES A src:
       * the endpoint returns it through UgcPath::stored(), which accepts
       * `/uploads/ugc/<one segment>` and nothing else, so a column edited by hand
       * cannot point this <video> anywhere. Rule 5, at both ends.
       */
      var pv = newDone.clip
        ? '<div class="ugx-newpv"><video src="' + esc(newDone.clip) + '" controls playsinline '
          + 'preload="metadata"' + (newDone.poster ? ' poster="' + esc(newDone.poster) + '"' : '')
          + '></video></div>'
        : '';

      return '<div class="ugx-up" style="margin-top:12px">'
        + '<div class="ugx-uph"><span class="ugx-upn">' + esc(newDone.title || newDone.name) + '</span>'
        + '<span>&#10003; added to this section</span></div>'
        + '<div class="ugx-prog"><div class="ugx-progb" style="width:100%"></div></div>'
        + '<div class="ugx-upm">'
        + '<span>' + esc(kb(newDone.bytes)) + ' arrived whole.</span>'
        + '<span>It is in the Clips tab and the Media Library too.</span>'
        + '</div>'
        + pv
        /*
         * SAID PLAINLY, BECAUSE THE ALTERNATIVE IS HIM THINKING IT IS LIVE.
         * A clip cannot be published without a cover and a granted permission —
         * UgcVideo::publishBlockers() decides, and the endpoint returns its
         * answer rather than this screen guessing at the rule. Printing the
         * server's own list is what keeps the two from drifting.
         */
        + '<div class="ugx-note is-warm" style="margin-top:10px">'
        + '<b>This clip is a draft.</b> It will not appear on the shop until it has a cover image '
        + 'and the creator’s permission is recorded.'
        + (newDone.blockers && newDone.blockers.length
            ? '<br>' + newDone.blockers.map(esc).join('<br>')
            : '')
        /*
         * WHAT THE SERVER COULD NOT DO, PRINTED HERE AND NOT ONLY TOASTED.
         *
         * On a box with ffmpeg the upload request cuts the cover and the teaser
         * and this is empty. On a box without one — and UgcTranscoder::available()
         * now answers false for a host that cannot start a program at all, not
         * merely one with no binary — derive() returns a sentence saying so, and
         * that sentence is the difference between "choose a cover and this goes
         * live" and an owner staring at a draft with no reason given.
         *
         * A toast is not enough for it: a toast is gone in four seconds and this
         * is the one thing standing between the clip and the shop. The server's
         * own words, escaped, beside the blockers they explain.
         */
        + ((newDone.notes && newDone.notes.length)
            ? '<br>' + newDone.notes.map(esc).join('<br>')
            : '')
        + '</div>'
        + '<div class="ugx-upa">'
        + '<button type="button" class="ugx-mini" data-ugx-edit="' + esc(String(newDone.id)) + '">'
        + 'Name it and add the cover</button>'
        + '<button type="button" class="ugx-mini" data-ugx-nupdismiss="1">Dismiss</button>'
        + '</div>'
        + '</div>';
    }

    return '<div class="ugx-up is-bad" style="margin-top:12px">'
      + '<div class="ugx-uph"><span class="ugx-upn">' + esc(newDone.name) + '</span>'
      + '<span>not accepted</span></div>'
      + '<div class="ugx-upm"><span>' + esc(newDone.message) + '</span>'
      + '<span>' + esc(kb(newDone.bytes)) + ' — no clip was created.</span></div>'
      /* Offered only where the kit kept the file, which is only where a second
         press could really work. A Try again beside "this server accepts at most
         9.9 MB" is a button that cannot succeed. */
      + (newDone.retry
          ? '<div class="ugx-upa"><button type="button" class="ugx-mini" data-ugx-nupretry="1">'
            + 'Try again</button><span class="ugx-footnote">The file is still on your computer '
            + '— nothing needs choosing again.</span></div>'
          : '<div class="ugx-upa"><button type="button" class="ugx-mini" '
            + 'data-ugx-nupdismiss="1">Dismiss</button></div>')
      + '</div>';
  }

  /**
   * One file, straight into the section being edited.
   *
   * `input` may be a file input (the click path) or a File (the drop path), so
   * there is one uploader and not two — the shape upload() above already uses.
   */
  function uploadNewClip(input) {
    var file = (input instanceof File) ? input
      : (input && input.files && input.files[0]) ? input.files[0] : null;

    var sectionId = editing && editing.id ? editing.id : null;

    if (!sectionId || !file) return;

    /*
     * THE PRE-FLIGHT, AGAINST THE CEILING THIS SERVER WILL REALLY HONOUR AND
     * NEVER AGAINST THE APP'S OWN 64 MB. App\Support\ServerUploadLimits answers
     * min(app cap, upload_max_filesize, post_max_size less the multipart
     * overhead); a file over it is discarded by PHP before the shop sees a byte,
     * and the refusal that follows blames the file. Refused here it costs nothing
     * and says why.
     */
    var ceiling = capBytes('clip');

    if (ceiling > 0 && file.size > ceiling) {
      newUp = null;
      newDone = {
        ok: false, name: file.name, bytes: file.size,
        message: 'That file is ' + kb(file.size) + ' and ' + capNote('clip').replace(/^Up to /, 'the most '
          + 'this box takes is ') + ' It was not sent, so no clip was created.',
        retry: null
      };
      say(newDone.message);
      render();
      return;
    }

    /*
     * THE KIT HAS TO BE ON THE PAGE, and saying so beats throwing.
     *
     * window.kbbUpload is defined by partials/upload-kit.blade.php. If that
     * @include is ever dropped from app.blade.php — or a stale compiled view
     * survives a package, which is what this release's clear_caches migration
     * exists to prevent — calling it raises "kbbUpload is not a function" INSIDE
     * this handler. Nothing catches that: `busy` stays true, the screen stays
     * locked, and the only symptom is a dead card. The same shape as the
     * swallowed write CLAUDE.md records for UpdateRunner.
     */
    if (typeof window.kbbUpload !== 'function') {
      say('The uploader is not loaded on this page. The admin console needs the upload kit '
        + 'partial — clear the view cache and reload; if it persists the package did not land.');
      busy = false; render();
      return;
    }

    newUp = { name: file.name, pct: 0, sentText: '', text: '', stalled: false };
    newDone = null;
    busy = true; render();

    newHandle = window.kbbUpload({
      url: base() + '/ugc-sections/' + encodeURIComponent(sectionId) + '/upload',
      file: file,
      field: 'file',
      /* A CONSTANT, not a setting — rule 5. On a box with ffmpeg the upload
         request really does cut the cover and the teaser before it answers. */
      serverNote: 'and cutting what it can',
      onProgress: function (st) {
        if (!newUp) return;
        newUp.pct = st.pct;
        newUp.sentText = st.loadedText + ' of ' + st.totalText + ' sent';
        newUp.text = st.text;
        newUp.stalled = st.stalled;
        paintNewUpload();
      },
      onDone: function (payload) {
        if (!payload || !payload.ok || !payload.video) {
          /* 2xx with ok:false is this endpoint's own shape, which the kit cannot
             judge. Not retryable: the server took the request and declined the
             file on its merits. */
          newUp = null; newHandle = null; busy = false;
          newDone = { ok: false, name: file.name, bytes: file.size,
                      message: (payload && payload.error) || 'That file was not accepted.',
                      retry: null };
          say(newDone.message);
          render();
          return;
        }

        var v = payload.video;

        newUp = null; newHandle = null;
        newDone = {
          ok: true, id: v.id, title: v.title, name: file.name, bytes: file.size,
          clip: v.file_path || '', poster: v.poster || '',
          blockers: v.blockers || [],
          /* The server's own sentences about what it could and could not cut.
             Kept on the panel, not only toasted — see the note where they are
             printed. */
          notes: payload.notes || []
        };

        (payload.notes || []).forEach(say);
        say('Added to this section as a draft.');

        /* Re-read the section and the library, so the list above and the picker
           below both hold the new clip — the counts and the poster come from the
           server and not from here. */
        openSection(sectionId).then(function () { return load(); });
      },
      onFail: function (f) {
        newUp = null; newHandle = null; busy = false;
        /*
         * THE SERVER'S OWN SENTENCE, INCLUDING FOR A 413. The kit's explain()
         * reads `body.error`, and App\Support\UploadArrival composes exactly
         * that key, so the honest sentences reach this panel unchanged. What does
         * NOT is a 413 from Laravel's global ValidatePostSize middleware, which
         * throws before the router and leaves no body at all — the kit handles
         * that status first and separately.
         */
        newDone = { ok: false, name: file.name, bytes: file.size,
                    message: f.message, retry: f.retryable ? file : null };
        say(f.message);
        render();
      }
    });
  }

  /*
   * THE BAR, WRITTEN STRAIGHT INTO THE DOM RATHER THAN THROUGH render().
   *
   * A repaint per progress event would rebuild the whole card, which throws away
   * the caret of anything being typed in the section's own fields and re-mounts
   * the drop zones. WRITING A WIDTH IS NOT MEASURING ONE — nothing here reads a
   * rect, an offset or a scroll position. Rule 4.
   */
  function paintNewUpload() {
    if (!newUp) return;

    var bar = document.querySelector('[data-ugx-nbar]');
    var pct = document.querySelector('[data-ugx-npct]');
    var sent = document.querySelector('[data-ugx-nsent]');
    var stage = document.querySelector('[data-ugx-nstage]');
    var box = document.querySelector('[data-ugx-nupbox]');

    if (bar) bar.style.width = newUp.pct + '%';
    if (pct) pct.textContent = newUp.pct + '%';
    if (sent) sent.textContent = newUp.sentText;
    if (stage) stage.textContent = newUp.text || '';
    /* A class write, not a measurement. */
    if (box) box.classList.toggle('is-slow', !!newUp.stalled);
  }

  /* ------------------------------------------------------------- the sidebar */

  /*
   * ── THE ONE FRONT DOOR ──────────────────────────────────────────────────
   *
   * The owner: "I really don't understand the videos rail section, it's really
   * confusing." Three sidebar rows — Content → Shoppable video, Content → Video
   * sections and Appearance → Video rail — were three doors into one feature,
   * and none of them said which was the way in. There is one row now, and these
   * are its tabs.
   *
   * The two screens that lost their row stay ROUTABLE by id, which is the same
   * thing this console already does for `blog` and `rev-capsule` — see the
   * TITLES comment in app.blade.php. #ugcvideo, ?go=ugcstyle and every existing
   * deep link still work.
   *
   * Each screen keeps a DISTINCT #ptitle, deliberately: all three guard their
   * own render() on that text, so two screens sharing a title would both answer
   * a single navigation and fight over #content. The tab strip is what tells
   * the owner they are in one place; the title tells the three screens apart.
   */
  /* On window, because the other two tabs live in their own IIFEs and each one
     has to draw the same strip. Defined here because this is the screen the one
     sidebar row opens, so it is the file that is always present. */
  window.kbbUgcTabs = function (active) {
    var tabs = [
      ['ugcsections', 'Sections'],
      ['ugcvideo', 'All clips'],
      ['ugcstyle', 'Appearance']
    ];

    return '<div class="subtabs ugx-seg" style="margin-bottom:16px">'
      + tabs.map(function (t) {
          return '<button class="subtab' + (t[0] === active ? ' on' : '') + '"'
            + ' data-ugc-tab="' + t[0] + '">' + t[1] + '</button>';
        }).join('')
      + '</div>';
  };

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-ugc-tab]') : null;
    if (!t) return;
    e.preventDefault();
    window.go(t.getAttribute('data-ugc-tab'));
  });

  function addNavEntry() {
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Shoppable video',
      icon: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="m9 14 4 2-4 2z"/>',
      group: 'Content',
      /* 'ugcvideo' is the LIBRARY screen's own id (ugc-library-screen.blade.php
         declares it), and the first draft said 'ugc' — which nothing registers,
         so AdminNavAndIdsTest failed on a dead anchor. Found by running it. */
      /* The library screen no longer registers a row of its own, so anchoring
                 to it would be a dead anchor — the mistake this comment used to
                 record. Anchored to the Content rows that do exist. */
                 after: ['media', 'htmlblocks', 'posts']
    });
  }

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
    if (title) title.textContent = 'Shoppable video';

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    editing = null; editingVideo = null;
    render();
    load();
    return undefined;
  };

  /* ------------------------------------------------------------------ reads */

  async function load() {
    var mine = ++seq;
    busy = true; banner = null; render();
    try {
      var body = await api('/ugc-sections');
      if (mine !== seq) return;
      sections = body.sections || [];
      library = body.library || [];
      vocab = body.vocabulary || null;
      maxTiles = body.max_tiles || 48;
      moduleOn = body.module_on === true;
    } catch (e) {
      if (mine !== seq) return;
      banner = explain(e, 'The sections could not be read.');
    } finally {
      if (mine === seq) { busy = false; render(); }
    }
  }

  async function openSection(id) {
    busy = true; render();
    try {
      editing = (await api('/ugc-sections/' + encodeURIComponent(id))).section;
    } catch (e) {
      banner = explain(e, 'That section could not be opened.');
    } finally {
      busy = false; render();
    }
  }

  async function openVideo(id) {
    busy = true; render();
    try {
      var body = await api('/ugc-videos/' + encodeURIComponent(id));
      /*
       * RESET THE TAB ONLY FOR A DIFFERENT CLIP. openVideo() is called again
       * right after a save, to pick up what the server made of the row — and
       * resetting here unconditionally threw the owner back to Details every
       * time he pressed Save from the Products tab.
       */
      if (openModalId !== body.video.id) { modalTab = 'details'; }

      editingVideo = body.video;
      /* THE REAL CEILING, straight from the server, so the Files tab can stop
         printing the app's 64 MB at somebody whose PHP will take 9.9. */
      if (body.limits) limits = body.limits;
      tagged = (body.video.products || []).map(function (p) {
        return { id: p.id, name: p.name, brand: p.brand };
      });
      results = []; term = '';
    } catch (e) {
      banner = explain(e, 'That video could not be opened.');
    } finally {
      busy = false; render();
    }
  }

  /* ----------------------------------------------------------------- writes */

  async function saveSection() {
    if (!editing || busy) return;
    busy = true; render();
    var payload = {
      title: editing.title || '',
      heading: editing.heading || null,
      subheading: editing.subheading || null,
      status: editing.status || 'draft',
      columns: editing.columns || null,
      locale: editing.locale || null,
      max_tiles: Number(editing.max_tiles) || 12,
      position: Number(editing.position) || 0
    };
    try {
      var out = editing.id
        ? await api('/ugc-sections/' + editing.id, payload, 'PUT')
        : await api('/ugc-sections', payload);
      say('Section saved.');
      editing = out.section;
      await load();
      if (editing.id) await openSection(editing.id);
    } catch (e) {
      banner = explain(e, 'That section could not be saved.');
      busy = false; render();
    }
  }

  async function saveOrder() {
    if (!editing || !editing.id || busy) return;
    busy = true; render();
    try {
      /* THE WHOLE LIST IN ONE WRITE. A per-row endpoint would let two drags
         interleave into an order neither operator asked for, and the pivot's unique
         index would make that a 500 halfway through rather than a refusal. */
      await api('/ugc-sections/' + editing.id + '/videos', {
        videos: (editing.videos || []).map(function (v) { return v.id; })
      });
      say('Order saved.');
      await openSection(editing.id);
      await load();
    } catch (e) {
      banner = explain(e, 'That order could not be saved.');
      busy = false; render();
    }
  }

  async function saveVideo() {
    if (!editingVideo || busy) return;
    busy = true; render();
    var v = editingVideo;
    try {
      await api('/ugc-videos/' + v.id, {
        title: v.title || '',
        caption: v.caption || null,
        status: v.status || 'draft',
        source_platform: v.source_platform || 'upload',
        source_url: v.source_url || null,
        creator_handle: v.creator_handle || null,
        creator_url: v.creator_url || null,
        rights_status: v.rights_status || 'pending',
        rights_evidence: v.rights_evidence || null,
        locale: v.locale || null,
        published_at: v.published_at || null
      }, 'PUT');

      /* `products: [{id, at_ms}]`, which is the shape
         UgcVideoController::tag() validates. POSITION IS THE ARRAY INDEX and is
         not sent: that endpoint derives it from the order, so sending one would be
         a second source of truth for the same thing. at_ms is player D's and
         nobody else's — null everywhere, so choosing any other player never asks
         anybody to type a timestamp. */
      await api('/ugc-videos/' + v.id + '/products', {
        products: tagged.map(function (p) { return { id: p.id, at_ms: null }; })
      });

      say('Video saved.');

      /*
       * ── SAVED, THEN CLOSED ─────────────────────────────────────────────
       *
       * "when hit save on popup, it should save all steps together and close
       * the popup automatically."
       *
       * BOTH HALVES ALREADY SAVED TOGETHER and that was never the gap: the PUT
       * above carries every field from Details, Source and Placement, the call
       * after it carries the Products tab, and the Files tab writes on upload
       * rather than on save. One press has always persisted the lot.
       *
       * What it then did was re-open the dialog on the row it had just saved,
       * which reads as "nothing happened" -- the owner pressed Save and was
       * left looking at the same popup. It closes now, and the lists behind it
       * refresh so the row he just edited is up to date underneath.
       *
       * openVideo() is gone from this path rather than kept and closed after:
       * it re-fetches the clip only to throw the result away, which on his
       * server is a round trip spent on nothing.
       */
      editingVideo = null;
      if (editing && editing.id) await openSection(editing.id);
      await load();
    } catch (e) {
      banner = explain(e, 'That video could not be saved.');
      busy = false; render();
    }
  }

  async function search() {
    if (!term) { results = []; render(); return; }
    try {
      results = (await api('/ugc-videos/products?q=' + encodeURIComponent(term))).products || [];
    } catch (e) {
      results = [];
    }
    render();
  }

  /*
   * ONE FILE, WITH A REAL PROGRESS BAR AND AN HONEST ENDING.
   *
   * ── WHAT THIS REPLACES ────────────────────────────────────────────────────
   *
   * A `fetch` with a FormData and a spinner. fetch reports NOTHING about a
   * request body in flight — there is no upload-progress event on it — so this
   * screen could not have had a bar however much it wanted one. That is the
   * whole reason window.kbbUpload is XMLHttpRequest.
   *
   * It also meant a 9 MB upload showed a disabled panel for thirty seconds with
   * no percentage, no speed, no time remaining and no Cancel, and then either a
   * toast saying "Uploaded." or a toast saying "That file was not accepted." —
   * for a file that was, on the owner's server, perfectly fine.
   *
   * `input` may be a file input (the click path) or a File (the drop path). Both
   * arrive here, so there is one uploader and not two.
   */
  function upload(kind, input) {
    var file = (input instanceof File) ? input
      : (input && input.files && input.files[0]) ? input.files[0] : null;

    if (!editingVideo || !file) return;

    /*
     * THE PRE-FLIGHT, AGAINST THE CEILING THIS SERVER WILL REALLY HONOUR AND
     * NEVER AGAINST THE APP'S OWN 64 MB.
     *
     * This is the refusal that was missing. The owner's 8.4 MB file was under
     * this server's 9.9 MB, so it sent — but a 12 MB one would have sailed past a
     * 64 MB check, been discarded by PHP before the shop saw a byte, and come
     * back blaming the file. Refused here it costs nothing and says why.
     */
    var ceiling = capBytes(kind);

    if (ceiling > 0 && file.size > ceiling) {
      upState = null;
      upDone = {
        ok: false, kind: kind, name: file.name, bytes: file.size,
        message: 'That file is ' + kb(file.size) + ' and ' + capNote(kind).replace(/^Up to /, 'the most this '
          + 'box takes is ') + ' It was not sent, so nothing on the clip was changed.',
        /* No Try again: the same file over the same ceiling fails identically. */
        retry: null
      };
      say(upDone.message);
      render();
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

    upState = { kind: kind, name: file.name, pct: 0, sentText: '', text: '', stalled: false };
    /* The last ending is cleared before this one starts, so a red panel from a
       refused attempt never sits under a fresh bar. */
    upDone = null;
    busy = true; render();

    /*
     * THE CLIP THIS UPLOAD BELONGS TO, READ ONCE — not `editingVideo.id` inside
     * the callbacks. Closing the dialog mid-upload sets editingVideo to null, and
     * a callback that then reads `.id` throws inside the handler, which unlocks
     * nothing and leaves the screen with busy still true.
     */
    var target = editingVideo.id;
    var sectionId = editing && editing.id ? editing.id : null;

    upHandle = window.kbbUpload({
      url: base() + '/ugc-videos/' + encodeURIComponent(target) + '/media',
      file: file,
      field: 'file',
      extra: { kind: kind },
      /* A CONSTANT, not a setting — rule 5. On a box with ffmpeg the upload
         request really does cut the cover and the teaser before it answers. */
      serverNote: 'and cutting what it can',
      onProgress: function (s) {
        if (!upState) return;
        upState.pct = s.pct;
        upState.sentText = s.loadedText + ' of ' + s.totalText + ' sent';
        upState.text = s.text;
        upState.stalled = s.stalled;
        paintUpload();
      },
      onDone: function (payload) {
        if (payload && payload.limits) limits = payload.limits;

        if (!payload || !payload.ok) {
          /* 2xx with ok:false is this endpoint's own shape, which the kit cannot
             judge. Not retryable: the server took the request and declined the
             file on its merits. */
          upState = null; upHandle = null; busy = false;
          upDone = { ok: false, kind: kind, name: file.name, bytes: file.size,
                     message: (payload && payload.error) || 'That file was not accepted.',
                     retry: null };
          say(upDone.message);
          render();
          return;
        }

        upState = null; upHandle = null;
        upDone = { ok: true, kind: kind, name: file.name, bytes: file.size,
                   note: kind === 'clip' ? 'This is the video the tile plays.'
                                         : 'This is the file the tile loops.' };
        (payload.notes || []).forEach(say);
        say('Uploaded.');

        /* Re-read the row, then the section, exactly as before — the sizes and
           the poster on the card come from the server and not from here. */
        openVideo(target).then(function () {
          return sectionId ? openSection(sectionId) : undefined;
        });
      },
      onFail: function (f) {
        upState = null; upHandle = null; busy = false;
        /*
         * THE SERVER'S OWN SENTENCE, INCLUDING FOR A 413.
         *
         * explain() below ends in `e.body.error`, and App\Support\UploadArrival
         * composes exactly that key — so the honest sentences DO reach this
         * screen unchanged, which was worth verifying rather than assuming.
         *
         * WHAT DOES NOT reach it is a 413 from Laravel's GLOBAL
         * ValidatePostSize middleware: it throws PostTooLargeException before the
         * router, so no controller composes a body, there is no `error` key, and
         * `e.body.error` is undefined. That is precisely why this screen has been
         * answering "That file was not accepted." for files that were fine. The
         * kit's explain() handles that status first and separately.
         */
        upDone = { ok: false, kind: kind, name: file.name, bytes: file.size,
                   message: f.message, retry: f.retryable ? file : null };
        say(f.message);
        render();
      }
    });
  }

  /*
   * THE BAR, WRITTEN STRAIGHT INTO THE DOM RATHER THAN THROUGH render().
   *
   * A repaint per progress event would rebuild the whole dialog, which throws
   * away the caret of anything being typed in another tab and re-mounts the
   * poster preview. Writing a width is not measuring one — nothing here reads a
   * rect, an offset or a scroll position.
   */
  function paintUpload() {
    if (!upState) return;

    var bar = document.querySelector('[data-ugx-bar]');
    var pct = document.querySelector('[data-ugx-pct]');
    var sent = document.querySelector('[data-ugx-sent]');
    var stage = document.querySelector('[data-ugx-stage]');
    var box = document.querySelector('[data-ugx-upbox]');

    if (bar) bar.style.width = upState.pct + '%';
    if (pct) pct.textContent = upState.pct + '%';
    if (sent) sent.textContent = upState.sentText;
    if (stage) stage.textContent = upState.text || '';
    /* A class write, not a measurement. */
    if (box) box.classList.toggle('is-slow', !!upState.stalled);
  }

  /*
   * A POSTER DROPPED ONTO ITS ROW, WHICH GOES THROUGH THE MEDIA LIBRARY.
   *
   * THE OWNER'S RULE, in his words: "on any upload media on the whole backend,
   * the media library is a must to show." So a dragged image is uploaded to the
   * LIBRARY's own endpoint and then adopted as the poster by URL — which is
   * exactly what the Choose button does through window.kbbPickMedia, and it is
   * why there is no <input type="file"> with an image accept anywhere on this
   * screen for AdminMediaPickerEverywhereTest to find.
   */
  /**
   * Take the cover out of the video, in this browser, from the popup.
   * ═══════════════════════════════════════════════════════════════════════
   *
   * THE SAME THING THE All-clips SCREEN DOES, because the owner reached this
   * dialog by adding a video to a section and found no way to get a cover:
   * "while adding video directly inside section, there should also the same
   * cover cut functionality in browser, and should have button."
   *
   * He is right, and the asymmetry was an oversight rather than a decision.
   * The two screens edit the same clips through the same endpoints; a cover he
   * can take on one and not the other is a trap.
   *
   * IT GOES THROUGH dropPoster() BELOW, exactly as the other screen goes
   * through its own: that path already enforces this server's real ceiling,
   * uploads to the Media Library and adopts the result. A canvas blob is a File
   * with a name, so it walks in through the same door as a dragged picture --
   * and the server sniffs the real MIME with finfo, so it is checked the same
   * way too. Nothing here is a second implementation of anything.
   *
   * The frame comes from window.kbbPosterFromVideo (upload-kit.blade.php),
   * which is where the whole argument for the browser doing this lives.
   */
  function cutCoverHere() {
    if (!editingVideo || !editingVideo.id) { say('Open a clip first.'); return; }

    if (typeof window.kbbPosterFromVideo !== 'function') {
      say('This console is missing its upload kit; reload the page.');

      return;
    }

    // The clip this shop is serving. ugxStored() is the same allowlist the
    // <video> in this dialog goes through, so a hand-edited column cannot
    // point the canvas at another host.
    var source = ugxStored(editingVideo.file_path);

    if (!source) { say('There is no video on this clip to take a frame from.'); return; }

    cutting = true;
    if (cutClear) { clearTimeout(cutClear); cutClear = null; }
    cutStage = { pct: 0, words: 'Starting' };
    renderModal();

    window.kbbPosterFromVideo(source, {
      onStage: function (pct, words) {
        cutStage = { pct: pct, words: words };
        paintCutBar();
      }
    })
      .then(function (blob) {
        cutting = false;
        cutStage = { pct: 80, words: 'Saving the cover' };
        paintCutBar();

        var stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '');
        var name = 'cover-' + (editingVideo.slug || editingVideo.id) + '-' + stamp + '.jpg';

        dropPoster(new File([blob], name, { type: 'image/jpeg' }));
      })
      .catch(function (e) {
        cutting = false;
        cutStage = null;
        say((e && e.message) ? e.message : 'The cover could not be taken from this video here.');
        renderModal();
      });
  }

  /** The cut's bar. Empty unless one is running. */
  function cutBarHTML() {
    if (!cutStage) return '';

    return '<div class="ugx-up" data-ugx-cutbar style="margin-top:10px">'
      + '<div class="ugx-uph"><span class="ugx-upn">Taking the cover</span>'
      + '<span data-ugx-cutpct>' + esc(String(cutStage.pct)) + '%</span></div>'
      + '<div class="ugx-prog"><div class="ugx-progb" data-ugx-cutfill '
      + 'style="width:' + esc(String(cutStage.pct)) + '%"></div></div>'
      + '<div class="ugx-upm"><span data-ugx-cutwords>' + esc(cutStage.words) + '</span></div>'
      + '</div>';
  }

  /* Moves the bar in place: a full renderModal() on every stage would throw
     away the <video> element mid-decode, which is the thing being read from. */
  function paintCutBar() {
    var box = document.querySelector('[data-ugx-cutbar]');

    if (!box) { renderModal(); return; }

    var fill = box.querySelector('[data-ugx-cutfill]');
    var pct = box.querySelector('[data-ugx-cutpct]');
    var words = box.querySelector('[data-ugx-cutwords]');

    if (fill) fill.style.width = cutStage.pct + '%';
    if (pct) pct.textContent = cutStage.pct + '%';
    if (words) words.textContent = cutStage.words;
  }

  function dropPoster(file) {
    if (!editingVideo || !file) return;

    var ceiling = capBytes('poster');

    if (ceiling > 0 && file.size > ceiling) {
      upState = null;
      upDone = { ok: false, kind: 'poster', name: file.name, bytes: file.size,
                 message: 'That picture is ' + kb(file.size) + ' and the most this box takes is '
                   + (capOf('poster') ? capOf('poster').effective_label : '') + '. It was not sent, '
                   + 'so nothing on the clip was changed.',
                 retry: null };
      say(upDone.message);
      render();
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

    upState = { kind: 'poster', name: file.name, pct: 0, sentText: '', text: '', stalled: false };
    upDone = null;
    busy = true; render();

    var target = editingVideo.id;
    var sectionId = editing && editing.id ? editing.id : null;

    upHandle = window.kbbUpload({
      url: base() + '/media/upload',
      file: file,
      field: 'file',
      extra: { folder: 'posters' },
      serverNote: 'and adding it to the Media Library',
      onProgress: function (s) {
        if (!upState) return;
        upState.pct = s.pct;
        upState.sentText = s.loadedText + ' of ' + s.totalText + ' sent';
        upState.text = s.text;
        upState.stalled = s.stalled;
        paintUpload();
      },
      onDone: function (payload) {
        var url = payload && (payload.url || (payload.media && payload.media.url));

        if (!url) {
          upState = null; upHandle = null; busy = false;
          upDone = { ok: false, kind: 'poster', name: file.name, bytes: file.size,
                     message: (payload && payload.error) || 'That picture was uploaded but the '
                       + 'library did not return a URL for it, so the poster was not changed.',
                     retry: null };
          say(upDone.message);
          render();
          return;
        }

        /* The adopt step, by URL, exactly as the picker's path does it. */
        api('/ugc-videos/' + encodeURIComponent(target) + '/poster', { url: url })
          .then(function () {
            upState = null; upHandle = null;
            upDone = { ok: true, kind: 'poster', name: file.name, bytes: file.size,
                       note: 'It is in the Media Library too.' };

            /*
             * ── THE CUT'S BAR FINISHES HERE, AND ONLY HERE ─────────────────
             *
             * THE DEFECT. cutCoverHere() set the bar to 80% "Saving the cover"
             * and handed the file to dropPoster(), and NOTHING ever moved it
             * again. The cover arrived -- the Poster row showed 134 KB -- and
             * the bar sat at 80% underneath it for good. The owner reported
             * exactly that: "saving the cover step stucks. and nothing
             * proceeding further."
             *
             * It was my oversight: the All-clips screen got this completion
             * and this screen did not, which is the cost of writing the same
             * feature twice.
             *
             * It completes on the REAL event -- the adopt call returning --
             * rather than on a timer, so 100% means the cover is genuinely on
             * the clip and not merely that some milliseconds have passed.
             */
            cutStage = { pct: 100, words: 'Cover set' };
            paintCutBar();

            if (cutClear) clearTimeout(cutClear);
            cutClear = setTimeout(function () {
              cutStage = null;
              cutClear = null;
              renderModal();
            }, 1800);

            say('Poster set.');
            return openVideo(target).then(function () {
              return sectionId ? openSection(sectionId) : undefined;
            });
          })
          .catch(function (err) {
            // A bar stuck at 80 under a refusal is the same defect wearing a
            // different colour.
            cutStage = null;
            if (cutClear) { clearTimeout(cutClear); cutClear = null; }
            upState = null; upHandle = null; busy = false;
            upDone = { ok: false, kind: 'poster', name: file.name, bytes: file.size,
                       message: explain(err, 'That picture could not be used as a poster.'),
                       retry: null };
            render();
          });
      },
      onFail: function (f) {
        upState = null; upHandle = null; busy = false;
        upDone = { ok: false, kind: 'poster', name: file.name, bytes: file.size,
                   message: f.message, retry: f.retryable ? file : null };
        say(f.message);
        render();
      }
    });
  }

  function pickPoster() {
    if (!editingVideo) return;
    /* THE POSTER'S ONLY WAY IN. The owner's rule is that the Media Library must be
       offered for any media upload in the back office, and
       AdminMediaPickerEverywhereTest scans every admin Blade for a raw file input
       to enforce it. The clip and the teaser keep theirs and are excluded by what
       they accept — the library is an image library, and a 64MB clip has no
       business in it. */
    if (typeof window.kbbPickMedia !== 'function') {
      banner = 'The Media Library picker is not loaded on this page.';
      render();
      return;
    }

    window.kbbPickMedia({
      /* The library's own folder for these, NOT 'ugc'. /uploads/ugc/ is this
         module's directory — the one UgcPath::stored() allows and UgcMedia::forget()
         deletes from — and a library upload landing in it would put files this module
         may delete beside files it owns. */
      folder: 'posters',
      title: 'Choose a poster',
      note: 'The still the tile shows before anything moves — and all it shows when there is no loop.',
      onPick: function (urls) {
        /* onPick always receives an ARRAY, even for a single-select call —
           media-picker.blade.php says so in its own docblock, so a caller cannot be
           written against the wrong shape and work by accident. */
        if (!urls || !urls.length) return;

        busy = true; render();

        /* `url`, not `path`: the key UgcVideoController::poster() reads. Getting it
           wrong is a 422 that reads like a rejected image. */
        api('/ugc-videos/' + editingVideo.id + '/poster', { url: urls[0] })
          .then(function () {
            say('Poster set.');
            return openVideo(editingVideo.id);
          })
          .then(function () { if (editing && editing.id) return openSection(editing.id); })
          .catch(function (err) {
            banner = explain(err, 'That picture could not be used as a poster.');
            busy = false; render();
          });
      }
    });
  }

  /* ----------------------------------------------------------------- render */

  function selectHTML(name, options, value, labels) {
    return '<select data-ugx-field="' + esc(name) + '">'
      + options.map(function (o) {
        var key = o === null ? '' : o;
        var label = labels && labels[key] !== undefined ? labels[key] : (o === null ? '— both languages —' : o);
        return '<option value="' + esc(key) + '"'
          + ((value || '') === key ? ' selected' : '') + '>' + esc(label) + '</option>';
      }).join('')
      + '</select>';
  }

  function pillsFor(v) {
    var out = '';
    out += v.status === 'publish'
      ? '<span class="ugx-pill is-live">Published</span>'
      : '<span class="ugx-pill">Draft</span>';
    if (v.rights_status !== 'granted') {
      out += '<span class="ugx-pill is-hold">Permission ' + esc(v.rights_status) + '</span>';
    }
    /* NOT "Poster only", which read as a fault and is not one: a clip with no
       cut-down loop file still loops, from the full video. Measured. The pill
       is neutral and says what it is — an optimisation that has not been
       applied — rather than amber, which meant "something is wrong here". */
    if (v.media_state === 'poster_only') out += '<span class="ugx-pill">Loops from full video</span>';
    if (v.media_state === 'none') out += '<span class="ugx-pill is-hold">No files</span>';
    out += '<span class="ugx-pill">' + esc(String(v.products_count || 0)) + ' products</span>';
    return out;
  }

  function listHTML() {
    if (!sections.length) {
      /* THE EMPTY STATE IS THE INSTRUCTIONS. The owner asked for "every step
         prominent" — so the three things a section needs are the three things
         he reads here, numbered, rather than one paragraph he has to parse. */
      return '<div class="ugx-card ugx-hero">'
        + '<div class="ugx-title" style="font-size:17px">Put a rail of shoppable videos anywhere</div>'
        + '<p class="ugx-sub">A section is a named, ordered set of clips. You make one, fill it, and paste '
        + 'its shortcode wherever you want the rail — as many places as you like.</p>'
        + '<ol class="ugx-steps-mini">'
        + '<li><b>Name it.</b> Only you ever see the name.</li>'
        + '<li><b>Add clips</b> from your library, in the order they should appear.</li>'
        + '<li><b>Paste its shortcode</b> into a page, a post or an HTML block.</li>'
        + '</ol>'
        + '<div class="ugx-actions"><button class="ugx-btn is-primary" data-ugx-new>'
        + 'Make the first section</button></div></div>';
    }

    return '<div class="ugx-card"><div class="ugx-listhead">'
      + '<div><div class="ugx-title">Video sections</div>'
      + '<p class="ugx-sub">Each one is a rail you can place anywhere with its shortcode.</p></div>'
      + '<button class="ugx-btn is-primary" data-ugx-new>New section</button></div>'
      + '<div class="ugx-rows" style="margin-top:14px">'
      + sections.map(function (s) {
        return '<div class="ugx-row"><div>'
          + '<div class="ugx-rowname">' + esc(s.title) + '</div>'
          + '<div class="ugx-rowmeta">' + esc(String(s.videos_count)) + ' video'
          + (s.videos_count === 1 ? '' : 's')
          + ' · at most ' + esc(String(s.max_tiles)) + ' shown'
          + (s.locale ? ' · ' + esc(s.locale) + ' only' : '')
          + (s.columns ? ' · columns: ' + esc(s.columns) : '') + '</div>'
          + '<div class="ugx-pills">'
          + (s.status === 'publish'
              ? '<span class="ugx-pill is-live">Published</span>'
              : '<span class="ugx-pill">Draft</span>')
          + '</div>'
          + '<code class="ugx-code">' + esc(s.shortcode) + '</code>'
          + '</div><div class="ugx-rowacts">'
          + '<button class="ugx-mini" data-ugx-open="' + esc(String(s.id)) + '">Open</button>'
          + '<button class="ugx-mini" data-ugx-copy="' + esc(s.shortcode) + '">Copy shortcode</button>'
          + '<button class="ugx-mini" data-ugx-delete="' + esc(String(s.id)) + '">Delete</button>'
          + '</div></div>';
      }).join('')
      + '</div></div>';
  }

  function editorHTML() {
    var s = editing;
    var videos = s.videos || [];
    var inSection = {};
    videos.forEach(function (v) { inSection[v.id] = true; });

    /* Title OR creator handle, case-folded, substring. Deliberately not fuzzy:
       an owner searching "noor" wants the clips by @noor.routine, and a fuzzy
       match that also returned "no drama" would be worse than no search. */
    var needle = libTerm.trim().toLowerCase();
    var matches = needle === '' ? library : library.filter(function (v) {
      return (String(v.title || '') + ' ' + String(v.handle || '')).toLowerCase().indexOf(needle) !== -1;
    });

    /* Its OWN header, not the dialog's .ugx-mh -- that one is a three-column
       grid built for the popup, and the back button landed in its 1fr cell and
       stretched across half the card. */
    return '<div class="ugx-card"><div class="ugx-eh">'
      + '<button class="ugx-back-link" data-ugx-back>'
      + '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M12 4.5 6.5 10l5.5 5.5"/></svg>All sections</button>'
      + '<div class="ugx-title">' + esc(s.title || 'New section') + '</div>'
      + '</div>'

      /*
       * ── THREE NUMBERED STEPS, NOT ONE FORM ────────────────────────────────
       *
       * The owner: "i don't like the default add section page, i want something
       * focused and user friendly and without classic look, with more prominent
       * every step etc." What was here threw name, heading, status, columns,
       * language and tile cap at him in one undifferentiated block, and buried
       * the shortcode — the one thing that actually puts the rail on the shop —
       * as a small grey chip beside the title.
       *
       * The steps are the three things a section genuinely needs, in the order
       * it needs them, and each one says what it is for.
       */
      + ugxStep(1, 'Name it', 'Only you see the name. The heading is what a shopper reads above the rail.')
      + '<div class="ugx-fields">'
      + '<div class="ugx-f"><label>Name (yours, never shown on the shop)</label>'
      + '<input type="text" data-ugx-field="title" value="' + esc(s.title || '') + '" autocomplete="off"'
      + ' placeholder="Homepage rail"></div>'
      + '<div class="ugx-two">'
      + '<div class="ugx-f"><label>Heading on the shop</label>'
      + '<input type="text" data-ugx-field="heading" value="' + esc(s.heading || '') + '" autocomplete="off"'
      + ' placeholder="Shop the look">'
      + '<p class="ugx-help">Leave empty and no heading is drawn at all.</p></div>'
      + '<div class="ugx-f"><label>Line under the heading</label>'
      + '<input type="text" data-ugx-field="subheading" value="' + esc(s.subheading || '') + '"'
      + ' autocomplete="off" placeholder="Real routines from the people who use them."></div>'
      + '</div></div>'

      + ugxStep(2, 'Decide how it looks and who sees it',
          'Every one of these can be left alone — the rail follows Appearance → Video rail by default.')
      + '<div class="ugx-fields">'
      + '<div class="ugx-two">'
      + '<div class="ugx-f"><label>Status</label>'
      + selectHTML('status', (vocab && vocab.status) || ['draft', 'publish'], s.status,
          { draft: 'Draft — not on the shop', publish: 'Published' }) + '</div>'
      + '<div class="ugx-f"><label>Tiles across on a phone</label>'
      + selectHTML('columns', [null].concat(Object.keys((vocab && vocab.columns) || {})), s.columns,
          Object.assign({ '': '— follow Appearance → Video rail —' }, (vocab && vocab.columns) || {}))
      + '</div>'
      + '</div>'
      + '<div class="ugx-two">'
      + '<div class="ugx-f"><label>Language</label>'
      + selectHTML('locale', [null].concat((vocab && vocab.locales) || ['en', 'ar']), s.locale,
          { '': '— both storefronts —', en: 'English only', ar: 'Arabic only' }) + '</div>'
      + '<div class="ugx-f"><label>At most this many tiles</label>'
      + '<input type="number" min="1" max="' + esc(String(maxTiles)) + '" data-ugx-field="max_tiles" value="'
      + esc(String(s.max_tiles || 12)) + '"></div>'
      + '</div>'
      + '</div>'
      + '<div class="ugx-actions" style="margin-top:16px">'
      + '<button class="ugx-btn is-primary" data-ugx-save' + (busy ? ' disabled' : '') + '>Save section</button>'
      + '</div></div>'

      /*
       * ── STEP 4 FIRST ON THE PAGE WHEN IT EXISTS ────────────────────────
       * The shortcode is the whole point of a section and it was a grey chip.
       * It is a step of its own now, with the line to copy at a size somebody
       * can actually read and copy.
       */
      /* ── the list of clips inside it, which is the thing he asked for ──── */
      + '<div class="ugx-card">'
      + ugxStep(3, 'Put clips in it', 'In the order they appear on the shop. Click one to edit it in a popup.')
      + (videos.length
          ? '<div class="ugx-rows" style="margin-top:12px">' + videos.map(function (v, i) {
              return '<div class="ugx-vid">'
                + '<div class="ugx-thumb">' + (v.poster
                    ? '<img src="' + esc(v.poster) + '" alt="" loading="lazy">'
                    : 'no poster') + '</div>'
                + '<div><div class="ugx-rowname">' + esc(v.title || v.slug) + '</div>'
                + '<div class="ugx-rowmeta"><bdi>' + esc(v.handle || '—') + '</bdi></div>'
                + '<div class="ugx-pills">' + pillsFor(v) + '</div>'
                + (v.blockers && v.blockers.length
                    ? '<div class="ugx-note is-warm" style="margin-top:8px">' + v.blockers.map(esc).join(' ') + '</div>'
                    : '')
                + '</div>'
                + '<div class="ugx-rowacts">'
                + '<button class="ugx-mini" data-ugx-edit="' + esc(String(v.id)) + '">Edit</button>'
                + '<button class="ugx-mini" data-ugx-up="' + i + '"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>'
                + '<button class="ugx-mini" data-ugx-down="' + i + '"'
                + (i === videos.length - 1 ? ' disabled' : '') + '>&darr;</button>'
                + '<button class="ugx-mini is-danger" data-ugx-remove="' + i + '">Remove</button>'
                + '</div></div>';
            }).join('') + '</div>'
          : '<p class="ugx-sub" style="margin-top:10px">Nothing in it yet.</p>')
      + '<div class="ugx-actions">'
      + '<button class="ugx-btn is-primary" data-ugx-order' + (busy ? ' disabled' : '') + '>Save this order</button>'
      + '</div></div>'

      /* ── and the library to fill it from ──────────────────────────────── */
      /*
       * SEARCHED AND DENSE. The owner: "I want here proper search option to
       * search videos and add. also squeeze this list, so more videos can be
       * show." Six clips filled a screen; a shop with sixty would be unusable.
       *
       * The filter is done HERE, over the library already loaded, rather than
       * by asking the server on every keystroke: this list is the whole library
       * and it is already in memory, so a round trip would be slower and would
       * spend a query per character for an answer the browser already holds.
       */
      + '<div class="ugx-card"><div class="ugx-eh" style="margin-bottom:12px">'
      /*
       * ── THE HEAD IS A ROW NOW: TITLE LEFT, UPLOAD RIGHT ────────────────
       *
       * The owner drew a red box in the top-right of this card and asked for
       * "the upload new video function, instead of going to clips tab
       * specially". This is that box. One drop and the clip exists, is in THIS
       * section, is in the Clips tab and is in the Media Library — the panel
       * underneath says so, with the live bar and the playable preview the clip
       * editor already has.
       */
      + '<div class="ugx-ehtop">'
      + '<div class="ugx-ehtitle">'
      + '<div class="ugx-title">Add from the library</div>'
      + '<p class="ugx-sub">A clip can be in as many sections as you like — the same file and the same '
      + 'like count in each.</p></div>'
      + newUploadControlHTML()
      + '</div>'
      + newUploadHTML()
      + '</div>'
      + (library.length
          ? '<div class="ugx-search">'
            + '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" '
            + 'stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/>'
            + '<path d="m13.2 13.2 3.3 3.3"/></svg>'
            + '<input type="text" data-ugx-libsearch value="' + esc(libTerm) + '" autocomplete="off"'
            + ' placeholder="Search ' + library.length + ' clips by title or creator">'
            + (libTerm ? '<button class="ugx-mini" data-ugx-libclear>Clear</button>' : '')
            + '</div>'
            + (matches.length
                ? '<div class="ugx-picks">' + matches.map(function (v) {
                    var already = inSection[v.id];
                    return '<div class="ugx-pick' + (already ? ' is-in' : '') + '">'
                      + '<span class="ugx-thumb">' + (v.poster
                          ? '<img src="' + esc(v.poster) + '" alt="" loading="lazy">'
                          : '') + '</span>'
                      + '<span class="ugx-pickbody"><span class="ugx-pickname">'
                      + esc(v.title || ('#' + v.id)) + '</span>'
                      + '<span class="ugx-pickmeta"><bdi>' + esc(v.handle || '—') + '</bdi>'
                      + (v.live ? '' : ' · not live yet') + '</span></span>'
                      + (already
                          ? '<span class="ugx-pill is-live">Added</span>'
                          : '<button class="ugx-mini" data-ugx-add="' + esc(String(v.id)) + '">Add</button>')
                      + '</div>';
                  }).join('') + '</div>'
                : '<div class="ugx-empty" style="margin-top:12px">No clip matches “' + esc(libTerm)
                  + '”. Clear the search to see all ' + library.length + '.</div>')
          : '<div class="ugx-empty" style="margin-top:12px">The library is empty. Add a video in '
            + '<b>Content → Shoppable video</b> first.</div>')
      + '</div>'

      + (s.handle
          ? '<div class="ugx-card">'
            + ugxStep(4, 'Put it on the shop', 'Paste this wherever the rail should appear — a page, a '
                + 'post, or an HTML block. It works in as many places as you like.')
            + '<div class="ugx-shortcode">'
            + '<code>' + esc(s.shortcode) + '</code>'
            + '<button class="ugx-btn" data-ugx-copy="' + esc(s.shortcode) + '">Copy</button>'
            + '</div>'
            + (s.status === 'publish' ? '' : '<div class="ugx-note is-warm" style="margin-top:12px">'
                + '<b>This section is a draft.</b> The shortcode will render nothing until you set the '
                + 'status above to Published.</div>')
            + '</div>'
          : '');
  }

  /* ── THE POPUP: full controls, product selection, files, source URLs ──── */
  /* Which tab of the clip editor is open. Reset every time a clip is opened,
     so the dialog always lands on Details rather than wherever it was left. */
  var modalTab = 'details';

  /* The id of the clip the dialog on screen was built for. renderModal()
     compares against it so an open dialog is updated rather than rebuilt. */
  var openModalId = null;

  var MODAL_TABS = [
    ['details', 'Details'],
    ['source', 'Source'],
    ['files', 'Files'],
    ['products', 'Products'],
    ['placement', 'Placement']
  ];

  /* An inline SVG, from a fixed set. A CONSTANT and never a setting: this is
     printed unescaped, which CLAUDE.md rule 5 allows only for a constant. */
  function ugxIcon(name) {
    var d = {
      film: '<path d="M3 4.5h14v11H3z"/><path d="M6.5 4.5v11M13.5 4.5v11M3 10h14"/>',
      image: '<path d="M3 4.5h14v11H3z"/><circle cx="7.2" cy="8.2" r="1.4"/><path d="m3.6 14 4-4 3.2 3 2.4-2 3.2 3"/>',
      loop: '<path d="M4 8.5a5 5 0 0 1 8.6-3.4L15 7.5"/><path d="M16 11.5a5 5 0 0 1-8.6 3.4L5 12.5"/><path d="M15 3.5v4h-4M5 16.5v-4h4"/>'
    };
    return '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (d[name] || '') + '</svg>';
  }

  /**
   * One file row: a real <input type=file> inside a <label>.
   *
   * The input is visually hidden rather than removed, because it is what the
   * change handler listens to and what makes the control keyboard-operable —
   * a <label> is a genuine activator for its input in every browser, so no
   * JavaScript forwards the click. The browser's own "Choose File / No file
   * chosen" button is what made this panel look a decade old, and it is the one
   * thing about a file input that cannot be styled.
   */
  function ugxFileRow(kind, icon, title, bytes, note) {
    var has = bytes > 0;

    /*
     * THE `accept` IS A LITERAL IN THIS SOURCE, and not a parameter, because
     * AdminMediaPickerEverywhereTest reads this FILE rather than the rendered
     * page. It sweeps for a raw <input type=file> and treats one with no
     * visible accept as an image picker that should have gone through the
     * shared Media Library. Concatenating the attribute made both of these
     * invisible to it and the guard reported them -- correctly, on the evidence
     * it had. These two genuinely are video pickers, and now they say so where
     * the guard can read it.
     */
    var input = kind === 'clip'
      ? '<input type="file" accept="video/mp4,video/webm,video/quicktime" data-ugx-upload="clip">'
      : '<input type="file" accept="video/mp4,video/webm" data-ugx-upload="teaser">';

    /*
     * data-ugx-zone IS WHAT MOUNTS THE DROP TARGET. mountZones() finds every one
     * of them after each repaint and hands it to window.kbbDropZone, which adds
     * dragging WITHOUT touching the click path — the <label> still opens the file
     * dialog exactly as it did, and a keyboard user reaches it through the input
     * inside it.
     */
    return '<label class="ugx-drop' + (has ? '' : ' is-empty') + '" data-ugx-zone="' + kind + '" '
      + 'data-ugx-accept="' + (kind === 'clip' ? 'video/mp4,video/webm,video/quicktime'
                                               : 'video/mp4,video/webm') + '">'
      + '<span class="ugx-dropicon">' + ugxIcon(icon) + '</span>'
      + '<span><span class="ugx-dropname">' + esc(title) + '</span>'
      + '<span class="ugx-dropmeta">' + (has ? esc(kb(bytes)) + ' · ' : '') + esc(note) + '</span>'
      /* The one line that says dragging works. Said rather than implied: a drop
         target nobody knows is a drop target is a plain button. */
      + '<span class="ugx-drophint">Drag a file here, or choose one.</span></span>'
      + '<span class="ugx-dropcta">' + (has ? 'Replace' : 'Choose') + '</span>'
      + input
      + '</label>';
  }

  /** The poster row, which picks from the Media Library rather than from disk. */
  function ugxPosterRow(v) {
    var has = (v.poster_bytes || 0) > 0;

    /*
     * A DROP TARGET WITH NO FILE INPUT IN IT. `accept` is an image type, and a
     * dragged picture goes through dropPoster() to the Media Library's own
     * upload endpoint and is then adopted by URL — so the owner's "the media
     * library is a must" rule holds for the drop path as well as the button, and
     * AdminMediaPickerEverywhereTest finds no image-accepting <input type=file>
     * on this screen because there genuinely is not one.
     */
    return '<div class="ugx-drop' + (has ? '' : ' is-empty') + '" data-ugx-poster role="button" '
      + 'tabindex="0" data-ugx-zone="poster" data-ugx-accept="image/jpeg,image/png,image/webp">'
      + '<span class="ugx-dropicon">' + ugxIcon('image') + '</span>'
      + '<span><span class="ugx-dropname">Poster</span>'
      + '<span class="ugx-dropmeta">' + (has ? esc(kb(v.poster_bytes)) + ' · ' : '')
      + 'Required to publish — the tile reserves its box from this image, which is what stops the page '
      + 'jumping.' + (capNote('poster') ? ' ' + esc(capNote('poster')) : '') + '</span>'
      + '<span class="ugx-drophint">Drag a picture here, or choose one from the Media Library.</span>'
      + '</span>'
      + '<span class="ugx-dropcta">' + (has ? 'Change' : 'Choose') + '</span>'
      + '</div>'
      /*
       * ── THE BROWSER CUT, ON THIS SCREEN TOO ───────────────────────────
       *
       * OUTSIDE the drop target above, not inside it. That whole block is one
       * `role="button"` with a click handler on it, so a button nested in it
       * would open the Media Library picker on its way to doing its own job.
       *
       * Offered only when there is a video to take a frame from; without one
       * the button would have nothing to do and the row already says so.
       */
      + (ugxStored(v.file_path)
          ? '<div class="ugx-cutrow">'
            + '<button type="button" class="ugx-btn" data-ugx-cuthere="1"'
            + ((busy || cutting) ? ' disabled' : '') + '>'
            + (cutting ? 'Taking the frame…' : 'Take the cover from the video')
            + '</button>'
            + '<span class="ugx-cuthint">In your browser, from the frame at 0.6 seconds. '
            + 'Nothing is asked of the server.</span>'
            + '</div>'
            + cutBarHTML()
          : '');
  }

  /** Just the panel for whichever tab is open. */
  function modalBodyHTML() {
    var v = editingVideo;
    var body = '';

    if (modalTab === 'details') {
      body = '<div class="ugx-fields">'
        + '<div class="ugx-f"><label>Title</label>'
        + '<input type="text" data-ugx-vfield="title" value="' + esc(v.title || '') + '" autocomplete="off"></div>'
        + '<div class="ugx-f"><label>Caption</label>'
        + '<textarea data-ugx-vfield="caption" placeholder="The creator’s own words">' + esc(v.caption || '') + '</textarea>'
        + '<p class="ugx-help">There is deliberately no Translate button here: a machine-translated caption '
        + 'attributed to a named person is putting words in her mouth.</p></div>'
        + '</div>';
    } else if (modalTab === 'source') {
      body = '<div class="ugx-fields">'
        + '<div class="ugx-two">'
        + '<div class="ugx-f"><label>Source</label>'
        + selectHTML2('source_platform', (vocab && vocab.platforms) || ['upload', 'instagram', 'tiktok', 'youtube'],
            v.source_platform, { upload: 'Uploaded here', instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube' })
        + '</div>'
        + '<div class="ugx-f"><label>Link to the original post</label>'
        + '<input type="text" data-ugx-vfield="source_url" value="' + esc(v.source_url || '') + '" '
        + 'autocomplete="off" placeholder="https://"></div>'
        + '</div>'
        + '<p class="ugx-help">Attribution and a link back, never an embed. The shop serves the file you upload, '
        + 'which is what lets a tile loop a one-second teaser — inside somebody else’s player it could not.</p>'
        + '<div class="ugx-two">'
        + '<div class="ugx-f"><label>Creator handle</label>'
        + '<input type="text" data-ugx-vfield="creator_handle" value="' + esc(v.creator_handle || '') + '" '
        + 'autocomplete="off" placeholder="@handle"></div>'
        + '<div class="ugx-f"><label>Creator link</label>'
        + '<input type="text" data-ugx-vfield="creator_url" value="' + esc(v.creator_url || '') + '" '
        + 'autocomplete="off" placeholder="https://"></div>'
        + '</div>'
        + '<div class="ugx-two">'
        + '<div class="ugx-f"><label>Permission</label>'
        + selectHTML2('rights_status', (vocab && vocab.rights) || ['pending', 'granted', 'refused'], v.rights_status,
            { pending: 'Not asked yet', granted: 'Granted in writing', refused: 'Refused' })
        + '</div>'
        + '<div class="ugx-f"><label>Where the permission is recorded</label>'
        + '<input type="text" data-ugx-vfield="rights_evidence" value="' + esc(v.rights_evidence || '') + '" '
        + 'autocomplete="off" placeholder="Email, DM, signed note…"></div>'
        + '</div>'
        + '<p class="ugx-help">A clip cannot be published until permission says granted — the creator owns the '
        + 'copyright in her video and being tagged in it grants nothing. Neither field is ever shown on the shop '
        + 'or returned by any public endpoint.</p>'
        + '</div>';
    } else if (modalTab === 'files') {
      body = '<div class="ugx-fields">'
        /*
         * THE NUMBER IS THE SERVER'S NOW, AND IT USED TO BE A LIE.
         *
         * This line read 'Up to 64MB.' as a literal. 64 MB is the APP's cap
         * (UgcMedia::MAX_BYTES) and on the owner's box the real ceiling is
         * 9.9 MB, because post_max_size is 10M while upload_max_filesize is
         * already 100M. capNote() prints what this server will really take and,
         * where PHP is the thing capping it, says so and names the directive.
         */
        + ugxFileRow('clip', 'film', 'Video',  v.bytes,
            (capNote('clip') ? capNote('clip') + ' ' : '')
            + 'Checked by its own bytes and not by its name.')
        + ugxPosterRow(v)
        /*
         * THE OLD HELP LINE HERE WAS FALSE, and the owner reasonably read it as
         * "you must upload a second file". Measured in Chromium with
         * teaser_path NULL on every clip: the rail mounts the FULL clip and
         * loops inside the first 2.5 seconds (currentTime 0.35 -> 0.9 after
         * 5.7s, never past the limit). See playTeaser() in ugc/assets.blade.php,
         * which is `src = teaser || full` with a timeupdate rewind.
         *
         * So a separate loop is a BANDWIDTH optimisation and nothing else, and
         * this row now says so instead of implying a missing piece.
         */
        + ugxFileRow('teaser', 'loop', 'Short loop file', v.teaser_bytes,
            (capNote('teaser') ? capNote('teaser') + ' ' : '')
            + 'Optional, and nothing is missing without it — the tile already loops the first '
            + 'second of the video above. Adding a cut-down file only saves the shopper bytes — about '
            + '30KB against the whole clip.')
        /* THE PANEL, UNDER THE THREE ROWS. In flight it carries the percentage,
           the bytes, the speed, the time remaining and Cancel; afterwards it
           carries the ending, and the ending STAYS. */
        + uploadHTML()
        + '</div>';
    } else if (modalTab === 'products') {
      body = '<div class="ugx-fields">'
        + '<div class="ugx-tagged">'
        + (tagged.length ? tagged.map(function (p, i) {
            return '<div class="ugx-tag"><span class="ugx-tagno">' + (i + 1) + '</span>'
              + '<span><span class="ugx-tagname">' + esc(p.name) + '</span>'
              + (p.brand ? '<span class="ugx-tagbrand">' + esc(p.brand) + '</span>' : '') + '</span>'
              + '<span class="ugx-rowacts">'
              + '<button class="ugx-mini" data-ugx-pup="' + i + '"' + (i === 0 ? ' disabled' : '')
              + ' aria-label="Move up">&uarr;</button>'
              + '<button class="ugx-mini" data-ugx-pdown="' + i + '"'
              + (i === tagged.length - 1 ? ' disabled' : '') + ' aria-label="Move down">&darr;</button>'
              + '<button class="ugx-mini is-danger" data-ugx-untag="' + i + '">Remove</button>'
              + '</span></div>';
          }).join('') : '<div class="ugx-empty">No products yet. The first one you add is the product the '
              + 'tile’s card shows.</div>')
        + '</div>'
        + '<div class="ugx-f"><label>Search the catalogue</label>'
        + '<input type="text" data-ugx-search value="' + esc(term) + '" autocomplete="off" '
        + 'placeholder="Product name"></div>'
        + (results.length ? '<div class="ugx-results">' + results.map(function (p) {
            return '<div class="ugx-tag"><span class="ugx-tagno">+</span>'
              + '<span><span class="ugx-tagname">' + esc(p.name) + '</span>'
              + (p.brand ? '<span class="ugx-tagbrand">' + esc(p.brand) + '</span>' : '') + '</span>'
              + '<button class="ugx-mini" data-ugx-tag="' + esc(String(p.id)) + '"'
              + ' data-ugx-tagname="' + esc(p.name) + '"'
              + ' data-ugx-tagbrand="' + esc(p.brand || '') + '">Add</button></div>';
          }).join('') + '</div>' : '')
        + '</div>';
    } else {
      body = '<div class="ugx-fields"><div class="ugx-two">'
        + '<div class="ugx-f"><label>Status</label>'
        + selectHTML2('status', (vocab && vocab.statuses) || ['draft', 'publish'], v.status,
            { draft: 'Draft', publish: 'Published' }) + '</div>'
        + '<div class="ugx-f"><label>Language</label>'
        + selectHTML2('locale', [null, 'en', 'ar'], v.locale,
            { '': '— both storefronts —', en: 'English only', ar: 'Arabic only' }) + '</div>'
        + '</div></div>';
    }

    return body
      + (v.blockers && v.blockers.length
          ? '<div class="ugx-note is-warm"><b>Not publishable yet.</b> ' + v.blockers.map(esc).join(' ') + '</div>'
          : '');
  }

  function modalHTML() {
    var v = editingVideo;
    /* `poster_path`, which is what UgcVideoController::card() returns for a
       single clip. The section LIST uses a different key (`poster`), so both
       are read rather than guessing which payload opened this dialog. */
    var poster = v.poster_path || v.poster || '';

    return '<div class="ugx-back" data-ugx-backdrop><div class="ugx-modal" role="dialog" aria-modal="true">'

      /* The header carries the clip's own poster, so what is being edited is
         visible the whole time rather than only on the Files tab. */
      + '<div class="ugx-mh">'
      + '<span class="ugx-thumb" style="width:40px">'
      + (poster ? '<img src="' + esc(poster) + '" alt="">' : 'no<br>poster') + '</span>'
      + '<span><span class="ugx-previewname">' + esc(v.title || v.slug) + '</span>'
      + '<span class="ugx-previewmeta">Saved to the library, so it changes in every section that carries it.'
      + '</span></span>'
      + '<button class="ugx-x" data-ugx-close aria-label="Close">&times;</button></div>'

      + '<div class="ugx-tabs" role="tablist">'
      + MODAL_TABS.map(function (t) {
          var count = t[0] === 'products' && tagged.length
            ? '<span class="ugx-count">' + tagged.length + '</span>' : '';
          return '<button class="ugx-tab' + (t[0] === modalTab ? ' on' : '') + '" role="tab"'
            + ' aria-selected="' + (t[0] === modalTab ? 'true' : 'false') + '"'
            + ' data-ugx-mtab="' + t[0] + '">' + t[1] + count + '</button>';
        }).join('')
      + '</div>'

      + '<div class="ugx-body">' + modalBodyHTML() + '</div>'

      /* The action bar does not scroll. The single long scroll this replaced
         could put Save below the fold on every tab. */
      + '<div class="ugx-foot">'
      + '<span class="ugx-footnote">Changes apply to every section carrying this clip.</span>'
      + '<button class="ugx-btn" data-ugx-close>Close</button>'
      + '<button class="ugx-btn is-primary" data-ugx-vsave' + (busy ? ' disabled' : '') + '>Save video</button>'
      + '</div></div></div>';
  }

  /* A second select helper for the popup's own fields, so a change event can tell
     a section field from a video field by its attribute rather than by guessing
     which editor is open. */
  function selectHTML2(name, options, value, labels) {
    return '<select data-ugx-vfield="' + esc(name) + '">'
      + options.map(function (o) {
        var key = o === null ? '' : o;
        var label = labels && labels[key] !== undefined ? labels[key] : String(key);
        return '<option value="' + esc(key) + '"' + ((value || '') === key ? ' selected' : '')
          + '>' + esc(label) + '</option>';
      }).join('')
      + '</select>';
  }

  function render() {
    var host = document.querySelector('#content');
    if (!host || (document.querySelector('#ptitle') || {}).textContent !== 'Shoppable video') return;

    if (busy && !sections.length && !editing) {
      host.innerHTML = '<div class="ugx-wrap"><div class="ugx-card"><p class="ugx-sub">Loading…</p></div></div>';
      return;
    }

    host.innerHTML = '<div class="ugx-wrap">'
      + window.kbbUgcTabs(SCREEN)
      + (banner ? '<div class="ugx-note is-bad">' + esc(banner) + '</div>' : '')
      + (moduleOn ? '' : '<div class="ugx-note is-warm"><b>Shoppable video is switched off.</b> '
          + 'Sections and clips can be set up now, and nothing appears on the shop until you turn it on in '
          + '<b>Store → Modules → Shoppable video</b>.</div>')
      + (editing ? editorHTML() : listHTML())
      + '</div>';

    /*
     * THE DIALOG IS NOT PART OF THIS STRING ANY MORE, and that is the whole fix
     * for the flashing the owner reported.
     *
     * render() replaces #content.innerHTML outright. While the dialog lived in
     * that string, EVERY screen re-render tore the dialog down and built it
     * again -- which re-ran its entrance animation, re-fetched the poster and
     * reset the scroll position. Switching a tab did it once (one flash), and
     * saving did it about seven times in a row, because saveVideo() calls
     * render(), then openVideo(), openSection() and load(), each of which
     * renders twice more. That is exactly the "all the tabs flash one by one,
     * and then saving" he described.
     *
     * The dialog now lives in its own element under <body>, painted by
     * renderModal(), so the two are independent: the screen can re-render as
     * often as it likes underneath an open dialog and the dialog does not move.
     */
    renderModal();

    /*
     * AND THE PAGE'S OWN DROP ZONES, WHICH USED NOT TO EXIST.
     *
     * mountZones() was called only from renderModal()/renderModalBody(), because
     * until this lane every drop target on this screen was inside the dialog. The
     * "Upload a new video" control in the card head is the first one on the PAGE,
     * and two things made calling it here necessary rather than tidy:
     *
     *   - render() replaces #content.innerHTML, so the zone is a fresh node with
     *     no listeners after every repaint;
     *   - renderModal() with no clip open tears every zoneOff down and returns
     *     early, so closing the dialog would silently un-mount the page's zone
     *     and dropping a file on it would open it in the browser instead.
     *
     * LAST, AFTER renderModal(), because mountZones() tears down and re-scans the
     * whole document — so whichever of the two ran most recently, the end state is
     * one listener per zone that is currently on the page.
     */
    mountZones();
  }

  /**
   * A numbered step header. `n` is an integer this file supplies and `title`
   * and `hint` are literals from this file — never a setting — which is what
   * lets them be printed as markup at all (CLAUDE.md rule 5).
   */
  function ugxStep(n, title, hint) {
    return '<div class="ugx-step">'
      + '<span class="ugx-stepno">' + n + '</span>'
      + '<span><span class="ugx-steptitle">' + esc(title) + '</span>'
      + '<span class="ugx-stephint">' + esc(hint) + '</span></span>'
      + '</div>';
  }

  /** The dialog's own host, created once and reused. */
  function modalHost() {
    var host = document.getElementById('ugxModalHost');

    if (!host) {
      host = document.createElement('div');
      host.id = 'ugxModalHost';
      document.body.appendChild(host);
    }

    return host;
  }

  /**
   * Paint the dialog, and DO NOT REBUILD ONE THAT IS ALREADY OPEN ON THE SAME
   * CLIP -- rebuilding is what flashed. When the clip has not changed, only the
   * body and the tab strip are touched, which is a swap of one element's
   * children rather than a teardown of the dialog.
   */
  function renderModal() {
    var host = modalHost();

    if (!editingVideo) {
      if (host.innerHTML !== '') { host.innerHTML = ''; }
      openModalId = null;
      /* The panel belongs to ONE clip. Left behind, a green "saved" panel would
         sit on the next clip's Files tab describing a file that is not on it —
         and an upload still in flight would go on painting into a dialog that is
         no longer there. */
      forgetUpload();
      zoneOffs.forEach(function (off) { try { off(); } catch (e) {} });
      zoneOffs = [];
      return;
    }

    if (openModalId === editingVideo.id && host.querySelector('.ugx-modal')) {
      renderModalBody();
      return;
    }

    host.innerHTML = modalHTML();
    openModalId = editingVideo.id;
    mountZones();
  }

  /** The body and the tab strip only. No animation, no re-fetched poster. */
  function renderModalBody() {
    var host = modalHost();
    var body = host.querySelector('.ugx-body');

    if (!body) { host.innerHTML = modalHTML(); openModalId = editingVideo.id; return; }

    body.innerHTML = modalBodyHTML();

    host.querySelectorAll('[data-ugx-mtab]').forEach(function (b) {
      var on = b.getAttribute('data-ugx-mtab') === modalTab;
      b.classList.toggle('on', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    /* The tab strip carries a count that changes as products are added. */
    var count = host.querySelector('[data-ugx-mtab="products"] .ugx-count');
    if (count) { count.textContent = String(tagged.length); }

    var save = host.querySelector('[data-ugx-vsave]');
    if (save) { save.disabled = !!busy; }

    mountZones();
  }

  /*
   * MOUNT THE DROP TARGETS THAT THE LAST REPAINT DREW.
   *
   * The dialog body is replaced wholesale on every repaint, so the zones are
   * fresh nodes each time and the previous teardowns must run first — otherwise
   * every repaint adds another set of listeners to nodes nobody can see, and a
   * single drop fires the uploader as many times as the tab has been opened.
   *
   * `accept` is read from the attribute, which is a LITERAL in ugxFileRow() and
   * ugxPosterRow() where AdminMediaPickerEverywhereTest can see it.
   */
  function mountZones() {
    zoneOffs.forEach(function (off) { try { off(); } catch (e) {} });
    zoneOffs = [];

    if (typeof window.kbbDropZone !== 'function') return;

    document.querySelectorAll('[data-ugx-zone]').forEach(function (el) {
      var kind = el.getAttribute('data-ugx-zone');

      zoneOffs.push(window.kbbDropZone(el, {
        accept: el.getAttribute('data-ugx-accept') || '',
        multiple: false,
        rejectHint: kind === 'poster'
          ? 'This box takes a JPG, PNG or WebP picture.'
          : 'This box takes an MP4 or WebM video.',
        onFiles: function (files) {
          if (!files || !files.length) return;
          if (kind === 'poster') dropPoster(files[0]);
          /* 'newclip' is the card-head control, which CREATES a clip rather than
             replacing one — see uploadNewClip(). It is not a `kind` UgcMedia
             knows and must never be passed to upload(), which would post it as
             one. */
          else if (kind === 'newclip') uploadNewClip(files[0]);
          else upload(kind, files[0]);
        }
      }));
    });
  }

  /* -------------------------------------------------------------- listeners */

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;

    if (t.closest('[data-ugx-close]') || (t.hasAttribute && t.hasAttribute('data-ugx-backdrop'))) {
      e.preventDefault(); editingVideo = null; render(); return;
    }
    /* A tab of the clip editor. Before the dialog was tabbed this was one very
       long scroll, so Save could sit below the fold and the thing being edited
       could scroll out of sight. */
    if (t.closest('[data-ugx-libclear]')) {
      e.preventDefault();
      libTerm = '';
      var box = document.querySelector('[data-ugx-libsearch]');
      if (box) { box.value = ''; box.focus(); }
      repaintPicks();
      return;
    }

    var mtab = t.closest('[data-ugx-mtab]');
    if (mtab) {
      e.preventDefault();
      modalTab = mtab.getAttribute('data-ugx-mtab');
      /* renderModalBody(), NOT render(): re-rendering the screen is what made
         switching a tab flash. Nothing outside the dialog changes. */
      renderModalBody();
      return;
    }
    if (t.closest('[data-ugx-back]')) { e.preventDefault(); editing = null; render(); return; }
    if (t.closest('[data-ugx-new]')) {
      e.preventDefault();
      editing = { id: null, title: '', heading: '', subheading: '', status: 'draft',
                  columns: null, locale: null, max_tiles: 12, position: 0, videos: [] };
      render();
      return;
    }

    var open = t.closest('[data-ugx-open]');
    if (open) { e.preventDefault(); openSection(open.getAttribute('data-ugx-open')); return; }

    var edit = t.closest('[data-ugx-edit]');
    if (edit) { e.preventDefault(); openVideo(edit.getAttribute('data-ugx-edit')); return; }

    var copy = t.closest('[data-ugx-copy]');
    if (copy) {
      e.preventDefault();
      var line = copy.getAttribute('data-ugx-copy');
      if (navigator.clipboard) navigator.clipboard.writeText(line).then(function () { say('Copied ' + line); });
      else say(line);
      return;
    }

    var del = t.closest('[data-ugx-delete]');
    if (del) {
      e.preventDefault();
      /* The clips are NOT deleted with it and the sentence says so, because "delete
         section" reads like "delete these videos" and it is not. */
      if (!window.confirm('Delete this section? The videos in it stay in the library and in any other '
          + 'section that carries them.')) return;
      api('/ugc-sections/' + del.getAttribute('data-ugx-delete'), undefined, 'DELETE')
        .then(function () { say('Section deleted.'); editing = null; return load(); })
        .catch(function (err) { banner = explain(err, 'That section could not be deleted.'); render(); });
      return;
    }

    if (t.closest('[data-ugx-save]')) { e.preventDefault(); saveSection(); return; }
    if (t.closest('[data-ugx-order]')) { e.preventDefault(); saveOrder(); return; }
    if (t.closest('[data-ugx-vsave]')) { e.preventDefault(); saveVideo(); return; }
    if (t.closest('[data-ugx-upcancel]')) {
      e.preventDefault();
      /* cancel() aborts the request, which lands in the kit's onabort and then in
         onFail with cancelled set — one ending writer. */
      if (upHandle) { try { upHandle.cancel(); } catch (err) {} }
      return;
    }
    if (t.closest('[data-ugx-upretry]')) {
      e.preventDefault();
      if (upDone && upDone.retry) {
        if (upDone.kind === 'poster') dropPoster(upDone.retry);
        else upload(upDone.kind, upDone.retry);
      }
      return;
    }
    if (t.closest('[data-ugx-nupcancel]')) {
      e.preventDefault();
      /* cancel() aborts the request, which lands in the kit's onabort and then in
         onFail with cancelled set — one ending writer, as above. */
      if (newHandle) { try { newHandle.cancel(); } catch (err) {} }
      return;
    }
    if (t.closest('[data-ugx-nupretry]')) {
      e.preventDefault();
      if (newDone && newDone.retry) uploadNewClip(newDone.retry);
      return;
    }
    if (t.closest('[data-ugx-nupdismiss]')) {
      e.preventDefault();
      newUp = null; newDone = null; newHandle = null;
      render();
      return;
    }
    /* BEFORE the poster row's own handler: the button sits beside that block,
       but a stray future nesting would otherwise open the picker instead. */
    if (t.closest('[data-ugx-cuthere]')) { e.preventDefault(); cutCoverHere(); return; }

    if (t.closest('[data-ugx-poster]')) { e.preventDefault(); pickPoster(); return; }

    var add = t.closest('[data-ugx-add]');
    if (add && editing) {
      e.preventDefault();
      var id = Number(add.getAttribute('data-ugx-add'));
      var row = library.filter(function (x) { return x.id === id; })[0];
      if (!row) return;
      editing.videos = (editing.videos || []).concat([{
        id: row.id, title: row.title, handle: row.handle, poster: row.poster,
        status: row.live ? 'publish' : 'draft', rights_status: row.live ? 'granted' : 'pending',
        media_state: row.poster ? 'poster_only' : 'none', blockers: [], products_count: 0
      }]);
      render();
      return;
    }

    var moveUp = t.closest('[data-ugx-up]');
    var moveDown = t.closest('[data-ugx-down]');
    if ((moveUp || moveDown) && editing) {
      e.preventDefault();
      var from = Number((moveUp || moveDown).getAttribute(moveUp ? 'data-ugx-up' : 'data-ugx-down'));
      var to = moveUp ? from - 1 : from + 1;
      var list = editing.videos || [];
      if (to < 0 || to >= list.length) return;
      list.splice(to, 0, list.splice(from, 1)[0]);
      render();
      return;
    }

    var remove = t.closest('[data-ugx-remove]');
    if (remove && editing) {
      e.preventDefault();
      (editing.videos || []).splice(Number(remove.getAttribute('data-ugx-remove')), 1);
      render();
      return;
    }

    var tag = t.closest('[data-ugx-tag]');
    if (tag) {
      e.preventDefault();
      var pid = Number(tag.getAttribute('data-ugx-tag'));
      /* Already tagged is a no-op rather than a second row: the pivot has a unique
         index and a duplicate would be a constraint violation the operator cannot
         act on. */
      if (tagged.some(function (x) { return x.id === pid; })) { say('Already tagged.'); return; }
      tagged.push({
        id: pid,
        name: tag.getAttribute('data-ugx-tagname') || '',
        brand: tag.getAttribute('data-ugx-tagbrand') || ''
      });
      render();
      return;
    }

    var untag = t.closest('[data-ugx-untag]');
    if (untag) { e.preventDefault(); tagged.splice(Number(untag.getAttribute('data-ugx-untag')), 1); render(); return; }

    var pup = t.closest('[data-ugx-pup]');
    var pdown = t.closest('[data-ugx-pdown]');
    if (pup || pdown) {
      e.preventDefault();
      var pf = Number((pup || pdown).getAttribute(pup ? 'data-ugx-pup' : 'data-ugx-pdown'));
      var pt = pup ? pf - 1 : pf + 1;
      if (pt < 0 || pt >= tagged.length) return;
      tagged.splice(pt, 0, tagged.splice(pf, 1)[0]);
      render();
      return;
    }
  });

  /** Re-draw the library picker's rows in place, leaving the search box alone. */
  function repaintPicks() {
    var card = document.querySelector('[data-ugx-libsearch]');
    if (!card) return;

    card = card.closest('.ugx-card');
    if (!card) return;

    var needle = libTerm.trim().toLowerCase();
    var inSection = {};

    /* `editing.videos`, not a bare `videos` — that name is a LOCAL inside
       editorHTML() and this function is not inside it. The first draft read it
       anyway and threw "videos is not defined" on the first keystroke, which
       left the list showing everything however narrow the search was. */
    ((editing && editing.videos) || []).forEach(function (v) { inSection[v.id] = true; });

    var matches = needle === '' ? library : library.filter(function (v) {
      return (String(v.title || '') + ' ' + String(v.handle || '')).toLowerCase().indexOf(needle) !== -1;
    });

    var list = card.querySelector('.ugx-picks') || card.querySelector('.ugx-empty');
    if (!list) return;

    var html = matches.length
      ? '<div class="ugx-picks">' + matches.map(function (v) {
          var already = inSection[v.id];
          return '<div class="ugx-pick' + (already ? ' is-in' : '') + '">'
            + '<span class="ugx-thumb">' + (v.poster
                ? '<img src="' + esc(v.poster) + '" alt="" loading="lazy">' : '') + '</span>'
            + '<span class="ugx-pickbody"><span class="ugx-pickname">'
            + esc(v.title || ('#' + v.id)) + '</span>'
            + '<span class="ugx-pickmeta"><bdi>' + esc(v.handle || '—') + '</bdi>'
            + (v.live ? '' : ' · not live yet') + '</span></span>'
            + (already
                ? '<span class="ugx-pill is-live">Added</span>'
                : '<button class="ugx-mini" data-ugx-add="' + esc(String(v.id)) + '">Add</button>')
            + '</div>';
        }).join('') + '</div>'
      : '<div class="ugx-empty" style="margin-top:12px">No clip matches “' + esc(libTerm)
        + '”. Clear the search to see all ' + library.length + '.</div>';

    list.outerHTML = html;

    /* The Clear button appears and disappears with the term, and it sits in the
       search row rather than the list, so it is toggled separately. */
    var row = card.querySelector('.ugx-search');
    var clear = row ? row.querySelector('[data-ugx-libclear]') : null;

    if (libTerm && !clear && row) {
      row.insertAdjacentHTML('beforeend',
        '<button class="ugx-mini" data-ugx-libclear>Clear</button>');
    } else if (!libTerm && clear) {
      clear.remove();
    }
  }

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute) return;

    if (el.hasAttribute('data-ugx-field') && editing) {
      editing[el.getAttribute('data-ugx-field')] = el.value;
      return;
    }
    if (el.hasAttribute('data-ugx-vfield') && editingVideo) {
      editingVideo[el.getAttribute('data-ugx-vfield')] = el.value;
      return;
    }
    /*
     * THE LIBRARY SEARCH REPAINTS THE LIST AND NOTHING ELSE.
     *
     * Not render(): that replaces #content wholesale, so the input being typed
     * into becomes a new node on every keystroke and the caret has to be put
     * back by hand — which is the workaround the product search below still
     * carries. Swapping only the results keeps the very element the owner is
     * typing in, so there is no caret to restore and nothing flickers.
     *
     * No debounce and no request: this filters the library already in memory.
     */
    if (el.hasAttribute('data-ugx-libsearch')) {
      libTerm = el.value;
      repaintPicks();
      return;
    }

    if (el.hasAttribute('data-ugx-search')) {
      term = el.value;
      if (searchTimer) clearTimeout(searchTimer);
      /* Debounced, and the caret is put back after the repaint: render() replaces
         #content wholesale, so the element being typed into is a new node by the
         time the answer lands. */
      searchTimer = setTimeout(function () {
        search().then(function () {
          var box = document.querySelector('[data-ugx-search]');
          if (box) { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }
        });
      }, 250);
    }
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute) return;

    if (el.tagName === 'SELECT' && el.hasAttribute('data-ugx-field') && editing) {
      editing[el.getAttribute('data-ugx-field')] = el.value === '' ? null : el.value;
      return;
    }
    if (el.tagName === 'SELECT' && el.hasAttribute('data-ugx-vfield') && editingVideo) {
      editingVideo[el.getAttribute('data-ugx-vfield')] = el.value === '' ? null : el.value;
      return;
    }
    if (el.hasAttribute('data-ugx-upload')) { upload(el.getAttribute('data-ugx-upload'), el); }
    /*
     * THE NEW-CLIP INPUT, AND ITS VALUE IS CLEARED AFTERWARDS.
     *
     * Without the reset, choosing the SAME file twice fires no `change` at all —
     * the value has not changed — so a failed upload could not be retried by
     * picking the file again, which is precisely what somebody does after a
     * refusal. Read the File first, then clear.
     */
    if (el.hasAttribute('data-ugx-newupload')) {
      var chosen = (el.files && el.files[0]) ? el.files[0] : null;
      el.value = '';
      if (chosen) uploadNewClip(chosen);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && editingVideo) { editingVideo = null; render(); }
  });

  addNavEntry();
})();
</script>
@endverbatim
