<?php
/*
 * Lane GQ preview helper, run with GO_LANE=lane-gq sh tools/go-tinker.sh.
 *
 *   GQ_DO=confirm  an order for the newest basket holding a claim, its gift
 *                  written by GiftOffers::attach() -- the same call Place order
 *                  makes -- and the real OrderConfirmation rendered to GQ_OUT.
 *                  Prints ORDER=<number> and the gift line's quantity.
 */
use Illuminate\Support\Facades\DB;

if ((getenv('GQ_DO') ?: '') === 'confirm') {
    $cart = \App\Models\Cart::query()->whereNotNull('gift_offer_claim_id')->latest('id')->first();
    $claim = DB::table('gift_offer_claims')->where('id', $cart->gift_offer_claim_id)->first();
    $order = DB::transaction(function () use ($cart, $claim) {
        $sub = (int) $cart->items()->get()->sum(fn ($i) => $i->unit_price * $i->quantity);
        $o = \App\Models\Order::create(['order_number' => (string) (20000 + random_int(1, 9999)), 'email' => 'layla@example.test', 'status' => 'processing', 'total' => $sub + 2000, 'subtotal' => $sub,
            'shipping_total' => 2000, 'currency' => 'AED', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery', 'locale' => 'en',
            'billing_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'country' => 'AE', 'city' => 'Dubai'],
            'shipping_address' => ['first_name' => 'Layla', 'last_name' => 'Hassan', 'country' => 'AE', 'city' => 'Dubai', 'line1' => 'Marina Heights']]);
        foreach ($cart->items()->with('product')->get() as $i) {
            \App\Models\OrderItem::create(['order_id' => $o->id, 'product_id' => $i->product_id, 'name' => $i->product->name, 'brand' => 'Beauty of Joseon', 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'subtotal' => $i->unit_price * $i->quantity, 'total' => $i->unit_price * $i->quantity]);
        }
        $units = app(\App\Services\GiftOffers\GiftOffers::class)->attach($o, $claim);
        $o->forceFill(['free_gift_count' => $units])->saveQuietly();

        return $o->fresh();
    });
    file_put_contents((string) getenv('GQ_OUT'), (new \App\Mail\OrderConfirmation($order))->render());
    echo 'ORDER=' . $order->order_number . "\n";
    echo 'GIFTQTY=' . (int) $order->gifts()->value('quantity') . "\n";
}
