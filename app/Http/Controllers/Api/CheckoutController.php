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

            // find-or-create the customer by email
            $c = Customer::firstOrCreate(
                ['email' => $data['customer']['email']],
                [
                    'name'            => $data['customer']['name'],
                    'phone'          => $data['customer']['phone'] ?? null,
                    'emirate'        => $data['customer']['emirate'] ?? null,
                    'default_address'=> $data['customer']['address'] ?? null,
                    'created_at'     => now()->toISOString(),
                ]
            );

            $order = Order::create([
                'customer_id' => $c->id,
                'status'      => $data['method'] === 'cod' ? 'processing' : 'pending',
                'billing'     => json_encode($data['customer']),
                'shipping'    => json_encode($data['customer']),
                'ship_method' => $data['ship_method'] ?? 'standard',
                'origin'      => 'Direct',
                'subtotal'    => $subtotal,
                'delivery'    => $delivery,
                'cod_fee'     => $codFee,
                'total'       => $total,
                'created_at'  => now()->toISOString(),
                'updated_at'  => now()->toISOString(),
            ]);

            foreach ($lines as $l) {
                OrderItem::create(['order_id' => $order->id] + $l);
            }

            // hand off to payment
            if ($data['method'] === 'cod') {
                return response()->json([
                    'ok'       => true,
                    'order_id' => $order->id,
                    'orderId'  => $order->id, // storefront reads camelCase (verbatim frontend)
                    'redirect' => "/checkout/success?order={$order->id}",
                ], 201);
            }

            // Phase 3: replace with the provider's hosted-session URL + webhook verification.
            return response()->json([
                'ok'        => true,
                'order_id'  => $order->id,
                'orderId'   => $order->id,
                'method'    => $data['method'],
                'redirect'  => "/checkout/pending?order={$order->id}",
                'note'      => 'Hosted payment session wired in Phase 3.',
            ], 201);
        });
    }
}
