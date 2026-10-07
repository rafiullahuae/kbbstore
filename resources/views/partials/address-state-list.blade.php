{{--
    The Emirate / state list's country switch (Lane AD), shared by the checkout
    and My account -> Addresses. Drawn only while Appearance -> Checkout page ->
    Fields & attention -> "Emirate / state as a list" is on.

    The server renders the list for the country the page opens on; this swaps
    it when the shopper picks another country, from ONE JSON object printed
    here for the countries this page offers. No request per change, nothing
    measured, no timer: it rebuilds a handful of options and renames the label.
    A country with no list gets the typed box back, with the same name, id and
    attributes, so everything that reads the field by id still finds it.

    Its listener is registered while the page parses, so it runs before the
    checkout's own country handler (a module, which runs after parsing) and
    that handler re-prices on the emirate the box now shows.

    Expects: $kbbStCountries (codes), $kbbStFor (the code the server rendered),
    $kbbStCountry / $kbbStState (ids), $kbbStLabel (a selector whose first text
    node is the label's words).
--}}
@php
    $kbbStCfg = [
        's' => __('store.checkout.field_state_select'),
        'o' => __(\App\Support\AddressRegions::LABELS['other']),
        'c' => (object) \App\Support\AddressRegions::clientMap($kbbStCountries),
    ];
@endphp
<script type="application/json" id="kbb-state-lists" data-for="{{ $kbbStFor }}">{!! json_encode($kbbStCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<script>
(function () {
  var box = document.getElementById('kbb-state-lists');
  var cs = document.getElementById(@json($kbbStCountry));
  if (!box || !cs) { return; }
  var cfg = JSON.parse(box.textContent), last = box.getAttribute('data-for');
  function label(text) {
    var l = document.querySelector(@json($kbbStLabel)), n = l && l.firstChild;
    if (n && n.nodeType === 3) { n.nodeValue = text + (/ $/.test(n.nodeValue) ? ' ' : ''); }
  }
  function swap() {
    var st = document.getElementById(@json($kbbStState));
    if (!st) { return; }
    var list = cfg.c[cs.value], cur = st.value, el = st, want = list ? 'SELECT' : 'INPUT', i, a, o, hit = false;
    if (st.tagName !== want) {
      el = document.createElement(want.toLowerCase());
      for (i = 0; i < st.attributes.length; i++) {
        a = st.attributes[i];
        if (a.name !== 'type' && a.name !== 'value' && a.name !== 'placeholder') { el.setAttribute(a.name, a.value); }
      }
      if (!list) { el.type = 'text'; if (st.closest('.fld')) { el.placeholder = ' '; } }
      /* The list is an emirate; the typed box for a country without one is a
         town, and the browser's autofill is told which. */
      a = el.getAttribute('autocomplete');
      if (a) { el.setAttribute('autocomplete', list ? a.replace('address-level2', 'address-level1') : a.replace('address-level1', 'address-level2')); }
      st.parentNode.replaceChild(el, st);
    }
    if (list) {
      el.textContent = '';
      o = new Option(cfg.s, ''); o.disabled = true; el.add(o);
      for (i = 0; i < list[1].length; i++) {
        a = list[1][i];
        a = new Option('\u2068' + a[1] + '\u2069 \u2014 ' + (a[2] || a[0]), a[0]);
        if (a.value === cur) { a.selected = hit = true; }
        el.add(a);
      }
      if (!hit) { o.selected = true; }
    } else if (el !== st) { el.value = ''; }
    label(list ? list[0] : cfg.o);
    var row = el.closest('.form-row');
    if (row && el.value === '') { row.classList.remove('woocommerce-validated', 'woocommerce-invalid', 'kbb-valid', 'kbb-invalid'); }
  }
  if (cs.value !== last) { last = cs.value; swap(); }
  cs.addEventListener('change', function () {
    if (cs.value === last) { return; }
    last = cs.value;
    swap();
  });
})();
</script>
