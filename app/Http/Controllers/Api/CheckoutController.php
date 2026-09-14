<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
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
            'ship_method'      => 'nullable|string|max:60',
            'method'           => 'required|string|in:cod,stripe,tabby,tamara',
        ]);

        return DB::transaction(function () use ($data) {
            $settings   = Setting::map();
            $freeShip   = (int) ($settings['free_ship'] ?? 20000);      // fils
            $codFeeCfg  = (int) ($settings['cod_fee'] ?? 0);            // fils
            $flatDelivery = (int) ($settings['delivery_flat'] ?? 2000); // fils

            // recompute subtotal from the catalog — client prices are ignored
            $subtotal = 0;
            $lines = [];
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

                // effectivePrice(), not sale_price ?? price. The raw column
                // ignores sale_starts_at and sale_ends_at, so an expired sale
                // kept selling at the sale price and a future one sold early.
                // Money, quietly, in both directions.
                $unit = $p->effectivePrice();

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
            }

            $delivery = $subtotal >= $freeShip ? 0 : $flatDelivery;
            $codFee   = $data['method'] === 'cod' ? $codFeeCfg : 0;
            $total    = $subtotal + $delivery + $codFee;

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
                'country' => $data['customer']['country'] ?? 'AE',
            ];

            $order = Order::create([
                'order_number'     => $this->nextOrderNumber(),
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
                'tax_total'        => 0,
                'total'            => $total,
                'shipping_method'  => $data['ship_method'] ?? 'standard',
                'payment_method'   => $data['method'],
            ]);

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
                $order->forceFill(['status' => 'failed'])->save();

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
     * Sequential, continuing from whatever is already there.
     *
     * The same rule as Store\CheckoutController::nextOrderNumber(): imported
     * WooCommerce orders keep their own numbers, so a new one must not collide
     * with them.
     */
    private function nextOrderNumber(): string
    {
        return (string) (10000 + (int) Order::max('id') + 1);
    }
}
