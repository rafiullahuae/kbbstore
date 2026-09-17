{{--
    "Live green/red validation as the customer types" — the `inline_validation`
    module, ported in Lane FI. App\Support\InlineValidation carries the argument
    for the default and for each of the three settings; this file is what the
    browser gets.

    ===========================================================================
    OFF MEANS NOT ONE BYTE
    ===========================================================================

    `$validation` is null unless the module is ON. Null and this file emits
    nothing at all — no style block, no script, no data island, no empty
    wrapper. That is the plan's own rule for a switched-off module and it is
    checked by fetching the page, not by reading this comment:
    ModuleInlineValidationTest asserts the OFF checkout is byte-identical to the
    checkout as it was before this module existed.

    A disabled-but-present script would have been the easier shape and it is the
    wrong one. Markup a shopper downloads is a trace.

    ===========================================================================
    WHY THE SCRIPT IS INLINE AND NOT IN resources/js/kbb/
    ===========================================================================

    CLAUDE.md: package.json defines no build script, asset builds are manual and
    CI does not build them, so the bundle running on the shop is routinely older
    than this repository. A module whose behaviour lived in checkout.js would
    ship as a switch that did nothing until somebody remembered to run vite —
    which is the exact class of defect Phase 3 exists to stop.

    The precedent is `quick_view`, whose markup, CSS and script all sit inline
    in layouts/store.blade.php behind its own moduleEnabled() gate. This is that
    shape, scoped to one page.

    ===========================================================================
    WHAT IT READS: WOOCOMMERCE'S OWN CONTRACT, ALREADY IN THE MARKUP
    ===========================================================================

    components/checkout/field.blade.php writes `validate-required`,
    `validate-email`, `validate-state` and `validate-phone` onto each
    `.form-row`, and its header records that nothing in this repo has ever read
    them. This does. The classes it PUTS BACK are Woo's too —
    `woocommerce-invalid` and `woocommerce-validated` on the row — so a
    stylesheet carried over from the live theme keeps matching, and so that this
    module is a reader of the shipped markup rather than a second, parallel
    vocabulary for the same idea.

    Nothing here adds, removes or reorders a field, and nothing here decides
    whether the form may be submitted. `required` on the control and the server's
    own rules are still the whole of that; checkout.js's Place-order handler is
    untouched. This module is marks and words, and a shopper with scripts
    blocked gets exactly the checkout they get today.

    ===========================================================================
    ACCESSIBILITY, WHICH IS WHY THE MARK IS NOT ONLY A COLOUR
    ===========================================================================

    Red and green alone say nothing to a shopper who cannot tell them apart, and
    nothing at all to a screen reader. So a judged field also carries
    `aria-invalid`, and a hint is a real element joined to its control with
    `aria-describedby` and announced with `role="status"` — polite, so it does
    not interrupt typing. The tick and cross glyphs are `aria-hidden`: they are
    the same information a second time, and a screen reader reading "✓" after
    every field is noise.
--}}
@php use App\Support\InlineValidation; @endphp

@if ($validation !== null)
    @php
        /*
         * One island, server-rendered, already translated. The script reads it
         * and no string is typed into the script itself — see the note in
         * Services\Translation\InterfaceStrings beside these four keys.
         *
         * The keys are the validate-* suffixes, so the lookup in the script is
         * the class name with its prefix removed and there is no second table
         * mapping one to the other.
         */
        $kbbIv = [
            'when' => $validation['when'],
            'ok' => $validation['ok'],
            'hint' => $validation['hint'],
            'msg' => [
                'required' => __('store.checkout.validate_required'),
                'email' => __('store.checkout.validate_email'),
                'state' => __('store.checkout.validate_state'),
                'phone' => __('store.checkout.validate_phone'),
            ],
        ];
    @endphp
<style>
/*
    Scoped to #kbbCheckoutForm throughout, so nothing here can reach the account
    forms, the cart page or the review form — all of which have .form-row rows
    of their own, and one of which (the cart) is checked for exactly that in
    ModuleInlineValidationTest.

    inset-inline-start / -end rather than left / right: the storefront serves
    Arabic right-to-left, and a mark pinned to the physical right would sit on
    the wrong side of every field on that side of the shop.

    THE WRAPPER IS POSITIONED FOR EVERY ROW, not only for a marked one, and that
    is the difference between a mark and a jolt. `position:relative` and
    `display:block` on a span change its box; applying them at the moment a
    field is first judged would move the field under the shopper's cursor as
    they typed. Applied to every row up front, the geometry is the same before
    and after a mark, and only the colour and the glyph change. This costs
    nothing when the module is off, because this whole block is only emitted
    when it is on.
*/
#kbbCheckoutForm .form-row .woocommerce-input-wrapper{position:relative;display:block}
#kbbCheckoutForm .form-row.woocommerce-invalid .input-text{border-color:#b4415c}
#kbbCheckoutForm .form-row.woocommerce-validated .input-text{border-color:#2f6b41}
#kbbCheckoutForm .form-row.woocommerce-invalid .woocommerce-input-wrapper::after,
#kbbCheckoutForm .form-row.woocommerce-validated .woocommerce-input-wrapper::after{
  position:absolute;inset-inline-end:12px;top:50%;transform:translateY(-50%);
  font-size:14px;line-height:1;pointer-events:none}
#kbbCheckoutForm .form-row.woocommerce-invalid .woocommerce-input-wrapper::after{content:"\2715";color:#b4415c}
#kbbCheckoutForm .form-row.woocommerce-validated .woocommerce-input-wrapper::after{content:"\2713";color:#2f6b41}
/* A select draws its own arrow in that corner, so the mark moves inboard of it
   rather than sitting on top of it. A textarea has no vertical middle worth
   pinning to, so its mark sits at the first line instead of halfway down. */
#kbbCheckoutForm .form-row:has(select) .woocommerce-input-wrapper::after{inset-inline-end:32px}
#kbbCheckoutForm .form-row:has(textarea) .woocommerce-input-wrapper::after{top:22px;transform:none}
#kbbCheckoutForm .kbb-iv-msg{display:block;margin-top:6px;font-size:12px;line-height:1.45;color:#b4415c}
</style>
<script>
(function () {
  'use strict';

  var form = document.getElementById('kbbCheckoutForm');
  if (!form) return;

  var cfg = @json($kbbIv);

  /*
      The checks, one per validate-* class the markup carries.

      Each returns true, false, or null for "no opinion" — and null is the
      important one. An OPTIONAL field left empty is not wrong, so `phone` and
      `email` say nothing about an empty box and let `required` be the only rule
      that has a view on emptiness. Without that third answer an empty optional
      phone would be marked red for being empty, which is the shop telling a
      shopper off for doing as they were asked.
  */
  var rules = {
    required: function (v) { return v.trim() !== ''; },
    email: function (v) {
      if (v.trim() === '') return null;
      /* Deliberately loose. A strict address grammar rejects real addresses,
         and this mark is a hint, not the gate: the server still decides. */
      return /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/.test(v.trim());
    },
    state: function (v) { return v.trim() !== ''; },
    phone: function (v) {
      if (v.trim() === '') return null;
      return (v.replace(/[^0-9]/g, '').length >= 6);
    }
  };

  function control(row) {
    return row.querySelector('input.input-text, select.input-text, textarea.input-text');
  }

  /* The failing rule's name, or '' when the row is fine. The order the classes
     appear in is the order they are tested, so "required" wins over "email" on
     an empty required address and the shopper is told the useful thing. */
  function fault(row, el) {
    var names = [];
    row.classList.forEach(function (c) {
      if (c.indexOf('validate-') === 0) names.push(c.slice(9));
    });
    for (var i = 0; i < names.length; i++) {
      var rule = rules[names[i]];
      if (!rule) continue;
      var verdict = rule(el.value == null ? '' : String(el.value));
      if (verdict === false) return names[i];
    }
    return '';
  }

  function hintFor(row, el) {
    var id = (el.id || '') + '_kbbiv';
    var node = row.querySelector('.kbb-iv-msg');
    if (!node) {
      node = document.createElement('span');
      node.className = 'kbb-iv-msg';
      node.id = id;
      node.setAttribute('role', 'status');
      row.appendChild(node);
    }
    return node;
  }

  function clearHint(row, el) {
    var node = row.querySelector('.kbb-iv-msg');
    if (node) node.parentNode.removeChild(node);
    /* Only the pointer THIS module wrote. A blanket removeAttribute would strip
       a description some other part of the page had put on the same control,
       and a field that silently loses its help text to an unrelated module is
       exactly the kind of damage a switched-on module should not do. */
    if (el.getAttribute('aria-describedby') === (el.id || '') + '_kbbiv') {
      el.removeAttribute('aria-describedby');
    }
  }

  function judge(row, el) {
    var bad = fault(row, el);

    row.classList.toggle('woocommerce-invalid', bad !== '');
    /* The green half only when the owner asked for it. `validated` is never
       added otherwise, so the tick and the green border have no selector to
       match and the quiet reading really is quiet. */
    row.classList.toggle('woocommerce-validated', cfg.ok && bad === '');

    if (bad !== '') {
      el.setAttribute('aria-invalid', 'true');
    } else {
      el.removeAttribute('aria-invalid');
    }

    if (bad !== '' && cfg.hint && cfg.msg[bad]) {
      var node = hintFor(row, el);
      node.textContent = cfg.msg[bad];
      el.setAttribute('aria-describedby', node.id);
    } else {
      clearHint(row, el);
    }
  }

  /*
      WATCHED, not judged, until the shopper has finished with the field once.

      Under 'blur' a row is judged when it is left and from then on live, so a
      correction turns the mark green as it is typed. Under 'type' the row is
      live from the first keystroke, which is the plugin's blurb taken at its
      word and is why it is a setting rather than an argument.

      The flag lives on the row's dataset rather than in a Set so that a row
      replaced by the country/emirate re-render arrives clean and is not
      remembered as already-judged by a stale object.
  */
  function live(row) { return cfg.when === 'type' || row.dataset.kbbIv === '1'; }

  /* One delegated pair for the whole form — Rule 27, and the fields on this
     page are re-rendered by checkout.js when the country changes, so a listener
     bound per control would be lost with the element it was bound to. */
  form.addEventListener('input', function (e) {
    var el = e.target;
    if (!el.classList || !el.classList.contains('input-text')) return;
    var row = el.closest('.form-row');
    if (!row || !live(row)) return;
    judge(row, el);
  });

  form.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.classList || !el.classList.contains('input-text')) return;
    var row = el.closest('.form-row');
    if (!row) return;
    /* A select is finished the moment it changes; there is no "still typing"
       state for it, so it is judged whatever the timing setting says. */
    if (el.tagName === 'SELECT') row.dataset.kbbIv = '1';
    if (!live(row)) return;
    judge(row, el);
  }, true);

  /* focusout, not blur: blur does not bubble, so a delegated listener never
     hears it. */
  form.addEventListener('focusout', function (e) {
    var el = e.target;
    if (!el.classList || !el.classList.contains('input-text')) return;
    var row = el.closest('.form-row');
    if (!row) return;
    row.dataset.kbbIv = '1';
    judge(row, el);
  });
})();
</script>
@endif
