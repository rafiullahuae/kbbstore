{{--
    "Remember my details on this device" (Lane PO).

    The owner: "the address fields etc should keep the data in user browser, so
    user should not enter everything again n again. even wihout login." The
    keeping is resources/js/kbb/checkout.js (rememberDetails); this is only the
    shopper's say in it, ticked because he asked for it ON.

    NO name ATTRIBUTE, so nothing about it is ever posted: the server neither
    sees nor stores the choice, and place() is byte for byte what it was. The
    box sits inside #kbbCheckoutForm only so it reads as part of the form; its
    `change` is stopped at the box, so the card form's release-on-change, the
    wallet's re-pricing and inline validation never hear it.

    Appearance -> Checkout page -> Fields & attention -> "Remember shopper
    details on this device". Off renders nothing here and nothing in the
    Contact bar, and the script erases a copy a browser already holds.

    The "Not you? Clear details" link lives in the Contact bar (checkout.blade)
    and its few rules are here, pushed to the head with the rest of the page's
    own: it is in the layout from the first paint, `visibility:hidden` until a
    remembered copy is actually used, so showing it moves nothing (no layout
    shift), and its tap area is grown with a pseudo-element rather than a
    min-height that would make the bar taller.
--}}
                        <p class="form-row form-row-wide kbb-acct kbb-rmb" id="kbb_remember_field">
                            <label for="kbb_remember" class="kbb-acct-opt">
                                <input type="checkbox" id="kbb_remember" data-kbb-local checked>
                                <span>{{ __('store.checkout.remember_me') }}</span>
                            </label>
                        </p>
@push('styles')
<style>
.kbb-checkout .sec > h2 .kbb-rmb-clear{margin-inline-start:auto;position:relative;min-height:0;padding:0;border:0;background:none;
  font:inherit;font-size:11.5px;font-weight:600;line-height:1.2;color:var(--pink-ink,#A82F53);text-decoration:underline;
  text-underline-offset:2px;white-space:nowrap;cursor:pointer}
.kbb-checkout .sec > h2 .kbb-rmb-clear::after{content:'';position:absolute;inset:-13px -8px}
.kbb-checkout .sec > h2 .kbb-rmb-clear:not(.on){visibility:hidden}
</style>
@endpush
