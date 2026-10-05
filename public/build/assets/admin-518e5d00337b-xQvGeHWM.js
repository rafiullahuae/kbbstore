
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

          /* A 404 with no JSON body is the compiled route table, not the
             translation service: every package that adds a route ships a
             clear_caches migration, and when it does not run these paths are
             unknown and Laravel answers its own HTML 404. "Could not translate
             that" sends the owner to look at his API key for a fault that has
             nothing to do with it. */
          if (!r.ok) {
            note(btn, d.message || d.error || (r.status === 404 && !(d && (d.message || d.error))
              ? 'The translate endpoint is not in this server\'s compiled route table yet. Clear the route cache (Platform \u2192 Cache) and reload.'
              : 'Could not translate that.'));
            return;
          }

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
