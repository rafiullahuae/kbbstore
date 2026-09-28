{{--
    The abandoned-basket reminder.

    Plain, not emails/layout.blade.php — see emails/back-in-stock.blade.php for
    the reasoning, which applies harder here: this goes to somebody who has
    never bought anything from this shop and whose address it holds only because
    they ticked a box on a cart page.

    NO PRICES TOTALLED, AND NO DISCOUNT.

    Two omissions worth naming. The line total is printed per item because the
    shopper chose those items and the quantities are a fact about their basket;
    a GRAND TOTAL is not printed, because the total that matters is the one at
    checkout after shipping, tax display rules and any coupon — and a number in
    an email that disagrees with the number at checkout is the kind of small
    wrongness that loses the sale it was sent to save. And there is no automatic
    discount code, because a shop that reliably discounts abandoned baskets has
    taught its customers to abandon baskets.

    THE ITEM LINK IS Url::external() AND NOT Url::to(), AND THAT WAS A LIVE BUG.

    Url::to() returns a ROOT-RELATIVE path. The two links this message is HANDED
    -- $cartUrl and $unsubscribeUrl, both built in OutboundSender -- have always
    been absolute; the per-item link is the one the view builds for itself, and
    it was the one that was missed. So every basket reminder this shop has ever
    sent named its products with href="/product/foo/", which an inbox has no
    origin to resolve and cannot follow. Nothing to do with moving domain: it
    has been dead in every mail client since the message was written.

    external() and not redirect(): this is rendered by the scheduled sender,
    which has no visitor to be in band with, and the reader of an email is never
    the person who made a request in any case.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p>{{ __('email.greeting.hello') }}</p>

    {{-- Escaped, then line breaks restored. See back-in-stock.blade.php. --}}
    <p>{!! nl2br(e($body)) !!}</p>

    @if (! empty($items))
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:20px 0;border-collapse:collapse;">
            @foreach ($items as $item)
                <tr>
                    <td style="padding:8px 0;border-bottom:1px solid #eee;font-size:14px;">
                        @if ($item['slug'] !== '')
                            <a href="{{ \App\Support\Url::external('/product/' . $item['slug'] . '/') }}" style="color:#1d1d1f;text-decoration:none;">{{ $item['name'] }}</a>
                        @else
                            {{ $item['name'] }}
                        @endif
                        <span style="color:#888;">&times; {{ $item['quantity'] }}</span>
                    @php if (($item['setContents'] ?? []) !== []) { echo '<div style="margin-top:5px;padding-inline-start:9px;border-inline-start:2px solid #eee;">' . implode('', array_map(fn ($l) => '<div style="font-size:12.5px;line-height:1.5;color:#666;">' . e($l) . '</div>', $item['setContents'])) . '</div>'; } @endphp{{-- (Lane SE) WHAT IS IN THE SET, one member per line.

                         A Set is one basket line at one price, so this table chased a shopper with a single anonymous name and no way to see what they had left behind — the same defect the order documents had, on the one message whose entire job is to make somebody want the basket back.

                         THE LIVE PIVOT, DELIBERATELY. CartRecovery::basket() reads the set as it is NOW, because this message is built at SEND time so that it describes the basket as it is now. That is the OPPOSITE of every order document, which reads the `order_items` snapshot; basket()'s docblock says why at length.

                         NO MONEY FROM THE MEMBERS. The figure in the cell beside this one is still the cart line's own `unit_price * quantity`. These lines carry a quantity and a name and nothing else.

                         ▲ THE BLOCK SITS AT THE START OF THE </td> LINE AND NOT AT THE END OF THE ONE ABOVE, and that is measured rather than reasoned. `@endphp` compiles to a PHP CLOSE TAG and PHP swallows the newline immediately after one — so appended to the `</span>` line it ate that line's newline and changed a byte in EVERY reminder this shop has ever sent, set or no set. PrintedEnglishUnchangedTest went red on `cart-recovery (html)` for exactly that, before this paragraph existed. Here the twenty spaces of indent are the ones that were always there, the block emits nothing for an ordinary line, and `</td>` follows on the same line so there is no newline left for the close tag to eat.

                         (emails/partials/items.blade.php can attach its own copy of this block to an `@endif` because a directive ALREADY ate that newline; there is no directive to hide behind here.) --}}</td>
                    <td style="padding:8px 0;border-bottom:1px solid #eee;font-size:14px;text-align:right;white-space:nowrap;">
                        {!! \App\Support\Money::format($item['unit_price'] * $item['quantity']) !!}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="margin:24px 0;">
        <a href="{{ $cartUrl }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">{{ __('email.cart_recovery.view_basket') }}</a>
    </p>

    {{--
        WHY THIS ARRIVED, IN WORDS — and here it is not merely good manners.
        This message exists because the recipient gave an address to a shop they
        have not yet bought from, which is precisely the situation a recipient
        does not remember a week later. Saying so, and saying where the address
        came from, is what the tick box promised.
    --}}
    <p style="font-size:13px;color:#555;">{{ __('email.cart_recovery.why') }}</p>

    <p style="color:#555;">— {{ $brand['storeName'] ?? config('app.name') }}</p>

    <p style="font-size:12px;color:#888;border-top:1px solid #eee;padding-top:12px;">
        {!! \App\Support\Phrase::inline(__('email.cart_recovery.unsubscribe_prompt')) !!}
        <a href="{{ $unsubscribeUrl }}" style="color:#888;">{{ __('email.common.unsubscribe') }}</a> {{ __('email.cart_recovery.unsubscribe_tail') }}
    </p>
</div>
