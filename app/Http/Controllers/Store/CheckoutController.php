<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Orders\OrderNumbers;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Checkout page and order placement.
 *
 * Totals are recomputed here from the cart and the catalogue. Nothing the
 * browser sends about price, discount or shipping is trusted — the form carries
 * choices (country, rate, payment method), never amounts. (Rule 23)
 */
class CheckoutController extends Controller
{
    private const LINE_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image', 'sku', 'type',
    ];

    /**
     * Order numbers this browser session is allowed to see in full.
     *
     * Deliberately NOT kbb_last_order, which the success view consumes on
     * first render so the Purchase pixel fires exactly once. See mayView().
     */
    private const VIEWABLE_KEY = 'kbb_orders_viewable';

    /**
     * What each field is called on the page, for the sentences a shopper reads.
     *
     * Laravel builds a message out of the field's NAME, so an empty checkout
     * answered "The billing email field is required." — `billing_email` is a
     * column name, "billing email" is not what the label above the box says,
     * and this is the first thing a person sees when an order does not go
     * through. Every name here is the label that is actually printed beside the
     * input in store/checkout.blade.php, so the sentence and the form agree.
     */
    private const FIELD_NAMES = [
        'billing_email' => 'email address',
        'billing_phone' => 'phone number',
        'billing_first_name' => 'name',
        'billing_last_name' => 'last name',
        'billing_address_1' => 'address',
        'billing_city' => 'city / area',
        'billing_state' => 'emirate',
        'billing_country' => 'country',
        'payment_method' => 'payment method',
        'account_password' => 'password',
        'customer_note' => 'delivery notes',
        'gift_note' => 'gift message',
    ];

    /** Emirates, in the order the live store lists them. */
    private const EMIRATES = [
        'Abu Dhabi' => 'Abu Dhabi', 'Dubai' => 'Dubai', 'Sharjah' => 'Sharjah',
        'Ajman' => 'Ajman', 'Umm Al Quwain' => 'Umm Al Quwain',
        'Ras Al Khaimah' => 'Ras Al Khaimah', 'Fujairah' => 'Fujairah',
    ];

    public function __construct(
        private CartService $carts,
        private ShippingService $shipping,
        private SettingsService $settings,
        private \App\Services\CouponService $coupons,
        private OrderNumbers $orderNumbers,
    ) {}

    public function page(Request $request): View|RedirectResponse
    {
        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return redirect(Url::redirect('/cart/'));
        }

        $customer = $request->user('customer');

        /*
         * THE ADDRESS BOOK IS ASKED FOR SHIPPING FIRST AND BILLING SECOND.
         *
         * It used to ask for shipping alone, which is Customer::defaultAddress()'s
         * default — and on this shop's own data that is the wrong question to
         * ask only once. WooCommerce has no addresses table: a customer's
         * billing and shipping addresses are loose usermeta keys, and
         * Import\AddressWriter skips whichever of the two the export left empty
         * ("an address with nothing in it is not an address"). A Woo customer
         * who only ever filled in billing — which is most of them, because a
         * shop delivering to the billing address never asks for a second one —
         * therefore has exactly one row, of type `billing`, and this checkout
         * prefilled NOTHING for them. Store -> Orders has asked both questions
         * in this order since it gained a customer picker; the storefront was
         * the half that did not.
         *
         * Shipping still wins where both exist. This page's step 2 is headed
         * "Shipping address" and that is what it is.
         */
        $address = $customer?->defaultAddress('shipping') ?? $customer?->defaultAddress('billing');

        // The country list is always live now — the zone countries (Gulf, in
        // production) plus anything Extended has added — so the selector and
        // detection both apply regardless of whether Extended has ever been
        // opened. Order of precedence: what they chose, then their saved
        // address, then detection, then the store's own country. Detection
        // never overrides a person who has already said where they are.
        // (The Extended service itself is no longer resolved here: the one thing
        // this method asked it for, the arrival estimate, is now deliveryEta()
        // below, so the page and the country-change refresh cannot answer
        // differently.)
        $countries = $this->countries();

        /*
         * Through App\Support\ShopperCountry, which is now the only thing in
         * the application that answers "where is this shopper?" — the home
         * page asks it too, and used to ask nobody at all and print a UAE
         * delivery promise to the world. The precedence this controller had is
         * unchanged and is documented there: what they chose on this request,
         * then what they chose on an earlier one, then a geo guess, then the
         * store's own country. The saved address is handed IN rather than
         * fetched there, because it is the one tier that costs a query and the
         * storefront must not pay for it on every page.
         */
        $resolved = \App\Support\ShopperCountry::for($request, $address?->country);

        /*
         * A GUESS IS FILTERED AGAINST THE SHOP'S OWN LIST; A STATEMENT IS NOT.
         * That asymmetry is deliberate and predates this class. A country the
         * shopper typed or saved is used as given even if no zone covers it,
         * so the page can go on to say "we do not deliver there yet"; a guess
         * that lands somewhere undeliverable must not silently become the
         * selected country.
         */
        $country = ($resolved->guessed() && ! isset($countries[$resolved->code]))
            ? (string) $this->settings->get('store_country', 'AE')
            : $resolved->code;

        // Only badge it as detected when nothing the shopper said supplied the
        // answer and the guess actually landed on a country this shop delivers
        // to. A remembered choice is not a detection and never wore this badge.
        $wasDetected = $resolved->source === \App\Support\ShopperCountry::HEADER
            && isset($countries[$resolved->code]);

        // Detection found somewhere this shop does not deliver to. Said
        // plainly rather than silently falling back to the store's own
        // country and letting the shopper find out at the payment step.
        $unserved = ($resolved->source === \App\Support\ShopperCountry::HEADER
                && ! isset($countries[$resolved->code]))
            ? $resolved->code
            : null;

        [$rates, $chosen, $totals] = $this->rateContext($cart, $country, old('billing_state') ?? $address?->state);

        return view('store.checkout', [
            'settings' => $this->settings,
            'cart' => $cart,
            'items' => $cart->items,
            'totals' => $totals,
            'rates' => $rates,
            'chosenRate' => $chosen,
            'gateways' => $this->gateways((int) ($totals['total'] ?? 0), $country),
            // Set when Payment & Shipping Rules has hidden Cash on delivery, so
            // the page can say why rather than the option simply not being there.
            'codHidden' => app(\App\Services\PayShipRules::class)
                ->codHiddenReason((int) ($totals['total'] ?? 0)),
            'states' => self::EMIRATES,
            'countries' => $countries,
            'defaultCountry' => $country,
            'countryDetected' => $wasDetected,
            'unservedCountry' => $unserved,
            'deliveryEta' => $this->deliveryEta($country),
            'deliveryText' => $this->deliveryText($country),
            'showBrowsed' => (bool) $this->settings->get('show_browsed', true),
            'browsed' => $this->browsed($request, $cart),
            // Off splits the field into first and last name. The backend has
            // always accepted either shape — splitName() auto-splits a single
            // value on spaces, or takes an explicit last name when one is
            // posted — only the form itself was never given the other shape
            // to send. The setting existed and did nothing until now.
            'singleName' => (bool) $this->settings->get('checkout_single_name', true),
            'prefill' => $this->prefill($customer, $address),
        ]);
    }

    /**
     * THE RETURN TYPE IS WIDER THAN IT WAS, and only for the card path.
     *
     * A card typed into fields on this page cannot be confirmed by a form
     * POST: the browser has to stay on the page, hand the card to Stripe and
     * deal with whatever the issuer asks for next. So the checkout submits
     * this endpoint with fetch() when the chosen gateway wants that, and gets
     * JSON back instead of a 302.
     *
     * Every other caller is byte-for-byte unaffected. The branch is
     * `$request->expectsJson()`, which an ordinary form POST never is, and
     * each refusal below is translated by refused() rather than rewritten —
     * so a validation failure says the same thing through both doors.
     */
    public function place(Request $request): RedirectResponse|JsonResponse
    {
        // The form marks Last name as required only when the single-name
        // field is off (Store → Ecommerce → Checkout → Form fields); the
        // server enforces the same thing it showed, rather than trusting
        // whatever shape was actually posted.
        $lastNameRule = $this->settings->get('checkout_single_name', true) ? 'nullable' : 'required';

        $data = $request->validate([
            'billing_email' => ['required', 'string', 'max:160', new \App\Rules\StorefrontEmail],
            // Guest checkout -> account. Both optional: leaving them alone
            // keeps the existing guest flow byte-for-byte unchanged.
            'create_account' => ['nullable', 'boolean'],
            'account_password' => ['nullable', 'required_if:create_account,1', 'string', 'min:8', 'max:72'],
            // "Save this card for future purchases", from the card form. Only
            // a request, never a permission: whether it is honoured is decided
            // below, after it is known whether this shopper has an account for
            // the card to belong to.
            'save_card' => ['nullable', 'boolean'],
            // Order note and gift message. Both optional; 600 characters is
            // generous for a gift card and short enough that a paste of an
            // entire email does not end up printed on one.
            'customer_note' => ['nullable', 'string', 'max:600'],
            'is_gift' => ['nullable', 'boolean'],
            'gift_note' => ['nullable', 'string', 'max:600'],
            // Optional, matching the live checkout where Phone is marked
            // (optional). Requiring it here would reject a valid order.
            'billing_phone' => ['nullable', 'string', 'max:40'],
            'billing_first_name' => ['required', 'string', 'max:120'],
            'billing_last_name' => [$lastNameRule, 'string', 'max:120'],
            'billing_address_1' => ['required', 'string', 'max:255'],
            'billing_city' => ['required', 'string', 'max:120'],
            // A free-text field on the live site, not a fixed list.
            'billing_state' => ['required', 'string', 'max:120'],
            'billing_country' => ['required', 'string', 'size:2'],
            'shipping_method' => ['nullable', 'integer'],
            'payment_method' => ['required', 'string', 'max:40'],
            'billing_kbb_whatsapp' => ['nullable'],
        ], [], self::FIELD_NAMES);

        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return $request->expectsJson()
                ? $this->refused($request, 'Your bag is empty.')
                : redirect(Url::redirect('/cart/'))->withErrors('Your bag is empty.');
        }

        /*
         * RE-CHECK THE COUPON AGAINST THE BASKET BEING BOUGHT, NOT THE ONE IT
         * WAS APPLIED TO.
         *
         * validate() used to run in exactly two places -- CartController::
         * coupon() and couponUpdate() below -- both of which are "the shopper
         * just typed a code". Nothing ran it again afterwards, and nothing
         * clears cart.coupon_id when the basket changes: updateQuantity() and
         * remove() in CartService touch neither. The cart cookie lives 30 days.
         *
         * So the discount that reached `orders.discount_total` was priced by
         * CartService::totals() -> CouponService::discountFor(), which applies
         * the product and category rules and nothing else. Expiry, minimum
         * spend, maximum spend and the allowed-email list were enforced on the
         * cart page and nowhere else. Apply a code at AED 500, empty the
         * basket to AED 50, press Place Order, and a minimum-spend of AED 500
         * paid out against AED 50 -- the whole basket free, and the shop still
         * paying the courier. An expired code kept working for as long as the
         * cart survived.
         *
         * usage_limit and usage_limit_per_user were the only two already
         * re-checked here, and only as a side effect of recordRedemption()
         * re-reading the row under a lock to settle the race.
         *
         * This is the check the admin's own path has always made:
         * ManualOrderBuilder::price() re-runs validate() before it prices
         * anything, for the reason written beside it there. The storefront is
         * where the real money is and it was the half that did not.
         *
         * Refusing rather than silently dropping the code matches what already
         * happens when CouponExhausted is thrown below: the shopper is told in
         * the coupon's own words and keeps their basket, rather than being
         * charged a total they never agreed to.
         *
         * The email is the one being ordered under, which is what makes the
         * allowed-emails and per-user rules mean anything for a guest -- at
         * the moment the code was applied there may have been no email at all.
         */
        if ($cart->coupon) {
            $check = $this->coupons->validate(
                $cart->coupon->code,
                $cart,
                $data['billing_email'],
            );

            if (! $check['ok']) {
                return $this->refused($request, $check['error']);
            }
        }

        // The single "Full name" field is split on save, exactly as the theme does.
        [$first, $last] = $this->splitName($data['billing_first_name'], $data['billing_last_name'] ?? null);

        $rates = $this->shipping->ratesFor($data['billing_country'], $data['billing_state'],
            $this->netSubtotal($cart), (bool) $this->settings->get('hide_paid_when_free', true));

        if (! $rates) {
            return $this->refused($request, 'We do not deliver to that country yet.');
        }

        // Only a rate actually offered for this destination is accepted.
        $rate = collect($rates)->firstWhere('id', $data['shipping_method'] ?? null) ?? $rates[0];

        $totals = $this->carts->totals($cart, $data['billing_country'], $data['billing_state'], (int) $rate['cost']);

        // The same checks the payment list applies, repeated on submit. The
        // list is only what the page offered; a posted method is whatever the
        // shopper sent, and the note beside it in the plugin's own comment —
        // "only a rate actually offered for this destination is accepted" —
        // applies just as much to a gateway. Two separate reasons COD can be
        // unavailable: the order-value window (Payment & Shipping Rules), and
        // now also whether Store → Ecommerce → Checkout has it switched on at
        // all — a toggle that only hid the option from the page without also
        // being enforced here would not really be a switch.
        $offered = $this->gateways((int) ($totals['total'] ?? 0), $data['billing_country']);

        if ($data['payment_method'] === 'cod') {
            $reason = app(\App\Services\PayShipRules::class)
                ->codHiddenReason((int) ($totals['total'] ?? 0));

            // Kept as its own branch purely for the wording: the window has a
            // specific sentence to say ("available on orders over X"), which
            // the generic check below cannot produce.
            if ($reason !== null) {
                return $this->refused($request, $reason);
            }
        }

        // Generalised from the COD-only check that stood here. The reasoning
        // never depended on the method: the list is only what the page
        // offered, and a posted method is whatever the shopper sent. An
        // unconfigured Stripe, a Tabby switched off an hour ago, and a gateway
        // id this build has no code for are all the same answer — it was not
        // on offer, so it is not accepted.
        if (! collect($offered)->contains('id', $data['payment_method'])) {
            return $this->refused($request, 'That payment method is not available.');
        }

        // The wording the shopper actually saw on the checkout page. It is
        // snapshotted onto the order for the same reason order_items snapshot
        // name and price: the merchant can rename a gateway in Store →
        // Payments tomorrow, and an old order must still say what it said on
        // the day. Without this the order pages fall back to the raw id and
        // print "cod" at the customer.
        $paymentTitle = (string) (collect($offered)
            ->firstWhere('id', $data['payment_method'])['title'] ?? '');

        $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find($data['payment_method']);

        if ($gateway === null) {
            return $this->refused($request, 'That payment method is not available.');
        }

        // Gift fee is read from settings, never from the request. The form
        // posts whether the shopper wants wrapping; how much that costs is the
        // merchant's to decide, and a posted amount would be a price the
        // browser got to choose.
        $giftFee = ($request->boolean('is_gift') && $this->settings->get('gift_enabled', '1'))
            ? (int) $this->settings->get('gift_fee', '1500')
            : 0;

        $request->session()->forget('kbb_gift');

        // The gateway's own surcharge, asked of the gateway rather than
        // inferred from its id. Still read server-side -- COD is the only one
        // that charges anything today and it reads the same `cod_fee` setting
        // it always did, never a figure from the request.
        $fee = $giftFee + $gateway->feeFils((int) ($totals['total'] ?? 0));

        // $giftFee is in this use list because the closure writes it to the
        // order's gift_fee column. It was missing, and a PHP closure inherits
        // nothing it is not handed -- so every call reached "Undefined
        // variable $giftFee", which Laravel's error handler turns into an
        // ErrorException. That is thrown from inside DB::transaction, so the
        // order rolled back: the storefront checkout could not place an order
        // at all. Found by the first test to POST to this endpoint.
        /*
         * BEFORE the transaction, deliberately, and this is load-bearing.
         *
         * The number used to be minted inside the closure below, from a MAX()
         * read that MySQL served out of the transaction's own snapshot. Two
         * shoppers placing an order at the same moment therefore computed the
         * same number, and the loser's order died on the unique index at the
         * last step of their checkout — the retry could not help, because
         * every retry re-read the same frozen maximum. Allocating out here
         * gives each statement its own transaction and its own view of what
         * has been committed, which is the whole of the fix; the reasoning in
         * full, including why this takes no lock, is in OrderNumbers.
         */
        $orderNumber = $this->nextOrderNumber();

        /*
         * Set by the closure below when THIS request gave a brand-new account
         * its password, and read afterwards by the one decision that needs it:
         * whether a card may be saved. See the guard beneath the transaction.
         */
        $accountCreated = false;

        try {
            $order = DB::transaction(function () use ($cart, $data, $first, $last, $rate, $totals, $fee, $giftFee, $request, $paymentTitle, $orderNumber, &$accountCreated) {
                $customer = $request->user('customer') ?? Customer::firstOrCreate(
                    ['email' => mb_strtolower($data['billing_email'])],
                    ['name' => trim($first . ' ' . $last), 'first_name' => $first, 'last_name' => $last, 'phone' => $data['billing_phone'] ?? null]
                );

                // A guest who asked for an account gets a usable password on the
                // row firstOrCreate already made for them. `password` is cast
                // `hashed`, so assigning the plain value hashes it.
                //
                // The guard matters more than the feature, and it is stated once,
                // in canSetInitialPassword() -- the order-received page offers the
                // same thing to a guest afterwards and asks that same method, so
                // the two cannot drift apart.
                //
                // Silent when it declines. Telling the person at checkout that an
                // account already exists for an address they typed is an account
                // enumeration oracle, and the order itself is fine either way.
                if (! $request->user('customer')
                    && $request->boolean('create_account')
                    && ($data['account_password'] ?? '') !== ''
                    && self::canSetInitialPassword($customer)
                ) {
                    $customer->forceFill(['password' => $data['account_password']])->save();
                    $accountCreated = true;
                }

                $address = [
                    'first_name' => $first, 'last_name' => $last,
                    'line1' => $data['billing_address_1'], 'city' => $data['billing_city'],
                    'state' => $data['billing_state'], 'country' => $data['billing_country'],
                    'phone' => $data['billing_phone'] ?? null,
                ];

                $order = Order::create([
                    'order_number' => $orderNumber,
                    'customer_id' => $customer->id,
                    'email' => mb_strtolower($data['billing_email']),
                    'phone' => $data['billing_phone'] ?? null,
                    'status' => 'pending',
                    'currency' => 'AED',
                    // customer_note has existed on this table from the start, but
                    // nothing ever wrote to it and the admin never showed it, so
                    // the column was dead at both ends. Wired here and rendered on
                    // the order screen in the same package.
                    'customer_note' => $data['customer_note'] ?? null,
                    'is_gift' => $request->boolean('is_gift'),
                    // The amount charged, not the amount configured. Recomputing
                    // this later from the setting would misreport every past order
                    // the first time the price changes.
                    'gift_fee' => $giftFee,
                    // Only kept when the gift box is actually ticked -- otherwise
                    // an untouched-but-populated field (browser autofill, a
                    // shopper changing their mind) would print a gift card nobody
                    // asked for.
                    'gift_note' => $request->boolean('is_gift') ? ($data['gift_note'] ?? null) : null,
                    'billing_address' => $address,
                    'shipping_address' => $address,
                    'subtotal' => $totals['subtotal'],
                    'discount_total' => $totals['discount'],
                    // From the totals this request already computed, not from
                    // the raw rate. The two were the same number until a
                    // coupon could carry free_shipping; now CartService::
                    // totals() zeroes the delivery line for such a code, and
                    // `total` below is built on that zero. Taking shipping_total
                    // off $rate['cost'] here would leave the order stating AED
                    // 20 of delivery against a total that does not contain it —
                    // one order disagreeing with itself by the whole rate, on
                    // the invoice, in the confirmation email and in the
                    // accounts. ManualOrderBuilder has always written
                    // $totals['shipping']; this is the storefront half catching
                    // up. Every other case is unchanged, because with no such
                    // coupon totals() returns the rate it was handed.
                    'shipping_total' => (int) $totals['shipping'],
                    'fee_total' => $fee,
                    /*
                     * THE TAX, AS IT WAS ON THE DAY, RECORDED ON THE ORDER.
                     *
                     * This was `0` with the note "VAT is display-only (D-64)".
                     * The owner overturned D-64 on 2026-09-16 — see the header
                     * of App\Support\VatDisplay for his three messages and the
                     * reasoning — so the column now carries the tax that is
                     * INSIDE `total`, and the rate and basis that produced it
                     * are written beside it.
                     *
                     * THE RATE AND BASIS ARE SNAPSHOTTED, NOT LOOKED UP LATER,
                     * for the same reason `payment_method_title` and the order
                     * line's `name` and `unit_price` are: the owner can change
                     * Saudi Arabia from 5% to 15% next year, and last year's
                     * invoices must not silently reprint at the new rate.
                     *
                     * In the shipped default state (tax_mode = 'display') all
                     * three are 0 / null / null, which is byte-for-byte what
                     * this row held before.
                     */
                    'tax_total' => (int) $totals['tax_charged'],
                    'tax_rate' => $totals['tax_rate'],
                    'tax_basis' => $totals['tax_basis'],
                    // Already contains the tax when the destination is on an
                    // exclusive basis: totals() added it to `total` there, so
                    // adding it again here would charge it twice.
                    'total' => $totals['total'] + $fee,
                    'shipping_method' => $rate['title'],
                    'payment_method' => $data['payment_method'],
                    'payment_method_title' => $paymentTitle !== '' ? $paymentTitle : null,
                    'coupon_code' => $totals['coupon_code'],
                    'whatsapp_optin' => $request->boolean('billing_kbb_whatsapp'),
                    'ip_address' => $request->ip(),
                ]);

                /*
                 * THE STOCK CHECK, and the only one that happens where the
                 * money is.
                 *
                 * Everything above this line has been true since the shopper
                 * pressed Add to cart, which may have been a fortnight ago:
                 * CartController::add() and browsedAdd() both ask whether a
                 * product is in stock AT THE MOMENT IT GOES IN THE BAG, and
                 * nothing asked again. So a product that sold out in between
                 * was sold anyway, and no counted stock figure had ever been
                 * reduced by an order at all.
                 *
                 * Deliberately BEFORE the order lines are written, and inside
                 * this transaction: StockUnavailable propagates out of
                 * DB::transaction() and rolls back the order row, the customer
                 * row's password, and — crucially — the coupon redemption
                 * recorded below, so a refused placement has not spent the
                 * shopper's one use of a code. It is also before
                 * $gateway->start() further down, so nothing has been asked of
                 * a payment provider.
                 *
                 * The whole basket is refused rather than trimmed. The reasoning
                 * for that choice, and what was rejected, is in
                 * CartService::claimStock().
                 *
                 * THE ORDER IS PASSED so that what leaves the shelf is recorded
                 * against it in `order_stock_claims`. That record is the only
                 * thing that makes a return possible later: it is what tells a
                 * cancellation which units this order actually took, as opposed
                 * to which units its lines now say it should have.
                 */
                $this->carts->claimStock($cart, $order);

                foreach ($cart->items as $item) {
                    $p = $item->product;

                    $order->items()->create([
                        'product_id' => $p?->id,
                        'product_variant_id' => $item->product_variant_id,
                        // Snapshots, so the order still reads correctly if the
                        // product is later renamed or removed.
                        'name' => $p?->name ?? 'Item',
                        'brand' => $p?->brand?->name,
                        'sku' => $item->variant?->sku ?? $p?->sku,
                        'variant_attributes' => $item->variant?->attributeValues->pluck('name')->all(),
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'subtotal' => $item->lineTotal(),
                        'total' => $item->lineTotal(),
                    ]);
                }

                // Spend the coupon, inside the transaction that wrote the order.
                //
                // This is what makes usage_limit and usage_limit_per_user mean
                // anything: both are checked by CouponService::validate() against
                // state that, until this call existed, nothing ever wrote. It also
                // re-checks the limits against a locked coupon row, so two shoppers
                // holding the last use of a code cannot both spend it — see
                // CouponService::recordRedemption().
                //
                // CouponExhausted from in here propagates out of DB::transaction(),
                // which rolls the order back. That is the intended outcome: if the
                // code ran out while this shopper was on the checkout page, they
                // get the refusal rather than the discount.
                if ($cart->coupon) {
                    $this->coupons->recordRedemption(
                        $cart->coupon,
                        (int) $totals['discount'],
                        $order->id,
                        $customer->id,
                        $order->email,
                    );
                }

                $cart->forceFill(['status' => 'converted', 'converted_at' => now()])->save();

                return $order;
            });
        } catch (\App\Services\StockUnavailable $e) {
            /*
             * Something in the bag sold out while this shopper was on the
             * checkout page — or the last unit went to someone who pressed
             * Place order a moment sooner. The transaction rolled back, so
             * there is no order, no redemption and nothing off the shelf; the
             * basket is exactly as they left it.
             *
             * The message names the product and says what to do about it, and
             * it lands above the form with everything they typed still in the
             * fields. That is the promise this change makes: the customer is
             * told, in words, before any money moves.
             */
            return $this->refused($request, $e->getMessage());
        } catch (\App\Services\CouponExhausted $e) {
            // The code ran out between this shopper applying it and pressing
            // Place Order — someone else took the last use, or this is their
            // own second go at a one-per-customer code. The transaction rolled
            // back, so there is no order and no redemption; the basket is still
            // theirs to buy at full price.
            return $this->refused($request, $e->getMessage());
        }

        // Marks this browser session as the one that actually just placed
        // this order. The success page has no ownership check on ?order=
        // at all — anyone who knows or guesses an order number can view its
        // details — which is a pre-existing gap this does not fix. What it
        // does fix: Marketing Pixels' Purchase event only fires when this
        // marker matches, so a guessed order number cannot fire (and
        // permanently consume, via pixels_fired_at) a Purchase event that
        // was never this visitor's sale to begin with — which would also
        // have silently broken the real customer's own conversion tracking
        // if they revisited their own success page a moment later.
        session(['kbb_last_order' => $order->order_number]);

        /*
         * ------------------------------------------- "save this card for later"
         *
         * THE TICK IS A REQUEST. THIS IS THE DECISION, and it is made here
         * rather than in the card form because the form cannot be the one that
         * makes it: a hidden checkbox still posts, and the box arrives with
         * whatever value a browser — or something that is not a browser — chose
         * to send.
         *
         * A card may only be kept for somebody who can come back and be
         * recognised, which means an account they can sign into. There are
         * exactly two such people at this point in the request:
         *
         *   - a signed-in customer, and
         *   - a guest who has just created an account HERE, in this request,
         *     which is what $accountCreated records. It is set only on the
         *     branch that actually wrote a password, which canSetInitialPassword()
         *     refuses when one already exists.
         *
         * THE CASE THAT MAKES THIS A GUARD RATHER THAN A FORMALITY: the
         * transaction above attaches an order to an EXISTING customer row
         * whenever a guest types the email address of one — that is what
         * firstOrCreate does, and it is right for the order history. So "this
         * order has a customer" is not the same question as "this shopper has
         * an account", and answering the first one would let a guest who knows
         * somebody's email address attach a card to their account, for them to
         * be offered at a later checkout. $accountCreated cannot be true in
         * that case, because a customer with a password is exactly the one
         * canSetInitialPassword() declines.
         *
         * Everything else about the payment is unchanged: same order, same
         * amount, same intent, same confirmation in the browser.
         */
        $saveCard = $request->boolean('save_card')
            && ($request->user('customer') !== null || $accountCreated);

        // Hand off to the gateway. The order row exists and is `pending`
        // before this runs, so a hosted session that is started and then
        // abandoned leaves a real order to reconcile rather than nothing at
        // all — and the webhook that eventually arrives has something to match
        // its reference against.
        //
        // Deliberately outside the transaction above: this is a network call
        // to a third party, and holding a database transaction open across one
        // is how a slow provider becomes a locked table.
        $start = ($saveCard && $gateway instanceof \App\Services\Payments\Gateways\StripeGateway)
            ? $gateway->startAndSaveCard($order)
            : $gateway->start($order);

        if (! $start->ok()) {
            /*
             * The order stays, marked failed, so the shopper can retry and
             * support can see what happened. $start->message is written for a
             * shopper — gateways never put an API error body in it.
             *
             * THE COUPON USE IS HANDED BACK, AND NOT BY THIS METHOD ANY MORE.
             *
             * The payment never started, so the discount was never given. If
             * the use stayed spent, a shopper whose card was declined would
             * have burned their one go at WELCOME10 on a sale that did not
             * happen — and their retry, which is the whole reason the failed
             * order is kept, would be refused by the limit they just consumed.
             *
             * This method used to release it here itself, and a long note in
             * this place set out why cancellation and refund could NOT be
             * treated the same way: there was no single place an order's status
             * changed. It moved in OrdersApiController::bulkStatus() through a
             * mass update that bypassed Eloquent entirely, in
             * AdminOrderController, in PaymentRefunder when money actually
             * moved, and in the gateway webhooks. A release hooked to some of
             * those and not the others would have made usage_count disagree
             * with the redemption rows depending on which screen the operator
             * used.
             *
             * That single place now exists — App\Services\Orders\OrderStatus
             * — and every one of those writers goes through it. So this path
             * stops being special: it states the transition, and the funnel
             * decides what a `failed` order owes the shopper, using the same
             * rule a cancellation and a full refund get. There is exactly one
             * answer to "was this code given back", and
             * `coupon_redemptions.released_at` is where it is written down.
             */
            app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                'failed',
                by: 'system',
                reason: 'The payment could not be started.',
            );

            return $this->refused(
                $request,
                $start->message ?? 'We could not start that payment. Please try another method.'
            );
        }

        /*
         * The order emails: the customer's receipt, and the alert to the store.
         *
         * PLACED HERE AND NOWHERE ELSE, for three reasons worth stating:
         *
         *   - AFTER the transaction. Inside it, a refused SMTP relay or a
         *     twenty-second connect timeout would roll the order back — the
         *     order would be gone and the shopper would see a 500 for an email
         *     nobody needed.
         *   - AFTER $start->ok(). Before it, a declined gateway would have
         *     receipted an order that is about to be marked `failed`.
         *   - BEFORE both returns, so the hosted-redirect path gets the same
         *     receipt as the on-site one.
         *
         * It cannot throw. App\Services\Mail\OrderMailer catches every transport
         * failure, logs it with the order number and returns — its header sets
         * out why a missing email is a support question and a failed checkout is
         * an outage. Nothing is queued; there is no worker on this host.
         */
        app(\App\Services\Mail\OrderMailer::class)->placed($order);

        if ($start->redirectUrl !== null) {
            // Away to the provider's hosted page. Not Url::redirect(), which
            // prefixes our own base path — this is an absolute URL on somebody
            // else's domain.
            return $request->expectsJson()
                ? response()->json(['ok' => true, 'action' => 'redirect', 'url' => $start->redirectUrl])
                : redirect()->away($start->redirectUrl);
        }

        /*
         * ------------------------------------------ the card fields on this page
         *
         * The gateway has an intent open and wants the browser to finish it.
         * Nothing here has taken any money and the order is still `pending`;
         * what goes back is the handle for this one intent and the address to
         * come back to.
         *
         * THE HANDLE ONLY REACHES THE BROWSER THAT PLACED THE ORDER. It is
         * minted inside this request, in the response to the POST that created
         * the order, and is never readable afterwards — there is no endpoint
         * that will hand out an order's client secret. That matters because a
         * client secret is not merely a token to confirm with: its holder can
         * also read that intent's amount and status.
         */
        if ($start->clientSecret !== null) {
            /*
             * NO JAVASCRIPT, NO CARD, AND THEREFORE NO ORDER.
             *
             * A shopper with scripting off sees no card fields — they are
             * Stripe's iframes and Stripe.js mounts them — so they cannot have
             * entered a card, and this is an ordinary form POST rather than
             * the fetch() the page makes. Falling through to the success
             * redirect below would show "thank you for your order" for an
             * order nobody has paid for and nobody can pay for.
             *
             * The order is failed the same way a refused gateway fails it,
             * which hands the stock and the coupon back, and the shopper is
             * told what to do instead.
             */
            if (! $request->expectsJson()) {
                app(\App\Services\Orders\OrderStatus::class)->moveTo(
                    $order,
                    'failed',
                    by: 'system',
                    reason: 'The card form could not be completed in this browser.',
                );

                return back()->withInput()->withErrors(
                    'Paying by card needs JavaScript switched on in your browser. '
                    . 'Please turn it on and try again, or choose another payment method.'
                );
            }

            return response()->json([
                'ok' => true,
                'action' => 'confirm',
                'client_secret' => $start->clientSecret,
                'order' => $order->order_number,
                /*
                 * Where the ISSUER sends the shopper back to, on the minority
                 * of cards whose 3-D Secure step is a full-page redirect
                 * rather than the modal Stripe runs over this page. Stripe
                 * appends its own query parameters to it; the success page
                 * reads `order` and ignores the rest.
                 */
                'return_url' => url(Url::redirect('/checkout/success')) . '?order=' . urlencode((string) $order->order_number),
                'success_url' => Url::redirect('/checkout/success') . '?order=' . urlencode((string) $order->order_number),
            ]);
        }

        return $request->expectsJson()
            ? response()->json([
                'ok' => true,
                'action' => 'placed',
                'order' => $order->order_number,
                'success_url' => Url::redirect('/checkout/success') . '?order=' . urlencode((string) $order->order_number),
            ])
            : redirect(Url::redirect('/checkout/success') . '?order=' . $order->order_number);
    }

    /**
     * A refusal, said the same way through both doors.
     *
     * The form POST keeps what it always did: back to the checkout with the
     * fields repopulated and the message above them. The fetch() from the card
     * form gets the same sentence as JSON with a 422, which is what the page
     * prints next to the card fields.
     *
     * 422 and not 400: this is a request that was understood and refused on
     * its content, and it is the status Laravel's own validator returns for
     * the same class of thing, so the browser half has one code to check.
     */
    private function refused(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => $message], 422);
        }

        return back()->withInput()->withErrors($message);
    }

    /* ------------------------------------------- the card form's two reports */

    /**
     * The card form says the payment went through.
     *
     * The browser calls this the instant Stripe.js reports `succeeded`, so the
     * order-received page it then opens shows an order that is actually paid
     * rather than one that is still `pending` until a webhook lands. Nothing
     * the browser claims is believed: the amount, the currency and the status
     * are read back from Stripe server-to-server, and the whole thing goes
     * through PaymentConfirmer, so this and the webhook can both arrive and
     * only one of them applies.
     *
     * It is not the authority and must never become it. If this call is lost —
     * a closed tab, a dropped connection, a shopper who navigates away the
     * moment their bank approves — the webhook still marks the order paid.
     * That is the arrangement that makes card fields on our own page no worse
     * than the redirect they replaced.
     */
    public function cardConfirmed(Request $request): JsonResponse
    {
        $order = $this->orderThisSessionPlaced($request);

        if ($order === null) {
            // The same answer for a wrong order number and for one that was
            // never this browser's, so this cannot be used to find out which
            // order numbers exist. It is the rule success() already follows.
            return response()->json(['ok' => false], 404);
        }

        $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe');

        if (! $gateway instanceof \App\Services\Payments\Gateways\StripeGateway) {
            return response()->json(['ok' => false], 404);
        }

        $outcome = $gateway->confirmFromBrowser($order);

        /*
         * `ok` here means "this browser may go to the order-received page",
         * which is true whenever the payment is not in doubt — applied now, or
         * applied already by the webhook that beat us to it. It is NOT a
         * report of what happened to the order, and it deliberately says
         * nothing about the order's status: the page has no decision left to
         * make and a shopper cannot act on the difference.
         */
        return response()->json(['ok' => $outcome->accepted], $outcome->accepted ? 200 : 202);
    }

    /**
     * The shopper gave up on the card and wants their basket back.
     *
     * Reached from the "Return to your basket" control that appears beside a
     * declined card, and from nowhere else — there is no timer and no
     * unload handler doing this silently, because the events that look like
     * abandonment (a 3-D Secure window, a tab switch, a slow bank) are exactly
     * the events that also look like a payment in progress.
     *
     * Three things happen, and the order matters:
     *
     *  1. The intent is CANCELLED AT STRIPE FIRST, and everything else is
     *     conditional on that succeeding. An intent left confirmable is one a
     *     stale tab can still put money through; doing that after step 2 has
     *     handed this order's units back to the shelf is how a paying customer
     *     ends up with no product.
     *  2. The order is failed through OrderStatus, which is what returns the
     *     stock and releases the coupon use. Same call, same funnel and the
     *     same consequences as a gateway that refused the payment outright —
     *     see the `$start->ok()` branch in place().
     *  3. The cart goes back to `active`, which is the whole point. The
     *     shopper still holds its cookie and CartService::resolve() only ever
     *     finds an active cart, so this is the single field that decides
     *     whether their basket exists. Without it a declined card empties the
     *     bag, which is the thing that would make an on-site card form worse
     *     than the redirect for anybody whose first card does not work.
     */
    public function cardAbandoned(Request $request): JsonResponse
    {
        $order = $this->orderThisSessionPlaced($request);

        if ($order === null) {
            return response()->json(['ok' => false], 404);
        }

        // A paid order is not abandonable. Whatever the browser thinks it saw,
        // money that has moved is a refund and a decision for the merchant.
        if ($order->paid_at !== null) {
            return response()->json(['ok' => false, 'error' => 'That payment has already gone through.'], 409);
        }

        $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find('stripe');

        if (! $gateway instanceof \App\Services\Payments\Gateways\StripeGateway) {
            return response()->json(['ok' => false], 404);
        }

        if (! $gateway->abandonIntent($order)) {
            return response()->json([
                'ok' => false,
                'error' => 'We could not cancel that payment. Please refresh the page before trying again.',
            ], 409);
        }

        /*
         * The cart by its own cookie, not by a column on the order — `orders`
         * has never carried a cart id. CartService::resolve() finds a cart by
         * exactly this token and refuses anything that is not `active`, which
         * is why `status` is the single field that decides whether this
         * shopper still has a basket, and why it is the one being put back.
         */
        $token = (string) $request->cookie(CartService::COOKIE);

        $cart = $token === '' ? null : \App\Models\Cart::query()
            ->where('token', $token)
            ->where('status', 'converted')
            ->first();

        DB::transaction(function () use ($order, $cart) {
            app(\App\Services\Orders\OrderStatus::class)->moveTo(
                $order,
                'failed',
                by: 'system',
                /*
                 * True of both callers, which is why it does not say "returned
                 * to their basket". One is the control beside a decline, which
                 * does send them back; the other is a field they corrected
                 * afterwards, which replaces this order in place and leaves
                 * them where they are.
                 */
                reason: 'The card payment was cancelled before it completed; the basket was restored.',
                only: ['paid_at' => null],
            );

            $cart?->forceFill(['status' => 'active', 'converted_at' => null, 'last_activity_at' => now()])->save();
        });

        $request->session()->forget('kbb_last_order');

        return response()->json(['ok' => true, 'url' => Url::to('/checkout/')]);
    }

    /**
     * The order this browser placed a moment ago, or null.
     *
     * `kbb_last_order` is the marker place() writes into the session, and it
     * is the same one Marketing Pixels' Purchase event is gated on — the
     * comment beside it in place() sets out why a query-string order number is
     * not on its own evidence of anything. Both endpoints above act on an
     * order, so both take the marker and nothing else: a posted order number
     * that does not match it is simply not found.
     *
     * The gateway is checked here too. Neither endpoint means anything for an
     * order paid another way, and an order number is not a secret.
     */
    private function orderThisSessionPlaced(Request $request): ?Order
    {
        $number = trim((string) $request->input('order'));
        $mine = trim((string) $request->session()->get('kbb_last_order'));

        if ($number === '' || $mine === '' || ! hash_equals($mine, $number)) {
            return null;
        }

        $order = Order::where('order_number', $number)
            ->where('payment_method', 'stripe')
            ->first();

        return $order;
    }

    /**
     * The order-received page.
     *
     * ACCESS. What stood here was `where('order_number', $request->query('order'))`
     * and nothing else: any order number typed into the query string rendered
     * that order, and the numbers are sequential (see nextOrderNumber()). The
     * comment in place() records that gap. It was survivable while the page
     * showed four lines of nothing very private; it is not survivable now that
     * the page carries the line items, the delivery address and the gift
     * message, so the page is gated rather than widened.
     *
     * Two ways in, both of which the visitor already has by other means:
     *
     *   - a signed-in customer looking at their own order;
     *   - the browser that actually placed it, which is remembered in the
     *     session by rememberViewable() below.
     *
     * Anything else gets exactly what a wholly made-up order number gets — the
     * "we could not find that order" panel — so the page cannot be used to
     * probe which order numbers exist.
     */
    public function success(Request $request): View
    {
        $number = trim((string) $request->query('order', ''));

        $order = $number === '' ? null : Order::with([
            // Eager-loaded because Marketing Pixels' Purchase event reads every
            // line item; without this it lazy-loads them on every visit instead.
            // The product behind each line is loaded for its image only — the
            // name, price and quantity are snapshots on the line itself, which
            // is why a deleted product still renders (product_id is nullable
            // and Product soft-deletes, so `product` is simply null here).
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->select(['id', 'slug', 'name', 'image', 'brand_id']),
            'items.product.brand:id,name',
        ])->where('order_number', $number)->first();

        if ($order !== null && ! $this->mayView($request, $order)) {
            $order = null;
        }

        /*
         * WHEN THE PARCEL ARRIVES, not just what the rate is called.
         *
         * The confirmation showed `Delivery: Free delivery` — the rate name and
         * nothing about timing, on the one screen a shopper reads immediately
         * after paying and the one question they have at that moment.
         *
         * Nothing is invented for it. This is the SAME recorded line the
         * checkout has been showing under Place order all along, asked of the
         * same deliveryText(), for the country this parcel is actually going
         * to — so a Gulf order gets no window rather than the UAE's, exactly as
         * at checkout, and the owner's `delivery_texts` row for a country wins
         * on both screens at once.
         */
        $country = (string) ($order?->shipping_address['country'] ?? '');

        return view('store.checkout-success', [
            'order' => $order,
            'settings' => $this->settings,
            'deliveryText' => $country === '' ? '' : $this->deliveryText($country),
        ]);
    }

    /**
     * Finish a guest account: set a password on the customer row the order
     * already created.
     *
     * The rule about WHICH rows may be given a password is not restated here.
     * It is canSetInitialPassword(), the same method place() asks, because two
     * copies of "never overwrite an existing password" is one copy too many.
     *
     * The answer is identical whether a password was written or not. Telling
     * the visitor that an account already exists for the address would be an
     * account enumeration oracle, exactly as it would be at checkout, and the
     * sentence they get back is true either way: they can sign in with that
     * email address. For the same reason nobody is logged in here — a session
     * that appeared only on success would say just as much as a message.
     */
    public function claimAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'string', 'max:64'],
            // Same bounds as the checkout field, min:8 included, so the two
            // ways into an account cannot disagree about what a password is.
            'account_password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $order = Order::where('order_number', trim($data['order']))->first();

        // Same gate as the page itself: without it this would be a way to set
        // a password on any customer whose order number you could guess.
        if ($order === null || ! $this->mayView($request, $order)) {
            abort(404);
        }

        $back = redirect(Url::redirect('/checkout/success') . '?order=' . $order->order_number)
            ->with('kbb_account_done', '1');

        if ($request->user('customer') || $order->customer_id === null) {
            return $back;
        }

        $customer = Customer::find($order->customer_id);

        if ($customer !== null && self::canSetInitialPassword($customer)) {
            // `password` is cast `hashed`, so assigning the plain value hashes it.
            $customer->forceFill(['password' => $data['account_password']])->save();
        }

        return $back;
    }

    /**
     * May this request see this order?
     *
     * @see success() for why the page is gated at all.
     */
    private function mayView(Request $request, Order $order): bool
    {
        $customer = $request->user('customer');

        if ($customer !== null && $order->customer_id !== null
            && (int) $order->customer_id === (int) $customer->id) {
            return true;
        }

        // place() writes kbb_last_order, but the view CONSUMES it — the
        // Purchase pixel must fire exactly once — so it cannot itself be what
        // grants access on a reload. Seeing it once is converted here into a
        // durable grant that survives the reload, the browser Back button and
        // the round trip through a hosted payment page.
        if ((string) $request->session()->get('kbb_last_order', '') === (string) $order->order_number) {
            $this->rememberViewable($request, (string) $order->order_number);

            return true;
        }

        return in_array((string) $order->order_number, $this->viewable($request), true);
    }

    /** @return list<string> */
    private function viewable(Request $request): array
    {
        $seen = $request->session()->get(self::VIEWABLE_KEY, []);

        return is_array($seen) ? array_values(array_map('strval', $seen)) : [];
    }

    private function rememberViewable(Request $request, string $number): void
    {
        $seen = $this->viewable($request);

        if (in_array($number, $seen, true)) {
            return;
        }

        $seen[] = $number;

        // Bounded: a session is a cookie on this host, and an unbounded list
        // of order numbers in it would grow until the cookie stopped fitting.
        // Ten is more orders than one browser session plausibly places.
        $request->session()->put(self::VIEWABLE_KEY, array_slice($seen, -10));
    }

    /**
     * Whether a password may be written onto this customer row. The single
     * expression of the rule; place() and claimAccount() both ask it.
     *
     * It only ever fills a blank and never replaces one, and legacy_password
     * counts as set: those 3,712 imported customers have a real WordPress
     * password waiting to be upgraded on first login and must not be trampled.
     * Without the rule, typing a stranger's email at checkout would overwrite
     * their password and hand over their account.
     */
    public static function canSetInitialPassword(Customer $customer): bool
    {
        return $customer->password === null && $customer->legacy_password === null;
    }

    /* ------------------------------------------------------------ helpers */

    private function loadCart(Request $request)
    {
        $cart = $this->carts->current($request, create: false);

        $cart?->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->select(self::LINE_COLUMNS),
            'items.product.brand:id,name,slug',
            'items.variant:id,product_id,sku,price,sale_price,image,stock_status',
            'items.variant.attributeValues:id,attribute_id,name',
            'coupon:id,code,type,amount',
        ]);

        // The mini-cart panel the layout renders needs a subset of exactly
        // this, and used to fetch its own copy — three more queries for rows
        // already in memory. See CartService::$displayLoaded.
        if ($cart !== null) {
            $this->carts->markDisplayLoaded();
        }

        return $cart;
    }

    private function netSubtotal($cart): int
    {
        return max(0, (int) $cart->items->sum(fn ($i) => $i->lineTotal()));
    }

    /** First token is the first name, the remainder is the surname. */
    private function splitName(string $full, ?string $last): array
    {
        if ($last !== null && $last !== '') {
            return [$full, $last];
        }

        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $first = array_shift($parts) ?? '';

        return [$first, implode(' ', $parts)];
    }

    /**
     * What the checkout puts in the boxes for a shopper who is signed in.
     *
     * ── THE RULE, WHICH IS ONE RULE ─────────────────────────────────────────
     *
     * AN EMPTY BOX BEATS A WRONG GUESS. Every value here is something the
     * account actually holds; nothing is derived, inferred or defaulted, and a
     * field with nothing behind it comes back null so the box renders empty and
     * the shopper types what they were always going to type. The cost of the
     * other choice is not a wasted keystroke — it is an order delivered to an
     * address nobody read, because a box that is already filled in is a box
     * that gets skipped.
     *
     * Two values are taken from the saved address when the account record has
     * nothing, and only those two: the phone number and the name. Both are the
     * shopper's OWN details either way — `addresses.phone` and
     * `addresses.first_name` are what they typed the last time they gave this
     * shop an address — so this is reading the same fact from the other place
     * it is written down, not inventing one.
     *
     * ── WHAT IS DELIBERATELY NOT HERE ───────────────────────────────────────
     *
     * displayName() falls back to the EMAIL ADDRESS when a customer has no
     * name, which is right for "who is this" on an order screen and quite wrong
     * for a box labelled "Full name": an imported customer with no name would
     * have found buyer@example.com sitting in it, and a shopper who did not
     * look would have had it printed on the parcel. name() below stops at the
     * name.
     *
     * Nothing is filled in for a guest. $customer is null and every value is
     * null with it — there is nothing this shop knows about them to fill in,
     * and the browser's own autofill is better at this than we are.
     *
     * NOTHING HERE OVERRIDES THE SHOPPER. Every field in the template reads
     * old() first, so a rejected submission comes back as it was typed rather
     * than as the account still reads. And a change made after a card is
     * declined is not this method's business at all: that path never re-renders
     * the page — it releases the order and places a fresh one from the fields
     * as they now stand, which is what partials/checkout/stripe-elements is
     * doing when it posts to /checkout/card/abandon on a `change`.
     *
     * @return array<string, string|null>
     */
    private function prefill(?Customer $customer, ?\App\Models\Address $address): array
    {
        if ($customer === null) {
            return [];
        }

        $value = static function (?string ...$candidates): ?string {
            foreach ($candidates as $candidate) {
                $candidate = trim((string) $candidate);

                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return null;
        };

        // The account's own name, never the email standing in for one.
        $name = $value(
            $customer->name,
            trim($customer->first_name . ' ' . $customer->last_name),
            trim($address?->first_name . ' ' . $address?->last_name),
        );

        return [
            'email' => $value($customer->email),
            'phone' => $value($customer->phone, $address?->phone),
            'name' => $name,
            'first_name' => $value($customer->first_name, $address?->first_name),
            'last_name' => $value($customer->last_name, $address?->last_name),
            'line1' => $value($address?->line1),
            'city' => $value($address?->city),
            'state' => $value($address?->state),
            'country' => $value($address?->country),
        ];
    }

    /**
     * The payment options this basket may use.
     *
     * The array shape is unchanged — id / title / description / fee_html /
     * fee_fils, exactly what store.checkout has always iterated — but the list
     * is now built by GatewayRegistry rather than assembled from provider rows
     * here. That moves three things out of this method that never belonged to
     * it: whether a gateway's credentials are present, what its fee is, and
     * what its description says. Each gateway answers for itself, so adding
     * Stripe did not mean adding another `$p->id === 'stripe'` arm to a chain
     * of them.
     *
     * Payment & Shipping Rules still decides the COD window. It is called from
     * CashOnDelivery::availableFor(), which is the same PayShipRules instance
     * the other three call sites use — one rule, one definition.
     */
    private function gateways(int $totalFils = 0, ?string $country = null): array
    {
        $list = app(\App\Services\Payments\GatewayRegistry::class)
            ->checkoutList($totalFils, $country);

        if ($list !== []) {
            return $list;
        }

        $cod = (int) $this->settings->get('cod_fee', 0);
        $codAllowed = app(\App\Services\PayShipRules::class)->codAllowed($totalFils);

        // A store with nothing configured at all would render an empty
        // payment section; COD is the safe floor for that case. But once a
        // cod row exists — once the merchant has an explicit answer, on or
        // off, from Store → Ecommerce → Checkout — that answer is respected
        // even when every other gateway also happens to be off. Otherwise
        // switching Cash on Delivery off would silently do nothing whenever
        // no other gateway was enabled either, which defeats the switch.
        $codRowExists = PaymentProvider::whereKey('cod')->exists();

        return (! $codRowExists && $codAllowed)
            ? [[
                'id' => 'cod', 'title' => 'Cash on delivery',
                'description' => $cod > 0 ? 'Pay in cash to the courier. A small ' . \App\Support\Money::format($cod) . ' handling fee applies.' : null,
                // LTR-isolated on an Arabic page, as every other fee_html is -- a
                // leading '+' otherwise reorders to the trailing side. See App\Support\Bidi.
                'fee_html' => $cod > 0 ? \App\Support\Bidi::number('+' . \App\Support\Money::format($cod)) : null,
                'fee_fils' => $cod,
            ]]
            : [];
    }

    /**
     * The checkout's country list: every zone country — the Gulf set in
     * production — always, plus whatever Extended has switched on, if
     * anything. Extended adds to this list; it never removes from it, and a
     * shop that has never opened the Extended tab sees exactly the zone
     * countries it always has.
     */
    /**
     * Rates, the chosen one, and totals for a destination — the same three
     * things page() has always returned, factored out so the AJAX rate
     * refresh (added for the country selector) uses the exact calculation the
     * page itself uses, rather than a second copy that could drift from it.
     *
     * @return array{0: array, 1: ?string, 2: array}
     */
    /**
     * Gift wrapping on or off, then fresh totals.
     *
     * The choice is kept in the session rather than posted with every
     * subsequent request, because two other paths recompute these totals --
     * the country-change refresh and an ordinary reload -- and neither sends
     * the checkbox. Holding it server-side means all three agree instead of
     * the fee disappearing the moment someone changes emirate.
     */
    public function gift(Request $request): JsonResponse
    {
        $request->validate(['is_gift' => ['nullable', 'boolean']]);

        if (! $this->settings->get('gift_enabled', '1')) {
            return response()->json(['ok' => false, 'error' => 'Gift wrapping is not available.'], 422);
        }

        $on = $request->boolean('is_gift');
        $on ? $request->session()->put('kbb_gift', true) : $request->session()->forget('kbb_gift');

        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Your bag is empty.'], 422);
        }

        $country = (string) ($request->input('country') ?: 'AE');
        [, , $totals] = $this->rateContext($cart, $country, $request->input('state'));

        $gift = $on ? (int) $this->settings->get('gift_fee', '1500') : 0;
        $cod = (int) $this->settings->get('cod_fee', 0);

        /*
         * THE SAME LEDGER WIDTH THE RENDERED SUMMARY USED — Lane FA.
         *
         * checkout.js assigns these strings straight into the rows the Blade
         * printed, so a width decided differently here would leave one column
         * mixing two precisions the moment a shopper ticked the gift box.
         * partials/checkout/order-block.blade.php makes the identical call.
         */
        $dp = $this->carts->ledgerDecimals($totals, $cod, $gift);

        return response()->json([
            'ok' => true,
            'on' => $on,
            'giftFee' => \App\Support\Money::format($gift, $dp),
            'total' => \App\Support\Money::format((int) $totals['total'] + $gift, $dp),
            // Always sent, never null. `.js-total-row-fee` is the row the CSS
            // puts on screen whenever Cash on delivery is selected, whatever
            // the fee is (see order-block.blade.php), so a null here left the
            // ONLY visible total stale the moment a COD shopper ticked the
            // gift box on a shop with no COD surcharge.
            'totalWithFee' => \App\Support\Money::format((int) $totals['total'] + $cod + $gift, $dp),
        ]);
    }

    /**
     * The width this request's checkout ledger prints at — Lane FA.
     *
     * A thin wrapper over CartService::ledgerDecimals() that supplies the two
     * fees this controller adds on top of totals(), so every JSON figure the
     * country-change refresh returns lands at the same precision as the rows
     * the Blade printed. See CartService::totals()' `decimals` key.
     */
    private function ledgerDp(array $totals, Request $request): int
    {
        return $this->carts->ledgerDecimals(
            $totals,
            (int) $this->settings->get('cod_fee', 0),
            $this->giftFee($request),
        );
    }

    /** Gift fee in fils for this request, or zero. Settings are the price. */
    private function giftFee(Request $request): int
    {
        return ($request->session()->get('kbb_gift') && $this->settings->get('gift_enabled', '1'))
            ? (int) $this->settings->get('gift_fee', '1500')
            : 0;
    }

    private function rateContext($cart, ?string $country, ?string $state): array
    {
        $rates = $this->shipping->ratesFor($country, $state,
            $this->netSubtotal($cart), (bool) $this->settings->get('hide_paid_when_free', true));

        $chosen = $rates[0]['id'] ?? null;
        $shippingCost = $rates ? (int) $rates[0]['cost'] : 0;

        $totals = $this->carts->totals($cart, $country, $state, $shippingCost);

        return [$rates, $chosen, $totals];
    }

    /**
     * The country selector now actually changes, so the delivery options and
     * totals it drives have to follow — this is what checkout.js calls on
     * change. Only ever asked for a country already in the shopper's own
     * dropdown, so there is no unserved-country case to handle here; that is
     * decided once, server-side, when the list itself is built.
     */
    public function rates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:60'],
        ]);

        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Your bag is empty.'], 422);
        }

        $country = strtoupper($data['country']);

        if (! isset($this->countries()[$country])) {
            return response()->json(['ok' => false, 'error' => 'We do not deliver there yet.'], 422);
        }

        /*
         * THE ONE PLACE A SHOPPER'S CHOICE IS RECORDED. This endpoint is what
         * the country selector calls, so reaching it means a person picked a
         * country — the only signal in the application that is a statement
         * rather than a guess. Remembering it here is what makes the rest of
         * the shop agree with the checkout: change the country to Saudi Arabia
         * and the home page stops promising UAE delivery too, on the next page
         * they load, instead of the two screens contradicting each other.
         *
         * Only a country that passed the check above is remembered, so the
         * session can never hold somewhere this shop does not deliver to.
         */
        \App\Support\ShopperCountry::remember($request, $country);

        [$rates, $chosen, $totals] = $this->rateContext($cart, $country, $data['state'] ?? null);

        return response()->json([
            'ok' => true,
            /*
             * THE ARRIVAL ESTIMATE MOVES WITH THE COUNTRY TOO.
             *
             * This rendered the partial without $deliveryEta, and the partial's
             * own header recorded the assumption behind that — "absent when
             * called from the AJAX endpoint for a zone country, which has no
             * estimate concept". The endpoint is not only asked about zone
             * countries: it answers for every country in the shopper's own
             * dropdown, Extended ones included. So a shopper who arrived on a
             * country with an estimate and changed to another watched "Arrives
             * in 5-7 days" disappear and never return, however many times they
             * changed it back.
             *
             * Exactly the defect `deliveryText` below was added to this response
             * for, one field along. Asked of deliveryEta(), the same method the
             * page itself uses, so the two cannot answer differently.
             */
            'deliveryHtml' => view('partials.checkout.delivery-options', [
                'rates' => $rates,
                'chosenRate' => $chosen,
                'deliveryEta' => $this->deliveryEta($country),
            ])->render(),
            // The same partial the page renders, fed the freshly recomputed
            // totals — the free-shipping bar's threshold, percentage and
            // "unlocked" state are all per-country (Extended can set its own,
            // a zone has its own), so this has to move with the delivery
            // charge rather than being left showing whichever country the
            // page happened to load with.
            'freeshipHtml' => view('partials.checkout.freeship-bar', [
                'settings' => $this->settings,
                'totals' => $totals,
            ])->render(),
            // The promise under Place order belongs to the destination too, and
            // it is the one region of the order block this endpoint does not
            // re-render. Left alone, switching from the UAE to Saudi Arabia
            // updated the charge to AED 150 and left "1–3 days fast delivery
            // all over UAE" sitting under it — which is the whole defect
            // deliveryText() was just repaired for, re-entering through the
            // one door that does not go past it. An empty string is a real
            // answer here and means "say nothing", so it is sent as a string
            // and never withheld.
            'deliveryText' => $this->deliveryText($country),
            /*
             * ONE WIDTH FOR THE WHOLE REFRESHED COLUMN — Lane FA. Same call as
             * partials/checkout/order-block.blade.php makes when it renders
             * these rows, so a country change cannot leave the ledger printing
             * two precisions at once. See CartService::totals()' `decimals`.
             */
            'subtotal' => \App\Support\Money::format((int) $totals['subtotal'], $this->ledgerDp($totals, $request)),
            'shipping' => $totals['shipping'] > 0
                ? \App\Support\Money::format((int) $totals['shipping'], $this->ledgerDp($totals, $request))
                : '<span style="color:var(--green);font-weight:700">Free</span>',
            'total' => \App\Support\Money::format((int) $totals['total'] + $this->giftFee($request), $this->ledgerDp($totals, $request)),
            // The COD-fee-inclusive total, kept in step with the country so
            // it is never wrong after switching country while Cash on
            // delivery happens to be selected. The fee itself is flat and
            // never changes; only the total under it does.
            //
            // Sent whether or not there IS a fee. `.js-total-row-fee` is what
            // the stylesheet shows while Cash on delivery is selected, however
            // small the surcharge, so withholding this number when the fee is
            // zero left the shopper's only visible total showing the previous
            // country's figure.
            'totalWithFee' => (function () use ($totals, $request) {
                $fee = (int) $this->settings->get('cod_fee', 0);

                return \App\Support\Money::format((int) $totals['total'] + $fee + $this->giftFee($request), $this->ledgerDp($totals, $request));
            })(),
            // `added` is the third thing the refresh needs and the newest: on
            // an exclusive basis the tax row belongs ABOVE the Total, where it
            // is part of the sum, and on every other basis below it as an "of
            // which" note. Switching from an inclusive country to an exclusive
            // one has to move the line, not only rewrite its figure.
            'vat' => $totals['vat'] ? [
                'label' => $totals['vat']['label'],
                'formatted' => $totals['vat']['formatted'],
                'added' => $totals['vat']['added'],
            ] : null,
        ]);
    }

    /**
     * One tap on Add in the checkout's Browsed tab.
     *
     * Silent by design: no drawer, no jump back to Order summary. The page
     * stays exactly where it is and the regions the new line actually changes
     * are re-rendered HERE and swapped in, so the browser is never asked to
     * work out a price. Money stays integer fils on this side of the wire.
     *
     * Four regions change on a single add, and all four come out of this one
     * request so they cannot disagree with each other:
     *
     *   1. the Order summary lines and the totals block (which opens with the
     *      free-delivery bar, so that moves with them);
     *   2. the payment options — PayShipRules measures its Cash-on-delivery
     *      window against the order total, so one more product can withdraw
     *      the method the shopper has already selected. Leaving the list
     *      alone would hand them a method place() refuses at the last step;
     *   3. the mobile bag strip — thumbnails, "N items", and its own copy of
     *      the free-delivery bar;
     *   4. the Browsed list itself and its count badge, both rendered from
     *      the same collection so the row leaves the list only because the
     *      server says it is in the bag.
     *
     * The cart drawer and badge are the one thing NOT rendered here: the count
     * comes back with this response for the badge, and the panel body is
     * refreshed through the drawer endpoint that already exists, rather than a
     * second copy of CartController's drawer payload growing in this class.
     */
    public function browsedAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:120'],
            // What the shopper currently has selected. A choice, never an
            // amount — the same rule the rest of this controller follows.
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        $cart = $this->loadCart($request);

        // Nothing is ever removed from the page on a failure; each of these
        // returns a sentence the row can show, in words.
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'Your bag is empty — please start again from the cart.',
            ], 422);
        }

        $product = Product::query()->select(self::LINE_COLUMNS)->visible()->find($data['product_id']);

        if (! $product) {
            return response()->json(['ok' => false, 'error' => 'That product is no longer available.'], 404);
        }

        if ($product->stock_status !== 'instock') {
            return response()->json(['ok' => false, 'error' => 'That product is sold out.'], 422);
        }

        // Adding the same product again increments the line rather than
        // duplicating it — CartService::add() matches on product and variant
        // and reprices the whole line, because a bundle rate depends on the
        // final quantity.
        $this->carts->add($cart, $product, 1);

        $cart = $this->loadCart($request);

        return response()->json(
            ['ok' => true, 'productId' => $product->id] + $this->fragments($request, $cart, $data)
        );
    }

    /**
     * A quantity change, or a removal, made from the checkout's order summary.
     *
     * The controls were already live and already correct; what they did with
     * the answer was `window.location.reload()`. The cart endpoints in
     * CartController return the DRAWER and the cart page, neither of which is
     * on screen here, so there was nothing this page could swap in and a full
     * navigation was the only way to show the new figures. Reloading throws
     * away every field already typed into the form above, scrolls back to the
     * top and re-runs page()'s country detection over the shopper's own
     * choice — an expensive way to change a number by one.
     *
     * This is the same shape as browsedAdd: one write, then every region that
     * write moves, rendered here and swapped in. Money never crosses the wire
     * as anything but a formatted string the server produced.
     */
    public function lineUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            // 0 removes the line — the ✕ beside the stepper is the same change
            // with a different number, not a second endpoint.
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'Your bag is empty — please start again from the cart.',
            ], 422);
        }

        /*
         * The line has to be one of THIS cart's own.
         *
         * CartService::updateQuantity() already scopes its lookup to the cart,
         * so an id belonging to someone else's basket matches nothing and
         * changes nothing. Saying so is the difference between a stepper that
         * refuses and one that silently reports success for a change that never
         * happened.
         */
        if (! $cart->items->contains('id', (int) $data['item_id'])) {
            return response()->json(['ok' => false, 'error' => 'That item is no longer in your bag.'], 404);
        }

        $this->carts->updateQuantity($cart, (int) $data['item_id'], (int) $data['quantity']);

        $cart = $this->loadCart($request);

        /*
         * Removing the last line is the one change that legitimately leaves
         * this page: page() itself redirects an empty bag to /cart/, so there
         * is no checkout left to repaint. The destination is named here rather
         * than guessed in the browser, so it carries the deployment's base
         * path like every other link.
         */
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'ok' => true,
                'empty' => true,
                'count' => 0,
                'redirect' => Url::to('/cart/'),
            ]);
        }

        return response()->json(
            ['ok' => true, 'empty' => false, 'itemId' => (int) $data['item_id']]
            + $this->fragments($request, $cart, $data)
        );
    }

    /**
     * Applying or removing a coupon from the checkout page, in place.
     *
     * The stepper got its own endpoint because /api/cart/coupon renders the
     * mini-cart and the cart page — neither of which is on screen here — so the
     * only way to show new figures was a full reload, which threw away every
     * field already typed. A coupon moves exactly the regions a quantity change
     * moves (line discounts, totals, the free-delivery bar, and which payment
     * methods the order total still qualifies for), so it shares fragments()
     * with the stepper rather than growing a second copy that can drift.
     *
     * The discount itself is never taken from the request: validate() reads the
     * coupon from the database and checks it against this cart, exactly as the
     * cart page's own endpoint does. The browser sends a code, never an amount.
     */
    public function couponUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:60'],
            'remove' => ['nullable', 'boolean'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['nullable', 'string', 'max:40'],
        ]);

        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'Your bag is empty — please start again from the cart.',
            ], 422);
        }

        if ($request->boolean('remove')) {
            $cart->forceFill(['coupon_id' => null])->save();
            $message = 'Coupon removed';
        } else {
            $result = $this->coupons->validate(
                (string) ($data['code'] ?? ''),
                $cart,
                $request->user('customer')?->email
            );

            // A rejected code leaves the cart exactly as it was. The page is
            // still repainted from the unchanged totals so the shopper sees the
            // error beside figures that are current, not stale.
            if (! $result['ok']) {
                return response()->json(
                    ['ok' => false, 'error' => $result['error']]
                    + $this->fragments($request, $cart, $data)
                );
            }

            $cart->forceFill(['coupon_id' => $result['coupon']->id])->save();
            $message = 'Coupon applied';
        }

        $cart = $this->loadCart($request);

        return response()->json(
            ['ok' => true, 'message' => $message]
            + $this->fragments($request, $cart, $data)
        );
    }

    /**
     * Every region of the checkout that a change to the cart moves, rendered
     * once from one set of totals so they cannot disagree with each other.
     *
     * Shared by the Browsed one-tap add and the summary's quantity steppers:
     * both change what is in the bag, and both change the same four regions.
     * A second copy of this is how the add and the stepper would drift apart.
     *
     * $posted carries the shopper's current payment choice — a choice, never an
     * amount. PayShipRules measures its Cash-on-delivery window against the
     * order total, so a quantity change in either direction can withdraw or
     * restore the method they have selected.
     */
    private function fragments(Request $request, $cart, array $data): array
    {
        // Only a country the shopper's own selector offers. Anything else
        // falls back to the store's country rather than being taken on trust.
        $country = strtoupper((string) ($data['country'] ?? ''));

        if (! isset($this->countries()[$country])) {
            $country = (string) $this->settings->get('store_country', 'AE');
        }

        $state = $data['state'] ?? null;

        [, , $totals] = $this->rateContext($cart, $country, $state);

        $totalFils = (int) ($totals['total'] ?? 0);
        $gateways = $this->gateways($totalFils, $country);
        $codHidden = app(\App\Services\PayShipRules::class)->codHiddenReason($totalFils);

        // Did this change cost them the method they had selected? Said out
        // loud, with what is selected instead — a radio quietly moving under
        // the cursor is the confusion this feature exists to remove.
        $posted = trim((string) ($data['payment_method'] ?? ''));
        $offeredIds = array_column($gateways, 'id');
        $dropped = $posted !== '' && ! in_array($posted, $offeredIds, true);
        $payNotice = null;

        if ($dropped) {
            $payNotice = ($gateways === [])
                ? 'No payment method is available for this order total.'
                : 'Your payment method is no longer available for this order — '
                    . $gateways[0]['title'] . ' is selected instead.';
        }

        $browsed = $this->browsed($request, $cart);

        $view = [
            'settings' => $this->settings,
            'items' => $cart->items,
            'totals' => $totals,
        ];

        return [
            'count' => (int) ($totals['item_count'] ?? 0),
            'itemsHtml' => view('partials.checkout.summary-items', $view)->render(),
            'orderHtml' => view('partials.checkout.order-block', $view + [
                'withActions' => true,
                'deliveryText' => $this->deliveryText($country),
            ])->render(),
            'thumbsHtml' => view('partials.checkout.thumbs', $view)->render(),
            'paymentHtml' => view('partials.checkout.payment-methods', [
                'gateways' => $gateways,
                'codHidden' => $codHidden,
                'selectedMethod' => $dropped ? null : ($posted !== '' ? $posted : null),
                'payNotice' => $payNotice,
            ])->render(),
            'browsedHtml' => view('partials.checkout.browsed-list', ['browsed' => $browsed])->render(),
            'browsedCount' => $browsed->count(),
            // For the optional sticky bar, which carries a .js-total of its own
            // outside every slot above. Formatted here like everything else —
            // including at the ledger's own width, so the bar cannot show a
            // rounded total over a summary that widened (Lane FA).
            'total' => \App\Support\Money::format($totalFils + $this->giftFee($request), $this->ledgerDp($totals, $request)),
            'payNotice' => $payNotice,
        ];
    }

    private function countries(): array
    {
        $zoneCountries = $this->shipping->coveredCountries();

        if ($zoneCountries === []) {
            // No zone is configured at all — a fallback rather than an empty
            // checkout, matching what shipped before Extended existed.
            $zoneCountries = ['AE' => 'United Arab Emirates'];
        }

        $extended = app(\App\Services\ExtendedDelivery::class);

        if (! $extended->enabled()) {
            return $zoneCountries;
        }

        $names = \App\Support\Countries::NAMES;
        $extra = collect($extended->served())
            ->mapWithKeys(fn ($row, $code) => [$code => $names[$code] ?? $code])
            ->all();

        // Zone countries are listed first, in the order the zones were
        // configured; + keeps the left side's entries when a code appears in
        // both, which the admin picker prevents from happening in practice.
        return $zoneCountries + $extra;
    }

    /**
     * The line under Place order, for the country this parcel is going to.
     *
     * Kept as a one-line delegation rather than deleted, because it is the name
     * three call sites in this file use and because the rule it enforces has
     * not changed at all — only its address. The reasoning that used to live
     * here, in full, is now the class doc comment of App\Support\DeliveryLine:
     * the default is a UAE promise, it is offered to the UAE alone, nothing is
     * invented for anywhere else, and an explicit `delivery_texts` row still
     * wins for any country.
     *
     * IT MOVED BECAUSE THE HOME PAGE NEEDED THE SAME ANSWER. That page printed
     * `delivery_default_text` to every visitor on earth with no country check,
     * so the rule was true of the checkout and false of the first page of the
     * shop. A private method on this controller could not be the one rule the
     * whole shop obeys, and two copies of it would have drifted.
     */
    private function deliveryText(string $country): string
    {
        return app(\App\Support\DeliveryLine::class)->for($country);
    }

    /**
     * "Arrives in …" for this destination, or null when there is no estimate.
     *
     * ── A DURATION, NOT A SENTENCE, AND THAT IS WHY THERE ARE TWO OF THESE ──
     *
     * Three separate lanes read `delivery_texts` and `delivery_countries.eta`
     * as one duplicated idea waiting to be merged. They are not, and the two
     * methods sitting here side by side are the clearest statement of it:
     *
     *   deliveryText()  a WHOLE SENTENCE the owner wrote, standing on its own
     *                   under Place order, on the home page, on the product page
     *                   and on the order confirmation. Any country. It may carry
     *                   {country}. It is what the Gulf actually uses.
     *
     *   deliveryEta()   a FRAGMENT of at most 40 characters, printed after the
     *                   fixed words "Arrives in " in the delivery options and
     *                   nowhere else. Only while Extended delivery is on, and
     *                   only for a country NO ZONE COVERS —
     *                   ExtendedDeliveryApiController refuses one that a zone
     *                   already serves, which means the Gulf can never have one.
     *
     * A row reading "Delivered across Saudi Arabia" records a sentence, not a
     * number of days; folding the two together would either print "Arrives in
     * Delivered across Saudi Arabia" or throw the sentence away to keep a
     * duration. ProductController's `cutoff()` header refuses the same
     * conflation for the same reason.
     *
     * ── COSTS NOTHING WHILE EXTENDED IS OFF ────────────────────────────────
     *
     * enabled() is a settings read, and ExtendedDelivery::countries() returns
     * early on it, so `delivery_countries` is not touched at all in the shipped
     * default state. StorefrontQueryBudgetTest's checkout ceiling was measured
     * with Extended off and does not have to move for this.
     *
     * ── AND IT IS A METHOD BECAUSE TWO CALLERS NEED THE SAME ANSWER ────────
     *
     * The page renders it and the rates endpoint re-renders it. The endpoint
     * used to render the delivery options WITHOUT it, so switching country made
     * the arrival estimate vanish and never come back — the identical defect
     * deliveryText() was extracted to fix, entering through the one door that
     * did not go past it.
     */
    private function deliveryEta(string $country): ?string
    {
        $extended = app(\App\Services\ExtendedDelivery::class);

        return $extended->enabled() ? $extended->etaFor($country) : null;
    }

    private function browsed(Request $request, $cart)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->cookie('kbb_viewed', ''))));

        if ($ids === []) {
            return collect();
        }

        $ids = array_slice(array_values(array_diff($ids, $cart->items->pluck('product_id')->all())), 0, 6);

        if ($ids === []) {
            return collect();
        }

        return Product::query()->select(self::LINE_COLUMNS)->visible()
            ->whereIn('id', $ids)->with('brand:id,name,slug')->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();
    }

    /**
     * The next free order number.
     *
     * Kept as a one-line delegation rather than deleted, because it is the
     * name the rest of this file and two comments elsewhere in the application
     * refer to, and because what it used to contain is worth being able to
     * find from here.
     *
     * WHAT IT USED TO BE, and why none of it survived:
     *
     *   $candidate = max(10000 + (int) Order::max('id'),
     *                    (int) Order::max('order_number'));
     *   for ($i = 0; $i < 1000; $i++) { ...first number nothing holds... }
     *
     * Three defects, each of which reached customers:
     *
     *   1. IT RAN INSIDE THE PLACING TRANSACTION. Under REPEATABLE READ the
     *      MAX() came from the transaction's own snapshot, so two simultaneous
     *      checkouts computed the same number and the loser's order died on
     *      the unique index. The thousand-iteration loop could not help: every
     *      iteration re-read the same frozen maximum.
     *
     *   2. IT WAS BLIND TO SOFT-DELETED ORDERS. Both queries went through the
     *      default scope, while the unique index does not. One trashed order
     *      at the top of the range made every checkout in the shop fail on the
     *      same number, with no concurrency needed at all.
     *
     *   3. `(int) Order::max('order_number')` IS A LEXICAL MAXIMUM. The column
     *      is a VARCHAR, so with '9999' and '50002' both present it answers
     *      '9999' and the cast makes that 9999.
     *
     * All three are fixed in App\Services\Orders\OrderNumbers, which allocates
     * from a sequence row by compare-and-swap. The call has moved to before
     * the transaction opens — see place() — which is the part that actually
     * matters, so this method must not be called from inside one.
     */
    private function nextOrderNumber(): string
    {
        return $this->orderNumbers->allocate();
    }
}
