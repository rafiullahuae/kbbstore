<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Fils;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orders placed by staff on a customer's behalf.
 *
 * Most of the store's orders do not come through the website. They arrive on
 * WhatsApp and in Instagram DMs, get agreed in a conversation, and until now
 * had nowhere to go — so the inventory team, who pack from the order record,
 * had nothing to pack from.
 *
 * ---------------------------------------------------------------------------
 * The one rule this class exists to keep
 * ---------------------------------------------------------------------------
 *
 * Every figure on a manual order is produced by the code the website checkout
 * uses. Not "the same formula", not "the same rounding" — literally the same
 * objects:
 *
 *   - line unit prices  -> CartService::add(), which calls unitPriceFor() and
 *                          so honours sale windows, variant pricing and the
 *                          quantity-bundle tiers
 *   - coupon validity   -> CouponService::validate()
 *   - discount          -> CartService::totals(), which calls
 *                          CouponService::discountFor()
 *   - shipping rates    -> ShippingService::ratesFor()
 *   - the total         -> CartService::totals()
 *   - the COD fee       -> the cod_fee setting, added exactly as
 *                          Store\CheckoutController::place() adds it
 *
 * Those services all take a Cart, so a manual order builds a real cart and
 * prices it. A quote builds one inside a transaction that is always rolled
 * back; a placed order keeps its cart and marks it converted, which is what a
 * website order does too.
 *
 * The alternative — a second copy of the totals maths for the back office —
 * is the thing that guarantees the two will disagree, and the disagreement
 * always surfaces as a customer being charged a different number than they
 * were quoted.
 *
 * ---------------------------------------------------------------------------
 * Two deliberate differences from the website checkout
 * ---------------------------------------------------------------------------
 *
 *  1. Payment & Shipping Rules' Cash-on-delivery window is NOT enforced here.
 *     That rule exists to stop a shopper choosing COD on an order value the
 *     shop will not carry it on. An operator taking a WhatsApp order has
 *     already agreed the terms with the customer by voice; refusing to record
 *     what was agreed would simply mean the order does not get recorded. The
 *     window still governs the storefront exactly as before.
 *
 *  2. Shipping cost may be overridden by the operator, because a negotiated
 *     courier fee is a normal part of these orders. The override replaces the
 *     rate's cost at the point CartService::totals() already takes it as an
 *     argument — it is an input to the shared calculation, not a second one.
 *     Everything downstream of it, free-shipping thresholds included, is
 *     unchanged.
 *
 * ---------------------------------------------------------------------------
 * What this deliberately does NOT do
 * ---------------------------------------------------------------------------
 *
 *  - It does not decrement stock. Neither does a website order: there is no
 *    stock movement anywhere in Store\CheckoutController::place(). Adding it
 *    on this path only would make a back-office order and a web order mean
 *    different things to the inventory count, which is worse than neither
 *    doing it. This wants fixing for both paths at once, in its own change.
 *
 *  - It does not record a coupon redemption. Store\CheckoutController::place()
 *    does not call CouponService::recordRedemption() either, so usage_count
 *    and usage_limit_per_user do not move on a website order. Matching that is
 *    the whole point of this class; diverging would make a back-office order
 *    consume a coupon a web order does not.
 *
 *  - It does not apply a manual, ad-hoc discount. CartService::totals() derives
 *    discount from the coupon and nothing else, so an arbitrary "take 15 off"
 *    would have to be arithmetic performed here — exactly the fork this class
 *    is written to avoid. Make a coupon.
 */
class ManualOrderBuilder
{
    /**
     * The order statuses a manual order may be created with.
     *
     * `orders.status` is a free-form string column — the Phase 0 schema says
     * so in as many words, because imported WooCommerce statuses such as
     * wc-shipped have to survive — so there is no database enum to read. The
     * vocabulary is the one the rest of the console already uses:
     * OrdersApiController::KNOWN_STATUSES, minus the three a person keying in
     * an order has no business choosing.
     *
     * 'refunded' is absent for the reason OrdersApiController gives for
     * leaving it out of its bulk actions: PaymentRefunder writes it when money
     * actually moved, and a screen that could type it would be a way to make
     * the books say a refund happened without one. 'failed' is absent for the
     * same shape of reason — it is what a gateway decided, not what an
     * operator decides. 'draft' is absent because this screen places orders;
     * an order nobody agreed to does not need a customer and a total.
     *
     * Nothing outside this list is invented. In particular the vocabulary is
     * NOT active/draft/archived: those belong to no column in this schema.
     */
    public const STATUSES = ['pending', 'processing', 'onhold', 'shipped', 'completed', 'cancelled'];

    /**
     * Default for a new manual order.
     *
     * 'processing' rather than 'pending': a WhatsApp or DM order has already
     * been agreed with the customer, and processing is one of the four
     * Order::REAL_STATUSES that count as revenue.
     */
    public const DEFAULT_STATUS = 'processing';

    /** Where the order came from, written to orders.origin. */
    public const CHANNELS = ['whatsapp', 'instagram', 'phone', 'walk-in', 'email', 'other'];

    public function __construct(
        private CartService $carts,
        private CouponService $coupons,
        private ShippingService $shipping,
        private SettingsService $settings,
    ) {}

    /* ===================================================================
     | Vocabularies — read out of the schema, never guessed
     |=================================================================== */

    /**
     * Payment methods that exist for this store.
     *
     * payment_providers.id is the primary key and the vocabulary: cod, stripe,
     * tabby, tamara. A store with no rows at all falls back to cod, which is
     * the same floor Store\CheckoutController::gateways() applies, so the two
     * screens offer the same thing on a fresh install.
     */
    public function paymentMethods(): array
    {
        $rows = PaymentProvider::query()->orderBy('position')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return [[
                'id' => 'cod',
                'title' => 'Cash on delivery',
                'enabled' => true,
                'fee_fils' => (int) $this->settings->get('cod_fee', 0),
            ]];
        }

        return $rows->map(fn (PaymentProvider $p) => [
            'id' => (string) $p->id,
            'title' => (string) ($p->title ?: $p->id),
            'enabled' => (bool) $p->enabled,
            // Only COD carries a fee in this build, and it is a setting, not a
            // provider column — same as the storefront reads it.
            'fee_fils' => $p->id === 'cod' ? (int) $this->settings->get('cod_fee', 0) : 0,
        ])->values()->all();
    }

    /** @return list<string> ids accepted by validation */
    public function paymentMethodIds(): array
    {
        return array_column($this->paymentMethods(), 'id');
    }

    /** The COD handling fee in fils, or 0 for any other method. */
    public function feeFor(string $paymentMethod): int
    {
        return $paymentMethod === 'cod' ? (int) $this->settings->get('cod_fee', 0) : 0;
    }

    /* ===================================================================
     | Pricing
     |=================================================================== */

    /**
     * Price a basket without writing anything.
     *
     * The draft cart has to be a real row, because CartService and
     * CouponService both work on a persisted Cart with persisted items. It is
     * built inside a transaction that always rolls back, so a quote — which
     * the screen fires on every quantity change — leaves nothing behind.
     *
     * @param  array  $input  see create()
     * @return array{ok: bool, error: ?string, totals: ?array, rates: array, lines: array, fee: int, grand_total: ?int}
     */
    public function quote(array $input): array
    {
        $result = null;

        try {
            DB::transaction(function () use ($input, &$result) {
                $cart = $this->buildDraftCart($input);
                $result = $this->price($cart, $input);

                // Unwinds the draft cart, its items and the id it consumed.
                throw new DraftCartRollback();
            });
        } catch (DraftCartRollback) {
            // Expected: the only way out of a transaction that must not commit.
        }

        return $result ?? $this->failure('Nothing to price.');
    }

    /**
     * Create the order.
     *
     * @param  array  $input {
     *     customer_id?: int, new_customer?: array{name,email,phone?},
     *     items: list<array{product_id:int, variant_id?:int, quantity:int}>,
     *     address: array{line1,city,state,country,phone?},
     *     coupon_code?: string, shipping_method_id?: int,
     *     shipping_override_fils?: int, payment_method: string,
     *     status?: string, channel?: string, customer_note?: string,
     *     whatsapp_optin?: bool
     * }
     * @return array{ok: bool, error: ?string, order: ?Order}
     */
    public function create(array $input, ?string $author = null): array
    {
        try {
            $order = DB::transaction(function () use ($input, $author) {
                return $this->createWithin($input, $author);
            });
        } catch (ManualOrderFailure $e) {
            // The draft cart and anything else written inside the transaction
            // is already gone; the operator gets the service's own wording.
            return ['ok' => false, 'error' => $e->getMessage(), 'order' => null];
        }

        return ['ok' => true, 'error' => null, 'order' => $order];
    }

    /** The body of create(), run inside the transaction. */
    private function createWithin(array $input, ?string $author): Order
    {
        $cart = $this->buildDraftCart($input);
        $priced = $this->price($cart, $input);

        if (! $priced['ok']) {
            throw new ManualOrderFailure((string) $priced['error']);
        }

        $customer = $cart->customer;
        $address = $this->addressPayload($input, $customer);
        $totals = $priced['totals'];
        $fee = $priced['fee'];

        $order = $this->insertOrder([
            'customer_id' => $customer->id,
            'email' => mb_strtolower((string) $customer->email),
            'phone' => $address['phone'] ?? $customer->phone,
            'status' => $input['status'] ?? self::DEFAULT_STATUS,
            'currency' => 'AED',
            'billing_address' => $address,
            'shipping_address' => $address,
            'subtotal' => (int) $totals['subtotal'],
            'discount_total' => (int) $totals['discount'],
            'shipping_total' => (int) $totals['shipping'],
            'fee_total' => $fee,
            // VAT is display-only (D-64), exactly as the checkout writes it.
            'tax_total' => 0,
            'total' => (int) $totals['total'] + $fee,
            'shipping_method' => $priced['chosen_rate']['title'] ?? null,
            'payment_method' => $input['payment_method'],
            'payment_method_title' => $this->titleFor($input['payment_method']),
            'coupon_code' => $totals['coupon_code'],
            'whatsapp_optin' => (bool) ($input['whatsapp_optin'] ?? false),
            'customer_note' => $input['customer_note'] ?? null,
            // The thing the owner actually asked to be able to see: which
            // conversation this order came out of.
            'origin' => $input['channel'] ?? 'other',
        ]);

        foreach ($cart->items as $item) {
            $product = $item->product;

            $order->items()->create([
                'product_id' => $product?->id,
                'product_variant_id' => $item->product_variant_id,
                // Snapshots, so the order still reads correctly if the product
                // is later renamed or removed. Same fields, same order, as
                // Store\CheckoutController::place().
                'name' => $product?->name ?? 'Item',
                'brand' => $product?->brand?->name,
                'sku' => $item->variant?->sku ?? $product?->sku,
                'variant_attributes' => $item->variant?->attributeValues->pluck('name')->all(),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->lineTotal(),
                'total' => $item->lineTotal(),
            ]);
        }

        $cart->forceFill(['status' => 'converted', 'converted_at' => now()])->save();

        $this->writeAudit($order, $input, $author);

        return $order->load('items', 'notes');
    }

    /* ===================================================================
     | Internals
     |=================================================================== */

    /**
     * A cart carrying this order's customer, lines and coupon.
     *
     * Cart::create() is used directly rather than CartService::create(),
     * because the latter queues the kbb_cart cookie onto the response — on an
     * admin request that would hand the operator's own browser a storefront
     * cart. Everything price-bearing still goes through CartService.
     */
    private function buildDraftCart(array $input): Cart
    {
        $customer = $this->resolveCustomer($input);

        $cart = Cart::create([
            'token' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'currency' => 'AED',
            'status' => 'active',
            'shipping_country' => strtoupper((string) ($input['address']['country'] ?? '')),
            'shipping_state' => $input['address']['state'] ?? null,
            'last_activity_at' => now(),
        ]);

        foreach ($input['items'] ?? [] as $line) {
            $product = Product::find($line['product_id'] ?? null);

            if (! $product) {
                continue;
            }

            $variant = isset($line['variant_id'])
                ? ProductVariant::where('product_id', $product->id)->find($line['variant_id'])
                : null;

            // CartService::add() is what decides the unit price: sale window,
            // variant pricing, quantity-bundle tier. Nothing here recomputes it.
            $this->carts->add($cart, $product, (int) ($line['quantity'] ?? 1), $variant);
        }

        if (! empty($input['coupon_code'])) {
            $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower(trim((string) $input['coupon_code']))])->first();

            if ($coupon) {
                $cart->forceFill(['coupon_id' => $coupon->id])->save();
            }
        }

        $cart->setRelation('customer', $customer);

        return $cart->fresh([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product.brand',
            'items.variant.attributeValues',
            'coupon',
            'customer',
        ]);
    }

    /**
     * Every figure, from the shared services.
     *
     * @return array{ok: bool, error: ?string, totals: ?array, rates: array, chosen_rate: ?array, lines: array, fee: int, grand_total: ?int}
     */
    private function price(Cart $cart, array $input): array
    {
        if ($cart->items->isEmpty()) {
            return $this->failure('Add at least one product to the order.');
        }

        $country = strtoupper((string) ($input['address']['country'] ?? ''));
        $state = $input['address']['state'] ?? null;

        // The coupon is validated by the same service the cart page uses, so
        // an expired or over-used code is refused here in its own words.
        if ($cart->coupon) {
            $check = $this->coupons->validate($cart->coupon->code, $cart, $cart->customer?->email);

            if (! $check['ok']) {
                return $this->failure($check['error']);
            }
        } elseif (! empty($input['coupon_code'])) {
            return $this->failure('That code is not valid.');
        }

        // Rates are asked for on the pre-discount subtotal and the free-
        // shipping bar is measured on the discounted one. That split is the
        // checkout's, reproduced by calling the same two things in the same
        // order — not by re-deriving it.
        $rates = $this->shipping->ratesFor(
            $country,
            $state,
            $this->netSubtotal($cart),
            (bool) $this->settings->get('hide_paid_when_free', true),
        );

        if (! $rates) {
            return $this->failure('We do not deliver to that country yet.');
        }

        // Only a rate actually offered for this destination is accepted — the
        // checkout's rule, and it matters just as much when the person
        // choosing is staff.
        $chosen = collect($rates)->firstWhere('id', $input['shipping_method_id'] ?? null) ?? $rates[0];

        $shippingCost = array_key_exists('shipping_override_fils', $input) && $input['shipping_override_fils'] !== null
            ? max(0, (int) $input['shipping_override_fils'])
            : (int) $chosen['cost'];

        $totals = $this->carts->totals($cart, $country, $state, $shippingCost);

        $fee = $this->feeFor((string) ($input['payment_method'] ?? ''));

        /*
         * Will any of this fit in the columns it is about to be written to?
         *
         * orders.subtotal, .total and order_items.unit_price, .subtotal,
         * .total are all `$t->integer` — signed 32-bit, so 2,147,483,647 fils
         * (AED 21,474,836.47). Checked before a row is written rather than
         * after, because the two engines fail differently and only one of them
         * is loud: MySQL in strict mode raises, SQLite stores the wrapped
         * number in silence and the order reads as placed with a nonsense
         * total.
         *
         * Checked on the SUM as well as on each line. Ninety-nine of something
         * expensive is one line that fits; sixty lines of it is an order total
         * that does not, and no individual check would have caught that.
         */
        foreach ($cart->items as $item) {
            if (! Fils::productFits((int) $item->unit_price, (int) $item->quantity)) {
                return $this->failure(
                    'That quantity at that price comes to more than an order line can hold ('
                    . Money::plain(Fils::max()) . ').'
                );
            }
        }

        if (! Fils::sumFits((int) $totals['total'], $fee)) {
            return $this->failure(
                'That order comes to more than an order total can hold ('
                . Money::plain(Fils::max()) . ').'
            );
        }

        return [
            'ok' => true,
            'error' => null,
            'totals' => $totals,
            'rates' => $rates,
            'chosen_rate' => $chosen,
            'lines' => $cart->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'variant_id' => $i->product_variant_id,
                'name' => $i->product?->name ?? 'Item',
                'sku' => $i->variant?->sku ?? $i->product?->sku,
                'quantity' => (int) $i->quantity,
                'unit_price' => (int) $i->unit_price,
                'line_total' => (int) $i->lineTotal(),
            ])->values()->all(),
            'fee' => $fee,
            // The number the customer pays. Written out rather than left for
            // the caller to add up, so there is one answer to that question.
            'grand_total' => (int) $totals['total'] + $fee,
        ];
    }

    /** Pre-discount basket value, as Store\CheckoutController computes it. */
    private function netSubtotal(Cart $cart): int
    {
        return max(0, (int) $cart->items->sum(fn ($i) => $i->lineTotal()));
    }

    /**
     * The customer this order is for: an existing row, or one created from
     * what the operator typed.
     *
     * firstOrCreate on the email, matching the checkout — a customer who once
     * ordered on the website and now orders on WhatsApp must not end up with
     * two records, or their order history splits in half.
     */
    private function resolveCustomer(array $input): Customer
    {
        if (! empty($input['customer_id'])) {
            $existing = Customer::find($input['customer_id']);

            if ($existing) {
                return $existing;
            }
        }

        $new = $input['new_customer'] ?? [];
        $email = mb_strtolower(trim((string) ($new['email'] ?? '')));
        [$first, $last] = $this->splitName((string) ($new['name'] ?? ''));

        $customer = Customer::firstOrCreate(
            ['email' => $email],
            [
                'name' => trim($first . ' ' . $last) ?: $email,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $new['phone'] ?? null,
                'whatsapp_optin' => (bool) ($input['whatsapp_optin'] ?? false),
            ],
        );

        // A brand-new customer gets the typed address saved to their address
        // book, so the next manual order for them prefills instead of being
        // retyped. An existing customer's book is left alone: overwriting a
        // saved address from an order screen is how people lose one.
        if ($customer->wasRecentlyCreated && ! empty($input['address']['line1'])) {
            foreach (['billing', 'shipping'] as $type) {
                // Through the relation, not Address::create([...'customer_id']):
                // that column is deliberately not fillable, so a mass-assigned
                // one is silently dropped and the insert fails on NOT NULL.
                $customer->addresses()->create([
                    'type' => $type,
                    'is_default' => true,
                    'first_name' => $first,
                    'last_name' => $last,
                    'line1' => $input['address']['line1'],
                    'city' => $input['address']['city'] ?? null,
                    'state' => $input['address']['state'] ?? null,
                    'country' => strtoupper((string) ($input['address']['country'] ?? 'AE')),
                    'phone' => $input['address']['phone'] ?? ($new['phone'] ?? null),
                ]);
            }
        }

        return $customer;
    }

    /** The address snapshot written onto the order, shaped as checkout shapes it. */
    private function addressPayload(array $input, Customer $customer): array
    {
        $address = $input['address'] ?? [];
        [$first, $last] = $this->splitName($customer->displayName());

        return [
            'first_name' => $customer->first_name ?: $first,
            'last_name' => $customer->last_name ?: $last,
            'line1' => $address['line1'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'country' => strtoupper((string) ($address['country'] ?? 'AE')),
            'phone' => $address['phone'] ?? $customer->phone,
        ];
    }

    /** First token is the first name, the remainder the surname — the theme's split. */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $first = array_shift($parts) ?? '';

        return [$first, implode(' ', $parts)];
    }

    private function titleFor(string $id): ?string
    {
        foreach ($this->paymentMethods() as $method) {
            if ($method['id'] === $id) {
                return $method['title'];
            }
        }

        return null;
    }

    /**
     * Insert the order, allocating an order_number nothing already holds.
     *
     * The allocation is Store\CheckoutController::nextOrderNumber()'s, and it
     * has to stay that way or the two paths will collide with each other: the
     * highest of "10000 + the largest id" and "the largest order_number",
     * walked forward until something is free. The second candidate is what
     * makes imported WooCommerce orders safe — they keep their own numbers, and
     * an id-derived guess alone lands straight on top of one.
     *
     * That method is private on the checkout controller, so the rule is
     * repeated here rather than the controller being edited. If it changes,
     * this has to change with it.
     *
     * What is added: order_number is UNIQUE, and two operators saving in the
     * same second both read the same maximum. The insert is retried against the
     * unique index, which is the only thing that actually knows.
     */
    private function insertOrder(array $attributes): Order
    {
        $lastError = null;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = max(
                10000 + (int) Order::withTrashed()->max('id'),
                (int) Order::withTrashed()->max('order_number'),
            );

            // Bounded, like the checkout's own loop: a thousand consecutive
            // taken numbers means something a retry should not paper over.
            $number = null;

            for ($i = 0; $i < 1000; $i++) {
                $next = (string) (++$candidate);

                if (! Order::withTrashed()->where('order_number', $next)->exists()) {
                    $number = $next;
                    break;
                }
            }

            if ($number === null) {
                $number = (string) $candidate . random_int(100, 999);
            }

            try {
                return Order::create($attributes + ['order_number' => $number]);
            } catch (QueryException $e) {
                // Lost the race between the check and the insert. Look again.
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('Could not allocate an order number.');
    }

    /**
     * Who created this and how, on the order itself.
     *
     * is_customer_note is false: these are staff notes. They are the only
     * record that an order was keyed in rather than placed, which is what
     * makes a back-office order auditable at all.
     */
    private function writeAudit(Order $order, array $input, ?string $author): void
    {
        $channel = $input['channel'] ?? 'other';

        $lines = ['Created in the back office (' . $channel . ').'];

        if (array_key_exists('shipping_override_fils', $input) && $input['shipping_override_fils'] !== null) {
            $lines[] = 'Delivery charge set by hand to AED '
                . Fils::toDecimalString((int) $input['shipping_override_fils']) . '.';
        }

        $lines[] = ($input['send_confirmation'] ?? false)
            ? 'Operator asked for a confirmation email.'
            : 'No confirmation email requested.';

        // Said on the record rather than only in a report, because the packer
        // reading this order is the person who would otherwise assume the
        // count had moved.
        $lines[] = 'Stock was not adjusted — no order path in this build moves stock.';

        $order->notes()->create([
            'author' => $author ?: 'Back office',
            'is_customer_note' => false,
            'content' => implode(' ', $lines),
        ]);
    }

    private function failure(?string $message): array
    {
        return [
            'ok' => false,
            'error' => $message ?? 'That order cannot be priced.',
            'totals' => null,
            'rates' => [],
            'chosen_rate' => null,
            'lines' => [],
            'fee' => 0,
            'grand_total' => null,
        ];
    }
}
