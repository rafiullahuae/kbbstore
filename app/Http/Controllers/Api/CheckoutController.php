<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\Orders\OrderNumbers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    /**
     * POST /api/checkout/session
     *
     * Creates the order with amounts RECALCULATED SERVER-SIDE from the catalog
     * (never trusting client-sent prices — security baseline §5). Then hands off
     * to the chosen payment method.
     *
     * Payments status: COD is completed here. Hosted card flows (Stripe / Tabby /
     * Tamara) are wired in Phase 3; this returns a placeholder redirect until then.
     */
    public function session(Request $request)
    {
        $data = $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.slug'     => 'required|string',
            'items.*.qty'      => 'required|integer|min:1|max:99',
            'customer'         => 'required|array',
            'customer.name'    => 'required|string|max:120',
            'customer.email'   => 'required|email|max:160',
            'customer.phone'   => 'nullable|string|max:40',
            'customer.emirate' => 'nullable|string|max:60',
            'customer.address' => 'nullable|string|max:500',
            /*
             * `country` WAS NEVER VALIDATED, AND THEREFORE NEVER ARRIVED.
             *
             * Request::validate() returns ONLY the keys it was given rules for.
             * `customer` is validated as an array and each sub-key by name, so
             * a sub-key with no rule of its own is dropped from $data before
             * any of this method sees it.
             *
             * Two places below read `$data['customer']['country']` and both had
             * been reading an absent key since the day they were written:
             * the billing/shipping snapshot recorded every order as 'AE'
             * whatever the caller sent, and the gateway's own
             * availableFor($total, $country) check was handed null on every
             * single call — so a gateway restricted by country was never
             * actually asked about one. Neither could fail loudly, because
             * `?? 'AE'` and `?? null` are exactly what an absent key produces.
             *
             * Same shape as the broken-filter lesson in CLAUDE.md: the code
             * downstream read fine and had never once run on real input.
             */
            'customer.country' => 'nullable|string|size:2',
            'ship_method'      => 'nullable|string|max:60',
            'method'           => 'required|string|in:cod,stripe,tabby,tamara',
        ]);

        /*
         * BEFORE the transaction, for the reason Store\CheckoutController::place()
         * gives at length: an order number read from inside the placing
         * transaction comes out of that transaction's snapshot, so two
         * simultaneous placements compute the same one and the loser dies on
         * the unique index.
         */
        $orderNumber = $this->nextOrderNumber();

        // Resolved outside the transaction: it is the storefront's pricing
        // service, asked only to price a line, and nothing about it needs to
        // be inside the write.
        $carts = app(CartService::class);

        try {
            return $this->write($data, $orderNumber, $carts);
        } catch (\App\Services\StockUnavailable $e) {
            /*
             * Something in the basket is gone, or there are fewer left than
             * were asked for. The transaction rolled back, so there is no
             * order, no line, nothing off the shelf and no customer row.
             *
             * 422 WITH THIS BODY, and not the 500 an uncaught RuntimeException
             * would give. `{ok: false, error: <sentence>}` is the shape every
             * other refusal on this endpoint already uses — the shipping
             * refusal, the COD window, the unavailable gateway — so a headless
             * caller that handles one handles this. The sentence is the one
             * StockClaim writes for a shopper: it names the product and, where
             * stock is counted, says how many are actually left, which is what
             * a caller needs to correct the basket and try again.
             */
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /** The placing transaction itself. Split out only so the catch above can sit outside it. */
    private function write(array $data, string $orderNumber, CartService $carts)
    {
        return DB::transaction(function () use ($data, $orderNumber, $carts) {
            $settings   = Setting::map();
            $codFeeCfg  = (int) ($settings['cod_fee'] ?? 0);            // fils

            // recompute subtotal from the catalog — client prices are ignored
            $subtotal = 0;
            $lines = [];

            /*
             * The same list StockClaim takes from the storefront's basket, built
             * here from the request shape instead. Collected in this loop rather
             * than derived from $lines later so that the label in a refusal is
             * the product's own name, off the row this loop already has.
             *
             * `variant_id` is null on every line because this endpoint's request
             * shape is a slug and a quantity and has no variant in it at all —
             * the same reason unitPriceFor() is called with null above.
             */
            $claimLines = [];

            foreach ($data['items'] as $it) {
                // visible(), not a bare slug lookup. Without it a draft,
                // private or hidden product could be ordered through this
                // endpoint by anyone who knew its slug -- and unlike the read
                // endpoints fixed in 2.60.95, this one creates a real order
                // against it.
                $p = Product::visible()->with('brand:id,name')->where('slug', $it['slug'])->first();

                if (! $p) {
                    abort(422, "Unknown product: {$it['slug']}");
                }

                // Out of stock is refused rather than sold. The storefront
                // checkout will not offer it; this path would have taken the
                // order and left someone to explain it afterwards.
                if (($p->stock_status ?? 'instock') === 'outofstock') {
                    abort(422, "Out of stock: {$p->name}");
                }

                /*
                 * CartService::unitPriceFor(), not effectivePrice() alone.
                 *
                 * effectivePrice() was already the right answer for the SALE
                 * WINDOW — "not sale_price ?? price. The raw column ignores
                 * sale_starts_at and sale_ends_at, so an expired sale kept
                 * selling at the sale price and a future one sold early. Money,
                 * quietly, in both directions." — and it still is. What it does
                 * not know about is QUANTITY.
                 *
                 * Quantity bundles are generated for the whole catalogue from
                 * one tier table, so every simple product's page offers a
                 * 2-pack at 5% off and a 3-pack at 10%. The storefront charges
                 * that rate because CartService::add() prices every line
                 * through unitPriceFor(). This endpoint did not, so three
                 * bottles of a AED 150 serum cost AED 405.00 on the product
                 * page and AED 450.00 here — AED 45 over the advertised price,
                 * on a public endpoint, with the order written.
                 *
                 * It erred towards the shop, which is why it survived. That is
                 * also why it is the worse direction: the figure the customer
                 * was shown is the figure they agreed to.
                 *
                 * DEFERRED rather than reimplemented, for the reason
                 * unitPriceFor()'s own comment gives — one place recomputes
                 * what a line costs, from the catalogue and never from the
                 * request (Rule 23). It is also the only place that knows
                 * bundles are a simple-product offer and that the whole module
                 * can be switched off under Store → Modules. `null` for the
                 * variant because this endpoint takes a slug and a quantity and
                 * has no variant in its request shape at all.
                 */
                $unit = $carts->unitPriceFor($p, null, (int) $it['qty']);

                $subtotal += $unit * $it['qty'];
                $lines[] = [
                    'product_id' => $p->id,
                    'name'       => $p->name,
                    // brand is a belongsTo relation; writing it raw put a model
                    // (or null) where the line expects a name.
                    'brand'      => $p->brand?->name,
                    'qty'        => $it['qty'],
                    'unit_price' => $unit,
                ];

                $claimLines[] = [
                    'product_id' => (int) $p->id,
                    'variant_id' => null,
                    'quantity'   => (int) $it['qty'],
                    'label'      => (string) $p->name,
                ];
            }

            /*
             * DELIVERY COMES FROM THE SHIPPING ZONES, exactly as it does in
             * Store\CheckoutController::place().
             *
             * What stood here was:
             *
             *     $delivery = $subtotal >= $freeShip ? 0 : $flatDelivery;
             *
             * — two settings (`free_ship`, `delivery_flat`) that NOTHING ELSE IN
             * THIS APPLICATION READS. The storefront prices delivery from
             * ShippingService::ratesFor(), which is what the owner actually
             * edits under Store → Shipping, and production runs two zones: All
             * UAE at AED 20 free over AED 199, and Gulf Countries at AED 150
             * free over AED 1,600.
             *
             * So this endpoint charged AED 20 to ship to Saudi Arabia while the
             * storefront charged AED 150 for the identical basket — the shop
             * quietly absorbing AED 130 of courier cost on every Gulf order
             * placed through the API, and the two doors into the same shop
             * disagreeing about the price of the same delivery.
             *
             * The flat defaults hid it: `delivery_flat` defaults to 2000 fils
             * and `free_ship` to 20000, which is within a dirham of the UAE
             * zone. Every UAE order looked right. Only the Gulf was wrong, and
             * only on this path.
             *
             * A DESTINATION NO ZONE COVERS IS NOW REFUSED rather than shipped at
             * a flat rate. place() answers "We do not deliver to that country
             * yet" and stops; this took the order, charged the flat rate and
             * left somebody to explain it afterwards. An endpoint that accepts
             * orders the shop cannot fulfil is worse than one that says no.
             */
            $country = strtoupper(trim((string) ($data['customer']['country'] ?? 'AE'))) ?: 'AE';
            $state   = $data['customer']['emirate'] ?? null;

            $rates = app(\App\Services\ShippingService::class)->ratesFor(
                $country,
                $state,
                $subtotal,
                (bool) app(\App\Services\SettingsService::class)->get('hide_paid_when_free', true),
            );

            if (! $rates) {
                return response()->json([
                    'ok' => false,
                    'error' => 'We do not deliver to that country yet.',
                ], 422);
            }

            // Only a rate actually offered for this destination is accepted —
            // place()'s rule, and for the same reason: `ship_method` is whatever
            // the caller sent. Compared loosely because rate ids are integers
            // and this endpoint validates `ship_method` as a string.
            $rate = collect($rates)
                ->first(fn ($r) => (string) $r['id'] === trim((string) ($data['ship_method'] ?? '')))
                ?? $rates[0];

            $delivery = (int) $rate['cost'];
            $codFee   = $data['method'] === 'cod' ? $codFeeCfg : 0;

            /*
             * TAX, ON THIS PATH TOO, AND FOR THE SAME DESTINATION.
             *
             * A tax engine that runs on the storefront checkout and not here
             * produces orders whose totals do not add up — this endpoint
             * creates real orders, takes real payments and is PUBLIC (see
             * CLAUDE.md: every /api/* endpoint is unauthenticated), so it is
             * not a side door that may lag behind.
             *
             * It cannot call CartService::totals(): this path never builds a
             * Cart, prices its lines straight from the catalogue and supports
             * no coupon, which is why `discount_total` below is 0. So it asks
             * VatDisplay the same question totals() asks, on the same taxable
             * base — subtotal minus discount (nil here) plus delivery, with the
             * COD surcharge outside it, exactly as the storefront computes it.
             *
             * BEFORE the Payment & Shipping Rules window and the gateway's own
             * availableFor() check, deliberately: both measure the ORDER TOTAL,
             * and on an exclusive basis the total is the tax-inclusive one the
             * driver actually collects. Asking them about a pre-tax figure
             * would offer Cash on delivery for an order that is over the
             * ceiling by the time it is placed.
             *
             * Lane CQ owns the stock handling on this endpoint. Nothing here
             * touches stock, the lines, or the gateway hand-off.
             */
            $taxableBase = $subtotal + $delivery;

            /*
             * $country, NOT `$data['customer']['country'] ?? null`, and this is
             * the whole of the difference.
             *
             * `customer.country` is `nullable` on this endpoint, so an absent
             * country is a reachable state on a PUBLIC, unauthenticated route
             * rather than a theoretical one. Every other figure on this order
             * already resolves that absence to 'AE' — $country above defaults
             * to it, ShippingService prices the delivery against it, and the
             * billing/shipping snapshot records it — but the tax quote was
             * handed the raw null, and VatDisplay::ruleFor(null) answers the
             * GLOBAL default rule rather than the rule for AE.
             *
             * So one order said `country: AE`, was charged AE delivery, and was
             * taxed at whatever `vat_rate`/`vat_basis` happen to be — while the
             * storefront checkout, for the identical destination, used AE's own
             * row. With AE inclusive by default and AE's row set to exclusive,
             * the same basket came to AED 220.00 through this door and AED
             * 231.00 through the other one, and omitting a nullable field was
             * the cheaper of the two.
             *
             * This is the same shape as the defect recorded thirty lines above
             * — `?? null` reading fine and never once running on real input —
             * one call along, and CLAUDE.md's own note about a broken filter
             * hiding a second bug. The destination is resolved ONCE, at the top
             * of this method, and every reader below uses that one answer.
             */
            $tax = app(\App\Support\VatDisplay::class)->quote($taxableBase, $country);

            $total = $tax['total'] + $codFee;

            // Payment & Shipping Rules applies here too. This endpoint takes a
            // method straight from the request, so leaving it out would make it
            // the way around the rule rather than an oversight nobody noticed.
            // Same for whether COD is switched on at all — Store → Ecommerce →
            // Checkout, not just the order-value window.
            if ($data['method'] === 'cod') {
                $reason = app(\App\Services\PayShipRules::class)->codHiddenReason($total);

                if ($reason !== null) {
                    return response()->json(['ok' => false, 'error' => $reason], 422);
                }

                if (! \App\Models\PaymentProvider::find('cod')?->enabled
                        && \App\Models\PaymentProvider::whereKey('cod')->exists()) {
                    return response()->json(['ok' => false, 'error' => 'Cash on delivery is not available.'], 422);
                }
            }

            // The same gate the storefront checkout applies, applied here too.
            // This endpoint is public and takes `method` straight off the
            // request, so a gateway with no credentials, or one that does not
            // qualify for this basket, has to be refused here as well —
            // otherwise this is the way around the storefront's checks rather
            // than a second door into the same shop.
            //
            // configured() is the load-bearing half: until the owner fills in
            // the keys, asking this endpoint for `stripe` gets a 422 saying so
            // rather than a 500 from a gateway trying to call an API with no
            // credentials.
            $gateway = app(\App\Services\Payments\GatewayRegistry::class)->find($data['method']);

            if ($gateway === null
                || ! $gateway->configured()
                || ! $gateway->availableFor($total, $data['customer']['country'] ?? null)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'That payment method is not available.',
                ], 422);
            }

            // find-or-create the customer by email
            //
            // `emirate` and `default_address` are not columns on `customers`
            // (an address is a row in `addresses`), and $guarded = [] meant
            // Eloquent passed them straight through to an INSERT that could
            // only fail. Dropped rather than mapped: this endpoint has no
            // address shape rich enough to make an Address row from, and
            // inventing one would put half-formed rows in the table the
            // account pages read.
            $c = Customer::firstOrCreate(
                ['email' => mb_strtolower($data['customer']['email'])],
                [
                    'name'  => $data['customer']['name'],
                    'phone' => $data['customer']['phone'] ?? null,
                ]
            );

            // Every column below is one `orders` actually has.
            //
            // What was here before wrote `billing`, `shipping`, `ship_method`,
            // `delivery` and `cod_fee` -- none of which exist on this table --
            // and omitted `order_number`, which is NOT NULL UNIQUE with no
            // default. So this endpoint could never have created an order; it
            // threw on the INSERT every time. The broken-filter lesson from
            // Api\ProductController applies exactly: nothing downstream of
            // that line had ever run, so nothing downstream had ever been
            // exercised either.
            $address = [
                'name'    => $data['customer']['name'],
                'phone'   => $data['customer']['phone'] ?? null,
                'state'   => $data['customer']['emirate'] ?? null,
                'line1'   => $data['customer']['address'] ?? null,
                // The SAME resolved destination the delivery was priced against
                // and the tax was quoted for, rather than a second reading of
                // the raw field. Normalising only — $country is this value
                // trimmed and upper-cased, with the identical 'AE' fallback —
                // so the country recorded on the order can never be a different
                // country, or a different spelling of one, from the country the
                // order was charged for.
                'country' => $country,
            ];

            $order = Order::create([
                'order_number'     => $orderNumber,
                'customer_id'      => $c->id,
                'email'            => mb_strtolower($data['customer']['email']),
                'phone'            => $data['customer']['phone'] ?? null,
                // Not `processing` for COD any more. The gateway decides, and
                // CashOnDelivery::start() moves it on -- so the one place that
                // knows what a method means to an order's state is the class
                // for that method.
                'status'           => 'pending',
                'currency'         => 'AED',
                'billing_address'  => $address,
                'shipping_address' => $address,
                'origin'           => 'Direct',
                'subtotal'         => $subtotal,
                'discount_total'   => 0,
                'shipping_total'   => $delivery,
                'fee_total'        => $codFee,
                // The same three columns the storefront checkout writes, from
                // the same quote, so an order placed here and one placed there
                // record their tax identically. 0 / null / null in the shipped
                // default state, which is what this row held before.
                'tax_total'        => (int) $tax['charged'],
                'tax_rate'         => $tax['mode'] === \App\Support\VatDisplay::MODE_LIVE ? $tax['rate'] : null,
                'tax_basis'        => $tax['mode'] === \App\Support\VatDisplay::MODE_LIVE ? $tax['basis'] : null,
                'total'            => $total,
                // The rate's own title, as place() writes it — not the raw
                // `ship_method` slug off the request. This column is what the
                // order email's "Delivery method" line and the admin order
                // screen print, and both were showing the customer the literal
                // word "standard".
                'shipping_method'  => $rate['title'],
                'payment_method'   => $data['method'],
            ]);

            /*
             * THE STOCK CLAIM, and the reason this endpoint is no longer a way
             * to buy the same single jar repeatedly.
             *
             * `/api/*` IS UNAUTHENTICATED — CLAUDE.md says so in as many words —
             * so every gap here is a gap anyone on the internet can walk
             * through. What stood above is still there and still runs: a
             * product whose `stock_status` is `outofstock` is refused before
             * any of this. What was missing is everything to do with the
             * COUNTED figure. This endpoint never looked at `stock`, never
             * decremented it, and never marked a shelf empty, so the flag check
             * was the entire defence — and the flag only moves when the owner
             * flips it by hand or when something decrements the last unit.
             * Nothing did. One jar, one `instock` row, and as many real orders
             * as anybody cared to POST.
             *
             * DELIBERATELY IN THE SAME PLACE place() PUTS IT: after the order
             * row, before the lines, inside the transaction, and before
             * $gateway->start() below. StockUnavailable propagates out of
             * DB::transaction() and rolls back the order and the customer row
             * with it, so a refused call has written nothing and asked nothing
             * of a payment provider. The catch is OUTSIDE the transaction for
             * exactly that reason — catching it in here and returning a
             * response would commit the order it is refusing.
             *
             * The order id is passed so the claim is recorded and can be given
             * back if this order is later cancelled.
             */
            app(\App\Services\StockClaim::class)->claim($claimLines, (int) $order->id);

            foreach ($lines as $l) {
                // `qty` is not a column either; the line table calls it
                // `quantity`, and wants subtotal/total per line.
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $l['product_id'],
                    'name'       => $l['name'],
                    'brand'      => $l['brand'],
                    'quantity'   => $l['qty'],
                    'unit_price' => $l['unit_price'],
                    'subtotal'   => $l['unit_price'] * $l['qty'],
                    'total'      => $l['unit_price'] * $l['qty'],
                ]);
            }

            // Hand off to the gateway — the same GatewayRegistry and the same
            // PaymentGateway::start() the storefront checkout uses, so there
            // is one implementation of "begin paying for this order" rather
            // than a second one behind the API that drifts from it.
            $start = $gateway->start($order);

            if (! $start->ok()) {
                // Through the funnel — App\Services\Orders\OrderStatus — for
                // the same reason the storefront path uses it: a `failed` order
                // gives back whatever it was holding, and the two checkouts
                // must not answer that differently. This endpoint applies no
                // discount today, so there is nothing to release; that is a
                // fact about this path, not a rule of its own, and the day it
                // does apply one it is already handled.
                app(\App\Services\Orders\OrderStatus::class)->moveTo(
                    $order,
                    'failed',
                    by: 'system',
                    reason: 'The payment could not be started.',
                );

                // The units go back on the shelf as well. That call stood
                // here until the funnel existed; it is inside OrderStatus now,
                // so this path and every cancellation reach OrderTransitionStock
                // by the same road — which is what deciding it in two places was
                // always going to cost.

                return response()->json([
                    'ok' => false,
                    'error' => $start->message ?? 'We could not start that payment.',
                ], 502);
            }

            // The success page keys off order_number, which is what the
            // storefront checkout redirects with too.
            $redirect = $start->redirectUrl
                ?? "/checkout/success?order={$order->order_number}";

            return response()->json([
                'ok'           => true,
                'order_id'     => $order->id,
                'orderId'      => $order->id,   // storefront reads camelCase (verbatim frontend)
                'order_number' => $order->order_number,
                'method'       => $data['method'],
                'redirect'     => $redirect,
            ], 201);
        });
    }

    /**
     * The next free order number.
     *
     * WHAT THIS USED TO BE, and why it was the worst of the three:
     *
     *     return (string) (10000 + (int) Order::max('id') + 1);
     *
     * Its docblock claimed it continued "from whatever is already there" and
     * that imported WooCommerce orders were safe from it. It did neither. It
     * never looked at `order_number` at all, so it did not continue from
     * anything — it derived a number from the primary key and hoped. An
     * imported order numbered 48231, or any soft-deleted order holding the
     * number it computed, and this endpoint raised SQLSTATE 23000 on the
     * insert. The other two paths at least searched for a free number; this one
     * had no check and no retry.
     *
     * It also ran INSIDE the placing transaction, so it carried the snapshot
     * race as well: two callers at once computed the same number and one lost
     * its order.
     *
     * All of it is now App\Services\Orders\OrderNumbers', and session()
     * allocates before opening the transaction — which is the part that matters
     * and the reason this is not called from in there.
     *
     * THIS ENDPOINT IS UNAUTHENTICATED. `/api/*` is public (CLAUDE.md says so
     * in as many words), so it is reachable by anyone and shares one sequence
     * with the storefront and the back office. That is correct — one shop, one
     * series of order numbers — and it is why the allocator has to be safe
     * under concurrency rather than merely usually right.
     */
    private function nextOrderNumber(): string
    {
        return app(OrderNumbers::class)->allocate();
    }
}
