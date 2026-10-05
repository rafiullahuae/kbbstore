
(function(){
  'use strict';

  /* The palette the rest of the console uses for its initials squares. tcol()
     and initials() in app.blade.php are `const` in that script's own scope and
     cannot be read from here, so the two-line version lives here. */
  var TINTS = ['#E0567B','#7C6BD8','#2F9E7E','#D98324','#3C7DD9','#B0559B','#5B8C3E','#C0576B'];

  function tint(s){
    var n = 0;
    s = String(s || '?');
    for (var i = 0; i < s.length; i++) n += s.charCodeAt(i);
    return TINTS[n % TINTS.length];
  }

  function initials(s){
    return String(s || '?').trim().split(/\s+/).map(function(w){ return w[0] || ''; })
      .join('').slice(0, 2).toUpperCase() || '?';
  }

  /* Exported so a screen that draws the SAME square outside a suggestion row —
     New Order's line items, for one — tints it identically. */
  window.kbbProductPickerTint = tint;

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function el(ref){
    if (!ref) return null;
    return typeof ref === 'string' ? document.querySelector(ref) : ref;
  }

  /**
   * spec:
   *   input      selector or element for the text box (re-resolved on attach)
   *   results    selector or element for the list container
   *   search     async (term) -> array of rows
   *   onPick     (row) -> void
   *   rowMeta    (row) -> string under the name           [optional]
   *   rowSide    (row) -> string on the right             [optional]
   *   minChars   default 1
   *   debounceMs default 220
   *   emptyText / failText
   *   openDisplay  CSS display used when the list is open, default 'block'
   */
  window.kbbProductPicker = function(spec){
    var query = '';         // what the operator typed, OUTLIVES a host re-render
    var rows = [];          // the suggestions currently on offer
    var active = -1;        // keyboard highlight
    var note = '';          // "nothing matched", "search failed"
    var seq = 0;            // guards an older response landing last
    var focused = false;    // did the box have focus when it was torn out
    var caret = null;
    var timer = null;
    var input = null;
    var box = null;
    var dead = false;

    var minChars = spec.minChars == null ? 1 : spec.minChars;
    var wait = spec.debounceMs == null ? 220 : spec.debounceMs;
    var openDisplay = spec.openDisplay || 'block';
    var emptyText = spec.emptyText || 'Nothing in the catalogue matches that.';
    var failText = spec.failText || 'Search failed.';
    var listId = spec.listId || ('kpp-' + Math.random().toString(36).slice(2, 8));

    function rowsHTML(){
      return rows.map(function(p, i){
        var image = p.image ? String(p.image) : '';
        /* (Lane IM2) DRAWN FROM `thumb`, CHOSEN BY `image`. .kpp-th is a 36px
           square and this list was painting it with the catalogue original --
           ~290KB each, for a box that can show 36. `thumb` is the 200w copy
           when one exists on disk and is the original itself when it does not,
           so a catalogue that has never been through Make phone-sized copies
           looks and behaves exactly as it does today. Only the `src` moves:
           `image` is still what decides whether a row HAS a photograph, and
           the onerror fallback below still keys off the same element. */
        var shown = p.thumb ? String(p.thumb) : image;
        var thumb = image
          ? '<span class="kpp-th" style="background:' + esc(tint(p.brand || p.name)) + '">' +
              '<img src="' + esc(shown) + '" alt="" loading="lazy" data-kpp-img="' + i + '">' +
            '</span>'
          : '<span class="kpp-th" style="background:' + esc(tint(p.brand || p.name)) + '">' +
              esc(initials(p.brand || p.name)) + '</span>';

        var meta = spec.rowMeta ? spec.rowMeta(p) : (p.sku || '');
        var side = spec.rowSide ? spec.rowSide(p) : '';

        return '<button type="button" class="kpp-hit" role="option" data-kpp="' + i + '" ' +
          'id="' + esc(listId) + '-opt-' + i + '" aria-selected="' + (i === active) + '"' +
          (i === active ? ' data-kpp-active="1"' : '') + '>' +
          thumb +
          '<span class="kpp-main"><b>' + esc(p.name) + '</b><span>' + esc(meta) + '</span></span>' +
          (side ? '<span class="kpp-side">' + esc(side) + '</span>' : '') +
          '</button>';
      }).join('');
    }

    /** Paint whatever the state says, into whatever elements exist now. */
    function paint(){
      if (!box) return;

      var open = rows.length > 0 || note !== '';

      box.innerHTML = rows.length ? rowsHTML() : (note ? '<div class="kpp-note">' + esc(note) + '</div>' : '');
      box.style.display = open ? openDisplay : 'none';
      box.setAttribute('role', 'listbox');
      box.id = box.id || listId;

      if (input) {
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (active >= 0 && rows[active]) {
          input.setAttribute('aria-activedescendant', listId + '-opt-' + active);
        } else {
          input.removeAttribute('aria-activedescendant');
        }
      }

      box.querySelectorAll('[data-kpp]').forEach(function(b){
        b.onclick = function(){ pick(+b.dataset.kpp); };
      });

      /* A URL that 404s would otherwise draw the browser's broken-image icon,
         which reads as a bug rather than as "no photograph yet". */
      box.querySelectorAll('[data-kpp-img]').forEach(function(img){
        img.onerror = function(){
          var p = rows[+img.dataset.kppImg] || {};
          var holder = img.parentNode;
          if (holder) holder.textContent = initials(p.brand || p.name);
        };
      });

      if (active >= 0) {
        var on = box.querySelector('[data-kpp-active="1"]');
        if (on && on.scrollIntoView) on.scrollIntoView({block: 'nearest'});
      }
    }

    function close(){
      rows = [];
      note = '';
      active = -1;
      paint();
    }

    function reset(){
      query = '';
      if (input) input.value = '';
      close();
    }

    function pick(i){
      var row = rows[i];
      if (!row) return;
      // Cleared BEFORE the handler runs: the handler usually re-renders the
      // host, and attach() would otherwise faithfully restore the list the
      // operator has just finished with.
      reset();
      spec.onPick(row);
    }

    async function run(term){
      var mine = ++seq;
      try {
        var out = await spec.search(term);
        if (mine !== seq || dead) return;          // an older answer, landing last
        rows = Array.isArray(out) ? out : [];
        note = rows.length ? '' : emptyText;
      } catch (e) {
        if (mine !== seq || dead) return;
        rows = [];
        note = failText;
      }
      active = -1;
      paint();
    }

    function onInput(){
      query = input.value;
      caret = input.selectionStart;
      clearTimeout(timer);

      var term = query.trim();

      if (term.length < minChars) {
        seq++;                                     // abandon anything in flight
        close();
        return;
      }

      timer = setTimeout(function(){ run(term); }, wait);
    }

    function move(delta){
      if (!rows.length) return;
      active = active < 0
        ? (delta > 0 ? 0 : rows.length - 1)
        : (active + delta + rows.length) % rows.length;
      paint();
    }

    function onKeydown(e){
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); return; }
      if (e.key === 'Enter') {
        if (!rows.length) return;
        e.preventDefault();
        pick(active >= 0 ? active : 0);
        return;
      }
      if (e.key === 'Escape') {
        if (rows.length || note) { e.stopPropagation(); close(); }
        return;
      }
      if (e.key === 'Tab') close();
    }

    /* ONE listener of each kind, for the life of the picker. The order detail
       screen used to add a fresh document click listener on every render of the
       order, each holding on to that render's detached nodes. */
    function onDocPointerDown(e){
      if (dead || !box) return;
      if (box.contains(e.target) || e.target === input) return;
      close();
    }

    /*
     * Whether the operator was still in the box when it was torn out.
     *
     * NOT `blur`. Chromium fires blur on the old input while it is still in the
     * document — measured, not assumed — so a blur handler cannot tell "the
     * operator clicked away" from "the screen re-rendered underneath them", and
     * treating a re-render as a click away is how the caret ends up on <body>
     * mid-word. focusin only fires when focus lands on something else, which is
     * exactly the first case and never the second.
     */
    function onDocFocusIn(e){
      if (dead) return;
      focused = e.target === input;
    }

    document.addEventListener('pointerdown', onDocPointerDown);
    document.addEventListener('focusin', onDocFocusIn);

    return {
      /**
       * Bind to the elements in the document NOW and repaint from state.
       * Safe to call after every host re-render, and idempotent.
       */
      attach: function(){
        var nextInput = el(spec.input);
        var nextBox = el(spec.results);

        if (!nextInput || !nextBox) { input = null; box = null; return this; }

        input = nextInput;
        box = nextBox;
        box.classList.add('kpp-box');

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', box.id || listId);

        input.oninput = onInput;
        input.onkeydown = onKeydown;
        input.onfocus = function(){ focused = true; };

        if (input.value !== query) input.value = query;

        paint();

        // Only put the caret back when there was a search in progress: a picker
        // nobody is using must not steal focus from whatever the operator moved
        // on to.
        if (focused && (query !== '' || rows.length) && document.contains(input)) {
          try {
            input.focus();
            var at = caret == null ? input.value.length : Math.min(caret, input.value.length);
            input.setSelectionRange(at, at);
          } catch (e) {}
        }

        return this;
      },
      reset: reset,
      close: close,
      destroy: function(){
        dead = true;
        clearTimeout(timer);
        document.removeEventListener('pointerdown', onDocPointerDown);
        document.removeEventListener('focusin', onDocFocusIn);
      },
      /** For tests and for a host that wants to know what is on screen. */
      state: function(){
        return {query: query, rows: rows.slice(), active: active, note: note};
      }
    };
  };
})();
