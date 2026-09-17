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
                            <a href="{{ \App\Support\Url::to('/product/' . $item['slug'] . '/') }}" style="color:#1d1d1f;text-decoration:none;">{{ $item['name'] }}</a>
                        @else
                            {{ $item['name'] }}
                        @endif
                        <span style="color:#888;">&times; {{ $item['quantity'] }}</span>
                    </td>
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
