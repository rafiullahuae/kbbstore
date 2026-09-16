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

    /**
     * Has something in THIS request already loaded the cart's display
     * relations — the lines, their products, those products' brands, the
     * variants and the coupon?
     *
     * Three places load exactly that set and each used to load it from
     * scratch: CartController::loadCart(), CheckoutController::loadCart(), and
     * CartDrawerComposer, which fills the mini-cart panel the layout renders on
     * EVERY page. `load()` re-queries whether or not the relation is already
     * there, so /cart fetched its lines, products and brands twice over and
     * /checkout did the same — six wasted queries between the two pages.
     *
     * The flag, not `relationLoaded()`, because the three column lists differ
     * slightly and "already loaded" has to mean "loaded by one of these", not
     * "loaded by anything at all". The controllers load the superset; the
     * composer is the one that stands down.
     *
     * Every mutator below clears it, so the reload after an add, a quantity
     * change or a removal still happens. That is the whole reason the
     * controllers keep loading unconditionally and only the composer reads
     * this: the composer always runs last, during the render, after whatever
     * the controller did.
     */
    private bool $displayLoaded = false;

    /** Called by whoever has just loaded the display relations. */
    public function markDisplayLoaded(): void
    {
        $this->displayLoaded = true;
    }

    public function displayLoaded(): bool
    {
        return $this->displayLoaded;
    }

    /**
     * Has a create:false lookup in this request already come back empty?
     *
     * "No cart" is an answer too, and not remembering it cost a second
     * `select * from carts where token = ? and status = ?` on EVERY page: the
     * layout composer asks once for the header badge and the drawer composer
     * asks again a moment later, and with nothing to memoise both went to the
     * database.
     *
     * The case is not rare. A cart is marked `converted` the moment an order is
     * placed, and the browser keeps the cookie, so every page a customer visits
     * after checking out — until something creates them a new cart — carries a
     * token that matches no active row. Same for a cart the cleanup job has
     * abandoned.
     *
     * Only ever consulted for create:false. A create:true call still goes
     * through resolve(), which is what the original comment here was protecting:
     * remembering the miss must never stop a cart being created later in the
     * same request.
     */
    private bool $resolvedMiss = false;

    /** The active cart for this request, created on demand. */
    public function current(Request $request, bool $create = true): ?Cart
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->resolvedMiss && ! $create) {
            return null;
        }

        $cart = $this->resolve($request, $create);

        // Only remember a real cart. Caching "no cart" would stop one being
        // created later in the same request -- hence $resolvedMiss, which is
        // deliberately a different fact and only answers create:false.
        if ($cart !== null) {
            $this->resolved = $cart;
        } elseif (! $create) {
            $this->resolvedMiss = true;
        }

        return $cart;
    }

    /** Forget the memo — used after a merge swaps one cart for another. */
    public function forget(): void
    {
        $this->resolved = null;
        $this->resolvedMiss = false;
        $this->displayLoaded = false;
    }

    /**
     * The cart already resolved during THIS request, or null.
     *
     * Never queries, never creates, never reads the cookie. It exists because
     * the cookie is not a reliable answer to "does this visitor have a cart"
     * on the one request that matters most: the first add of a fresh session
     * creates the cart here and queues its cookie onto the RESPONSE, so the
     * request itself still carries none. Anything rendering later in that same
     * request — the drawer composer, in particular — has to ask the service
     * what it already found rather than re-reading an incoming cookie that
     * cannot exist yet.
     */
    public function resolved(): ?Cart
    {
        return $this->resolved;
    }

    /**
     * The cart row, and nothing else.
     *
     * This used to be `Cart::with('items.product', 'items.variant')`, which
     * fetched every line and every whole product row — `description` is a
     * longText, `seo`, `meta_feed` and `custom_tabs` are json — on every page
     * that carries a cart cookie. Nothing downstream kept them: both
     * CartController::loadCart() and CheckoutController::loadCart() call
     * `load()` on the same relations a moment later with a narrow column list,
     * and `load()` re-queries regardless, so the eager set was fetched and then
     * immediately overwritten. Two queries and several KB a page, discarded.
     *
     * Whatever still needs the lines asks for them: itemCount() reads
     * `$cart->items` (one batched query), totals() calls loadMissing() for the
     * products, variants and coupon before it prices anything, and the two
     * controllers load the display set explicitly. None of that is per-row.
     */
    private function resolve(Request $request, bool $create): ?Cart
    {
        $customer = $request->user('customer');

        if ($customer) {
            $cart = Cart::query()
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
            $cart = Cart::query()
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
        $this->resolvedMiss = false;

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
        // The lines just changed, so whatever was loaded for display is stale.
        $this->displayLoaded = false;
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
        $this->displayLoaded = false;
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
        $this->displayLoaded = false;
        $cart->items()->where('id', $itemId)->delete();
        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    public function clear(Cart $cart): void
    {
        $this->displayLoaded = false;
        $cart->items()->delete();
        $cart->forceFill(['coupon_id' => null, 'last_activity_at' => now()])->save();
    }

    /**
     * Take what this basket needs off the shelf, or refuse the whole order.
     *
     * THE GAP THIS FILLS. Both places that put something in a basket check
     * stock at that moment — Store\CartController::add() and
     * Store\CheckoutController::browsedAdd() both refuse anything whose
     * stock_status is not `instock`. The last step, where the money is actually
     * taken, checked nothing: place() walked $cart->items and wrote order
     * lines. A basket added on Monday was sold on Friday whether or not the
     * product still existed, and nothing in the application had ever reduced a
     * counted stock figure, so one unit could be sold to everyone who reached
     * the checkout.
     *
     * THE DECISION IS TO REFUSE THE WHOLE ORDER, and it is a decision about
     * behaviour rather than a repair. The alternatives were:
     *
     *   - drop the unavailable line and take the rest. The shopper then pays
     *     for a basket they never agreed to, at a total they never saw — and
     *     silently, because by then the confirmation is already written.
     *   - take the order and flag it. That is the shop accepting money for
     *     something it knows it cannot ship, and someone has to ring the
     *     customer afterwards.
     *
     * Both move money against an order the customer did not confirm. A refusal
     * costs this sale — that cost is real and is the reason this is a decision
     * — but it happens BEFORE the gateway is touched, the basket survives
     * untouched, and the shopper is told in words which product is gone and
     * what to do about it. It is also exactly what the coupon path next door
     * already does when a code runs out mid-checkout, so one situation has one
     * shape.
     *
     * MUST RUN INSIDE THE PLACING TRANSACTION, and says so rather than hoping.
     * A decrement that can commit on its own would take units off the shelf for
     * an order that then rolls back on the next statement.
     *
     * THE ROWS ARE RE-READ BY KEY, never taken off the cart's own instances.
     * Every storefront path loads its lines through a narrow column list —
     * CheckoutController::LINE_COLUMNS has `stock_status` and neither
     * `manage_stock` nor `stock`, and the variant is loaded as
     * `id,product_id,sku,price,sale_price,image,stock_status`. On those
     * instances `manage_stock` reads null, which is falsy, so a check written
     * against them would decide that NOTHING in the shop counts stock and pass
     * every basket. That is the identical trap CouponService::lockForRedemption()
     * documents, and the reason it re-reads the coupon.
     *
     * WHICH SHELF A LINE COMES OFF. A variant that counts its own stock is its
     * own shelf; a variant that does not falls back to the parent product's,
     * which is how a variable product with one shared stock figure behaves.
     * Products that count no stock at all (`manage_stock` off) are not counted,
     * not decremented and not marked — the only thing asked of them is the
     * same `stock_status` question the add-to-basket paths already ask.
     *
     * @throws StockUnavailable  and nothing is written
     */
    public function claimStock(Cart $cart): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'CartService::claimStock() must run inside the transaction that creates the order. '
                . 'A decrement that can commit on its own takes units off the shelf for an order that then rolls back.'
            );
        }

        $cart->loadMissing('items.product', 'items.variant');

        /*
         * Demand is summed PER SHELF before anything is checked.
         *
         * One product can legitimately appear on two lines — the same product
         * added through the cart page and through the checkout's Browsed tab,
         * or a variant line beside a plain one — and checking each line against
         * the shelf on its own lets a basket of 1 + 1 buy a single remaining
         * unit twice over.
         */
        $wanted = [];

        foreach ($cart->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $key = $item->product_variant_id !== null
                ? 'variant:' . $item->product_variant_id
                : 'product:' . $item->product_id;

            $wanted[$key] ??= [
                'product_id' => (int) $item->product_id,
                'variant_id' => $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
                'quantity' => 0,
                // Used only in the refusal sentence, so it is taken from the
                // relations the checkout has already loaded and never fetched.
                'label' => $this->lineLabel($item),
            ];

            $wanted[$key]['quantity'] += max(0, (int) $item->quantity);
        }

        foreach ($wanted as $line) {
            $this->claimOne($line);
        }
    }

    /**
     * One shelf: checked under a lock, then decremented conditionally.
     *
     * BOTH HALVES ARE LOAD-BEARING and they guard different engines.
     *
     * lockForUpdate() is what serialises two MySQL transactions on the same
     * row, the same way CouponService::lockForRedemption() does for a usage
     * limit — without it both read `stock = 1`, both decide there is room, and
     * both write. It is a no-op on SQLite, which takes one database-wide write
     * lock instead.
     *
     * The decrement then repeats the condition in its own WHERE and checks how
     * many rows it changed. That is what makes the sequence correct with no
     * lock at all: an UPDATE ... WHERE stock >= n is atomic in its own right,
     * so if anything did slip between the read and the write, the loser changes
     * no rows and is refused rather than driving the column negative.
     *
     * @param  array{product_id:int, variant_id:?int, quantity:int, label:string}  $line
     *
     * @throws StockUnavailable
     */
    private function claimOne(array $line): void
    {
        $quantity = $line['quantity'];

        if ($quantity < 1) {
            return;
        }

        // Soft-deleted products are excluded by the model's own scope, so a
        // product the owner has binned since it went in the basket arrives here
        // as null and is refused rather than sold.
        $product = Product::whereKey($line['product_id'])->lockForUpdate()->first();

        if ($product === null) {
            throw new StockUnavailable($line['label'] . ' is no longer available. Please remove it from your basket to continue.');
        }

        $variant = $line['variant_id'] !== null
            ? ProductVariant::whereKey($line['variant_id'])->lockForUpdate()->first()
            : null;

        if ($line['variant_id'] !== null && $variant === null) {
            throw new StockUnavailable($line['label'] . ' is no longer available. Please remove it from your basket to continue.');
        }

        /*
         * The same question the add-to-basket paths ask, asked again here:
         * `($variant?->stock_status ?? $product->stock_status) !== 'instock'`.
         * It applies whether or not stock is counted, because this is the shape
         * a sell-out actually takes in this shop — the owner flips the status by
         * hand in Store → Products — and a product nobody may add to a basket
         * is not one anybody may pay for either.
         */
        if (($variant?->stock_status ?? $product->stock_status) !== 'instock') {
            throw new StockUnavailable($line['label'] . ' is sold out. Please remove it from your basket to continue.');
        }

        // The shelf: the variant's own when it counts stock, otherwise the
        // parent's, otherwise nothing is counted and there is nothing to do.
        if ($variant !== null && $variant->manage_stock) {
            $this->takeFromShelf('product_variants', (int) $variant->id, $variant->stock, $quantity, $line['label']);

            return;
        }

        if ($product->manage_stock) {
            $this->takeFromShelf('products', (int) $product->id, $product->stock, $quantity, $line['label']);
        }
    }

    /**
     * Decrement one counted shelf, and mark it sold out when it empties.
     *
     * MARKING IT MATTERS AS MUCH AS THE DECREMENT. Leaving stock_status at
     * `instock` over a zero shelf is what makes the shop go on advertising a
     * product it cannot ship: every card, every listing and the product page
     * itself read that column, and the next shopper gets all the way to Place
     * order before anything says no. Setting it here is the difference between
     * one refused checkout and a queue of them.
     *
     * It is deliberately one-way. Putting stock back — a restock, a cancelled
     * order — is the owner's own action in Store → Products, and there is no
     * single choke point for an order leaving `processing` at which a return
     * could be hooked; the note in CheckoutController::place() about
     * releaseRedemptions() sets out why that has to come first.
     *
     * @throws StockUnavailable
     */
    private function takeFromShelf(string $table, int $id, mixed $have, int $quantity, string $label): void
    {
        // NULL on a row that says it counts stock is zero, not "unlimited" —
        // the same reading the admin's own list takes (CatalogProductsApi
        // Controller prints `stock` as 0 for a managed product with no figure).
        $have = (int) ($have ?? 0);

        $changed = DB::table($table)
            ->where('id', $id)
            ->where('stock', '>=', $quantity)
            ->update(['stock' => DB::raw('stock - ' . $quantity)]);

        if ($changed !== 1) {
            throw new StockUnavailable($this->shortfall($label, $have));
        }

        if ($have - $quantity <= 0) {
            DB::table($table)->where('id', $id)->update(['stock_status' => 'outofstock']);
        }
    }

    /** The sentence a shopper reads when the shelf cannot cover their basket. */
    private function shortfall(string $label, int $have): string
    {
        if ($have < 1) {
            return $label . ' is sold out. Please remove it from your basket to continue.';
        }

        return 'Only ' . $have . ' of ' . $label . ' ' . ($have === 1 ? 'is' : 'are')
            . ' left. Please reduce the quantity in your basket to continue.';
    }

    /**
     * What to call this line in a refusal, in the shopper's terms.
     *
     * Off the relations the checkout has already loaded — never a fresh query,
     * and never the raw slug or id. attributeValues is part of
     * CheckoutController::loadCart()'s eager load, so a variant's size or shade
     * is free here; a line whose product row has gone falls back to the wording
     * the order snapshot uses for the same case.
     */
    private function lineLabel(CartItem $item): string
    {
        $name = trim((string) ($item->product?->name ?? 'Item'));

        if ($item->relationLoaded('variant')
            && $item->variant !== null
            && $item->variant->relationLoaded('attributeValues')) {
            $label = trim($item->variant->label());

            if ($label !== '') {
                return $name . ' (' . $label . ')';
            }
        }

        return $name;
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

        /*
         * THE BAR IS MEASURED ON THE SAME SUBTOTAL THAT QUALIFIES FOR THE RATE.
         *
         * This measured `$afterDiscount` while the rate that is actually
         * CHARGED is chosen from the GROSS subtotal: every caller of
         * ShippingService::ratesFor() — Store\CheckoutController::place(), its
         * rateContext(), Api\CheckoutController and ManualOrderBuilder::price()
         * — passes the pre-coupon sum. So the two halves of the same screen
         * disagreed:
         *
         *     basket AED 220, coupon -AED 40, free over AED 199
         *       charged   -> qualified on AED 220 -> delivery AED 0
         *       displayed -> "You're AED 19.00 away from free delivery", 90%
         *
         * The shopper was asked to spend AED 19 more for something the line
         * item beside it had already given them free.
         *
         * THE CHARGE IS NOT WHAT CHANGED, and deliberately so. Qualifying on
         * the gross subtotal is the customer-favouring half of the split
         * ManualOrderBuilder::price() documents — it is what stops a coupon
         * pushing a basket back under the threshold and re-imposing a delivery
         * charge the shopper had already earned. That is right, and it stays.
         *
         * What was wrong was this label claiming the same motive — "a coupon
         * should not push a customer back below the threshold" — while
         * measuring the one basis that can push them below it. Gross is never
         * below net, so this is never the less generous reading; it is simply
         * the honest one.
         */
        $threshold = $this->shipping->freeShippingThreshold($country, $state);
        $toFree = $threshold === null ? null : max(0, $threshold - $subtotal);

        /*
         * FREE DELIVERY FROM THE COUPON ITSELF.
         *
         * coupons.free_shipping arrived in the WooCommerce import and, until
         * this lane, nothing read it: the coupon editor drew the box disabled
         * and said on the screen that the shop priced delivery only from the
         * shipping method and the order-value threshold. Now the flag means
         * what it says.
         *
         * Zeroed HERE rather than in ShippingService because this is the only
         * place that knows both halves. ratesFor() is asked what a destination
         * costs and has never been handed a cart, let alone a coupon; teaching
         * it about coupons would put a discount rule inside the method the
         * header bar, the shipping admin and the manual-order builder all call
         * for a plain price list. Here the rate has already been chosen and is
         * simply not charged.
         *
         * And zeroing the LINE, not dropping the rate, is deliberate: the
         * shopper still picked a delivery method and the order still records
         * which one, exactly as an order over the free-delivery threshold
         * does. It is the cost that goes to zero.
         *
         * Every caller of totals() gets this for free — the cart page, the
         * drawer, the checkout summary, ManualOrderBuilder and
         * Store\CheckoutController::place() — which is what stops the cart
         * page and the order that follows it disagreeing.
         */
        $shipping = $shippingCost ?? 0;

        if ($cart->coupon && $this->coupons->grantsFreeShipping($cart->coupon)) {
            $shipping = 0;
        }

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
            // Same basis as $toFree above, or the bar's fill and its caption
            // would tell two different stories about one basket.
            'free_shipping_percent' => $threshold ? min(100, (int) round($subtotal / $threshold * 100)) : null,
            // Display only — never added to the total. (D-64)
            'vat' => $this->vat->line($total),
        ];
    }
}
