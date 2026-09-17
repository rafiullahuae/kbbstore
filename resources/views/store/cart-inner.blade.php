{{--
    Cart page body — ported verbatim from kbb_cartpage_inner() in
    kbb-theme/functions.php. Classes, element order and inline styles copied
    exactly; only the data expressions are translated to Eloquent.
--}}
@php
    use App\Support\Gradient;
    use App\Support\Url;

    /*
     * Cart & mini-cart -> "Cart-page discount code box" (module key
     * cart_coupon_field), default OFF.
     *
     * Read here rather than passed in from CartController because that
     * controller belongs to another lane, and because this is already how a
     * dozen storefront views resolve a module (see product-card.blade.php and
     * partials/checkout/*.blade.php). moduleEnabled() returns the stored
     * module_toggles row when one exists, so an install that has chosen a value
     * keeps it; only an install with no row at all gets the default.
     */
    $kbbCartCoupon = app(\App\Services\SettingsService::class)->moduleEnabled('cart_coupon_field', false);
@endphp

@if ($items->isEmpty())
    <div class="empty">
        <div class="em">🛍️</div>
        <b>Your bag is empty</b>
        <p>Discover authentic K-Beauty to start your glow.</p>
        <a class="cobtn" style="max-width:260px;margin:0 auto" href="{{ Url::to('/shop/') }}">Start shopping</a>
    </div>
@else
    @php
        $free = $totals['free_shipping_threshold'];
        $left = $totals['free_shipping_remaining'] ?? 0;
        $pct  = $totals['free_shipping_percent'] ?? 100;

        /*
         * The same guard the cart panel carries, and for the same bytes: this
         * page printed "You're AED 0 away from free delivery" on a basket 30
         * fils short, above a bar the same @php block fills to 100% because the
         * percent is rounded too. Zero is the one number this sentence cannot
         * say while $left > 0, since zero is what "you have it" looks like —
         * and the 🎉 branch below, which is that state, did not run.
         */
        $leftDp = \App\Support\Money::decimalsToDistinguish($left, 0);
    @endphp
    <div class="grid">
        <div>
            @if ($free)
            <div class="ship">
                <div class="t">
                    @if ($left > 0)
                        You're <b>{!! \App\Support\Money::format($left, $leftDp) !!}</b> away from <b>free delivery</b>
                    @else
                        🎉 <b>You've unlocked free delivery!</b>
                    @endif
                </div>
                <div class="bar"><div class="fill" style="width:{{ $pct }}%"></div></div>
            </div>
            @endif
            <div class="items">
                @foreach ($items as $item)
                    @php
                        $p     = $item->product;
                        $brand = $p?->brand?->name ?? '';
                        $img   = $item->variant?->image ?: $p?->image;
                        $thumb = $img ? "background-image:url('" . e($img) . "')" : 'background:' . Gradient::for($brand . ($p?->name ?? ''));
                        $attrs = $item->variant?->label();
                        $line  = $item->lineTotal();
                        $was   = ($p && $p->isOnSale()) ? (int) $p->price * $item->quantity : 0;
                        // The struck "was" and the line total are two figures
                        // off one basket line, so they are quoted at one width
                        // and at a width that separates them: whole dirhams
                        // printed both as "AED 100" for a line marked down from
                        // 10000 to 9980 fils. Money::decimalsToDistinguish()
                        // returns the store's usual 0 for every other line.
                        $wasDp = ($was > $line) ? \App\Support\Money::decimalsToDistinguish($was, $line) : null;
                    @endphp
                    <div class="ci">
                        <div class="cth" style="{{ $thumb }}">{{ $img ? '' : Gradient::initials($brand ?: ($p?->name ?? '?')) }}</div>
                        <div class="cmid">
                            @if ($brand)<div class="cbrand">{{ $brand }}</div>@endif
                            <div class="cn"><a href="{{ $p?->url() ?? '#' }}">{{ $p?->name }}</a></div>
                            @if ($attrs)<div class="cvar">{{ $attrs }}</div>@endif
                            <div class="qty">
                                <button type="button" data-kcpq="{{ $item->id }}" data-d="-1" aria-label="Decrease quantity">−</button>
                                <span>{{ $item->quantity }}</span>
                                <button type="button" data-kcpq="{{ $item->id }}" data-d="1" aria-label="Increase quantity">+</button>
                            </div>
                        </div>
                        <div class="cright">
                            <div class="cpr">{!! \App\Support\Money::format($line, $wasDp) !!}@if ($was > $line)<span class="cwas">{!! \App\Support\Money::format($was, $wasDp) !!}</span>@endif</div>
                            <button class="crm" type="button" data-kcprm="{{ $item->id }}">Remove</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <aside class="sum">
            <h2>Order Summary</h2>
            <div class="srow"><span>Subtotal</span><span>{!! \App\Support\Money::format($totals['subtotal']) !!}</span></div>
            @if ($totals['discount'])
                <div class="srow disc">
                    <span>{{ $totals['coupon_code'] }}</span>
                    <span>– {!! \App\Support\Money::format($totals['discount']) !!}</span>
                </div>
                <div class="appliedcoupon"><span>✓ {{ strtoupper($totals['coupon_code']) }}</span><a data-kcpremovecoupon="{{ $totals['coupon_code'] }}">Remove</a></div>
            @endif

            {{-- The entry UI only. The applied-coupon row above stays visible
                 whatever this is set to: hiding it would leave a shopper with a
                 discount they can see on the total and no way to take it off. --}}
            @if ($kbbCartCoupon)
                <div class="coupon">
                    <input type="text" id="kbbCartCoupon" placeholder="Discount code" autocomplete="off">
                    <button type="button" data-kcpcoupon>Apply</button>
                </div>
                @if ($couponHint)
                    <div class="cohint"><span>🎁</span><div>{!! $couponHint !!}</div></div>
                @endif
            @endif

            <div class="srow tot"><span>Total</span><span>{!! \App\Support\Money::format($totals['total']) !!}</span></div>
            {{-- CartController::payload() calls totals() with no shipping cost,
                 so this figure is the subtotal less any discount and nothing
                 else. A basket of AED 130 read "Total AED 130" here and became
                 AED 150 on the very next screen. The number is right; the word
                 beside it was not, and one line is cheaper than a shopper
                 discovering the difference at the payment step. --}}
            <div class="srow note">Delivery calculated at checkout</div>
            <a class="cobtn" href="{{ Url::to('/checkout/') }}">Proceed to checkout →</a>
            <a class="conti" href="{{ Url::to('/shop/') }}">or continue shopping</a>
            <div class="paylogos"><span>Visa</span><span>Mastercard</span><span>Tabby</span><span>Tamara</span><span>Apple Pay</span><span>COD</span></div>
        </aside>
    </div>
@endif
