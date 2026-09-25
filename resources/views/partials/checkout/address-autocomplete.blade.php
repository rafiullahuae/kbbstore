{{--
    "Suggestions as the shopper types the address field" — the
    `address_autocomplete` module, built in Lane M as far as it can honestly be
    built. App\Support\AddressAutocomplete carries the argument for the gate,
    the key and the consent setting; this file is what the browser gets.

    ===========================================================================
    OFF MEANS NOT ONE BYTE, AND OFF IS THE DEFAULT AND THE USUAL STATE
    ===========================================================================

    `$autocomplete` is null unless ALL THREE of these are true: the module is
    switched on, a well-formed key is stored, and the owner has answered the
    consent question with "yes". Null and this file emits nothing at all — no
    script tag, no data island, no empty wrapper — so the checkout is byte-for-
    byte the page it is today and NOTHING A SHOPPER TYPES LEAVES THE BROWSER.

    That is checked by fetching the page rather than by reading this comment:
    ModuleAddressAutocompleteTest asserts the off checkout is byte-identical to
    the checkout rendered without this include at all, and separately that no
    `maps.googleapis.com` string appears in the response in any of the three
    off states.

    A disabled-but-present script would have been the easier shape and it is the
    wrong one twice over here: markup a shopper downloads is a trace, and this
    particular markup is a third-party script that starts reporting keystrokes.

    ===========================================================================
    WHY THE INCLUDE SITS WHERE IT SITS
    ===========================================================================

    At the START of an existing line, not on one of its own, and this is not a
    style choice — Lane FI paid for it and wrote it down:

      - `@push` captures the newlines between its directives, so an include
        written across four lines pushes blank lines onto the stack whether or
        not the partial renders anything.
      - Blade's compiled `@include` ends in `?>`, and PHP eats the newline that
        follows a closing tag, so an include glued to the END of a line silently
        removes a newline that used to be there.

    So it goes at the start of a line that continues with `{!!`, where it is
    followed by neither a newline nor the end of the line, and the off page is
    unchanged in both directions.
--}}
@if ($autocomplete)
<script>
(function () {
  /*
   * THE THIRD-PARTY SCRIPT IS LOADED HERE AND NOWHERE ELSE, and only because
   * App\Support\AddressAutocomplete::config() returned non-null, which it only
   * does when the owner has said yes in as many words.
   *
   * The URL is built server-side by AddressAutocomplete::scriptUrl(), which is
   * the one seam where Google's origin is written and the one thing a test has
   * to stand in for. It is emitted through Blade's escaping as an ordinary
   * attribute value; the key it carries was matched against Google's own key
   * shape before it got here, so it cannot carry a quote out of the attribute.
   */
  var field = document.getElementById('billing_address_1');

  if (!field || !window.KBB_ADDR) { return; }

  var s = document.createElement('script');
  s.src = window.KBB_ADDR.url;
  s.async = true;

  s.onload = function () {
    if (!(window.google && google.maps && google.maps.places)) { return; }

    var ac = new google.maps.places.Autocomplete(field, {
      /* The shop delivers in the UAE. A suggestion for an address it cannot
         reach is worse than a plain box, and it is billed for. */
      componentRestrictions: { country: window.KBB_ADDR.country },
      fields: ['address_components', 'name'],
      types: ['address'],
    });

    ac.addListener('place_changed', function () {
      var place = ac.getPlace();

      if (!place || !place.address_components) { return; }

      var part = function (type) {
        for (var i = 0; i < place.address_components.length; i++) {
          var c = place.address_components[i];
          if (c.types.indexOf(type) !== -1) { return c.long_name; }
        }
        return '';
      };

      /* Only ever fills boxes the shopper can then edit. Nothing here submits
         the form, and nothing overwrites a box that the suggestion has no
         answer for — a blank from Google is not a correction. */
      var city = part('locality') || part('postal_town');
      var state = part('administrative_area_level_1');

      var cityEl = document.getElementById('billing_city');
      var stateEl = document.getElementById('billing_state');

      if (cityEl && city) { cityEl.value = city; }
      if (stateEl && state && stateEl.tagName !== 'SELECT') { stateEl.value = state; }
    });
  };

  document.head.appendChild(s);
})();
</script>
<script type="application/json" id="kbb-addr-cfg">@json($autocomplete)</script>
<script>window.KBB_ADDR = JSON.parse(document.getElementById('kbb-addr-cfg').textContent);</script>
@endif
