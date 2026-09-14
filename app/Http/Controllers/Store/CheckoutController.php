<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\CartService;
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
    ) {}

    public function page(Request $request): View|RedirectResponse
    {
        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return redirect(Url::redirect('/cart/'));
        }

        $customer = $request->user('customer');
        $address = $customer?->defaultAddress();

        // The country list is always live now — the zone countries (Gulf, in
        // production) plus anything Extended has added — so the selector and
        // detection both apply regardless of whether Extended has ever been
        // opened. Order of precedence: what they chose, then their saved
        // address, then detection, then the store's own country. Detection
        // never overrides a person who has already said where they are.
        $extended = app(\App\Services\ExtendedDelivery::class);
        $countries = $this->countries();
        $detected = $extended->detect($request);

        $country = old('billing_country')
            ?? $address?->country
            ?? (isset($countries[$detected]) ? $detected : null)
            ?? (string) $this->settings->get('store_country', 'AE');

        // Only badge it as detected when nothing else supplied the answer and
        // the guess actually landed on a country this shop delivers to.
        $wasDetected = $detected !== null
            && isset($countries[$detected])
            && old('billing_country') === null
            && $address?->country === null;

        // Detection found somewhere this shop does not deliver to. Said
        // plainly rather than silently falling back to the store's own
        // country and letting the shopper find out at the payment step.
        $unserved = ($detected !== null && ! isset($countries[$detected])
                && old('billing_country') === null && $address?->country === null)
            ? $detected
            : null;

        [$rates, $chosen, $totals] = $this->rateContext($cart, $country, old('billing_state') ?? $address?->state);

        return view('store.checkout', [
            'settings' => $this->settings,
            'cart' => $cart,
            'items' => $cart->items,
            'totals' => $totals,
            'rates' => $rates,
            'chosenRate' => $chosen,
            'gateways' => $this->gateways((int) ($totals['total'] ?? 0)),
            // Set when Payment & Shipping Rules has hidden Cash on delivery, so
            // the page can say why rather than the option simply not being there.
            'codHidden' => app(\App\Services\PayShipRules::class)
                ->codHiddenReason((int) ($totals['total'] ?? 0)),
            'states' => self::EMIRATES,
            'countries' => $countries,
            'defaultCountry' => $country,
            'countryDetected' => $wasDetected,
            'unservedCountry' => $unserved,
            'deliveryEta' => $extended->enabled() ? $extended->etaFor($country) : null,
            'deliveryText' => $this->deliveryText($country),
            'showBrowsed' => (bool) $this->settings->get('show_browsed', true),
            'browsed' => $this->browsed($request, $cart),
            // Off splits the field into first and last name. The backend has
            // always accepted either shape — splitName() auto-splits a single
            // value on spaces, or takes an explicit last name when one is
            // posted — only the form itself was never given the other shape
            // to send. The setting existed and did nothing until now.
            'singleName' => (bool) $this->settings->get('checkout_single_name', true),
            'prefill' => [
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'name' => $customer?->displayName(),
                'first_name' => $customer?->first_name,
                'last_name' => $customer?->last_name,
                'line1' => $address?->line1,
                'city' => $address?->city,
                'state' => $address?->state,
                'country' => $address?->country,
            ],
        ]);
    }

    public function place(Request $request): RedirectResponse
    {
        // The form marks Last name as required only when the single-name
        // field is off (Store → Ecommerce → Checkout → Form fields); the
        // server enforces the same thing it showed, rather than trusting
        // whatever shape was actually posted.
        $lastNameRule = $this->settings->get('checkout_single_name', true) ? 'nullable' : 'required';

        $data = $request->validate([
            'billing_email' => ['required', 'email', 'max:160'],
            // Guest checkout -> account. Both optional: leaving them alone
            // keeps the existing guest flow byte-for-byte unchanged.
            'create_account' => ['nullable', 'boolean'],
            'account_password' => ['nullable', 'required_if:create_account,1', 'string', 'min:8', 'max:72'],
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
        ]);

        $cart = $this->loadCart($request);

        if (! $cart || $cart->items->isEmpty()) {
            return redirect(Url::redirect('/cart/'))->withErrors('Your bag is empty.');
        }

        // The single "Full name" field is split on save, exactly as the theme does.
        [$first, $last] = $this->splitName($data['billing_first_name'], $data['billing_last_name'] ?? null);

        $rates = $this->shipping->ratesFor($data['billing_country'], $data['billing_state'],
            $this->netSubtotal($cart), (bool) $this->settings->get('hide_paid_when_free', true));

        if (! $rates) {
            return back()->withInput()->withErrors('We do not deliver to that country yet.');
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
        if ($data['payment_method'] === 'cod') {
            $reason = app(\App\Services\PayShipRules::class)
                ->codHiddenReason((int) ($totals['total'] ?? 0));

            if ($reason !== null) {
                return back()->withInput()->withErrors($reason);
            }

            if (! collect($this->gateways((int) ($totals['total'] ?? 0)))->contains('id', 'cod')) {
                return back()->withInput()->withErrors('Cash on delivery is not available.');
            }
        }

        // Gift fee is read from settings, never from the request. The form
        // posts whether the shopper wants wrapping; how much that costs is the
        // merchant's to decide, and a posted amount would be a price the
        // browser got to choose.
        $giftFee = ($request->boolean('is_gift') && $this->settings->get('gift_enabled', '1'))
            ? (int) $this->settings->get('gift_fee', '1500')
            : 0;

        $request->session()->forget('kbb_gift');

        $fee = $giftFee + ($data['payment_method'] === 'cod'
            ? (int) $this->settings->get('cod_fee', 0)
            : 0);

        $order = DB::transaction(function () use ($cart, $data, $first, $last, $rate, $totals, $fee, $request) {
            $customer = $request->user('customer') ?? Customer::firstOrCreate(
                ['email' => mb_strtolower($data['billing_email'])],
                ['name' => trim($first . ' ' . $last), 'first_name' => $first, 'last_name' => $last, 'phone' => $data['billing_phone'] ?? null]
            );

            // A guest who asked for an account gets a usable password on the
            // row firstOrCreate already made for them. `password` is cast
            // `hashed`, so assigning the plain value hashes it.
            //
            // The guard matters more than the feature. Without it, typing a
            // stranger's email into checkout would overwrite their password
            // and hand over their account -- so this only ever fills a blank,
            // never replaces one, and legacy_password counts as set: those
            // 3,712 imported customers have a real WordPress password waiting
            // to be upgraded on first login, and must not be trampled.
            //
            // Silent when it declines. Telling the person at checkout that an
            // account already exists for an address they typed is an account
            // enumeration oracle, and the order itself is fine either way.
            if (! $request->user('customer')
                && $request->boolean('create_account')
                && ($data['account_password'] ?? '') !== ''
                && $customer->password === null
                && $customer->legacy_password === null
            ) {
                $customer->forceFill(['password' => $data['account_password']])->save();
            }

            $address = [
                'first_name' => $first, 'last_name' => $last,
                'line1' => $data['billing_address_1'], 'city' => $data['billing_city'],
                'state' => $data['billing_state'], 'country' => $data['billing_country'],
                'phone' => $data['billing_phone'] ?? null,
            ];

            $order = Order::create([
                'order_number' => $this->nextOrderNumber(),
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
                'shipping_total' => (int) $rate['cost'],
                'fee_total' => $fee,
                'tax_total' => 0,   // VAT is display-only (D-64)
                'total' => $totals['total'] + $fee,
                'shipping_method' => $rate['title'],
                'payment_method' => $data['payment_method'],
                'coupon_code' => $totals['coupon_code'],
                'whatsapp_optin' => $request->boolean('billing_kbb_whatsapp'),
                'ip_address' => $request->ip(),
            ]);

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

            $cart->forceFill(['status' => 'converted', 'converted_at' => now()])->save();

            return $order;
        });

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

        return redirect(Url::redirect('/checkout/success') . '?order=' . $order->order_number);
    }

    public function success(Request $request): View
    {
        // Eager-loaded because Marketing Pixels' Purchase event reads every
        // line item; without this it lazy-loads them on every visit instead.
        $order = Order::with('items')->where('order_number', (string) $request->query('order', ''))->first();

        return view('store.checkout-success', ['order' => $order, 'settings' => $this->settings]);
    }

    /* ------------------------------------------------------------ helpers */

    private function loadCart(Request $request)
    {
        return $this->carts->current($request, create: false)?->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->select(self::LINE_COLUMNS),
            'items.product.brand:id,name,slug',
            'items.variant:id,product_id,sku,price,sale_price,image,stock_status',
            'items.variant.attributeValues:id,attribute_id,name',
            'coupon:id,code,type,amount',
        ]);
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

    private function gateways(int $totalFils = 0): array
    {
        $cod = (int) $this->settings->get('cod_fee', 0);

        // Payment & Shipping Rules: Cash on delivery is dropped outside the
        // configured order-value window. Every other gateway is untouched.
        $codAllowed = app(\App\Services\PayShipRules::class)->codAllowed($totalFils);

        $list = PaymentProvider::query()
            ->where('enabled', true)
            ->orderBy('position')
            ->get()
            ->reject(fn ($p) => $p->id === 'cod' && ! $codAllowed)
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'description' => $p->id === 'cod' && $cod > 0
                    ? 'Pay in cash to the courier. A small ' . \App\Support\Money::format($cod) . ' handling fee applies.'
                    : null,
                // Shown right on the option itself, not only in the
                // paragraph beneath it — a fee should be visible at the point
                // of choosing, not discovered after.
                'fee_html' => $p->id === 'cod' && $cod > 0
                    ? '+' . \App\Support\Money::format($cod)
                    : null,
                'fee_fils' => $p->id === 'cod' ? $cod : 0,
            ])
            ->all();

        if ($list !== []) {
            return $list;
        }

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
                'fee_html' => $cod > 0 ? '+' . \App\Support\Money::format($cod) : null,
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

        return response()->json([
            'ok' => true,
            'on' => $on,
            'giftFee' => \App\Support\Money::format($gift),
            'total' => \App\Support\Money::format((int) $totals['total'] + $gift),
            'totalWithFee' => $cod > 0
                ? \App\Support\Money::format((int) $totals['total'] + $cod + $gift)
                : null,
        ]);
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

        [$rates, $chosen, $totals] = $this->rateContext($cart, $country, $data['state'] ?? null);

        return response()->json([
            'ok' => true,
            'deliveryHtml' => view('partials.checkout.delivery-options', [
                'rates' => $rates,
                'chosenRate' => $chosen,
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
            'subtotal' => \App\Support\Money::format((int) $totals['subtotal']),
            'shipping' => $totals['shipping'] > 0
                ? \App\Support\Money::format((int) $totals['shipping'])
                : '<span style="color:var(--green);font-weight:700">Free</span>',
            'total' => \App\Support\Money::format((int) $totals['total'] + $this->giftFee($request)),
            // The COD-fee-inclusive total, kept in step with the country so
            // it is never wrong after switching country while Cash on
            // delivery happens to be selected. The fee itself is flat and
            // never changes; only the total under it does.
            'totalWithFee' => (function () use ($totals, $request) {
                $fee = (int) $this->settings->get('cod_fee', 0);
                $gift = $this->giftFee($request);

                return $fee > 0
                    ? \App\Support\Money::format((int) $totals['total'] + $fee + $gift)
                    : null;
            })(),
            'vat' => $totals['vat'] ? ['label' => $totals['vat']['label'], 'formatted' => $totals['vat']['formatted']] : null,
        ]);
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

    private function deliveryText(string $country): string
    {
        $rows = (array) $this->settings->get('delivery_texts', []);

        foreach ($rows as $row) {
            if (($row['country'] ?? '') === $country) {
                return (string) ($row['text'] ?? '');
            }
        }

        return (string) $this->settings->get('delivery_default_text', '1–3 days fast delivery all over UAE');
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
     * Sequential, prefixed, and continuing from whatever is already there —
     * imported WooCommerce orders keep their numbers, so new ones must not
     * collide with them.
     */
    private function nextOrderNumber(): string
    {
        $last = (int) Order::max('id');

        return (string) (10000 + $last + 1);
    }
}
