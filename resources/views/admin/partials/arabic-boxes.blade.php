{{--
  ═══════════════════════════════════════════════════════════════════════════
  T4b · An Arabic box beside every field, in every editor.   (Lane EX)
  ═══════════════════════════════════════════════════════════════════════════

  The owner's own words:

      "for addition of everything, like products, posts etc. we should must
       have arabic place for every field, so we can enter manually"

  ── WHY THIS IS ONE FILE AND NOT TWENTY BLOCKS ────────────────────────────

  Five editors need the same control: a right-to-left box marked as Arabic,
  sitting beside its English field, saved by the same button, with a Translate
  button that only exists when a key is configured. Written out per editor that
  is five copies of the escaping, five copies of the RTL attributes, five
  copies of the "no key, no button" rule — and the first correction to any of
  them leaves four wrong. Worse, three of those five live in files owned by
  three different people, so the copies could not even be corrected together.

  So: ONE helper, called from each editor. resources/views/admin/app.blade.php
  needs a single @include line; every other screen calls KBBArabic.box() where
  it wants the box and KBBArabic.collect() when it saves.

  ── THE CONTRACT, SET BY docs/BILINGUAL-PLAN.md AND NOT BY THIS FILE ──────

  * The box posts translations[ar][<field>] IN THE SAME REQUEST as the English
    field. Not a second screen, not a second save.
  * BLANK MEANS "NOT TRANSLATED YET" and deletes the row. It never means "same
    as the English". To say "deliberately identical" — a brand name like Anua —
    the owner types it in. That is the only reason the progress figure is
    countable rather than claimed, so the placeholder says so in words.
  * Manual entry is PUBLISHED IMMEDIATELY. Only a machine drafts.
  * A draft IS shown, marked as a draft, because approving something invisible
    is not approving.

  ── THE TRANSLATE BUTTON, AND WHAT HAPPENS WITH NO KEY ────────────────────

  POST /admin-api/translations/machine/field fills the box and STORES NOTHING;
  the ordinary Save stores it. With no API key configured the button is not
  rendered at all — not rendered-and-disabled, and certainly not rendered and
  answering 409 after the owner has waited for it. Every manual path works with
  no key, no account and no bill, and that is the path the plan expects the
  catalogue to actually be translated through.

  Markup is never sent. For a rich-text field the button sends the PLAIN TEXT
  of the English pane; MachineTranslationRunner::isMachineSafe() refuses markup
  on the server as well, so this is belt and braces on a rule the plan makes
  for a good reason — every translation service either breaks formatting or
  translates it as though it were words, and the owner would be paying for the
  damage.

  ── WHY THE BOXES ARE DRAWN EVEN WHILE ARABIC IS SWITCHED OFF ─────────────

  Because the catalogue has to be translated BEFORE the switch is flipped.
  Step 7 of the plan (content translation, ~55 hours of the owner's time) comes
  before step 8 (switch Arabic on). Hiding the boxes until Arabic is live would
  mean the shop can only be translated after it is already serving Arabic
  pages, which is the wrong way round.
--}}

<style id="kbbar-css">
  .kbbar{margin-top:9px;padding:10px 11px 9px;border:1px solid var(--kbbar-line,#dfe6ef);
    border-radius:9px;background:var(--kbbar-bg,#f7f9fc)}
  .kbbar-h{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:6px;
    font-size:11.5px;letter-spacing:.02em;color:#4b5a6b}
  .kbbar-h b{font-weight:600}
  .kbbar-flag{font-size:12.5px;font-weight:700;color:#0f172a}
  .kbbar-tag{font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;padding:1px 6px;
    border-radius:20px;background:#e2e8f0;color:#475569}
  .kbbar-tag.is-draft{background:#fdf0d5;color:#8a5a00}
  .kbbar-tag.is-stale{background:#fde2e2;color:#9b1c1c}
  .kbbar-row{display:flex;gap:7px;align-items:flex-start}
  .kbbar-row>.kbbar-ctl{flex:1 1 auto;min-width:0}
  .kbbar-ctl{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d6dee8;
    border-radius:7px;font:inherit;font-size:13.5px;background:#fff;color:#0f172a}
  .kbbar-ctl:focus{outline:2px solid #94b8e6;outline-offset:-1px}
  textarea.kbbar-ctl{min-height:64px;resize:vertical;line-height:1.6}
  .kbbar-btn{flex:0 0 auto;padding:8px 11px;border:1px solid #d6dee8;border-radius:7px;
    background:#fff;font:inherit;font-size:12px;cursor:pointer;color:#334155;white-space:nowrap}
  .kbbar-btn:hover{background:#eef3f9}
  .kbbar-btn[disabled]{opacity:.55;cursor:default}
  .kbbar-note{margin:6px 0 0;font-size:11px;color:#718091;line-height:1.45}
  /* A list typed into the Arabic pane runs the other way, so its markers sit
     on the RIGHT — and the browser's default 40px inline-start padding puts
     them past the edge of the box. Applies to the product editor's own rich
     pane too, which is rendered inside this wrapper. */
  .kbbar ul,.kbbar ol{padding-inline-start:24px;margin:.45em 0}
  .kbbar-rte{border:1px solid #d6dee8;border-radius:7px;background:#fff;overflow:hidden}
  .kbbar-rte .kbbar-area{min-height:84px;padding:10px 12px;font-size:13.5px;line-height:1.75;outline:0}
  .kbbar-rte .kbbar-area:empty::before{content:attr(data-ph);color:#94a3b8}
  @media (prefers-color-scheme: dark){
    .kbbar{--kbbar-line:#33415580;--kbbar-bg:#0f172a10}
  }
</style>

<script>
/* One global, created once. Every editor on this console calls into it. */
window.KBBArabic = (function(){
  'use strict';

  /* The shop's second language. Locale::LOCALES is the authority; this is the
     one entry the admin draws boxes for, and it is named rather than derived
     so the markup can carry the right `lang` and `dir` without a round trip. */
  var LOCALE = 'ar';
  var NATIVE = 'العربية';   /* العربية */

  /* "Leave empty if it is not translated yet." — in Arabic, because it sits
     inside a right-to-left box and an English sentence there reads as damage. */
  var PLACEHOLDER = 'اتركه فارغًا '
                  + 'إذا لم تتم الترجمة بعد';

  function esc(v){
    return String(v == null ? '' : v)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function apiBase(){
    return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api';
  }

  function cookie(name){
    var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* ---------------------------------------------------------------- state */

  /* Whether a machine-translation key is configured. Asked ONCE per page load
     and remembered as a promise, so ten boxes on one screen make one request
     rather than ten. A failure of any kind — 403 because the signed-in role
     cannot read translation settings, a network blip, a 404 because the
     translation routes are not mounted on this build — resolves to "no
     provider", which hides the button and leaves every manual path working.
     The manual path is the one that must never depend on anything. */
  var probe = null;

  function settings(){
    if (probe) return probe;

    probe = fetch(apiBase() + '/translations/settings', {
      credentials: 'same-origin', headers: {Accept: 'application/json'}
    }).then(function(r){
      return r.ok ? r.json() : null;
    }).then(function(d){
      return {
        available: !!(d && d.has_api_key && d.provider_available),
        arabic_enabled: !!(d && d.arabic_enabled)
      };
    }).catch(function(){
      return {available: false, arabic_enabled: false};
    });

    return probe;
  }

  /* Synchronous answer for the first paint. A screen renders before the probe
     resolves, so box() cannot await; it draws the button hidden and reveal()
     shows it if and when the answer comes back "yes". The wrong way round —
     drawing it and hiding it later — would flash a button that does not work. */
  var known = null;
  settings().then(function(s){ known = s; reveal(document); });

  function reveal(root){
    if (!known || !known.available) return;
    (root || document).querySelectorAll('[data-kbbar-translate]').forEach(function(b){
      b.hidden = false;
    });
  }

  /* --------------------------------------------------------------- render */

  /**
   * One Arabic box.
   *
   * opts:
   *   field       required — the column name. Becomes translations[ar][field].
   *   label       the English field's own label, echoed so the pair is obvious.
   *   prefill     the model's `translations` bag from the endpoint, or null.
   *   type        'text' (default) | 'textarea' | 'rich'
   *   maxlength   mirror of the English control's own bound.
   *   rows        textarea rows.
   *   from        CSS selector for the English control, for the Translate
   *               button. Omit it and no button is drawn — which is the right
   *               answer for a long description, because the plan is explicit
   *               that long descriptions are typed and not machined.
   *   fromText    true when `from` is a contenteditable / rich pane: its PLAIN
   *               TEXT is sent, never its markup.
   *   scope       a CSS scope prefix so two forms on one page cannot collide.
   */
  function box(opts){
    opts = opts || {};
    var field = String(opts.field || '');
    var cell  = cellFor(opts.prefill, field);
    /* `value` overrides the prefill. A screen that re-renders mid-edit (the
       product editor does, every time a panel is dragged) holds the live text
       in its own model and has to be able to put it back, or the Arabic typed
       thirty seconds ago reverts to whatever was last saved. */
    var value = (opts.value === undefined || opts.value === null) ? (cell.value || '') : opts.value;
    var type  = opts.type || 'text';

    /* Extra attributes the CALLING SCREEN authors for its own binding — e.g.
       the product editor's data-bind. Never operator input, which is why it is
       written through rather than escaped; the one place in this file that is. */
    var extra = opts.attrs ? ' ' + opts.attrs : '';

    var tags = '';
    if (cell.status === 'draft') {
      tags += '<span class="kbbar-tag is-draft">machine draft — read it, then Save</span>';
    }
    if (cell.stale) {
      tags += '<span class="kbbar-tag is-stale">the English changed since this was written</span>';
    }

    var control;

    if (type === 'rich') {
      /* A rich English field gets a rich Arabic field. A plain textarea beside
         a formatted pane would mean the Arabic product page could not carry the
         headings and lists the English one does — the two languages would be
         different pages, not translations of one. */
      control = '<div class="kbbar-rte kbbar-ctl" style="padding:0">'
        + '<div class="kbbar-area" contenteditable="true" dir="rtl" lang="' + LOCALE + '" '
        +   'data-kbbar-rich="' + esc(field) + '" data-ph="' + esc(PLACEHOLDER) + '"' + extra + '>'
        + (value || '')
        + '</div></div>';
    } else if (type === 'textarea') {
      control = '<textarea class="kbbar-ctl" dir="rtl" lang="' + LOCALE + '" '
        + 'data-kbbar-input="' + esc(field) + '" rows="' + (opts.rows || 3) + '"'
        + (opts.maxlength ? ' maxlength="' + esc(opts.maxlength) + '"' : '')
        + ' placeholder="' + esc(PLACEHOLDER) + '"' + extra + '>' + esc(value) + '</textarea>';
    } else {
      control = '<input type="text" class="kbbar-ctl" dir="rtl" lang="' + LOCALE + '" '
        + 'data-kbbar-input="' + esc(field) + '" value="' + esc(value) + '"'
        + (opts.maxlength ? ' maxlength="' + esc(opts.maxlength) + '"' : '')
        + ' placeholder="' + esc(PLACEHOLDER) + '"' + extra + '>';
    }

    /* `hidden` until the probe says a provider is connected. With no key this
       attribute is never removed, so the button does not exist as far as the
       owner is concerned and nothing on this box needs one. */
    var button = opts.from
      ? '<button type="button" class="kbbar-btn" hidden'
        + ' data-kbbar-translate="' + esc(field) + '"'
        + ' data-kbbar-from="' + esc(opts.from) + '"'
        + (opts.fromText ? ' data-kbbar-fromtext="1"' : '')
        + '>Translate</button>'
      : '';

    return '<div class="kbbar" data-kbbar-box="' + esc(field) + '">'
      + '<div class="kbbar-h"><span class="kbbar-flag" dir="rtl" lang="ar">' + NATIVE + '</span>'
      +   '<b>Arabic</b>' + (opts.label ? '<span>· ' + esc(opts.label) + '</span>' : '')
      +   tags
      + '</div>'
      + '<div class="kbbar-row">' + control + button + '</div>'
      + '<p class="kbbar-note">Leave it empty and this field counts as <b>not translated yet</b>. '
      +   'If the Arabic really is the same as the English, type it in — that is how the shop '
      +   'tells "deliberately identical" from "nobody has reached it".</p>'
      + '</div>';
  }

  /**
   * box(), but only if the SERVER says this field is translatable.
   *
   * `shape` is either the row's own `translations` bag or the empty
   * `translatable` shape an index endpoint hands down for its "add" form.
   * Asking it — rather than each screen carrying its own list of columns — is
   * what makes a field added to a model's $translatable allowlist grow a box
   * everywhere at once, and what stops a screen offering a box for a field the
   * server would silently drop.
   */
  function boxIf(shape, opts){
    if (!shape || !shape[LOCALE]
        || !Object.prototype.hasOwnProperty.call(shape[LOCALE], opts.field)) {
      return '';
    }

    return box(opts);
  }

  /** The prefill cell for one field, whatever shape (or absence) the bag has. */
  function cellFor(prefill, field){
    var byLocale = prefill && prefill[LOCALE];
    var cell = byLocale && byLocale[field];
    if (!cell) return {value: '', status: '', source: '', stale: false};
    return {
      value: cell.value || '',
      status: cell.status || '',
      source: cell.source || '',
      stale: !!cell.stale
    };
  }

  /* ----------------------------------------------------------------- wire */

  /** Attach the Translate buttons inside `root` (default: the document). */
  function wire(root){
    root = root || document;

    root.querySelectorAll('[data-kbbar-translate]').forEach(function(btn){
      if (btn.dataset.kbbarWired) return;
      btn.dataset.kbbarWired = '1';

      btn.onclick = async function(){
        var sel = btn.dataset.kbbarFrom;
        var src = sel ? document.querySelector(sel) : null;

        var english = !src ? ''
          : (btn.dataset.kbbarFromtext
              /* PLAIN TEXT for a rich pane. Markup is never sent — see the
                 header, and isMachineSafe() on the server refuses it anyway. */
              ? (src.textContent || '')
              : (src.value !== undefined ? src.value : (src.textContent || '')));

        english = english.trim();

        if (!english) { note(btn, 'Write the English first.'); return; }

        var was = btn.textContent;
        btn.disabled = true; btn.textContent = 'Translating…';

        try {
          var r = await fetch(apiBase() + '/translations/machine/field', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-XSRF-TOKEN': cookie('XSRF-TOKEN')
            },
            body: JSON.stringify({text: english, locale: LOCALE})
          });

          var d = await r.json().catch(function(){ return {}; });

          if (!r.ok) { note(btn, d.message || 'Could not translate that.'); return; }

          if (!d.translation) {
            note(btn, 'Nothing came back for that — formatted text is never sent to a machine.');
            return;
          }

          /* Filled in, and NOT stored. The owner reads it, corrects it, and the
             ordinary Save button stores it. A button that wrote behind the form
             would leave the screen showing one thing and the database another. */
          var field = btn.dataset.kbbarTranslate;
          var wrap = btn.closest('[data-kbbar-box]');
          var rich = wrap && wrap.querySelector('[data-kbbar-rich]');
          var flat = wrap && wrap.querySelector('[data-kbbar-input]');

          /* The `input` event is dispatched on BOTH shapes. A screen that
             mirrors its boxes into a model — the product editor does — listens
             for it, and a value written straight onto the element fires
             nothing on its own. Without this the Arabic would appear in the
             box, look saved, and be dropped by the next collect(). */
          if (rich) {
            rich.textContent = d.translation;
            rich.dispatchEvent(new Event('input', {bubbles:true}));
          }
          else if (flat) { flat.value = d.translation; flat.dispatchEvent(new Event('input', {bubbles:true})); }

          note(btn, 'Filled in. Read it, then press Save.');
        } catch (e) {
          note(btn, 'Could not reach the translation service.');
        } finally {
          btn.disabled = false; btn.textContent = was;
        }
      };
    });

    reveal(root);
  }

  function note(btn, text){
    var wrap = btn.closest('[data-kbbar-box]');
    var p = wrap && wrap.querySelector('.kbbar-note');
    if (!p) return;
    var keep = p.dataset.kbbarKeep || p.innerHTML;
    p.dataset.kbbarKeep = keep;
    p.textContent = text;
    setTimeout(function(){ p.innerHTML = keep; }, 4000);
  }

  /* -------------------------------------------------------------- collect */

  /**
   * Read every Arabic box inside `root` back into a payload bag.
   *
   * Returns {} when the screen drew no boxes at all, and {ar:{}} is never
   * produced by accident — an absent bag leaves existing translations alone,
   * while a present-but-empty field DELETES its row. Those two have to stay
   * distinguishable, which is why this returns nothing rather than an empty
   * locale when there is nothing on screen.
   */
  function collect(root){
    root = root || document;

    var fields = {};
    var any = false;

    root.querySelectorAll('[data-kbbar-input]').forEach(function(el){
      fields[el.dataset.kbbarInput] = el.value;
      any = true;
    });

    root.querySelectorAll('[data-kbbar-rich]').forEach(function(el){
      /* innerHTML, matching the English rich pane. RichText::clean() runs over
         it on the server exactly as it runs over the English. */
      fields[el.dataset.kbbarRich] = el.innerHTML;
      any = true;
    });

    if (!any) return {};

    var out = {};
    out[LOCALE] = fields;
    return out;
  }

  return {
    locale: LOCALE,
    box: box,
    boxIf: boxIf,
    wire: wire,
    collect: collect,
    cellFor: cellFor,
    settings: settings,
    /* Exposed for the product editor, which owns its own rich-text control and
       renders the Arabic pane with it rather than with the one above. */
    placeholder: PLACEHOLDER,
    native: NATIVE
  };
})();
</script>
