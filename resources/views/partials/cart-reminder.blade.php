{{--
    "Email me a reminder about this basket" — the abandoned-cart opt-in
    (Lane EN).

    ===========================================================================
    WHY THE CART PAGE AND NOT THE CHECKOUT
    ===========================================================================

    The checkout is where a recovery feature normally captures an address,
    because the address is already being typed there. That is exactly why it is
    the wrong place for this one. A shopper filling in the email field of a
    checkout is filling it in TO PLACE AN ORDER; treating what they type there
    as consent to be chased afterwards is collecting a permission by watching
    somebody work, and no tick box next to it makes the surrounding context
    honest.

    On the cart page there is no other reason to type an address. The box does
    one thing, it says what it does, and a shopper who does not want it simply
    does not fill it in. Store\CartRecoveryController's header lists the three
    captures this feature deliberately does NOT do, and this is the positive
    half of that argument.

    (The checkout markup exists as a hand-back item for the lane that owns
    resources/views/store/checkout.blade.php, should the owner want it. It is
    the same form and the same endpoint, with source='checkout'.)

    ===========================================================================
    TWO GATES, BOTH CLOSED BY DEFAULT
    ===========================================================================

    `$reminderLabel` is null unless the `abandoned_cart` module is ON *and* the
    owner has written the line beside the tick box. Both ship as they ship, so
    applying the package changes this page by exactly nothing.

    Note what this box does NOT gate on: whether the owner has written the
    EMAIL, or set a schedule. That is deliberate and it is the less obvious
    half. Consent is worth collecting from the moment the owner has written the
    thing the shopper is agreeing to; the sending gates are separate and the
    Sent mail screen's backlog panel reports an unwritten message or an empty
    schedule in words, so the gap is visible rather than silent. A shop that
    switched capture off until the email was drafted would lose every shopper
    who passed through in between.

    ===========================================================================
    THE BOX IS NOT PRE-TICKED
    ===========================================================================

    No `checked`, and no `value` that survives an unchecked box — a browser
    sends nothing at all for a checkbox that is not ticked, which is why
    Store\CartRecoveryController reads it with `boolean()` and treats absence as
    refusal. A pre-ticked box is not consent; it is a default somebody failed to
    notice, and this is the one feature in the shop where that distinction is
    the whole point.
--}}
@php use App\Support\Url; @endphp

@if ($reminderLabel !== null && ($totals['item_count'] ?? 0) > 0)
    <div class="cart-reminder" style="margin-top:20px;padding:16px;border:1px solid var(--line,#e6e6e6);border-radius:10px;max-width:640px;">
        @if (session('kbb_cart_reminder'))
            <p role="status" style="margin:0 0 10px;font-size:14px;">{{ session('kbb_cart_reminder') }}</p>
        @endif

        @if (session('kbb_cart_reminder_error'))
            <p role="alert" style="margin:0 0 10px;font-size:14px;color:#b00020;">{{ session('kbb_cart_reminder_error') }}</p>
        @endif

        {{-- A plain form post, no script. See partials/notify-me.blade.php:
             this has to work with scripts blocked, and CLAUDE.md records that
             asset builds on this project are manual. --}}
        <form method="post" action="{{ Url::to('/cart/remind-me') }}">
            @csrf

            <label for="cartReminderConsent" style="display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.5;cursor:pointer;">
                <input id="cartReminderConsent" type="checkbox" name="consent" value="1" style="margin-top:3px;flex:0 0 auto;">
                {{-- The owner's wording, escaped. This is the sentence the
                     shopper is agreeing to, so it is the owner's to write and
                     there is no default for it. --}}
                <span>{{ $reminderLabel }}</span>
            </label>

            <label for="cartReminderEmail" class="visually-hidden" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">{{ __('store.cart_reminder.email_label') }}</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <input id="cartReminderEmail" type="email" name="email" required maxlength="160"
                       autocomplete="email" placeholder="{{ __('store.cart_reminder.email_placeholder') }}"
                       style="flex:1 1 200px;min-width:0;padding:10px 12px;border:1px solid var(--line,#e6e6e6);border-radius:8px;font-size:15px;">
                <button type="submit" style="padding:10px 18px;border:0;border-radius:8px;background:#1d1d1f;color:#fff;font-size:15px;cursor:pointer;">{{ __('store.cart_reminder.submit') }}</button>
            </div>

            {{--
                WHAT IS STORED, WHEN, AND HOW TO GET OUT — on the form, in
                words, because that is what makes the consent informed. Every
                clause is a property the code actually has: the address and the
                basket link are the only things `cart_recoveries` holds, an
                order cancels the sequence inside the order's own transaction,
                and the unsubscribe link writes to `outbound_optouts` so a later
                capture is refused rather than merely stopped.
            --}}
            <p style="margin:10px 0 0;font-size:12px;color:#666;line-height:1.5;">
                {{ __('store.cart_reminder.privacy') }}
            </p>
        </form>
    </div>
@endif
