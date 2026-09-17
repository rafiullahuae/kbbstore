{{--
    Translation -> Language settings, Progress, Strings and Machine translation.
    (T1b, Lane FC)

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block and before the closing body tag, so this runs once
    the console's own script has defined window.go, toast() and the design
    tokens these screens borrow.

    Its own file rather than more lines inside a 19,000-line Blade: several
    lanes edit that file at once, and a screen that lives on its own can be
    reviewed, reverted and merged on its own. The shape is deliberately the same
    as admin/partials/review-settings-screen.blade.php; that is the precedent.

    WHAT THESE SCREENS ARE FOR. Nine endpoints have existed under
    /admin-api/translations/* since the bilingual foundation landed, and until
    this file nothing in the console called any of them. The Arabic module had a
    database, an API, Arabic boxes in six editors, a translated storefront and
    bilingual SEO, and no screen: the owner could not switch Arabic on without
    somebody running SQL for him. This is that screen, and it is where he turns
    Arabic on.

    NO NAV ENTRIES ARE ADDED HERE. Unlike the Coupons and New Order screens, all
    four ids are in app.blade.php's NAV const, in its own Translation group, and
    in TITLES -- the integrator's blocks put them there, and they are recorded
    verbatim in docs/T1B-ADMIN-APP-BLOCKS.md. Appending buttons here would give
    the owner each row twice. A parent menu cannot be created from a partial in
    any case: kbbAddNavEntry() joins groups and never invents one, because a
    group invented here would be a second place deciding the sidebar's shape.

    The four ids are ALSO ADDED TO LATE_RENDERED in app.blade.php, which is the
    other half and is not optional. go()'s dispatch object ends `||renderDash`,
    so an id that is routable but has no entry in it silently draws the
    DASHBOARD under its own breadcrumb -- and a deep link to ?go=tr-settings
    would land there, with nothing on the page to report. Each screen below
    paints synchronously before it awaits anything, which is the condition
    LATE_RENDERED requires: the replay's marker is already destroyed by the time
    its task runs, so nothing is drawn twice.

    THESE SCREENS SPEAK ENGLISH AND ARE NOT TRANSLATED. T8 -- translating the
    console itself -- is deferred by the owner, the back office is one operator,
    and Locale::localisable() already keeps the whole admin off the /ar prefix.

    EVERY EXPLANATORY SENTENCE ON THESE SCREENS COMES FROM THE SERVER. Not one
    of them is typed in here. The rule is not tidiness: this screen's entire job
    is explaining state, and a sentence written into a console is a second copy
    of an answer that goes stale silently the first time the behaviour changes.
    App\Support\TranslationConsole holds them, beside the code they describe;
    the settings and estimate endpoints return them. If a paragraph below is
    ever replaced by a literal, the screen has started lying on a schedule.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.

    The whole body is wrapped in one so that the braces inside JavaScript
    template literals are not read as Blade.
--}}
@verbatim
<style>
/* ---------------------------------------------------------------------------
   The Translation screens. Every rule is prefixed tr- and every id is tr-, so
   this file can never restyle or collide with another screen in the console.
   The console is one document: an unprefixed .card or #save here would reach
   into whatever else is mounted.

   Same layout rule as every screen beside it: nothing may be wider than its
   column at 390px, because the owner reviews on a phone. min-width:0 on the
   grid AND on its children is load-bearing rather than tidiness -- a grid
   item's default min-width is auto, "at least as wide as my content", so a card
   refuses to shrink below its widest child and drags the whole column past the
   viewport with no way to scroll back.
--------------------------------------------------------------------------- */
.tr-wrap{display:grid;gap:16px;min-width:0;max-width:1100px}
.tr-wrap > *{min-width:0}
.tr-card{background:var(--surface,#fff);border:1px solid var(--border,#e6e6e6);
         border-radius:var(--r,12px);padding:16px;min-width:0}
.tr-h{font-weight:650;font-size:15px;margin:0 0 4px}
.tr-sub{color:var(--ink-soft,#6b7280);font-size:12.5px;line-height:1.55;margin:0}
.tr-sub + .tr-sub{margin-top:7px}

/* A row that is a switch and its explanation. The explanation is never
   truncated: it is the reason the switch is safe to press. */
.tr-row{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;
        padding:13px 0;border-bottom:1px solid var(--border,#e6e6e6);min-width:0}
.tr-row:last-child{border-bottom:0;padding-bottom:0}
.tr-row:first-of-type{padding-top:0}
.tr-row-t{min-width:0;flex:1 1 auto}
.tr-row-t b{display:block;font-weight:600;font-size:13.5px;margin-bottom:3px}
.tr-row-c{flex:0 0 auto;padding-top:2px}

/* The switch. Its own markup rather than the console's .ectog, so a change to
   that class on another screen cannot silently restyle the two levers that
   publish a language and spend money. */
.tr-tog{display:inline-block;width:42px;height:24px;border-radius:999px;border:0;padding:0;
        background:rgba(127,127,127,.32);position:relative;cursor:pointer;transition:background .15s}
.tr-tog::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:18px;height:18px;
               border-radius:50%;background:#fff;transition:transform .15s}
.tr-tog.on{background:var(--accent,#15a85a)}
.tr-tog.on::after{transform:translateX(18px)}
.tr-tog[disabled]{opacity:.45;cursor:not-allowed}
.tr-tog:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}

/* A value the screen shows and does not let you change, with the reason beside
   it. Visibly not a control, so nobody hunts for the dropdown. */
.tr-fixed{display:inline-block;padding:3px 10px;border-radius:8px;font-size:13px;font-weight:600;
          border:1px solid var(--border,#e6e6e6);background:rgba(127,127,127,.07)}

/* The three notices. Colour is never the only signal -- each carries a word. */
.tr-note{border-radius:10px;padding:11px 13px;font-size:12.5px;line-height:1.6;
         border:1px solid var(--border,#e6e6e6);background:rgba(127,127,127,.06)}
.tr-note b{display:block;margin-bottom:3px;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
.tr-note.is-warn{border-color:#b7791f;color:#8a5b12;background:rgba(183,121,31,.08)}
.tr-note.is-bad{border-color:#b4443c;color:#8f332d;background:rgba(180,68,60,.08)}

.tr-btn{padding:8px 14px;border:1px solid var(--border,#e6e6e6);border-radius:9px;
        background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer}
.tr-btn:hover:not([disabled]){background:rgba(127,127,127,.08)}
.tr-btn.is-primary{background:var(--accent,#15a85a);border-color:var(--accent,#15a85a);color:#fff}
/* AFTER the hover rule above and more specific than it. `.tr-btn:hover` is
   three simple selectors to `.tr-btn.is-primary`'s two, so without this the
   grey hover fill wins on the primary buttons and "Yes — translate 1,250
   characters" goes white-on-white under the cursor that is about to press it.
   Caught in the screenshots, not by reading. */
.tr-btn.is-primary:hover:not([disabled]){background:var(--accent,#15a85a);filter:brightness(.93)}
.tr-btn.is-danger{border-color:#b4443c;color:#b4443c}
.tr-btn[disabled]{opacity:.45;cursor:not-allowed}
.tr-acts{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:14px}

.tr-field{display:block;margin-top:12px}

/* ── How to get a key ──────────────────────────────────────────────────────
   A <details>, so the guide is one line when a key is already saved and a full
   page when it is not. The summary keeps a real focus ring: this is the only
   control in the card a keyboard user can reach that is not a button, and the
   browser's default outline is what tells them where they are. */
.tr-guide{margin-top:18px;border-top:1px solid var(--line-2,var(--border,#e6e8eb));padding-top:14px}
.tr-guide > summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--ink,#111827);list-style:none;display:flex;align-items:center;gap:7px}
.tr-guide > summary::-webkit-details-marker{display:none}
.tr-guide > summary::before{content:'';width:0;height:0;border-left:5px solid currentColor;border-top:4px solid transparent;border-bottom:4px solid transparent;transition:transform .15s ease;flex:none}
.tr-guide[open] > summary::before{transform:rotate(90deg)}
@media (prefers-reduced-motion:reduce){.tr-guide > summary::before{transition:none}}
.tr-steps{margin:12px 0 0;padding-left:20px;display:flex;flex-direction:column;gap:13px}
.tr-steps li{font-size:12.5px;line-height:1.55;color:var(--ink-soft,#6b7280)}
.tr-steps li b{display:block;color:var(--ink,#111827);font-weight:600;margin-bottom:2px}
.tr-steps li a{display:inline-block;margin-top:5px;color:var(--brand,#0f7b4f);text-decoration:none;font-weight:600;word-break:break-word}
.tr-steps li a:hover,.tr-steps li a:focus-visible{text-decoration:underline}
.tr-guide .tr-sub{margin-top:14px}
.tr-field span{display:block;font-size:11.5px;font-weight:650;text-transform:uppercase;
               letter-spacing:.05em;color:var(--ink-soft,#6b7280);margin-bottom:5px}
.tr-field input,.tr-field select,.tr-field textarea{width:100%;box-sizing:border-box;padding:8px 10px;font:inherit;
        border:1px solid var(--border,#e6e6e6);border-radius:9px;background:transparent;color:inherit}
.tr-field textarea{min-height:66px;resize:vertical;line-height:1.5}

/* ---- progress -------------------------------------------------------- */
.tr-bar{position:relative;height:7px;border-radius:999px;background:rgba(127,127,127,.18);overflow:hidden}
.tr-bar i{position:absolute;inset-block:0;inset-inline-start:0;border-radius:999px;background:#1f7d52;display:block}
.tr-bar i.is-part{background:#b7791f}
.tr-bar i.is-none{background:transparent}

/* THE PILL IS GREEN ONLY AT 100, and 100 now means every field. The server
   reserves it: see TranslationEstimate::percent(). Before that fix
   (int) round(751/752*100) was 100 and this pill went green on a locale that
   was one interface string short -- on the screen whose only job is answering
   "is this finished?". */
.tr-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:650;
         border:1px solid var(--border,#e6e6e6);color:var(--ink-soft,#6b7280);white-space:nowrap}
.tr-pill.is-done{border-color:#1f7d52;color:#1f7d52;background:rgba(31,125,82,.09)}
.tr-pill.is-part{border-color:#b7791f;color:#8a5b12;background:rgba(183,121,31,.09)}
.tr-pill.is-draft{border-color:#5b6bb4;color:#4a58a0;background:rgba(91,107,180,.09)}

.tr-areas{display:grid;gap:13px;margin-top:4px}
.tr-area{display:grid;gap:7px;min-width:0}
.tr-area-h{display:flex;gap:10px;align-items:baseline;justify-content:space-between;flex-wrap:wrap}
.tr-area-h b{font-weight:600;font-size:13.5px}
.tr-area-n{font-size:12px;color:var(--ink-soft,#6b7280);font-variant-numeric:tabular-nums}

.tr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px;min-width:0}
.tr-stat{border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:13px 15px;min-width:0}
.tr-stat span{display:block;color:var(--ink-soft,#6b7280);font-size:11px;font-weight:650;
              text-transform:uppercase;letter-spacing:.05em}
.tr-stat b{display:block;font-size:22px;line-height:1.25;font-variant-numeric:tabular-nums;margin-top:3px}
.tr-stat em{display:block;font-style:normal;font-size:11.5px;color:var(--ink-soft,#6b7280);margin-top:4px}

/* ---- the string list ------------------------------------------------- */
/* The filter bar sticks, because the owner will spend real hours on this screen
   and scrolling 752 rows back to the top to change one filter is the difference
   between a tool and a wall. */
.tr-filters{position:sticky;top:0;z-index:3;background:var(--surface,#fff);
            border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:12px;
            display:grid;gap:9px;grid-template-columns:repeat(auto-fit,minmax(min(170px,100%),1fr));min-width:0}
.tr-filters label{margin:0}
.tr-check{display:flex;gap:8px;align-items:center;font-size:13px;align-self:end;padding-bottom:9px}
.tr-check input{width:auto}

.tr-list{display:grid;gap:10px;min-width:0}
.tr-item{border:1px solid var(--border,#e6e6e6);border-radius:var(--r,12px);padding:12px;
         display:grid;gap:8px;min-width:0}
.tr-item.is-saving{opacity:.6}
.tr-item-h{display:flex;gap:9px;align-items:baseline;justify-content:space-between;flex-wrap:wrap;min-width:0}
.tr-key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;
        color:var(--ink-soft,#6b7280);word-break:break-all}
.tr-en{font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word}
/* The Arabic box reads right-to-left whatever the console's own direction is:
   it holds Arabic, and typing Arabic into a left-to-right box puts the
   punctuation on the wrong end. */
.tr-ar{direction:rtl;text-align:right;font-size:14px}
.tr-more{display:flex;justify-content:center;padding:6px 0}
.tr-empty{padding:26px 10px;text-align:center;color:var(--ink-soft,#6b7280);font-size:13px}

.tr-table{width:100%;border-collapse:collapse;font-size:13px}
.tr-table th,.tr-table td{text-align:start;padding:8px 10px;border-bottom:1px solid var(--border,#e6e6e6);
                          vertical-align:middle}
.tr-table th{font-weight:600;color:var(--ink-soft,#6b7280);font-size:11.5px;
             text-transform:uppercase;letter-spacing:.04em}
.tr-table td.tr-num,.tr-table th.tr-num{text-align:end;font-variant-numeric:tabular-nums;white-space:nowrap}
.tr-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;max-width:100%}

@media (max-width:640px){
  .tr-card,.tr-filters{padding:13px}
  .tr-row{flex-direction:column;gap:9px}
  .tr-row-c{padding-top:0}
}
</style>

<script>
(function(){
  'use strict';

  /* The console builds its sidebar and its router before this runs. NAV,
     TITLES and ADMIN_BASE are const inside that script's own scope, so neither
     can be read from here -- the router is wrapped instead, which is the
     surface the console exposes for exactly this. */

  var SETTINGS = 'tr-settings';
  var PROGRESS = 'tr-progress';
  var STRINGS  = 'tr-strings';
  var MACHINE  = 'tr-machine';

  /* id -> the page title app.blade.php's TITLES gives it. Kept beside the ids
     so a rename is one edit here and one block in the integrator's document,
     and AdminNavAndIdsTest compares the two. */
  var TITLE = {};
  TITLE[SETTINGS] = 'Language settings';
  TITLE[PROGRESS] = 'Progress';
  TITLE[STRINGS]  = 'Strings';
  TITLE[MACHINE]  = 'Machine translation';

  var GROUP = 'Translation';
  var LOCALE = 'ar';

  /* The groups the list and the run can be narrowed to: `ui` is the interface
     strings and the rest are table names, which is what the endpoints call a
     group.

     THE LIST AND THE NAMES ARE THE SERVER'S wherever it has answered — see
     groupList() below. This is the fallback drawn in the instant before the
     first payload lands, and it exists only so the selector is never empty. A
     group added to TranslationEstimate::CONTENT therefore appears in the
     selector without this array being touched; if it is ever out of date, it is
     out of date for one paint. */
  var GROUPS = [
    ['ui', 'Interface text'],
    ['products', 'Products'],
    ['categories', 'Categories'],
    ['brands', 'Brands'],
    ['pages', 'Pages'],
    ['posts', 'Journal posts'],
    ['menu_items', 'Menu labels']
  ];

  /*
   * The areas, named by whichever endpoint has already said so.
   *
   * progress() and forLocale() both key their payload by group and both carry a
   * label per group, computed by TranslationEstimate::label(). Reading the list
   * off one of them means the selector cannot list a group the server does not
   * have, or call one by a name the Progress screen spells differently.
   */
  function groupList(){
    var src = (progress && progress.areas) || (estimate && estimate.groups) || null;

    if (!src) return GROUPS;

    var out = [];
    Object.keys(src).forEach(function(key){
      out.push([key, src[key].label || key]);
    });

    return out.length ? out : GROUPS;
  }

  var PAGE_SIZE = 50;

  /* ---------------------------------------------------------------- state */
  var settings = null;      // GET /translations/settings
  var progress = null;      // GET /translations/progress
  var estimate = null;      // GET /translations/estimate
  var rows = null;          // GET /translations
  var shown = PAGE_SIZE;    // how many of `rows` are drawn
  var filters = { group: 'ui', missing: false, q: '', limit: 200 };
  var apiKeyDraft = '';
  var confirming = null;    // the run the owner has been shown and not yet authorised
  var runResult = null;
  var banner = null;        // {kind, text}
  /*
   * ── ONE COUNTER PER RESOURCE, NOT ONE FOR THE SCREEN ────────────────────
   *
   * Opening Machine translation fires TWO requests at once — the settings
   * payload, which decides what every screen may draw, and the estimate. A
   * single shared counter made the second of them cancel the first: whichever
   * answered LAST was the only one allowed to repaint, so when the estimate
   * came back first the screen was painted while `settings` was still null and
   * then never painted again. It read "Not loaded." with both requests
   * successful and nothing in the console. Measured in Chromium as an editor,
   * which is simply the run in which the two happened to land that way round.
   *
   * So: one sequence per resource, which is what the guard was ever for —
   * dropping an OLDER answer for the SAME thing, such as a search two
   * keystrokes behind. And a count of requests in flight rather than a
   * boolean, so two overlapping loads cannot clear each other's "busy".
   */
  var seqs = {};
  var inflight = 0;

  function nextSeq(key){
    seqs[key] = (seqs[key] || 0) + 1;
    return seqs[key];
  }

  function busy(){ return inflight > 0; }
  var cur = null;           // which of the four screens is on the page

  /* ------------------------------------------------------------- plumbing */
  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  var BASE = window.location.pathname.replace(/\/+$/, '');

  async function api(path, method, payload){
    var opts = {
      method: method || 'GET',
      headers: {'Accept':'application/json'},
      credentials: 'same-origin'
    };
    opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');

    if (payload !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(payload);
    }

    var r = await fetch(BASE.replace(/\/[^\/]*$/, '') + '/admin-api' + path, opts);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }

    if (!r.ok) {
      var err = new Error('api ' + path + ' -> ' + r.status);
      err.status = r.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  /*
   * WHAT WENT WRONG, IN THE SERVER'S OWN WORDS WHERE IT GAVE ANY.
   *
   * Every endpoint here answers a refusal with a `message`, and
   * EnforceAdminCapability answers a 403 with one naming the capability. The
   * screen prints it rather than substituting a sentence of its own, for the
   * same reason none of the explanatory prose on these screens is typed in: the
   * server knows why it said no and the console is guessing.
   *
   * The 404 is the exception, and it is a guess the console is entitled to
   * make: on this host a route that is not in the compiled table does not
   * exist, so a 404 from an endpoint that is in the repo means the package
   * shipped without its cache clear. An empty screen would read as "there is
   * nothing to translate".
   */
  function explain(e, fallback){
    if (e && e.body && typeof e.body.message === 'string' && e.body.message) return e.body.message;
    if (e && e.status === 404) {
      return 'The translation endpoints are not registered on this server yet. The package that '
           + 'adds them also clears the compiled route table; if it was applied by hand, clear the '
           + 'route cache and reload.';
    }
    return fallback;
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function num(n){ return Number(n || 0).toLocaleString('en-US'); }
  function say(msg){ try { window.toast(msg); } catch (e) {} }

  function can(name){
    return !!(settings && settings.can && settings.can[name]);
  }

  /* The pill's class. Green at 100 and nowhere else -- and 100 is the server's
     word for "every field", not a rounded 99.9. See TranslationEstimate. */
  function pillClass(percent){
    if (percent >= 100) return ' is-done';
    if (percent > 0) return ' is-part';
    return '';
  }

  function bar(percent){
    var cls = percent >= 100 ? '' : (percent > 0 ? ' is-part' : ' is-none');
    return '<span class="tr-bar"><i class="' + cls.trim() + '" style="width:' + Math.max(0, Math.min(100, percent)) + '%"></i></span>';
  }

  function noteHTML(kind, label, text){
    if (!text) return '';
    return '<div class="tr-note' + (kind ? ' is-' + kind : '') + '"><b>' + esc(label) + '</b>' + esc(text) + '</div>';
  }

  function bannerHTML(){
    if (!banner) return '';
    return noteHTML(banner.kind, banner.kind === 'bad' ? 'Problem' : 'Note', banner.text);
  }

  /* Every screen prints this when the signed-in admin is missing one of the two
     owner-only levers. The sentence is the server's: TranslationConsole
     ::capabilityNote() builds it from the capabilities the endpoints actually
     enforce, so a control hidden here is a control that really would 403. */
  function capabilityHTML(){
    if (!settings || !settings.capability_note) return '';
    return noteHTML('', 'Your permissions', settings.capability_note);
  }

  function togHTML(name, on, enabled){
    return '<button type="button" class="tr-tog' + (on ? ' on' : '') + '" data-tr-tog="' + name + '"'
         + ' role="switch" aria-checked="' + (on ? 'true' : 'false') + '"'
         + (enabled ? '' : ' disabled aria-disabled="true"') + '></button>';
  }

  function rowHTML(title, body, control){
    return '<div class="tr-row"><div class="tr-row-t"><b>' + esc(title) + '</b>'
         + '<p class="tr-sub">' + body + '</p></div>'
         + '<div class="tr-row-c">' + control + '</div></div>';
  }

  /* =============================== screen 1: settings =============================== */
  /* ---------------------------------------------------------------------------
     HOW TO GET A KEY, under the box that wants one.

     A field asking for something the owner does not have and cannot guess is a
     dead end, and this one asks for a credential from somebody else's console
     six clicks deep. The steps come from the SERVER
     (TranslationConsole::setupGuide()), not from here, for the reason that
     applies to every sentence on these screens: they are true of one provider,
     and swapping the provider has to change them in the same commit.

     OPEN WHEN THERE IS NO KEY, COLLAPSED WHEN THERE IS. With no key, this is
     what the screen is for and it should not need a click. With a key saved,
     the owner is usually here to replace one — the guide is still the right
     answer, just not the first thing he should have to scroll past.

     Every link opens in a new tab with rel="noopener": these leave for Google's
     console, and a console tab that can reach back into this one through
     window.opener is a needless hole. The MENU PATH is printed beside each link
     on purpose — a deep link into somebody else's product is the part of this
     that rots first, and a moved page should cost a look rather than a dead end.
  --------------------------------------------------------------------------- */
  function setupGuideHTML(s){
    var g = s && s.setup_guide;
    if (!g || !g.steps || !g.steps.length) return '';

    var open = s.has_api_key ? '' : ' open';

    return '<details class="tr-guide"' + open + '>'
      + '<summary>' + esc(g.heading) + '</summary>'
      + '<ol class="tr-steps">'
      + g.steps.map(function(step){
          return '<li>'
            + '<b>' + esc(step.title) + '</b>'
            + '<div>' + esc(step.body) + '</div>'
            + (step.url
                ? '<a href="' + esc(step.url) + '" target="_blank" rel="noopener noreferrer">'
                  + esc(step.link_label || step.url) + ' \u2197</a>'
                : '')
            + '</li>';
        }).join('')
      + '</ol>'
      + (g.closing ? '<p class="tr-sub">' + esc(g.closing) + '</p>' : '')
      + '</details>';
  }

  function settingsView(){
    if (!settings) return '<div class="tr-card tr-empty">' + (busy() ? 'Loading…' : 'Not loaded.') + '</div>';

    var editable = can('settings');
    var s = settings;

    var switches = '<div class="tr-card">'
      + '<p class="tr-h">The two switches</p>'
      + '<p class="tr-sub">They are separate on purpose. A language being right-to-left is a fact about the '
      + 'language; whether this shop\'s layout has been mirrored is a fact about the shop, and the two finish '
      + 'at different times.</p>'
      + '<div style="margin-top:12px">'
      + rowHTML('Arabic storefront',
          'Off means /ar does not exist at all — not empty, absent.',
          togHTML('arabic_enabled', s.arabic_enabled, editable))
      + rowHTML('Right-to-left layout',
          'Mirrors the storefront. Switchable on its own, so an unfinished mirrored layout can be turned back '
          + 'without taking Arabic down.',
          togHTML('rtl_enabled', s.rtl_enabled, editable))
      + rowHTML('Highlight untranslated text in the admin',
          'Marks strings with no Arabic yet while you work. Changes nothing a shopper sees.',
          togHTML('highlight_missing', s.highlight_missing, editable))
      + '</div>'
      /* THE AWKWARD COMBINATION, PRINTED AND NOT PREVENTED. The endpoint decides
         whether there is one and what it says; this only decides where it goes.
         He asked for the control, and a control that refuses the state you asked
         for is not a control. */
      + (s.warning ? '<div style="margin-top:13px">' + noteHTML('warn', 'Worth knowing', s.warning) + '</div>' : '')
      + '</div>';

    var addresses = '<div class="tr-card">'
      + '<p class="tr-h">Default language, and what a bare / serves</p>'
      + '<div style="margin-top:10px">'
      + rowHTML('Default language', esc(s.default_locale_note),
          '<span class="tr-fixed">' + esc(s.default_locale_name) + '</span>')
      + '</div>'
      + '<p class="tr-sub" style="margin-top:12px">' + esc(s.root_serves) + '</p>'
      + '</div>';

    var key = '<div class="tr-card">'
      + '<p class="tr-h">Machine translation key</p>'
      + '<p class="tr-sub">' + esc(s.provider_note) + '</p>'
      + (editable
          ? '<label class="tr-field"><span>' + (s.has_api_key ? 'Replace the saved key' : 'API key') + '</span>'
            + '<input type="password" id="tr-key" autocomplete="off" spellcheck="false"'
            + ' placeholder="' + (s.has_api_key ? 'A key is saved. Leave blank to keep it.' : 'Paste your own key') + '"'
            + ' value="' + esc(apiKeyDraft) + '"></label>'
            + '<p class="tr-sub" style="margin-top:7px">The key is stored encrypted and is never sent back to this '
            + 'screen — it can be replaced or removed, not read. A blank box means "leave it alone".</p>'
            + '<div class="tr-acts">'
            + '<button type="button" class="tr-btn is-primary" data-tr-act="save-key"' + (busy() ? ' disabled' : '') + '>Save key</button>'
            + (s.has_api_key
                ? '<button type="button" class="tr-btn is-danger" data-tr-act="clear-key"' + (busy() ? ' disabled' : '') + '>Remove the saved key</button>'
                : '')


            + '</div>'
          : '<p class="tr-sub" style="margin-top:10px">' + (s.has_api_key ? 'A key is saved.' : 'No key is saved.')
            + '</p>')
      + setupGuideHTML(s)
      + '</div>';

    return '<div class="tr-wrap">' + bannerHTML() + capabilityHTML() + switches + addresses + key + '</div>';
  }

  /* =============================== screen 2: progress =============================== */
  function progressView(){
    if (!progress) return '<div class="tr-wrap">' + bannerHTML() + '<div class="tr-card tr-empty">'
      + (busy() ? 'Counting…' : 'Not loaded.') + '</div></div>';

    var p = progress;

    var head = '<div class="tr-card">'
      + '<div class="tr-area-h"><p class="tr-h" style="margin:0">Arabic, counted</p>'
      + '<span class="tr-pill' + pillClass(p.percent) + '">' + p.percent + '%</span></div>'
      + '<p class="tr-sub">Counted from the rows that exist, not from anything claimed. A blank Arabic box '
      + 'deletes its row rather than storing an empty one, which is the only reason these figures can be '
      + 'trusted: a field either has a translation or it does not.</p>'
      + '<div style="margin-top:12px">' + bar(p.percent) + '</div>'
      + '<div class="tr-stats" style="margin-top:14px">'
      + '<div class="tr-stat"><span>Fields with English</span><b>' + num(p.total) + '</b></div>'
      + '<div class="tr-stat"><span>Published in Arabic</span><b>' + num(p.translated) + '</b></div>'
      + '<div class="tr-stat"><span>Drafts awaiting approval</span><b>' + num(p.drafts) + '</b>'
      + '<em>Machine output. No shopper sees a draft.</em></div>'
      + '</div>'
      + (p.drafts > 0 && can('strings')
          ? '<div class="tr-acts"><button type="button" class="tr-btn" data-tr-act="publish-all"' + (busy() ? ' disabled' : '') + '>'
            + 'Approve all ' + num(p.drafts) + ' drafts</button></div>'
          : '')
      + '</div>';

    var areas = Object.keys(p.areas || {}).map(function(key){
      var a = p.areas[key];

      /* AN EMPTY AREA IS NOT A FINISHED ONE, however the arithmetic reads.
         The server answers 100 for a total of 0, and that is the honest reading
         of "nothing left to do" — but a full green bar beside "0 of 0" claims
         work that was never there. Said in words instead. */
      if (!a.total) {
        return '<div class="tr-area">'
          + '<div class="tr-area-h"><b>' + esc(a.label) + '</b>'
          + '<span class="tr-area-n"><span class="tr-pill">nothing to translate</span></span></div>'
          + bar(0)
          + '</div>';
      }

      return '<div class="tr-area">'
        + '<div class="tr-area-h"><b>' + esc(a.label) + '</b>'
        + '<span class="tr-area-n">' + num(a.translated) + ' of ' + num(a.total)
        + (a.drafts > 0 ? ' · <span class="tr-pill is-draft">' + num(a.drafts) + ' draft</span>' : '')
        + ' <span class="tr-pill' + pillClass(a.percent) + '">' + a.percent + '%</span></span></div>'
        + bar(a.percent)
        + '</div>';
    }).join('');

    return '<div class="tr-wrap">' + bannerHTML() + capabilityHTML() + head
      + '<div class="tr-card"><p class="tr-h">By area</p>'
      + '<div class="tr-areas" style="margin-top:12px">' + areas + '</div></div>'
      + '</div>';
  }

  /* =============================== screen 3: the strings =============================== */
  function filtersView(){
    var opts = groupList().map(function(g){
      return '<option value="' + g[0] + '"' + (filters.group === g[0] ? ' selected' : '') + '>' + esc(g[1]) + '</option>';
    }).join('');

    return '<div class="tr-filters">'
      + '<label class="tr-field" style="margin:0"><span>Area</span><select id="tr-group">' + opts + '</select></label>'
      + '<label class="tr-field" style="margin:0"><span>Search the English</span>'
      + '<input type="search" id="tr-q" value="' + esc(filters.q) + '" placeholder="part of a key or a phrase"></label>'
      + '<label class="tr-field" style="margin:0"><span>Rows to fetch</span>'
      + '<select id="tr-limit">'
      + [50,100,200,500].map(function(n){
          return '<option value="' + n + '"' + (filters.limit === n ? ' selected' : '') + '>' + n + '</option>';
        }).join('')
      + '</select></label>'
      + '<label class="tr-check"><input type="checkbox" id="tr-missing"' + (filters.missing ? ' checked' : '') + '>'
      + '<span>Only what is untranslated</span></label>'
      + '</div>';
  }

  function itemHTML(r, i){
    var writable = can('strings');

    var status = r.unsaved
      ? '<span class="tr-pill is-part">not saved yet</span>'
      : (r.value
          ? '<span class="tr-pill' + (r.status === 'draft' ? ' is-draft' : ' is-done') + '">'
            + (r.status === 'draft' ? 'draft' : 'published') + '</span>'
          : '<span class="tr-pill">untranslated</span>');

    var stale = r.stale
      ? ' <span class="tr-pill is-part">English changed since</span>'
      : '';

    var unsafe = (r.machine_safe === false)
      ? ' <span class="tr-pill">typed by hand only</span>'
      : '';

    return '<div class="tr-item" data-tr-row="' + i + '">'
      + '<div class="tr-item-h"><span class="tr-key">' + esc(r.group) + (r.item_id ? ' #' + r.item_id : '')
      + ' · ' + esc(r.field) + '</span><span>' + status + stale + unsafe + '</span></div>'
      + '<div class="tr-en">' + esc(r.english) + '</div>'
      + '<textarea class="tr-ar" dir="rtl" lang="ar" data-tr-ar="' + i + '"'
      + (writable ? '' : ' readonly')
      + ' placeholder="Arabic — leave blank for untranslated">' + esc(r.value) + '</textarea>'
      /* A control nobody can use is not drawn. An editor who cannot write, or a
         console with no API key, gets a row that reads honestly rather than a
         button that answers 403 when pressed. */
      + (writable || can('machine_field')
          ? '<div class="tr-acts" style="margin-top:0">'
            + (writable
                ? '<button type="button" class="tr-btn" data-tr-act="save-row" data-tr-i="' + i + '"'
                  + (busy() ? ' disabled' : '') + '>Save</button>'
                : '')
            + (can('machine_field') && settings && settings.provider_available
                ? '<button type="button" class="tr-btn" data-tr-act="tr-row" data-tr-i="' + i + '"'
                  + (busy() ? ' disabled' : '') + '>Translate this one</button>'
                : '')
            + '</div>'
          : '')
      + '</div>';
  }

  function stringsView(){
    var body;

    if (rows === null) {
      body = '<div class="tr-card tr-empty">' + (busy() ? 'Loading…' : 'Not loaded.') + '</div>';
    } else if (rows.length === 0) {
      body = '<div class="tr-card tr-empty">'
        + (filters.missing
            ? 'Nothing untranslated in this area matches. Clear the filter to see what is already done.'
            : 'No strings in this area match.')
        + '</div>';
    } else {
      var slice = rows.slice(0, shown);
      body = '<div class="tr-list">' + slice.map(itemHTML).join('') + '</div>'
        + (rows.length > shown
            ? '<div class="tr-more"><button type="button" class="tr-btn" data-tr-act="more">'
              + 'Show ' + Math.min(PAGE_SIZE, rows.length - shown) + ' more of ' + num(rows.length) + '</button></div>'
            : '');
    }

    var writable = can('strings');

    return '<div class="tr-wrap">'
      + bannerHTML() + capabilityHTML()
      + filtersView()
      + '<div class="tr-card"><p class="tr-h">One string at a time</p>'
      + '<p class="tr-sub">A blank box means untranslated and removes the row — it never means "the same as '
      + 'English". To say deliberately identical, type the English in. What you type here is published '
      + 'immediately; only machine output waits for approval.</p>'
      + (rows !== null
          ? '<p class="tr-sub">Showing ' + num(Math.min(shown, rows.length)) + ' of ' + num(rows.length)
            + ' fetched. Narrow with the filters above rather than scrolling — the fetch is capped at '
            + filters.limit + ' rows.</p>'
          : '')
      + (writable ? '' : '<p class="tr-sub">Your role cannot write translations, so the boxes below are read-only.</p>')
      + '</div>'
      + body
      + '</div>';
  }

  /* =============================== screen 4: machine translation =============================== */
  function machineView(){
    if (!settings) return '<div class="tr-wrap">' + bannerHTML() + '<div class="tr-card tr-empty">'
      + (busy() ? 'Loading…' : 'Not loaded.') + '</div></div>';

    var provider = '<div class="tr-card">'
      + '<p class="tr-h">Provider</p>'
      + '<div style="margin-top:10px">'
      + rowHTML('Service', esc(settings.provider_note),
          '<span class="tr-fixed">' + esc(settings.provider) + '</span>')
      + rowHTML('Your API key',
          'The key is yours and the bill is yours. It is owner-only, and it is stored encrypted and never read '
          + 'back to this screen.',
          '<span class="tr-fixed">' + (settings.has_api_key ? 'saved' : 'not set') + '</span>')
      + '</div>'
      + (can('settings')
          ? '<div class="tr-acts"><button type="button" class="tr-btn" data-tr-act="go-settings">Open Language settings to change the key</button></div>'
          : '')
      + '</div>';

    if (!estimate) {
      return '<div class="tr-wrap">' + bannerHTML() + capabilityHTML() + provider
        + '<div class="tr-card tr-empty">' + (busy() ? 'Counting…' : 'The estimate has not been read yet.') + '</div></div>';
    }

    var e = estimate;
    var run = e.run || {characters:0, fields:0, usd_display:'', limit:0};

    /* The area the ESTIMATE was taken for, which is the area the button will
       send. Not the Strings screen's filter: the two screens are read at
       different moments and a selector showing one while the figure beside it
       described the other is exactly the mistake the confirm step exists to
       make impossible. */
    var runGroupNow = run.group || '';
    var groupOpts = '<option value=""' + (runGroupNow === '' ? ' selected' : '') + '>Everything, in the order above</option>'
      + groupList().map(function(g){
          return '<option value="' + g[0] + '"' + (runGroupNow === g[0] ? ' selected' : '') + '>' + esc(g[1]) + '</option>';
        }).join('');

    var whole = '<div class="tr-card">'
      + '<p class="tr-h">What is still outstanding, across the whole shop</p>'
      + '<div class="tr-stats" style="margin-top:12px">'
      + '<div class="tr-stat"><span>Characters of English</span><b>' + num(e.characters) + '</b>'
      + '<em>Counted on the source text, spaces included — that is what is billed.</em></div>'
      + '<div class="tr-stat"><span>Fields</span><b>' + num(e.fields) + '</b></div>'
      + '<div class="tr-stat"><span>Months at the free allowance</span><b>' + num(e.months_at_free_tier) + '</b>'
      + '<em>If you never want to pay anything.</em></div>'
      + '</div>'
      + '<p class="tr-sub" style="margin-top:13px">' + esc(e.free_tier_note) + '</p>'
      + '<p class="tr-sub">' + esc(e.note) + '</p>'
      + '</div>';

    var byGroup = '<div class="tr-card"><p class="tr-h">By area</p><div class="tr-scroll" style="margin-top:10px">'
      + '<table class="tr-table"><thead><tr><th>Area</th><th class="tr-num">Fields</th>'
      + '<th class="tr-num">Already done</th><th class="tr-num">Characters left</th></tr></thead><tbody>'
      + Object.keys(e.groups || {}).map(function(key){
          var g = e.groups[key];
          return '<tr><td>' + esc(g.label || key) + '</td><td class="tr-num">' + num(g.fields) + '</td>'
               + '<td class="tr-num">' + num(g.translated) + '</td>'
               + '<td class="tr-num">' + num(g.characters) + '</td></tr>';
        }).join('')
      + '</tbody></table></div></div>';

    var runnable = can('machine_run') && e.provider_available;

    var control = '<div class="tr-card">'
      + '<p class="tr-h">The batch run</p>'
      + '<p class="tr-sub">Everything it writes is a draft. Nothing it writes is visible to a shopper until you '
      + 'have read it and pressed Approve on the Progress screen. Product descriptions are never sent: they are '
      + 'HTML, and a machine returns them mangled, so they are typed by hand and counted as outstanding until '
      + 'they are.</p>'
      + '<label class="tr-field"><span>Fields per run</span>'
      + '<select id="tr-run-limit">'
      + [25,100,250,500,1000,2000].map(function(n){
          return '<option value="' + n + '"' + (Number(run.limit) === n ? ' selected' : '') + '>' + n + '</option>';
        }).join('')
      + '</select></label>'
      + '<label class="tr-field"><span>Area</span><select id="tr-run-group">' + groupOpts + '</select></label>'
      + '<div class="tr-stats" style="margin-top:14px">'
      + '<div class="tr-stat"><span>This run would send</span><b>' + num(run.characters) + '</b>'
      + '<em>characters, across ' + num(run.fields) + ' fields</em></div>'
      + '<div class="tr-stat"><span>At list price</span><b>' + esc(run.usd_display) + '</b>'
      + '<em>' + esc(e.currency) + ', Google\'s published rate — not this shop\'s currency, and not converted.</em></div>'
      + '</div>'
      + (runnable
          ? (confirming
              ? noteHTML('warn', 'Press again to spend',
                  'This will send ' + num(confirming.characters) + ' characters to ' + settings.provider
                  + ' and bill your account ' + confirming.usd_display + '. Nothing has been sent yet.')
                + '<div class="tr-acts">'
                + '<button type="button" class="tr-btn is-primary" data-tr-act="run-confirm"' + (busy() ? ' disabled' : '') + '>'
                + 'Yes — translate ' + num(confirming.characters) + ' characters</button>'
                + '<button type="button" class="tr-btn" data-tr-act="run-cancel">Cancel</button>'
                + '</div>'
              : '<div class="tr-acts"><button type="button" class="tr-btn is-primary" data-tr-act="run"'
                + ((busy() || run.fields === 0) ? ' disabled' : '') + '>'
                + (run.fields === 0 ? 'Nothing outstanding to send' : 'Translate these ' + num(run.fields) + ' fields')
                + '</button></div>')
          : '<p class="tr-sub" style="margin-top:14px">'
            + (can('machine_run')
                ? 'The run is off because no service is connected. Everything above is still true and still free '
                  + 'to read; typing the Arabic in by hand needs no key.'
                : 'Your role cannot start a batch run — it spends the owner\'s money. Ask the owner.')
            + '</p>')
      + (runResult
          ? '<div style="margin-top:14px">' + noteHTML('', 'Last run',
              num(runResult.translated) + ' of ' + num(runResult.requested) + ' fields translated, '
              + num(runResult.characters) + ' characters sent'
              + (runResult.skipped ? ', ' + num(runResult.skipped) + ' skipped' : '') + '. '
              + (runResult.note || '')
              + ((runResult.errors && runResult.errors.length) ? ' ' + runResult.errors.join(' ') : '')) + '</div>'
          : '')
      + '</div>';

    return '<div class="tr-wrap">' + bannerHTML() + capabilityHTML() + provider + whole + byGroup + control + '</div>';
  }

  /* ---------------------------------------------------------------- render */
  var VIEW = {};
  VIEW[SETTINGS] = settingsView;
  VIEW[PROGRESS] = progressView;
  VIEW[STRINGS]  = stringsView;
  VIEW[MACHINE]  = machineView;

  function render(){
    if (!cur) return;

    var box = document.querySelector('#content');
    if (!box) return;

    /*
     * THE CARET SURVIVES THE REPAINT, and this is not a nicety on this screen.
     *
     * Every render replaces the whole of #content, and a render is triggered by
     * each request starting and finishing. Two boxes are typed in while that is
     * happening:
     *
     *   the search box — the search is the SERVER'S, so each keystroke
     *     schedules a fetch, and the fetch repaints. Without this the box loses
     *     focus mid-word and the owner types "ca", then "rt" into nothing. That
     *     is the screen he will spend hours in.
     *   an Arabic box — saving one row repaints all of them, and the caret
     *     would jump to the end of a different field.
     *
     * The element is found again by a selector rather than kept by reference,
     * because the old node is gone: innerHTML destroyed it.
     */
    var active = document.activeElement;
    var keep = null;

    if (active && box.contains(active) && (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT')) {
      var selector = active.dataset && active.dataset.trAr !== undefined
        ? '[data-tr-ar="' + active.dataset.trAr + '"]'
        : (active.id ? '#' + active.id : null);

      if (selector) {
        keep = {selector: selector, start: null, end: null};
        // selectionStart throws on some input types rather than returning null.
        try { keep.start = active.selectionStart; keep.end = active.selectionEnd; } catch (e) {}
      }
    }

    box.innerHTML = VIEW[cur]();
    wire();

    if (keep) {
      var back = box.querySelector(keep.selector);
      if (back) {
        back.focus();
        if (keep.start !== null) {
          try { back.setSelectionRange(keep.start, keep.end); } catch (e) {}
        }
      }
    }
  }

  var qTimer = null;

  function wire(){
    var box = document.querySelector('#content');
    if (!box) return;

    box.querySelectorAll('[data-tr-tog]').forEach(function(b){
      b.onclick = function(){ toggle(b.dataset.trTog); };
    });

    box.querySelectorAll('[data-tr-act]').forEach(function(b){
      b.onclick = function(){ act(b.dataset.trAct, b.dataset.trI); };
    });

    var key = box.querySelector('#tr-key');
    if (key) key.oninput = function(){ apiKeyDraft = key.value; };

    var group = box.querySelector('#tr-group');
    if (group) group.onchange = function(){ filters.group = group.value; shown = PAGE_SIZE; loadRows(); };

    var limit = box.querySelector('#tr-limit');
    if (limit) limit.onchange = function(){ filters.limit = parseInt(limit.value, 10) || 200; shown = PAGE_SIZE; loadRows(); };

    var missing = box.querySelector('#tr-missing');
    if (missing) missing.onchange = function(){ filters.missing = missing.checked; shown = PAGE_SIZE; loadRows(); };

    var q = box.querySelector('#tr-q');
    if (q) q.oninput = function(){
      filters.q = q.value;
      shown = PAGE_SIZE;
      /* Debounced, because the search is the server's -- it has to be, or
         "untranslated only" and the row cap would be applied to a page rather
         than to the area, and the owner would be searching whatever happened to
         have been fetched. */
      if (qTimer) clearTimeout(qTimer);
      qTimer = setTimeout(loadRows, 280);
    };

    var runLimit = box.querySelector('#tr-run-limit');
    if (runLimit) runLimit.onchange = function(){ confirming = null; loadEstimate(parseInt(runLimit.value, 10), currentRunGroup()); };

    var runGroup = box.querySelector('#tr-run-group');
    if (runGroup) runGroup.onchange = function(){ confirming = null; loadEstimate(currentRunLimit(), runGroup.value); };
  }

  function currentRunLimit(){
    var el = document.querySelector('#tr-run-limit');
    return el ? (parseInt(el.value, 10) || 100) : ((estimate && estimate.run && estimate.run.limit) || 100);
  }

  function currentRunGroup(){
    var el = document.querySelector('#tr-run-group');
    return el ? el.value : ((estimate && estimate.run && estimate.run.group) || '');
  }

  /* ------------------------------------------------------------------ data */
  /*
   * One read. `apply` is handed the payload, or null when the read failed, and
   * is called ONLY if this is still the newest read of that resource — so a
   * stale answer is dropped rather than overwriting a newer one. The repaint in
   * `finally` is unconditional, because the thing that must be redrawn is the
   * request count, which changed whichever answer this was.
   */
  async function read(key, url, apply, fallback){
    var mine = nextSeq(key);
    inflight++;
    render();

    try {
      var body = await api(url);
      if (mine === seqs[key]) { apply(body); banner = null; }
    } catch (e) {
      if (mine === seqs[key]) {
        apply(null);
        banner = {kind:'bad', text: explain(e, fallback)};
      }
    } finally {
      inflight--;
      render();
    }
  }

  function loadSettings(){
    return read('settings', '/translations/settings',
      function(body){ if (body) settings = body; },
      'Could not read the translation settings.');
  }

  function loadProgress(){
    return read('progress', '/translations/progress?locale=' + LOCALE,
      function(body){ progress = body; },
      'Could not count the translations.');
  }

  function loadRows(){
    var url = '/translations?locale=' + LOCALE
            + '&group=' + encodeURIComponent(filters.group)
            + '&limit=' + filters.limit
            + (filters.missing ? '&missing=1' : '')
            + (filters.q ? '&q=' + encodeURIComponent(filters.q) : '');

    return read('rows', url,
      function(body){ rows = body ? (body.rows || []) : null; },
      'Could not load that list of strings.');
  }

  function loadEstimate(limit, group){
    var url = '/translations/estimate?locale=' + LOCALE
            + '&limit=' + (limit || 100)
            + (group ? '&group=' + encodeURIComponent(group) : '');

    return read('estimate', url,
      function(body){ estimate = body; },
      'Could not work out what a run would cost.');
  }

  /* ----------------------------------------------------------------- acts */
  async function toggle(name){
    if (!can('settings') || !settings) return;

    var payload = {};
    payload[name] = !settings[name];

    inflight++; render();

    try {
      settings = await api('/translations/settings', 'POST', payload);
      banner = null;
      /* The endpoint answers with the whole settings payload, warning included,
         so the sentence under the switches is the server's answer to the state
         that now exists rather than this screen's guess at it. */
      say('Saved.');
    } catch (e) {
      banner = {kind:'bad', text: explain(e, 'That switch could not be saved.')};
    } finally {
      inflight--; render();
    }
  }

  async function saveKey(clear){
    if (!can('settings')) return;

    var value = clear ? '-' : apiKeyDraft.trim();

    if (!clear && value === '') {
      banner = {kind:'', text:'Nothing was typed in the key box, so nothing was changed.'};
      render();
      return;
    }

    inflight++; render();

    try {
      settings = await api('/translations/settings', 'POST', {api_key: value});
      apiKeyDraft = '';
      banner = null;
      say(clear ? 'Key removed.' : 'Key saved.');
    } catch (e) {
      banner = {kind:'bad', text: explain(e, 'The key could not be saved.')};
    } finally {
      inflight--; render();
    }
  }

  async function saveRow(i){
    if (!can('strings') || !rows) return;

    var r = rows[i];
    var box = document.querySelector('[data-tr-ar="' + i + '"]');
    if (!r || !box) return;

    var value = box.value;

    inflight++; render();

    try {
      var body = await api('/translations', 'POST', {
        locale: LOCALE, group: r.group, item_id: r.item_id, field: r.field, value: value
      });
      r.value = body.value || '';
      r.status = body.status || '';
      r.stale = false;
      banner = null;
      say(r.value === '' ? 'Cleared — that field counts as untranslated again.' : 'Saved.');
    } catch (e) {
      banner = {kind:'bad', text: explain(e, 'That string could not be saved.')};
    } finally {
      inflight--; render();
    }
  }

  async function translateRow(i){
    if (!rows || !can('machine_field')) return;

    var r = rows[i];
    if (!r) return;

    inflight++; render();

    try {
      var body = await api('/translations/machine/field', 'POST', {text: r.english, locale: LOCALE});
      /*
       * DELIBERATELY NOT SAVED. The endpoint stores nothing: the text lands in
       * the box, the owner reads it, and the ordinary Save writes it.
       *
       * It is written onto the row rather than poked into the textarea, because
       * the next render would wipe a value the model did not know about -- and
       * `unsaved` is what makes the row say so instead of looking published.
       */
      r.value = body.translation || '';
      r.status = '';
      r.unsaved = true;
      banner = null;
      say('Translated into the box. Read it, then press Save — nothing has been stored yet.');
    } catch (e) {
      banner = {kind:'bad', text: explain(e, 'That string could not be machine-translated.')};
    } finally {
      inflight--; render();
    }
  }

  async function publishAll(){
    if (!can('strings')) return;

    inflight++; render();

    try {
      var body = await api('/translations/publish', 'POST', {locale: LOCALE});

      // The recount FIRST, then the message. read() clears the banner on a
      // successful read, so setting it before the refresh would wipe the one
      // line that says how many drafts went live.
      await loadProgress();
      banner = {kind:'', text: body.published + ' draft(s) approved and live.'};
    } catch (e) {
      banner = {kind:'bad', text: explain(e, 'The drafts could not be approved.')};
    } finally {
      inflight--; render();
    }
  }

  /*
   * THE ONLY BUTTON IN THIS CONSOLE THAT SPENDS MONEY, IN TWO PRESSES.
   *
   * The first press shows the figure the second press authorises -- and the
   * figure is the SERVER'S count of the exact pending set the run will send,
   * not the whole-shop total, because those differ by most of the catalogue.
   * The second press posts it as confirm_characters, which machineRun()
   * re-counts and compares within a tolerance: if a catalogue was imported
   * between the two presses the run is refused and the new figure is shown.
   * That refusal is the server's sentence, printed as it arrives.
   */
  function askRun(){
    if (!estimate || !estimate.run || !can('machine_run')) return;
    confirming = estimate.run;
    render();
  }

  async function doRun(){
    if (!confirming || !can('machine_run')) return;

    var payload = {
      locale: LOCALE,
      limit: confirming.limit,
      confirm_characters: confirming.confirm_characters
    };
    if (confirming.group) payload.group = confirming.group;

    inflight++; render();

    try {
      runResult = await api('/translations/machine/run', 'POST', payload);
      confirming = null;
      banner = null;
      say('Run finished. Everything it wrote is a draft.');
      await loadEstimate(currentRunLimit(), currentRunGroup());
      return;
    } catch (e) {
      confirming = null;
      banner = {kind:'bad', text: explain(e, 'The run did not start.')};
    } finally {
      inflight--; render();
    }
  }

  function act(what, i){
    if (what === 'save-key') return saveKey(false);
    if (what === 'clear-key') return saveKey(true);
    if (what === 'save-row') return saveRow(parseInt(i, 10));
    if (what === 'tr-row') return translateRow(parseInt(i, 10));
    if (what === 'publish-all') return publishAll();
    if (what === 'run') return askRun();
    if (what === 'run-confirm') return doRun();
    if (what === 'run-cancel') { confirming = null; render(); return; }
    if (what === 'go-settings') { window.go(SETTINGS); return; }
    if (what === 'more') { shown += PAGE_SIZE; render(); return; }
  }

  /* ------------------------------------------------------------- the route */
  var previousGo = window.go;

  window.go = function(id){
    if (!VIEW[id]) return previousGo.apply(this, arguments);

    /* The console's own go() is NOT called for these ids. Its dispatch object
       ends `||renderDash`, so calling it would paint the dashboard a moment
       before this screen draws over it. The nav, crumb and title below are the
       only things it would have done for us. */
    document.querySelectorAll('.side .nav-item').forEach(function(b){
      b.classList.toggle('on', b.dataset.go === id);
    });
    document.querySelectorAll('#nav .nav-group').forEach(function(g){
      var has = [].slice.call(g.querySelectorAll('.nav-item')).some(function(b){
        return b.dataset.go === id;
      });
      g.classList.toggle('open', has);
    });

    var crumb = document.querySelector('#crumb');
    var title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE[id];

    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');

    var content = document.querySelector('#content');
    if (content) content.scrollTop = 0;

    cur = id;
    banner = null;
    confirming = null;

    /* SYNCHRONOUS FIRST PAINT, BEFORE ANY await. That is the condition
       LATE_RENDERED requires: the deep-link replay reads a marker inside
       #content that any real render destroys, so a screen that awaited before
       painting would be drawn twice. Measured on 'rev-all'; see the note above
       LATE_RENDERED in app.blade.php. */
    render();

    /* The settings payload carries `can`, which decides what every one of the
       four screens draws, so it is read whichever screen was opened. */
    if (!settings) loadSettings();

    if (id === PROGRESS) loadProgress();

    /* The Strings screen reads the progress payload too, and only for the Area
       selector: it is where the list of areas and their names come from, so the
       selector cannot drift from the Progress screen next door. Seven
       aggregates, which is what that endpoint is built to answer cheaply, and
       only when it has not already been read. */
    if (id === STRINGS) {
      if (!progress) loadProgress();
      if (rows === null) loadRows();
    }
    if (id === MACHINE) loadEstimate(currentRunLimit(), currentRunGroup());

    return undefined;
  };
})();
</script>
@endverbatim
