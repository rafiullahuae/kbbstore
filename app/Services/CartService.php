<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Services\BundleService;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;
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
        /*
         * ▲ A VARIABLE PRODUCT WITH NO OPTION CHOSEN IS AED 0, AND THIS LINE
         *   IS WHY IT HAS TO BE REFUSED HERE RATHER THAN ON THE TILE.
         *
         * `$variant?->effectivePrice() ?? $product->effectivePrice()` below is
         * the whole defect in one expression. A variable parent carries no
         * price of its own — WooCommerce keeps the figures on the variations —
         * and Product::effectivePrice() ends `return (int) $this->price` with
         * `$this->price` NULL, so the ?? falls through to ZERO and this method
         * wrote a basket line at nothing. The checkout then took the order:
         * nothing between here and place() ever asked what the line cost.
         *
         * Three live ways in, and the tiles were only two of them.
         * components/product-grid.blade.php drew an Add to cart button on every
         * tile including variable ones, the checkout's "you were looking at"
         * strip did the same, and /api/cart/add accepts a product_id with an
         * OPTIONAL variant_id — so a fetch call, a tab left open, or a tile
         * cached before this ships all reach this method directly. Fixing the
         * buttons alone would have left the door open; this is the door.
         *
         * THE CALLER STILL CHECKS FIRST. Store\CartController::add(),
         * Store\CheckoutController::browsedAdd() and
         * Services\ManualOrderBuilder each test Product::requiresVariant()
         * before calling this and answer in the shape their own endpoint
         * already uses, so a shopper gets a sentence rather than a stack trace.
         * This throw is for the caller nobody has written yet.
         */
        if ($variant === null && $product->requiresVariant()) {
            throw new VariantRequired('Choose an option before adding this product to your bag.');
        }

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
     * MUST RUN INSIDE THE PLACING TRANSACTION. StockClaim::claim() enforces
     * that itself rather than hoping: a decrement that can commit on its own
     * would take units off the shelf for an order that then rolls back on the
     * next statement.
     *
     * THE WORK ITSELF IS NOT HERE ANY MORE. It moved to App\Services\StockClaim
     * when the unauthenticated Api\CheckoutController::session() — which writes
     * real orders and has no Cart to hand — needed the same guarantee. What is
     * left here is the part that is genuinely about a Cart: turning its lines
     * into the (product, variant, quantity, label) list that routine takes.
     * Two implementations of "take the units off the shelf" drift, and the one
     * that drifts is the one nobody is looking at.
     *
     * THE ORDER IS PASSED so the claim can be recorded against it and given
     * back if the order is later cancelled. Nothing is recorded without one and
     * nothing recorded without one can ever be returned — see StockClaim's
     * class comment and the order_stock_claims migration.
     *
     * @throws StockUnavailable  and nothing is written
     */
    public function claimStock(Cart $cart, ?Order $order = null): void
    {
        $cart->loadMissing('items.product', 'items.variant');

        $lines = [];

        foreach ($cart->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $lines[] = [
                'product_id' => (int) $item->product_id,
                'variant_id' => $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
                'quantity' => (int) $item->quantity,
                // Taken from the relations the checkout has already loaded, so
                // the refusal sentence costs no query. Summing two lines that
                // share a shelf is StockClaim's job, not this loop's.
                'label' => $this->lineLabel($item),
            ];
        }

        app(StockClaim::class)->claim($lines, $order?->id !== null ? (int) $order->id : null);
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
     * The width a whole ledger prints at: totals()' own, widened by whatever
     * the caller adds on top of it — the COD surcharge and the gift-wrapping
     * fee, both of which the templates add after totals() returns.
     *
     * ONE PLACE, so the checkout summary, the cart page and the JSON the
     * country-change refresh returns cannot pick three different widths for
     * one basket. See the `decimals` key in totals() for what the width means
     * and why it exists.
     */
    public function ledgerDecimals(array $totals, int ...$extra): int
    {
        return max(
            (int) ($totals['decimals'] ?? 0),
            Money::receiptDecimals(...$extra),
        );
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

        /*
         * THE TAXABLE BASE, and the one place it is decided.
         *
         * subtotal - discount + delivery. AFTER the coupon, deliberately: VAT
         * is due on the consideration the customer actually pays, and charging
         * it on a discount nobody paid is a real financial error rather than a
         * display one. It is also exactly the figure VatDisplay was handed
         * before this lane existed, so the printed line does not move by a fil
         * when the package is applied.
         *
         * The COD surcharge and the gift-wrapping fee are OUTSIDE it, because
         * they are added by the order writers after this method returns and
         * always have been. Preserving that is what lets one computation, here,
         * serve all four paths that write an order without any of them
         * disagreeing — see the class header of App\Support\VatDisplay.
         */
        $taxableBase = $afterDiscount + $shipping;

        $tax = $this->vat->quote($taxableBase, $country);

        /*
         * D-64 WAS OVERTURNED BY THE OWNER ON 2026-09-16 and this is the line
         * where it happens: with tax_mode = 'live' and a country on an
         * `exclusive` basis, `total` is now HIGHER than subtotal - discount +
         * delivery. In every other state — and in the shipped default state —
         * $tax['added'] is false and this is the identical sum it always was.
         *
         * App\Support\VatDisplay's header carries the owner's three messages
         * verbatim and the reasoning in full. This is not an accident and it
         * is not to be quietly reverted.
         */
        $total = $tax['total'];

        return [
            'item_count' => $cart->itemCount(),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_code' => $couponCode,
            'shipping' => $shipping,
            /*
             * The taxable base, carried out so an order writer can record what
             * the tax was computed ON without recomputing it and risking a
             * different answer.
             */
            'taxable_base' => $taxableBase,
            /*
             * THE ORDER'S OWN RECORD OF ITS TAX, which is the single most
             * important thing this lane ships. `tax_charged` goes to
             * orders.tax_total, `tax_rate` and `tax_basis` go to the columns of
             * those names — so an invoice reprinted next year reprints at the
             * rate that was charged, not at whatever the shop's settings say by
             * then. Both are null in display mode, which leaves every reader
             * downstream on exactly the branch it took before this lane.
             */
            'tax_charged' => $tax['charged'],
            'tax_rate' => $tax['mode'] === \App\Support\VatDisplay::MODE_LIVE ? $tax['rate'] : null,
            'tax_basis' => $tax['mode'] === \App\Support\VatDisplay::MODE_LIVE ? $tax['basis'] : null,
            'tax_added' => $tax['added'],
            'total' => $total,
            'free_shipping_threshold' => $threshold,
            'free_shipping_remaining' => $toFree,
            'free_shipping_unlocked' => $threshold !== null && $toFree === 0,
            /*
             * Same basis as $toFree above, or the bar's fill and its caption
             * would tell two different stories about one basket.
             *
             * 100 IS RESERVED FOR ACTUALLY UNLOCKED, and it is read off
             * $toFree — the same value `free_shipping_unlocked` is read off —
             * rather than derived a second time from a division.
             *
             * `min(100, (int) round($subtotal / $threshold * 100))` gave 100 to
             * a basket 30 fils short of a AED 199 threshold: 19870/19900 is
             * 99.85%, which rounds up. The bar filled completely, the "is-
             * unlocked" styling did not fire, and the caption beside it read
             * "You're AED 0.30 away from free delivery" — a full progress bar
             * against a milestone that had not been reached, next to the
             * sentence saying so. A shopper reading the bar rather than the
             * caption checks out expecting free delivery and is charged AED 20.
             *
             * A bar is a claim about whether something is done, and rounding
             * one up to its milestone makes that claim untrue in exactly the
             * range where it matters most — the last fil. So: 100 when the
             * threshold is met, and at most 99 while any of it is still owed.
             * round() is kept below that ceiling, so nothing else about the
             * fill moves.
             */
            'free_shipping_percent' => $threshold
                ? ($toFree === 0 ? 100 : min(99, (int) round($subtotal / $threshold * 100)))
                : null,
            /*
             * THE PRINTED LINE, computed on the base rather than on the total.
             *
             * On an inclusive or flat basis the two are the same number, which
             * is why nothing moves for an existing shop. On an exclusive basis
             * they are not: the tax is 15% OF the base, not 15% of a total that
             * already contains it, and computing it on $total would print
             * 13.04 where 15.00 was charged.
             *
             * The DESTINATION country, resolved above from the argument or the
             * cart, so the rate follows the address rather than the shop's
             * default. This is the one place the country has to reach
             * VatDisplay: every caller of totals() goes through it — the cart
             * page, the drawer, the checkout summary, the country-change
             * refresh behind /api/checkout/rates, and ManualOrderBuilder — so
             * none of them can disagree with another about what the receipt
             * says.
             */
            'vat' => $this->vat->line($taxableBase, $country),
            /*
             * THE WIDTH EVERY ROW OF THIS LEDGER PRINTS AT — Lane FA.
             *
             * The lane began with a basket of AED 90.40 carrying a 60-fil
             * discount that printed
             *
             *     Subtotal AED 90 / − AED 1 / Total AED 90
             *
             * because Money::displayDecimals() is 0 on this store and each row
             * was rounded on its own on the way to the screen. The policy
             * removes the cause for anything the owner sets — his prices are
             * whole dirhams now, so the rows are whole and printing them at 0
             * decimals states them exactly. This key is what covers the rest:
             * a basket still holding a product priced in fils before the
             * policy existed, or a coupon imported from WooCommerce.
             *
             * Money::receiptDecimals() answers 0 when every figure here is a
             * whole dirham — which is the ordinary case, so the shop looks
             * exactly as the owner asked — and the currency's full precision
             * the moment one of them is not, for the WHOLE column at once, so
             * the figures still sum.
             *
             * THE COD AND GIFT FEES ARE NOT IN IT, because they are added by
             * the templates after this method returns (see the note on
             * $taxableBase above). Each of those two is a settings value the
             * whole-dirham rule already refuses unless it is whole, so they
             * cannot be the reason a column needs widening; the template ORs
             * them in anyway, through ledgerDecimals(), rather than resting on
             * that.
             *
             * Read by the Blade partials AND by the JSON the country-change
             * refresh returns, so the server decides the width once and the
             * live-updating rows cannot disagree with the rendered ones. There
             * is no arithmetic in checkout.js to keep in step — it assigns the
             * strings this side formats.
             */
            'decimals' => Money::receiptDecimals(
                $subtotal, $discount, $shipping, $taxableBase, $total,
                $threshold ?? 0, $toFree ?? 0,
            ),
        ];
    }
}
