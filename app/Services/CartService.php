<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Services\BundleService;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\VatDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The cart — ledger item F-01.
 *
 * The previous build had no cart at all: each page declared its own JavaScript
 * array, nothing persisted, and the checkout ran on a hard-coded basket. This is
 * server-side and durable: guests are tracked by a cookie token, signed-in
 * customers by their account, and a guest basket merges into the account on login
 * rather than being thrown away.
 *
 * Prices are snapshotted onto the line at add time, so a price change mid-session
 * cannot silently alter what someone is about to pay.
 */
class CartService
{
    public const COOKIE = 'kbb_cart';

    private const COOKIE_DAYS = 30;

    public function __construct(
        private CouponService $coupons,
        private ShippingService $shipping,
        private SettingsService $settings,
        private VatDisplay $vat,
    ) {}

    /**
     * Resolved once per request.
     *
     * Without this, a single add-to-cart resolves the cart twice: once to add
     * the item, and again to render the response. The second call re-reads the
     * cookie from the REQUEST, where the cookie queued moments earlier does not
     * exist yet — so it created a second, empty cart and rendered that. The item
     * went into the first cart and the customer was shown the second.
     */
    private ?Cart $resolved = null;

    /** The active cart for this request, created on demand. */
    public function current(Request $request, bool $create = true): ?Cart
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $cart = $this->resolve($request, $create);

        // Only remember a real cart. Caching "no cart" would stop one being
        // created later in the same request.
        if ($cart !== null) {
            $this->resolved = $cart;
        }

        return $cart;
    }

    /** Forget the memo — used after a merge swaps one cart for another. */
    public function forget(): void
    {
        $this->resolved = null;
    }

    private function resolve(Request $request, bool $create): ?Cart
    {
        $customer = $request->user('customer');

        if ($customer) {
            $cart = Cart::with('items.product', 'items.variant')
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if ($cart) {
                $this->rememberCookie($cart->token);

                return $cart;
            }
        }

        $token = $request->cookie(self::COOKIE);

        if ($token) {
            $cart = Cart::with('items.product', 'items.variant')
                ->where('token', $token)
                ->where('status', 'active')
                ->first();

            if ($cart) {
                // Sliding expiry: a customer who returns every week should not
                // lose their basket because their first visit was 31 days ago.
                $this->rememberCookie($cart->token);

                return $cart;
            }
        }

        return $create ? $this->create($customer?->id) : null;
    }

    /**
     * The unit price to store on a line.
     *
     * Recomputed here from the catalogue and the bundle tiers — never taken
     * from the request — so a tampered quantity or price cannot buy at a rate
     * the store does not offer. (Rule 23)
     */
    public function unitPriceFor($product, $variant, int $quantity): int
    {
        $base = $variant?->effectivePrice() ?? $product->effectivePrice();

        // Variants carry their own pricing; quantity bundles apply to simple
        // products only, which is how the offer is presented on the page.
        if ($variant !== null) {
            return $base;
        }

        return app(BundleService::class)->unitFor($base, $quantity);
    }

    public function create(?int $customerId = null): Cart
    {
        $cart = Cart::create([
            'token' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'currency' => 'AED',
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        // Without this the browser never learns the token, so the next request
        // finds nothing and builds another empty cart. Reading a cookie that is
        // never written is a cart that silently forgets everything.
        $this->rememberCookie($cart->token);

        return $cart;
    }

    /**
     * Queue the cart cookie. Called on create and again whenever an existing
     * cart is found, which gives it a sliding 30-day life rather than expiring
     * 30 days after the first ever visit.
     *
     * httpOnly: JavaScript has no reason to read it, and that keeps it out of
     * reach of anything injected into the page.
     */
    private function rememberCookie(string $token): void
    {
        cookie()->queue(cookie(
            self::COOKIE,
            $token,
            self::COOKIE_DAYS * 24 * 60,
            null,
            null,
            null,
            true,
            false,
            'lax'
        ));
    }

    public function add(Cart $cart, Product $product, int $quantity = 1, ?ProductVariant $variant = null): CartItem
    {
        $quantity = max(1, min(99, $quantity));
        $unitPrice = $variant?->effectivePrice() ?? $product->effectivePrice();

        return DB::transaction(function () use ($cart, $product, $variant, $quantity, $unitPrice) {
            $item = $cart->items()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->first();

            if ($item) {
                $item->quantity = min(99, $item->quantity + $quantity);
                // The bundle rate depends on the FINAL quantity, so adding a
                // second unit has to reprice the whole line.
                $item->unit_price = $this->unitPriceFor($product, $variant, $item->quantity);
                $item->save();
            } else {
                $item = $cart->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $quantity,
                    'unit_price' => $this->unitPriceFor($product, $variant, $quantity),
                ]);
            }

            $cart->forceFill(['last_activity_at' => now()])->save();

            return $item;
        });
    }

    public function updateQuantity(Cart $cart, int $itemId, int $quantity): void
    {
        $item = $cart->items()->find($itemId);
        if (! $item) {
            return;
        }

        if ($quantity <= 0) {
            $item->delete();
        } else {
            $qty = min(99, $quantity);

            // Changing the quantity can cross a bundle threshold in either
            // direction, so the unit price is recomputed rather than kept.
            $item->update([
                'quantity' => $qty,
                'unit_price' => $this->unitPriceFor($item->product, $item->variant, $qty),
            ]);
        }

        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    public function remove(Cart $cart, int $itemId): void
    {
        $cart->items()->where('id', $itemId)->delete();
        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->forceFill(['coupon_id' => null, 'last_activity_at' => now()])->save();
    }

    /**
     * Fold a guest cart into the customer's cart at login.
     *
     * Quantities are summed rather than replaced, because someone who added two
     * of something as a guest and one while signed in on another device meant to
     * have three, not one. Nothing is ever silently discarded.
     */
    public function mergeGuestCart(Cart $guest, int $customerId): Cart
    {
        return DB::transaction(function () use ($guest, $customerId) {
            $target = Cart::where('customer_id', $customerId)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if (! $target) {
                $guest->forceFill(['customer_id' => $customerId, 'last_activity_at' => now()])->save();
                $this->resolved = null;

                return $guest->fresh(['items.product', 'items.variant']);
            }

            foreach ($guest->items as $item) {
                $existing = $target->items()
                    ->where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->first();

                if ($existing) {
                    $existing->update(['quantity' => min(99, $existing->quantity + $item->quantity)]);
                } else {
                    $target->items()->create([
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                    ]);
                }
            }

            $guest->items()->delete();
            $guest->update(['status' => 'merged']);
            $target->forceFill(['last_activity_at' => now()])->save();

            $this->rememberCookie($target->token);
            $this->resolved = null;

            return $target->fresh(['items.product', 'items.variant']);
        });
    }

    /**
     * Every figure the cart, drawer and checkout display, computed in one place.
     * All amounts are fils.
     */
    public function totals(Cart $cart, ?string $country = null, ?string $state = null, ?int $shippingCost = null): array
    {
        $cart->loadMissing('items.product', 'items.variant', 'coupon');

        $subtotal = (int) $cart->items->sum(fn ($i) => $i->lineTotal());

        $discount = 0;
        $couponCode = null;
        if ($cart->coupon) {
            $discount = $this->coupons->discountFor($cart->coupon, $cart);
            $couponCode = $cart->coupon->code;
        }

        $afterDiscount = max(0, $subtotal - $discount);
        $country ??= $cart->shipping_country;
        $state ??= $cart->shipping_state;

        // The free-shipping bar measures the discounted subtotal, matching the
        // theme: a coupon should not push a customer back below the threshold.
        $threshold = $this->shipping->freeShippingThreshold($country, $state);
        $toFree = $threshold === null ? null : max(0, $threshold - $afterDiscount);

        $shipping = $shippingCost ?? 0;
        $total = $afterDiscount + $shipping;

        return [
            'item_count' => $cart->itemCount(),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_code' => $couponCode,
            'shipping' => $shipping,
            'total' => $total,
            'free_shipping_threshold' => $threshold,
            'free_shipping_remaining' => $toFree,
            'free_shipping_unlocked' => $threshold !== null && $toFree === 0,
            'free_shipping_percent' => $threshold ? min(100, (int) round($afterDiscount / $threshold * 100)) : null,
            // Display only — never added to the total. (D-64)
            'vat' => $this->vat->line($total),
        ];
    }
}
