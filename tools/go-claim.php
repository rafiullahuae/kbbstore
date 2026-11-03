<?php
/*
 * Lane GO preview helper (tools/go-tinker.sh). GO_DO selects:
 *   token   print a fresh abandoned-cart claim token for a AED 404 bag
 *   fill    put the serum and the cream into the newest basket holding a claim
 *   ptoken  a pending order and a reminder-1 claim token for it
 */
use Illuminate\Support\Facades\DB;

$do = getenv('GO_DO') ?: 'token';

if ($do === 'token') {
    $o = \App\Services\GiftOffers\GiftOffers::forBasket([['unit_price' => 40400, 'quantity' => 1]], null);
    echo 'TOKEN=' . preg_replace('~^.*/gift-offer/c/~', '', (string) ($o['href'] ?? '')) . "\n";
}

if ($do === 'sizes') {
    // Bytes of the real emails, with and without the gift (Gmail clips at 102 KB).
    $items = [['name' => 'Glow Deep Serum : Rice + Alpha-Arbutin 30ml', 'slug' => 'go-glow-serum', 'quantity' => 1, 'unit_price' => 18500],
        ['name' => 'Dynasty Cream 50ml', 'slug' => 'go-dynasty-cream', 'quantity' => 1, 'unit_price' => 21900]];
    $mail = fn (?array $o) => strlen((new \App\Mail\CartRecoveryReminder('Your basket', 'Hi Layla, you left a few favourites in your bag.', $items, url('/cart/'), url('/u'), $o))->render());
    $out = ['cart_without' => $mail(null), 'cart_with' => $mail(\App\Services\GiftOffers\GiftOffers::forBasket($items, null, true))];
    $order = \App\Models\Order::query()->with('items')->latest('id')->first();
    if ($order !== null) {
        $out['reminder_without'] = strlen((new \App\Mail\OrderPaymentReminder($order, 1, null))->render());
        $out['reminder_with'] = strlen((new \App\Mail\OrderPaymentReminder($order, 1, \App\Services\GiftOffers\GiftOffers::forOrder($order, 1, true)))->render());
    }
    echo 'SIZES=' . json_encode($out) . "\n";
}

if ($do === 'unclaim') {
    $cart = \App\Models\Cart::query()->whereNotNull('gift_offer_claim_id')->latest('id')->first();
    $cart->forceFill(['gift_offer_claim_id' => null])->save();
    echo 'CART=' . $cart->id . "\n";
}

if ($do === 'fill') {
    $cart = \App\Models\Cart::query()->whereNotNull('gift_offer_claim_id')->latest('id')->first();
    $cart->items()->delete();
    foreach (['go-glow-serum', 'go-dynasty-cream'] as $slug) {
        $p = \App\Models\Product::query()->where('slug', $slug)->first();
        $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => (int) $p->price]);
    }
    echo 'CART=' . $cart->id . "\n";
}
