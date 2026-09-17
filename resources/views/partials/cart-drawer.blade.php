{{--
    Mini-cart drawer — ported verbatim from kbb_minicart_inner() in
    kbb-theme/functions.php. Class names, element order and inline styles copied
    exactly; only the data expressions are translated to Eloquent.

    The .kc-fragment wrapper is what the JS replaces, matching the WordPress
    fragment contract.
--}}
@php
    use App\Support\Gradient;
    use App\Support\Url;

    $count = $totals['item_count'];
    $sub   = $totals['subtotal'];
    $free  = $totals['free_shipping_threshold'];
    $left  = $totals['free_shipping_remaining'] ?? 0;

    /*
     * THE BAR'S FILL COMES FROM THE SERVICE, not from a second division here.
     *
     * CartService::totals() computes `free_shipping_percent` and its comment
     * says why: "Same basis as $toFree above, or the bar's fill and its caption
     * would tell two different stories about one basket." This panel was the
     * copy that did not use it — `$sub / $free * 100` — so one basket 30 fils
     * short filled this bar to 99.667774086379% while the cart page, reading
     * the service, filled its own to 100%. Two bars, one basket, two answers.
     * Measured on a running preview.
     */
    // The fallback carries the service's rule too, including its ceiling: 100
    // is reserved for a basket that has actually reached the threshold, so a
    // basket 30 fils short cannot round its own bar up to full. Without that,
    // a caller that omits the key gets back the very bug the service no longer
    // has. See CartService::totals().
    $pct   = $totals['free_shipping_percent']
        ?? ($free ? ($left > 0 ? min(99, (int) round($sub / $free * 100)) : 100) : 100);

    /*
     * "You're AED 0 away from free delivery" — printed, on a basket 30 fils
     * short, beside a bar that is not full and above a delivery charge that is
     * still being made. Money::format() rounds to whole dirhams on this store,
     * and a remainder under half a dirham rounds to the one number this
     * sentence must never say, because zero is the value that means the shopper
     * already has it.
     *
     * Money::decimalsToDistinguish($left, 0) is the same rule the sale prices
     * use, asked against zero: the store's usual whole dirhams whenever the
     * remainder can be stated without becoming nothing, and the currency's real
     * precision — "AED 0.30" — only when it cannot.
     */
    $leftDp = \App\Support\Money::decimalsToDistinguish($left, 0);

    // Wording from Appearance → Cart panel, so none of the copy below is fixed.
    $cpText = app(\App\Services\CartPanel::class);
@endphp

<div class="kc-fragment">
    <div class="kc-tabs">
        <button class="kc-tab on" type="button" data-kctab="cart">{{ $cpText->get("txt_tab_cart") }} <span class="n">{{ $count }}</span></button>
        <button class="kc-tab" type="button" data-kctab="browsed">{{ $cpText->get("txt_tab_browsed") }}</button>
        <button class="kc-x" type="button" data-kbb-close>✕</button>
    </div>
    <div id="kcCart">
        @if ($count)
            @if ($free)
            <div class="kc-ship">
                @if ($left > 0)
                    <div class="t">{!! str_replace('{amount}', '<b>' . \App\Support\Money::format($left, $leftDp) . '</b>', e($cpText->get("txt_ship_away"))) !!}</div>
                @else
                    <div class="t done"><b>{{ $cpText->get("txt_ship_done") }}</b></div>
                @endif
                <div class="kc-bar"><div class="kc-fill" style="width:{{ $pct }}%"></div></div>
            </div>
            @endif
            <div class="dbody">
                @foreach ($items as $item)
                    @php
                        $p = $item->product;
                        $brand = $p?->brand?->name ?? '';
                        $img = $item->variant?->image ?: $p?->image;
                        $thumb = $img
                            ? "background:#fff url('" . e($img) . "') center/cover"
                            : 'background:' . Gradient::for($brand . ($p?->name ?? ''));
                    @endphp
                    <div class="kc-item">
                        <div class="kc-th" style="{{ $thumb }}">{{ $img ? '' : Gradient::initials($brand ?: ($p?->name ?? '?')) }}</div>
                        <div class="kc-mid">
                            <div class="kc-nm">{{ $p?->name }}</div>
                            <div class="kc-qty">
                                <button type="button" data-kcq="{{ $item->id }}" data-d="-1">−</button>
                                <span>{{ $item->quantity }}</span>
                                <button type="button" data-kcq="{{ $item->id }}" data-d="1">+</button>
                            </div>
                        </div>
                        <div class="kc-right">
                            <button class="kc-rm" type="button" data-kcrm="{{ $item->id }}">✕</button>
                            <div class="kc-pr">{!! \App\Support\Money::format($item->lineTotal()) !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="dfoot">
                @if ($promo)<div class="kc-coupon" style="color:#5e545a;background:#fff0f4;font-size:10px"><span class="ic">🎁</span><div>{!! $promo !!}</div></div>@endif
                <div class="sumrow tot"><span>{{ $cpText->get("txt_subtotal") }}</span><span>{!! \App\Support\Money::format($sub) !!}</span></div>
                <div class="kc-btns">
                    <a class="btn-ghost" href="{{ Url::to('/cart/') }}">{{ $cpText->get("txt_btn_cart") }}</a>
                    <a class="cobtn" href="{{ Url::to('/checkout/') }}">{{ $cpText->get("txt_btn_checkout") }}</a>
                </div>
            </div>
        @else
            <div class="dbody"><div class="empty-d">{{ $cpText->get("txt_empty") }}<br>{{ $cpText->get("txt_empty_sub") }}</div></div>
        @endif
    </div>
    <div id="kcBrowsed" style="display:none">
        @if (! empty($browsed))
            <div class="dbody">
                @foreach ($browsed as $bp)
                    @php
                        $bbrand = $bp->brand?->name ?? '';
                        $bimg   = $bp->image;
                        $bthumb = $bimg
                            ? "background:#fff url('" . e($bimg) . "') center/cover"
                            : 'background:' . Gradient::for($bbrand . $bp->name);
                    @endphp
                    <div class="kc-item" data-brow="{{ $bp->id }}">
                        <a class="kc-th" href="{{ $bp->url() }}" style="{{ $bthumb }}">{{ $bimg ? '' : Gradient::initials($bbrand ?: $bp->name) }}</a>
                        <div class="kc-mid">
                            <div class="kc-nm">{{ $bp->name }}</div>
                            <div class="kc-pr" style="font-size:12.5px">{!! \App\Support\Money::format($bp->effectivePrice()) !!}</div>
                        </div>
                        <div class="kc-right">
                            @php
                                $bIn  = in_array($bp->id, $inCart ?? [], true);
                                $bOut = $bp->stock_status !== 'instock';
                                $bLbl = $bOut
                                    ? __('store.cart_drawer.browsed_sold_out')
                                    : ($bIn ? __('store.cart_drawer.browsed_in_bag') : __('store.cart_drawer.browsed_add'));
                            @endphp
                            {{-- A tick once it is in the bag, so the shopper can see at a glance
                                 what they have already taken. Still pressable: pressing again adds
                                 another, up to the 99 the server allows. --}}
                            <button type="button" class="kc-badd{{ $bIn ? ' in' : '' }}" data-add="{{ $bp->id }}" @disabled($bOut) aria-label="{{ $bLbl }}" title="{{ $bLbl }}">
                                @if ($bIn)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                                @else
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                @endif
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="dbody"><div class="empty-d" style="padding:30px 20px">{{ $cpText->get("txt_browsed_none") }}</div></div>
        @endif
    </div>
</div>
