{{--
    Shared product picker — the type-ahead both order screens use.

    WHY IT EXISTS. The owner reported that the product search on New Order
    "appears and disappears instantly, it doesn't allow me to choose anything",
    and separately that neither picker showed product images. Two screens had
    two type-aheads written twice over: New Order's, in
    admin/partials/manual-order-screen.blade.php, and "add a product to this
    order" on the order detail screen, in admin/app.blade.php. Fixing one would
    have left the other exactly as it was, which is how the two drifted apart in
    the first place. One module, called from both, is the only shape that fixes
    it in both places at once — the same argument media-picker.blade.php makes
    about image fields, and this partial is included beside it for the same
    reason.

    THE BUG IT IS BUILT AROUND, stated exactly, because a re-fix has to know it.

    The list was not hidden. The SCREEN UNDER IT was replaced. New Order paints
    itself synchronously and THEN fetches its vocabularies:

        window.go('order-new')  ->  render();  boot();
        boot()                  ->  await /manual-orders/bootstrap  ->  render()

    The form is live the whole time that request is in flight, so an operator
    who starts typing straight away — which is what an operator does — gets
    suggestions, and then the bootstrap answers and render() rebuilds #moScreen
    from scratch. The input, its text, its focus and the whole results box go
    with it. On a host where that request is slow, or fails (V stays null, so it
    is re-fetched on EVERY visit to the screen), it happens over and over. It
    was never a blur handler; there is no blur handler on either screen.

    So the picker does not depend on its host leaving the DOM alone. It owns the
    query, the rows and the highlighted index, and attach() re-binds itself to
    whatever elements are in the document NOW and repaints from that state,
    restoring the text, the caret and the focus if it had them. A host may
    re-render as often as it likes; the picker survives it. That is stronger
    than "do not re-render while the picker is open", which is a rule six call
    sites would have to keep remembering.

    WHAT IT DELIBERATELY DOES NOT DO: hide on blur. `blur` fires BEFORE the
    click on a suggestion, so hiding there removes the element the click was
    going to land on and the click hits nothing — the classic version of exactly
    the complaint above. Closing on an outside pointerdown that is not inside
    the list does the same job and cannot eat the pick, and there is ONE such
    listener per picker, added at construction and removed by destroy(), rather
    than one more on every re-render.

    A sequence guard, for the same reason the quote has one: keystroke 2's
    response can land after keystroke 5's, and without a guard the older,
    narrower list overwrites the newer one under the operator's hands.

    IMAGES. `products.image` is a URL string on the product row — a site
    relative /media/... path or an http(s) address, which is what
    ProductEditorApiController's imageUrlRule() admits and what the order detail
    line items already render. Both endpoints this picker is pointed at return
    it already: /admin-api/manual-orders/products and /admin-api/catalog
    /products. A product with no image, or whose image 404s, gets the console's
    usual tinted initials square rather than a broken-image icon.

    It is a PURE CONSUMER: the host passes in a search function and a pick
    handler, so each screen keeps its own endpoint, its own payload shape and
    its own idea of what picking means. Nothing in app/ has to know this file
    exists.
--}}
@verbatim
<style>
/* Prefix kpp-, used nowhere else in the console. */
.kpp-box{min-width:0}
.kpp-box[hidden]{display:none}
.kpp-hit{display:flex;gap:10px;align-items:center;width:100%;text-align:left;
         padding:8px 10px;background:none;border:0;border-bottom:1px solid var(--border,#e6e9f2);
         cursor:pointer;font:inherit;color:inherit;min-width:0}
.kpp-hit:last-child{border-bottom:0}
.kpp-hit:hover,.kpp-hit[data-kpp-active="1"]{background:var(--surface-2,#f5f7fb)}
.kpp-th{width:36px;height:36px;border-radius:8px;flex:none;overflow:hidden;
        display:grid;place-items:center;background:var(--surface-2,#f5f7fb);
        font-size:10px;font-weight:800;color:#fff}
.kpp-th img{width:100%;height:100%;object-fit:cover;display:block}
.kpp-main{flex:1;min-width:0}
.kpp-main b{display:block;font-size:12.5px;font-weight:600;line-height:1.25;overflow-wrap:anywhere}
.kpp-main span{display:block;font-size:11px;color:var(--ink-soft,#626c80);overflow-wrap:anywhere}
.kpp-side{flex:none;font-size:11.5px;font-weight:700;color:var(--ink-2,#2f3646);text-align:right}
.kpp-note{padding:11px;font-size:12.5px;color:var(--ink-soft,#626c80)}
</style>
<script>
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
        var thumb = image
          ? '<span class="kpp-th" style="background:' + esc(tint(p.brand || p.name)) + '">' +
              '<img src="' + esc(image) + '" alt="" loading="lazy" data-kpp-img="' + i + '">' +
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
</script>
@endverbatim
