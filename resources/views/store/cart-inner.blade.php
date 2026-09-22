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
        <b>{{ __('store.cart.empty_heading') }}</b>
        <p>{{ __('store.cart.empty_body') }}</p>
        <a class="cobtn" style="max-width:260px;margin:0 auto" href="{{ Url::to('/shop/') }}">{{ __('store.cart.empty_cta') }}</a>
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
@php
/*
 * Appearance → Cart page (Lane: cart-page).
 *
 * EVERY DIRECTIVE THIS LANE ADDS TO THIS FILE STARTS AT COLUMN 0, and that is
 * load-bearing rather than untidy. Blade compiles @php/@if/@endif to a bare
 * <?php ?> and PHP swallows the single newline after it, so a directive on a
 * line of its own contributes zero bytes — but the INDENTATION in front of it
 * is ordinary text and is emitted whatever the condition says. An @if indented
 * to line up with the markup around it would add its own indent and a newline
 * to every classic cart page in the shop, and StorefrontEnglishUnchangedTest
 * compares this page against the base commit byte for byte. The $kbbCartDp
 * block below is at column 0 for exactly the same reason and says so.
 *
 * $kbbCartPage comes from store/cart.blade.php, which resolves it once so the
 * two halves of this page cannot disagree about which layout they are drawing.
 * On the classic layout — which is how this ships — squeezed() is false, every
 * branch below is skipped, and this file renders the bytes it renders today.
 */
$kbbSq = $kbbCartPage->squeezed();
$kbbCpg = $kbbCartPage->all();
/*
 * The address the docked row names, read from the session on the server.
 *
 * Rendered rather than fetched, so a shopper who reloads the page — or who
 * changes a quantity and gets this whole file re-rendered under them by
 * cart.js — keeps the address they chose with no script running at all. The
 * sheet's script only has to update the row for the tap that just happened.
 *
 * Only on the squeezed layout: App\Support\CartAddressState::all() touches the
 * session and the address book, and the classic page has nothing to show for
 * it.
 */
$kbbAddrState = $kbbSq ? \App\Support\CartAddressState::all(request()) : null;
$kbbAddr = $kbbAddrState['chosen'] ?? null;
$kbbSignedIn = (bool) ($kbbAddrState['signedIn'] ?? false);
@endphp
    <div class="grid">
        <div>
            @if ($free && ! $kbbSq)
            <div class="ship">
                <div class="t">
                    @if ($left > 0)
                        {!! __('store.cart.free_delivery_away', ['amount' => '<b>' . \App\Support\Money::format($left, $leftDp) . '</b>', 'free_delivery' => '<b>' . e(__('store.cart.free_delivery_phrase')) . '</b>']) !!}
                    @else
                        🎉 <b>{!! \App\Support\Phrase::inline(__('store.cart.free_delivery_unlocked')) !!}</b>
                    @endif
                </div>
                <div class="bar"><div class="fill" style="width:{{ $pct }}%"></div></div>
            </div>
            @endif
            <div class="items">
                @foreach ($items as $item)
                    @php
                        $p     = $item->product;
                        // t(), not the column; the seed stays English so a
                        // line keeps one colour in both languages.
                        $brand = $p?->brand?->t('name') ?? '';
                        $name  = $p?->t('name');
                        $seed  = ($p?->brand?->name ?? '') . ($p?->name ?? '');
                        $img   = $item->variant?->image ?: $p?->image;
                        $thumb = $img ? "background-image:url('" . e($img) . "')" : 'background:' . Gradient::for($seed);
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
                        <div class="cth" style="{{ $thumb }}">{{ $img ? '' : Gradient::initials($brand ?: ($name ?? '?')) }}</div>
                        <div class="cmid">
                            @if ($brand)<div class="cbrand">{{ $brand }}</div>@endif
                            <div class="cn"><a href="{{ $p?->url() ?? '#' }}">{{ $name }}</a></div>
                            @if ($attrs)<div class="cvar">{{ $attrs }}</div>@endif
                            <div class="qty">
                                <button type="button" data-kcpq="{{ $item->id }}" data-d="-1" aria-label="{{ __('store.cart.decrease_quantity') }}">−</button>
                                <span>{{ $item->quantity }}</span>
                                <button type="button" data-kcpq="{{ $item->id }}" data-d="1" aria-label="{{ __('store.cart.increase_quantity') }}">+</button>
                            </div>
                        </div>
                        <div class="cright">
                            <div class="cpr">{!! \App\Support\Money::format($line, $wasDp) !!}@if ($was > $line)<span class="cwas">{!! \App\Support\Money::format($was, $wasDp) !!}</span>@endif</div>
                            <button class="crm" type="button" data-kcprm="{{ $item->id }}">{{ __('store.cart.remove_item') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
@if ($kbbSq)
        {{-- Recommended for you.

             FULL BLEED and no rounded corners: "it should be full width section
             with no border radius, just the background animated changing
             colors, but slight". The wash is a ::before layer so the cards on
             top of it stay opaque and the animation repaints nothing else, and
             it stops entirely for a phone that has asked for reduced motion.

             The whole section disappears when no products have been chosen on
             the admin screen. An empty horizontal scroller under a heading is
             worse than no section. --}}
        @php $kbbRec = $kbbCpg['rec_on'] ? $kbbCartPage->recommended() : collect(); @endphp
        @if ($kbbRec->isNotEmpty())
        <section class="cpg-rec">
            <h2>{{ $kbbCpg['rec_heading'] }}</h2>
            <div class="cpg-rail">
                @foreach ($kbbRec as $recProduct)
                    @php
                        $recSeed  = ($recProduct->brand?->name ?? '') . $recProduct->name;
                        $recImg   = $recProduct->image;
                        $recStyle = $recImg
                            ? "background-image:url('" . e($recImg) . "')"
                            : 'background:' . Gradient::for($recSeed);
                        $recWas   = $recProduct->isOnSale() ? (int) $recProduct->price : 0;
                        $recNow   = (int) $recProduct->effectivePrice();
                    @endphp
                    {{-- The card is a link and the + is a button BESIDE it, not
                         inside it: a <button> nested in an <a> is invalid, and
                         browsers disagree about which of the two a tap lands on.

                         `kc-badd` with `data-add` is cart.js's OWN one-tap add,
                         bound on `document`, so this needs no second add path
                         and no script from this lane: the plus becomes a tick,
                         the basket re-renders, and the `busy` class that drives
                         the loading shimmer is set and cleared by the same code
                         that does every other cart write on this page. --}}
                    <div class="cpg-card">
                        <span class="im" style="{{ $recStyle }}">{{ $recImg ? '' : Gradient::initials($recProduct->brand?->name ?: $recProduct->t('name')) }}<button class="kc-badd" type="button" data-add="{{ $recProduct->id }}" aria-label="{{ __('store.cart_drawer.browsed_add') }}">+</button></span>
                        <a class="lk" href="{{ $recProduct->url() }}">
                            <span class="nm">{{ $recProduct->t('name') }}</span>
                            <span class="pr">{!! \App\Support\Money::format($recNow) !!}@if ($recWas > $recNow)<span class="cwas">{!! \App\Support\Money::format($recWas) !!}</span>@endif</span>
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- The coupon box, minimal and directly under the rail.

             Moved here rather than copied: the block inside the summary below
             is switched off on this layout, so there is exactly one discount
             field on the page whichever layout is on. The applied-coupon row
             and the promo hint stay where they are — they are a state and a
             suggestion, not an entry field. --}}
        @if ($kbbCartCoupon)
        <div class="coupon">
            <input type="text" id="kbbCartCoupon" placeholder="{{ __('store.cart.coupon_placeholder') }}" autocomplete="off">
            <button type="button" data-kcpcoupon>{{ __('store.cart.coupon_apply') }}</button>
        </div>
            @if ($couponHint)
                <div class="cohint"><span>🎁</span><div>{!! $couponHint !!}</div></div>
            @endif
        @endif
@endif

        <aside class="sum">
@php
    /*
     * THE WIDTH EVERY ROW OF THIS SUMMARY PRINTS AT — Lane FA.
     *
     * The basket this lane started from printed "Subtotal AED 90 / − AED 1 /
     * Total AED 90" because each row was rounded on its own on the way to the
     * screen. See CartService::totals()' `decimals` key: 0 when every figure is
     * a whole dirham, which is the ordinary case under the whole-dirham policy,
     * and the currency's full precision for the WHOLE column the moment one of
     * them is not.
     *
     * AT COLUMN 0, WITH NO BLANK LINE AROUND IT, and that is not tidiness.
     * Blade compiles a raw-PHP block to one <?php ?> and PHP swallows the
     * single newline after it, so a block written this way contributes zero
     * bytes to the rendered page — which is what
     * StorefrontEnglishUnchangedTest, comparing this page byte for byte,
     * requires. Indented, it leaves its own indentation behind; given a blank
     * line of its own, it adds one.
     */
    $kbbCartDp = (int) ($totals['decimals'] ?? 0);
@endphp
@if (! $kbbSq)
            <h2>{{ __('store.cart.summary_heading') }}</h2>
            <div class="srow"><span>{{ __('store.cart.subtotal') }}</span><span>{!! \App\Support\Money::format($totals['subtotal'], $kbbCartDp) !!}</span></div>
@else
@php
/*
 * Order Value, with the struck-through "before" beside it.
 *
 * The before-price is COMPUTED FROM THE LINES rather than marked up from the
 * subtotal by a percentage: it is the sum of each line's regular price where
 * that line is on sale, and its own price where it is not. A percentage would
 * print a discount the shop is not actually giving, which is the one thing a
 * struck-through figure must never do.
 *
 * It prints only when it is genuinely higher. A "was" equal to the "now" is
 * either noise or a lie, and both read as a lie.
 */
$kbbWasTotal = 0;
foreach ($items as $kbbWasItem) {
    $kbbWasP = $kbbWasItem->product;
    $kbbWasTotal += ($kbbWasP && $kbbWasP->isOnSale())
        ? (int) $kbbWasP->price * $kbbWasItem->quantity
        : $kbbWasItem->lineTotal();
}
/*
 * THE TOTAL IS THE SUM OF EXACTLY WHAT IS PRINTED ABOVE IT, with one stated
 * exception.
 *
 * A switched-off row adds nothing, because a charge a shopper cannot see on
 * the line above is a charge they meet for the first time at the payment step
 * — and this file already carries a note about the last time the cart and the
 * checkout disagreed about a total.
 *
 * The exception is Express, which is QUOTED and never added even when its row
 * is on: it is an option somebody picks at checkout, not a charge applied
 * behind them, and the reference screen shows exactly that — Express priced
 * beside a free Standard, and a total that matches Standard.
 *
 * The fee is worked out ONCE, in CartPage::serviceFee(), and both the summary
 * and the docked bar read this one variable. Two roundings of the same number
 * is how two totals end up a fil apart on the same screen.
 */
$kbbFee = $kbbCartPage->serviceFee((int) $totals['total']);
$kbbGrand = (int) $totals['total'] + $kbbFee;
@endphp
            <div class="srow"><span>{{ $kbbCpg['sum_value_label'] }} <span class="cpg-n">({{ trans_choice('store.cart.item_count', $totals['item_count']) }})</span></span><span>@if ($kbbWasTotal > $totals['subtotal'])<span class="cpg-was">{!! \App\Support\Money::format($kbbWasTotal, $kbbCartDp) !!}</span>@endif{!! \App\Support\Money::format($totals['subtotal'], $kbbCartDp) !!}</span></div>
@endif
            @if ($totals['discount'])
                <div class="srow disc">
                    <span>{{ $totals['coupon_code'] }}</span>
                    <span>{!! \App\Support\Bidi::number('– ' . \App\Support\Money::format($totals['discount'], $kbbCartDp)) !!}</span>
                </div>
                <div class="appliedcoupon"><span>✓ {{ strtoupper($totals['coupon_code']) }}</span><a data-kcpremovecoupon="{{ $totals['coupon_code'] }}">{{ __('store.cart.remove_coupon') }}</a></div>
            @endif

            {{-- The entry UI only. The applied-coupon row above stays visible
                 whatever this is set to: hiding it would leave a shopper with a
                 discount they can see on the total and no way to take it off.

                 `! $kbbSq` because the squeezed layout renders this same field
                 under the recommended rail instead. Moved, not copied: two
                 inputs carrying id="kbbCartCoupon" would be one id twice, and
                 cart.js reads the value by id. --}}
            @if ($kbbCartCoupon && ! $kbbSq)
                <div class="coupon">
                    <input type="text" id="kbbCartCoupon" placeholder="{{ __('store.cart.coupon_placeholder') }}" autocomplete="off">
                    <button type="button" data-kcpcoupon>{{ __('store.cart.coupon_apply') }}</button>
                </div>
                @if ($couponHint)
                    <div class="cohint"><span>🎁</span><div>{!! $couponHint !!}</div></div>
                @endif
            @endif

@if ($kbbSq)
            {{-- The delivery and fee rows.

                 EACH ONE CARRIES ITS OWN (i), and that is a control rather
                 than decoration: every one of these charges is a rule the shop
                 can change, and a shopper who cannot ask why assumes the worst.
                 It is a `title`, so it needs no script and works on a keyboard.

                 EXPRESS IS QUOTED, NOT ADDED. The reference screen shows
                 Express priced beside a free Standard and a total that matches
                 Standard — so it is an option a shopper picks at checkout, and
                 a total that silently included it would surprise them at the
                 payment step. Both rows hide themselves at zero. --}}
            @if ($kbbCpg['sum_express_on'])
                <div class="srow"><span>{{ $kbbCpg['sum_express_label'] }} <i class="cpg-i" title="{{ $kbbCpg['sum_express_help'] }}">i</i></span><span>{!! \App\Support\Money::format((int) $kbbCpg['sum_express'], $kbbCartDp) !!}</span></div>
            @endif
            @if ($kbbCpg['sum_delivery_on'])
                <div class="srow"><span>{{ $kbbCpg['sum_std_label'] }} <i class="cpg-i" title="{{ $kbbCpg['sum_std_help'] }}">i</i></span><span class="free">{{ $kbbCpg['sum_std_free'] }}</span></div>
            @endif
            @if ($kbbCpg['sum_service_on'])
                <div class="srow"><span>{{ $kbbCpg['sum_service_label'] }} <i class="cpg-i" title="{{ $kbbCpg['sum_service_help'] }}">i</i></span><span>{!! \App\Support\Money::format($kbbFee, $kbbCartDp) !!}</span></div>
            @endif
            {{-- WHAT TAKES THE DELIVERY ROW'S PLACE: the free-delivery bar this
                 shop already has, moved here rather than rebuilt.

                 THE SAME CLASSES AND THE SAME TRANSLATION KEYS as the block at
                 the top of this file — `.ship > .t` and `.bar > .fill`,
                 store.cart.free_delivery_away and
                 store.cart.free_delivery_unlocked — so it inherits kbb-cart.css
                 and needs no new strings for words that are already translated
                 into Arabic. It is the same markup twice rather than a shared
                 partial for one reason: the block above is compared BYTE FOR
                 BYTE against the base commit by StorefrontEnglishUnchangedTest,
                 and extracting it would move its indentation. The copy up there
                 is switched off on this layout, so only one renders.

                 BOTH STATES, not only the happy one. "You're AED 40 away from
                 free delivery" is the more useful half: it answers the delivery
                 question AND gives a reason to add another item, which a
                 sentence about the checkout page does neither of.

                 The threshold is the shop's existing one — CartService::totals()
                 already resolves it into $totals['free_shipping_threshold'] —
                 so this lane adds no second setting for it.

                 $kbbCpg['sum_fallback'] is the fallback to the fallback: a shop
                 with no threshold configured has no bar to draw, and a summary
                 that says nothing at all about delivery is the thing all of
                 this exists to avoid. --}}
            @if (! $kbbCpg['sum_delivery_on'])
                @if ($free)
                <div class="ship">
                    <div class="t">
                        @if ($left > 0)
                            {!! __('store.cart.free_delivery_away', ['amount' => '<b>' . \App\Support\Money::format($left, $leftDp) . '</b>', 'free_delivery' => '<b>' . e(__('store.cart.free_delivery_phrase')) . '</b>']) !!}
                        @else
                            🎉 <b>{!! \App\Support\Phrase::inline(__('store.cart.free_delivery_unlocked')) !!}</b>
                        @endif
                    </div>
                    {{-- `cpg-flat` at zero, and only there. The fill carries a
                         flowing gradient and a glowing bloom on its leading
                         edge; at 0% there is no edge to ride and the bloom
                         would sit outside the track looking like a stray mark.
                         Decided here, where the number is, rather than by the
                         stylesheet guessing at the inline width. --}}
                    <div class="bar"><div class="fill{{ $pct > 0 ? '' : ' cpg-flat' }}" style="width:{{ $pct }}%"></div></div>
                </div>
                @elseif ($kbbCpg['sum_fallback'] !== '')
                <div class="srow cpg-later"><span>{{ $kbbCpg['sum_fallback'] }}</span></div>
                @endif
            @endif
                {{-- No VAT note. It is inclusive, the checkout says so, and a
                     second place saying it is a second place to keep true. --}}
                <div class="cpg-totband">
                    <span>{{ $kbbCpg['sum_total_label'] }}</span>
                    <span class="tr"><b>{!! \App\Support\Money::format($kbbGrand, $kbbCartDp) !!}</b></span>
                </div>
            {{-- The secure badge, a rule, and the marks. One row, tiny, as
                 asked.

                 THE MARKS ARE DRAWN ARTWORK NOW, not the text chips that used
                 to sit here. The owner asked for proper payment icons, and a
                 brand name set in 6.5px type reads as a label rather than as a
                 mark. They are simplified renderings in each scheme's own
                 colours -- the kind a merchant acceptance row carries -- and
                 NOT the schemes' licensed asset kits. That is a real
                 distinction and the note that stood here before was right to
                 raise it: Visa, Mastercard, Apple Pay, Google Pay, tabby and
                 tamara are trademarks with brand rules about size and clear
                 space. If the owner obtains their supplied kits, or a scheme
                 asks for its own file, App\Support\PaymentMarkArt is the single
                 place any of it is swapped -- nothing else in the application
                 knows what a payment mark looks like. --}}
            @if ($kbbCpg['trust_on'])
                <div class="cpg-trust">
                    <span class="sec"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 12.5 5 5 10-11"/></svg>{{ $kbbCpg['trust_text'] }}</span>
                    <span class="sep" aria-hidden="true">|</span>
                    {{-- The marks come from CartPage::paymentMarks(), not from
                         six literals here. They carry COMPANY NAMES — a
                         translated one is a different company — and prose in a
                         storefront template is what
                         StorefrontStringsAreKeyedTest exists to catch;
                         silencing it with six allowlist entries would spend
                         that guard's credibility on something that should not
                         be in a template at all. The brand names now live in
                         each drawing's aria-label and <title>, still in PHP,
                         which is what keeps this row's meaning for a screen
                         reader once the words became pictures.

                         {!! !!} and not {{ }}: the markup IS the mark. It is
                         safe to print unescaped for one reason only — the list
                         is a hardcoded constant, so nothing user-supplied can
                         reach it. Keep it that way. --}}
                    {{-- flex-wrap INLINE, overriding the `nowrap` that
                         cart-squeeze.blade.php sets on .cpg-squeeze .paylogos.
                         That rule is fine for six short words and wrong for six
                         drawings: six marks cannot fit one line of a 360px
                         phone at trust_size 150%, and under `nowrap` the
                         browser shrinks the chips instead, so turning the
                         slider UP made the artwork smaller. Wrapping lets the
                         marks keep the size the slider asked for and take a
                         second line when they need one. Inline because the rule
                         it overrides lives in a file this lane does not own;
                         it belongs in that stylesheet when the two next meet.
                         (The marks' own max-width:100% is still the floor: on a
                         320px screen a single line that cannot fit scales down
                         rather than overlapping.) --}}
                    <span class="paylogos" style="flex-wrap:wrap">@foreach ($kbbCartPage->paymentMarks() as $kbbMark)<span>{!! $kbbMark !!}</span>@endforeach</span>
                </div>
            @endif
@else
            <div class="srow tot"><span>{{ __('store.cart.total') }}</span><span>{!! \App\Support\Money::format($totals['total'], $kbbCartDp) !!}</span></div>
@endif
@if (! $kbbSq)
            {{-- CartController::payload() calls totals() with no shipping cost,
                 so this figure is the subtotal less any discount and nothing
                 else. A basket of AED 130 read "Total AED 130" here and became
                 AED 150 on the very next screen. The number is right; the word
                 beside it was not, and one line is cheaper than a shopper
                 discovering the difference at the payment step. --}}
            <div class="srow note">{{ __('store.cart.delivery_at_checkout') }}</div>
            <a class="cobtn" href="{{ Url::to('/checkout/') }}">{{ __('store.cart.checkout_cta') }}</a>
            <a class="conti" href="{{ Url::to('/shop/') }}">{{ __('store.cart.continue_shopping_link') }}</a>
            <div class="paylogos"><span>Visa</span><span>Mastercard</span><span>Tabby</span><span>Tamara</span><span>Apple Pay</span><span>{{ __('store.footer.pay_cod') }}</span></div>
@endif
        </aside>
@if ($kbbSq)
        {{-- The two rows that float at the foot of the screen.

             INSIDE #cartInner, and deliberately, unlike the address sheet in
             cart.blade.php. cart.js replaces this div's contents on every
             quantity change, so the count and the total in the checkout row are
             re-rendered by the server along with the basket that produced them
             — there is no second code path keeping them in step, and therefore
             no way for them to fall out of step.

             The chosen address is rendered here too, from the session, for the
             same reason: a shopper who reloads the page, or who changes a
             quantity, keeps the address they picked without any script running.
             The sheet's script only has to update the row for the tap that has
             just happened.

             FIXED, not sticky. "it must be floating at the bottom of the
             screen" — and a sticky element only floats while its own containing
             block is still under the viewport, so a two-line basket would leave
             the checkout button stranded halfway up the page. .wrap carries
             padding-bottom of exactly these two heights, so the last row is
             never underneath them.

             The address row is the SHORTER of the two, as asked, and both
             heights are sliders. --}}
        <div class="cpg-docked">
        @if ($kbbCpg['addr_on'])
            {{-- cpg-has is what turns the fade-off-the-right on, and it is
                 rendered by the server so the row is never briefly wrong: a
                 shopper who reloads with an address already chosen gets the
                 fade in the first paint, and one who has not chosen never sees
                 the prompt dissolve. paintRow() keeps it in step after a tap. --}}
            <div class="cpg-addrbar{{ $kbbAddr ? ' cpg-has' : '' }}">
                <div class="who">
                    <b id="cpgAddrHead">{{ $kbbAddr ? str_replace('{tag}', $kbbCpg['sheet_' . $kbbAddr['tag']], $kbbCpg['addr_chosen']) : $kbbCpg['addr_heading'] }}</b>
                    <span id="cpgAddrSub" @if (! $kbbAddr) hidden @endif>{{ $kbbAddr['line'] ?? '' }}</span>
                </div>
                <button class="cpg-addrbtn" id="cpgAddrBtn" type="button">{{ $kbbAddr ? $kbbCpg['addr_btn_change'] : ($kbbSignedIn ? $kbbCpg['addr_btn_change'] : $kbbCpg['addr_btn_add']) }}</button>
            </div>
        @endif
            <div class="cpg-cobar">
                <div class="tally">
                    <span>{{ trans_choice('store.cart.item_count', $totals['item_count']) }}</span>
                    <b>{!! \App\Support\Money::format($kbbGrand, $kbbCartDp) !!}</b>
                </div>
                <a class="cobtn" href="{{ Url::to('/checkout/') }}">{{ $kbbCpg['co_label'] }}</a>
            </div>
        </div>
@endif
    </div>
@endif
