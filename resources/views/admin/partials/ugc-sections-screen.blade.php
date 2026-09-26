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
  var editing = null;      /* the section open in the editor */
  var editingVideo = null; /* the clip open in the POPUP */
  var tagged = [], results = [], term = '';
  var banner = null, busy = false, seq = 0, searchTimer = null;

  function base() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function say(m) { try { window.toast(m); } catch (e) {} }

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
    return (e && e.body && e.body.error) ? e.body.error : fallback;
  }

  function kb(n) {
    if (!n) return '—';
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
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
      editingVideo = body.video;
      // Always open on Details rather than wherever the last clip was left.
      modalTab = 'details';
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
      await openVideo(v.id);
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

  async function upload(kind, input) {
    if (!editingVideo || !input.files || !input.files[0]) return;
    var form = new FormData();
    form.append('kind', kind);
    form.append('file', input.files[0]);
    busy = true; render();
    try {
      var response = await fetch(base() + '/ugc-videos/' + editingVideo.id + '/media', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: form
      });
      var payload = null;
      try { payload = await response.json(); } catch (e) {}
      if (!response.ok) throw { status: response.status, body: payload };
      say('Uploaded.');
      await openVideo(editingVideo.id);
      if (editing && editing.id) await openSection(editing.id);
    } catch (e) {
      banner = explain(e, 'That file was not accepted.');
      busy = false; render();
    }
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
    if (v.media_state === 'poster_only') out += '<span class="ugx-pill is-soft">Poster only</span>';
    if (v.media_state === 'none') out += '<span class="ugx-pill is-hold">No files</span>';
    out += '<span class="ugx-pill">' + esc(String(v.products_count || 0)) + ' products</span>';
    return out;
  }

  function listHTML() {
    if (!sections.length) {
      return '<div class="ugx-card"><div class="ugx-title">No sections yet</div>'
        + '<p class="ugx-sub">A section is a named, ordered set of clips that you place on the shop with a '
        + 'shortcode. Make one, put some videos in it, then paste its shortcode into a page, a post or an '
        + 'HTML block — as many times and in as many places as you like.</p>'
        + '<div class="ugx-actions"><button class="ugx-btn is-primary" data-ugx-new>New section</button></div></div>';
    }

    return '<div class="ugx-card"><div class="ugx-mh"><div>'
      + '<div class="ugx-title">Video sections</div>'
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

    /* Its OWN header, not the dialog's .ugx-mh -- that one is a three-column
       grid built for the popup, and the back button landed in its 1fr cell and
       stretched across half the card. */
    return '<div class="ugx-card"><div class="ugx-eh">'
      + '<button class="ugx-back-link" data-ugx-back>'
      + '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M12 4.5 6.5 10l5.5 5.5"/></svg>All sections</button>'
      + '<div class="ugx-title">' + esc(s.title || 'New section') + '</div>'
      + (s.handle ? '<code class="ugx-code">' + esc(s.shortcode) + '</code>' : '')
      + '</div>'

      + '<div class="ugx-fields">'
      + '<div class="ugx-f"><label>Name (yours, never shown on the shop)</label>'
      + '<input type="text" data-ugx-field="title" value="' + esc(s.title || '') + '" autocomplete="off"></div>'
      + '<div class="ugx-two">'
      + '<div class="ugx-f"><label>Heading on the shop</label>'
      + '<input type="text" data-ugx-field="heading" value="' + esc(s.heading || '') + '" autocomplete="off">'
      + '<p class="ugx-help">Leave empty and no heading is drawn at all.</p></div>'
      + '<div class="ugx-f"><label>Line under the heading</label>'
      + '<input type="text" data-ugx-field="subheading" value="' + esc(s.subheading || '') + '" autocomplete="off"></div>'
      + '</div>'
      + '<div class="ugx-two">'
      + '<div class="ugx-f"><label>Status</label>'
      + selectHTML('status', (vocab && vocab.status) || ['draft', 'publish'], s.status,
          { draft: 'Draft — not on the shop', publish: 'Published' }) + '</div>'
      + '<div class="ugx-f"><label>Tiles across on a phone</label>'
      + selectHTML('columns', [null].concat(Object.keys((vocab && vocab.columns) || {})), s.columns,
          Object.assign({ '': '— follow Appearance → Video rail —' }, (vocab && vocab.columns) || {}))
      + '<p class="ugx-help">A per-section override. Leave it on the first option and this rail follows the '
      + 'setting, which is the R3 default.</p></div>'
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
      + '<div class="ugx-actions">'
      + '<button class="ugx-btn is-primary" data-ugx-save' + (busy ? ' disabled' : '') + '>Save section</button>'
      + '</div></div>'

      /* ── the list of clips inside it, which is the thing he asked for ──── */
      + '<div class="ugx-card"><div class="ugx-title">Videos in this section</div>'
      + '<p class="ugx-sub">In the order they appear on the shop. Click one to edit it in a popup.</p>'
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
      + '<div class="ugx-card"><div class="ugx-title">Add from the library</div>'
      + '<p class="ugx-sub">Every clip in Content → Shoppable video. A clip can be in as many sections as you '
      + 'like — it is the same file and the same like count in each.</p>'
      + (library.length
          ? '<div class="ugx-rows" style="margin-top:12px">' + library.map(function (v) {
              var already = inSection[v.id];
              return '<div class="ugx-vid">'
                + '<div class="ugx-thumb">' + (v.poster
                    ? '<img src="' + esc(v.poster) + '" alt="" loading="lazy">'
                    : 'no poster') + '</div>'
                + '<div><div class="ugx-rowname">' + esc(v.title || ('#' + v.id)) + '</div>'
                + '<div class="ugx-rowmeta"><bdi>' + esc(v.handle || '—') + '</bdi>'
                + (v.live ? '' : ' · will not show until it is published and permitted') + '</div></div>'
                + '<div class="ugx-rowacts">'
                + (already
                    ? '<span class="ugx-pill is-live">In this section</span>'
                    : '<button class="ugx-mini" data-ugx-add="' + esc(String(v.id)) + '">Add</button>')
                + '</div></div>';
            }).join('') + '</div>'
          : '<p class="ugx-sub" style="margin-top:10px">The library is empty. Add a video in '
            + '<b>Content → Shoppable video</b> first.</p>')
      + '</div>';
  }

  /* ── THE POPUP: full controls, product selection, files, source URLs ──── */
  /* Which tab of the clip editor is open. Reset every time a clip is opened,
     so the dialog always lands on Details rather than wherever it was left. */
  var modalTab = 'details';

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

    return '<label class="ugx-drop' + (has ? '' : ' is-empty') + '">'
      + '<span class="ugx-dropicon">' + ugxIcon(icon) + '</span>'
      + '<span><span class="ugx-dropname">' + esc(title) + '</span>'
      + '<span class="ugx-dropmeta">' + (has ? esc(kb(bytes)) + ' · ' : '') + esc(note) + '</span></span>'
      + '<span class="ugx-dropcta">' + (has ? 'Replace' : 'Choose') + '</span>'
      + input
      + '</label>';
  }

  /** The poster row, which picks from the Media Library rather than from disk. */
  function ugxPosterRow(v) {
    var has = (v.poster_bytes || 0) > 0;

    return '<div class="ugx-drop' + (has ? '' : ' is-empty') + '" data-ugx-poster role="button" tabindex="0">'
      + '<span class="ugx-dropicon">' + ugxIcon('image') + '</span>'
      + '<span><span class="ugx-dropname">Poster</span>'
      + '<span class="ugx-dropmeta">' + (has ? esc(kb(v.poster_bytes)) + ' · ' : '')
      + 'Required to publish — the tile reserves its box from this image, which is what stops the page '
      + 'jumping.</span></span>'
      + '<span class="ugx-dropcta">' + (has ? 'Change' : 'Choose') + '</span>'
      + '</div>';
  }

  function modalHTML() {
    var v = editingVideo;
    /* `poster_path`, which is what UgcVideoController::card() returns for a
       single clip. The section LIST uses a different key (`poster`), so both
       are read rather than guessing which payload opened this dialog. */
    var poster = v.poster_path || v.poster || '';

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
        + 'which is what lets a tile loop a 2–3 second teaser — inside somebody else’s player it could not.</p>'
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
        + ugxFileRow('clip', 'film', 'Video', v.bytes,
            'Up to 64MB. Checked by its own bytes and not by its name.')
        + ugxPosterRow(v)
        + ugxFileRow('teaser', 'loop', '2–3 second loop', v.teaser_bytes,
            'Optional. Without it the tile shows its poster — a quieter rail, not a broken one.')
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

      + '<div class="ugx-body">' + body
      + (v.blockers && v.blockers.length
          ? '<div class="ugx-note is-warm"><b>Not publishable yet.</b> ' + v.blockers.map(esc).join(' ') + '</div>'
          : '')
      + '</div>'

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
      + '</div>'
      + (editingVideo ? modalHTML() : '');
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
    var mtab = t.closest('[data-ugx-mtab]');
    if (mtab) {
      e.preventDefault();
      modalTab = mtab.getAttribute('data-ugx-mtab');
      render();
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
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && editingVideo) { editingVideo = null; render(); }
  });

  addNavEntry();
})();
</script>
@endverbatim
