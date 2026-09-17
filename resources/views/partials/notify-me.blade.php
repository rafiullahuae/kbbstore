{{--
    "Tell me when this is back" — the back-in-stock form (Lane EN).

    ===========================================================================
    WHERE IT SITS, AND WHY: BESIDE ADD TO BASKET, NOT INSTEAD OF IT
    ===========================================================================

    The module registry's inherited description says "in place of Add to cart
    when sold out". This deliberately sits BELOW the button instead, and the
    difference is worth arguing rather than assuming, because it is one of the
    questions handed back to the owner.

    Three reasons for beside:

     1. THE DISABLED BUTTON IS THE SHOPPER'S ANCHOR. It reads "Sold out" and it
        is where their eye already is. Removing it and putting a form there
        makes a familiar page look broken for the half-second before anyone
        reads the new thing; keeping it means the page says "sold out" in the
        place it always says what you can do, and then offers the remedy
        underneath.

     2. VARIANTS. This page's `$out` is computed for the PARENT, and a variable
        product can have the button live while the shade a shopper picked is
        sold out — the variant rows carry their own `stock_status` and the page
        tags them "Sold out" individually. A form that REPLACED the button would
        have to appear and disappear as options are clicked, which means the
        replacement is a scripted behaviour and a shopper with scripts blocked
        gets whichever state the server happened to render. A form that sits
        below is correct in every state without scripting.

     3. IT IS REVERSIBLE. Moving a form down the page is a decision the owner
        can change his mind about; teaching shoppers that the Add to cart button
        sometimes is not there is not.

    ===========================================================================
    AND WHY IT RENDERS FOR SOMEONE WHO HAS ALREADY BEEN ALERTED
    ===========================================================================

    Nothing here asks whether this shopper has a request outstanding, and it
    could not usefully: there is no session to ask about, and asking by address
    would mean a form that reveals whether an address is on the list — the
    membership oracle Store\SubscribeController's header describes.

    It is also the right answer to the case the brief names. A product that came
    back and sold out again has spent everybody's one alert; those shoppers need
    to ask again, and this is the form they ask with. StockAlerts' header
    explains why a spent request is not re-armed automatically.

    ===========================================================================
    TWO GATES, BOTH CLOSED BY DEFAULT
    ===========================================================================

    `$notifyLabel` is null unless the `back_in_stock` module is ON *and* the
    owner has written the line of prose that goes above the form. Both ship as
    they ship — off, and unwritten — so this partial renders nothing at all on
    a shop that has just applied the package, which is the property
    Support\CheckoutLegalNotice was built to have and the reason this is a
    variable rather than a literal.
--}}
@php use App\Support\Url; @endphp

@if ($notifyLabel !== null && $out)
    <div class="notifyme" style="margin-top:14px;padding:14px;border:1px solid var(--line,#e6e6e6);border-radius:10px;">
        {{-- The owner's prose, escaped. Plain text, not markup: this box is on
             a public page and the admin field behind it is a plain input. --}}
        <p style="margin:0 0 10px;font-size:14px;line-height:1.5;">{{ $notifyLabel }}</p>

        @if (session('kbb_stock_alert'))
            <p role="status" style="margin:0 0 10px;font-size:14px;color:#1d1d1f;">{{ session('kbb_stock_alert') }}</p>
        @endif

        @if (session('kbb_stock_alert_error'))
            <p role="alert" style="margin:0 0 10px;font-size:14px;color:#b00020;">{{ session('kbb_stock_alert_error') }}</p>
        @endif

        {{--
            A PLAIN FORM POST, and no script anywhere in this partial.

            It works with scripts blocked, which is the state a meaningful share
            of shoppers are in without knowing it, and it needs no asset build —
            CLAUDE.md records that package.json defines no `build` script and
            that asset builds are manual, so a feature whose form only works
            once somebody remembers to run vite is a feature that ships broken.

            Not nested inside the add-to-cart form above: a form inside a form
            is invalid HTML, and browsers resolve it by discarding the inner
            one, so the button would submit the CART.
        --}}
        <form method="post" action="{{ Url::to('/notify-me') }}">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">

            {{--
                The variant, when the shopper has picked one.

                Filled by the page's own option handling where that exists and
                left at 0 otherwise — 0 means "the product itself", the sentinel
                the stock_alerts unique index depends on. A wrong or foreign id
                is not an error: StockAlertController checks that the variant
                belongs to this product and falls back to 0, which is the
                request the shopper plainly meant.
            --}}
            <input type="hidden" name="variant_id" id="notifyVariantId" value="0">

            <label for="notifyEmail" class="visually-hidden" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">{{ __('store.notify_me.email_label') }}</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input id="notifyEmail" type="email" name="email" required maxlength="160"
                       autocomplete="email" placeholder="{{ __('store.notify_me.email_placeholder') }}"
                       style="flex:1 1 200px;min-width:0;padding:10px 12px;border:1px solid var(--line,#e6e6e6);border-radius:8px;font-size:15px;">
                <button type="submit" style="padding:10px 18px;border:0;border-radius:8px;background:#1d1d1f;color:#fff;font-size:15px;cursor:pointer;">{{ __('store.notify_me.submit') }}</button>
            </div>

            {{--
                WHAT WE DO WITH THE ADDRESS, ON THE FORM, IN WORDS.

                Not a link to a policy page. The promise this form makes is
                narrow and specific — one email, about this product, and not the
                mailing list — and it is a promise the code actually keeps:
                StockAlerts never touches `subscribers`, and
                NewsletterList::marketable() cannot see the table this writes
                to. A sentence somebody reads is worth more than a link nobody
                opens, and it is the sentence that makes the consent informed.
            --}}
            <p style="margin:10px 0 0;font-size:12px;color:#666;line-height:1.5;">
                {{ __('store.notify_me.privacy') }}
            </p>
        </form>
    </div>
@endif
