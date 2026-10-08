<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Services\BundleService;
use App\Services\CartTracking\CartTracker;
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

    /**
     * Point this browser at a cart it is not currently carrying.
     *
     * ── WHY THIS EXISTS (Lane PLC) ─────────────────────────────────────────
     *
     * A basket is marked `converted` the moment an order is placed, and this
     * service then finds no active cart for that cookie — so the next page that
     * asks with create:true mints a fresh empty one and re-cookies the browser.
     * That is right for an order that went through, and wrong for one that did
     * not: a shopper who abandons at Tabby or Tamara lands on /cart/, is given
     * an empty cart and a new token on the way in, and their real basket is a
     * `converted` row nothing can reach by cookie any more.
     *
     * Store\CheckoutReturnController::restore() puts that row back to `active`
     * and calls this to hand the browser its token again. The pair is exactly
     * what mergeGuestCart() does at the end of a merge — rememberCookie() for
     * the new token, then drop the memo so anything resolving later in this
     * request sees the swap rather than the cart it found first.
     *
     * MEASURED, and this is why it is a method rather than a cookie queued at
     * the call site: the trap was found by pressing the button in a browser and
     * looking at the answer. The offer was written on the request that landed
     * the shopper and the press arrived one request later, by which time the
     * cookie had moved — so "Put my basket back" said "There is nothing to put
     * back". The cookie's name, its thirty days, httpOnly and SameSite=lax are
     * decisions with reasons beside them in rememberCookie(), and a second copy
     * of them in a controller is a second place for them to drift.
     */
    public function adopt(Cart $cart): void
    {
        $this->rememberCookie($cart->token);
        $this->forget();
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

        /*
         * A SET TAKES NO QUANTITY DISCOUNT — the owner, 29 September 2026, asked
         * in as many words: "no there's no bulk discount for sets products."
         *
         * The strip stopped being DRAWN on a set's page when
         * BundleService::forProduct() learnt to answer [] for one. This is the
         * other half, and the lane that did the first half deliberately left it
         * and pinned that it had: changing what a basket is charged is not
         * something to slip into a package, and it needed his decision. It has
         * it.
         *
         * HERE AND NOT IN BundleService::unitFor(), which is where it looks
         * like it belongs. That method is pure arithmetic over a price and a
         * quantity and has no opinion about product types — its own docblock
         * argues the case at length, and CouponService reasons about it the
         * same way. This method already draws exactly this kind of line one
         * branch above, for variants, and for the same reason: an offer the
         * page does not make is an offer the basket must not apply.
         *
         * ▲ WHAT CHANGES ON A LIVE SHOP: a basket that already holds three of a
         * set is repriced at the set's own price the next time its line is
         * touched. That is a price going UP, which is the one direction that
         * needs saying out loud — it is the price on the set's own page, the
         * one the page has been showing since the strip was removed, so the
         * basket now agrees with what the shopper was told.
         */
        /*
         * isSet() READS getAttributes()['type'], so a model loaded with a
         * narrow column list answers FALSE rather than throwing — which on a
         * DISPLAY path is the right shape (draw the strip) and on a PRICING
         * path is the wrong one (charge the discount). This method is the
         * pricing path. So "the column is not loaded" is treated as "I do not
         * know" and answered with one lookup rather than a guess.
         *
         * It costs nothing on every call this application makes today: every
         * caller passes a full model or an `$item->product` relation, so `type`
         * is present and the query never runs. It is here for the caller that
         * has not been written yet.
         */
        $type = $product->getAttributes()['type']
            ?? \App\Models\Product::whereKey($product->getKey())->value('type');

        if ($type === 'set') {
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

            // (Lane CT) What the line was worth before, for the tracked value.
            $before = $item ? (int) $item->quantity * (int) $item->unit_price : 0;
            $beforeQty = $item ? (int) $item->quantity : 0;

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

            // (Lane CT) One event row; the cart's tracked summary rides on the
            // save just below. See App\Services\CartTracking\CartTracker.
            $this->tracker()->record(
                $cart, CartTracker::ADD, (int) $product->id, $variant?->id,
                (int) $item->quantity - $beforeQty, (int) $item->quantity, (int) $item->unit_price,
                (int) $item->quantity * (int) $item->unit_price - $before,
            );

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

        // (Lane CT) The line as it was, for the event and the tracked value.
        $beforeQty = (int) $item->quantity;
        $before = $beforeQty * (int) $item->unit_price;

        if ($quantity <= 0) {
            // Zero is removing it, and removing one member of a "Buy these
            // together" bundle dissolves the bundle (Lane RE).
            $this->dissolve($cart, $item->bt_group ?? null);
            $item->delete();

            $this->tracker()->record(
                $cart, CartTracker::REMOVE, $item->product_id !== null ? (int) $item->product_id : null,
                $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
                -$beforeQty, 0, (int) $item->unit_price, -$before,
            );
        } else {
            $qty = min(99, $quantity);

            // Changing the quantity can cross a bundle threshold in either
            // direction, so the unit price is recomputed rather than kept.
            $item->update([
                'quantity' => $qty,
                'unit_price' => $this->unitPriceFor($item->product, $item->variant, $qty),
            ]);

            if ($qty !== $beforeQty) {
                $this->tracker()->record(
                    $cart, CartTracker::QTY, $item->product_id !== null ? (int) $item->product_id : null,
                    $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
                    $qty - $beforeQty, $qty, (int) $item->unit_price, $qty * (int) $item->unit_price - $before,
                );
            }
        }

        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    public function remove(Cart $cart, int $itemId): void
    {
        $this->displayLoaded = false;

        // (Lane CT) The row rather than value('bt_group'): the same one query,
        // and it is what tells Cart Tracking WHICH product left the cart.
        $line = $cart->items()->whereKey($itemId)
            ->first(['id', 'product_id', 'product_variant_id', 'quantity', 'unit_price', 'bt_group']);

        $this->dissolve($cart, $line?->bt_group);
        $cart->items()->where('id', $itemId)->delete();

        if ($line !== null) {
            $this->tracker()->record(
                $cart, CartTracker::REMOVE, $line->product_id !== null ? (int) $line->product_id : null,
                $line->product_variant_id !== null ? (int) $line->product_variant_id : null,
                -(int) $line->quantity, 0, (int) $line->unit_price, -(int) $line->quantity * (int) $line->unit_price,
            );
        }

        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    /** (Lane CT) Resolved on first use, so carts that never change never build it. */
    private function tracker(): CartTracker
    {
        return app(CartTracker::class);
    }

    /**
     * Make the lines one "Buy these together" press just added into a group.
     *                                                                (Lane RE)
     *
     * Called ONLY by Store\CartController::addTogether(), with the ids of the
     * lines it added in that request — never with anything a browser sent. The
     * handle is minted here, 32 random characters, and `bt_size` records how
     * many lines it covers; App\Services\BuyTogetherPricing prices the group
     * only while exactly that many lines still carry it.
     *
     * A line that was already in ANOTHER group (the same product bought
     * together twice from two pages) moves to this one, and the group it left
     * is dissolved — its other lines go back to their own prices, exactly as
     * if this line had been removed from it.
     *
     * Fewer than three lines is not a bundle and makes no group.
     *
     * @param  list<int>  $itemIds
     */
    public function group(Cart $cart, array $itemIds): ?string
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if (count($itemIds) < BuyTogetherPricing::MIN_GROUP || count($itemIds) > BuyTogetherPricing::MAX_GROUP) {
            return null;
        }

        $this->displayLoaded = false;
        $handle = Str::lower(Str::random(32));

        DB::transaction(function () use ($cart, $itemIds, $handle) {
            $previous = $cart->items()->whereIn('id', $itemIds)->whereNotNull('bt_group')
                ->distinct()->pluck('bt_group')->all();

            foreach ($previous as $old) {
                $this->dissolve($cart, (string) $old);
            }

            $cart->items()->whereIn('id', $itemIds)
                ->update(['bt_group' => $handle, 'bt_size' => count($itemIds)]);
        });

        return $handle;
    }

    /**
     * The group goes; every line that was in it is an ordinary line again.
     *
     * "if any product removed from the cart, the other products prices will
     * become normal without buy together discount." Pricing already refuses an
     * incomplete group on every pass; clearing the handle on the survivors as
     * well is what makes that permanent — a product added back by the ordinary
     * button later cannot complete a bundle nobody pressed for.
     */
    private function dissolve(Cart $cart, ?string $group): void
    {
        if ($group === null || $group === '') {
            return;
        }

        $cart->items()->where('bt_group', $group)->update(['bt_group' => null, 'bt_size' => null]);
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

            $lines[] = $this->claimLine($item, (int) $item->quantity);
        }

        app(StockClaim::class)->claim($lines, $order?->id !== null ? (int) $order->id : null);
    }

    /** One basket line in the shape StockClaim::claim() takes. */
    private function claimLine(CartItem $item, int $quantity): array
    {
        return [
            'product_id' => (int) $item->product_id,
            'variant_id' => $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
            'quantity' => $quantity,
            // Taken from the relations the checkout has already loaded, so
            // the refusal sentence costs no query. Summing two lines that
            // share a shelf is StockClaim's job, not this loop's.
            'label' => $this->lineLabel($item),
        ];
    }

    /**
     * Which lines of this basket cannot be bought as they stand, and what each
     * could be cut to. (Lane CO — the checkout's sold-out dialog.)
     *
     * claimStock() refuses the whole basket on the FIRST shelf it cannot
     * cover, which is right for the money path and useless for telling the
     * shopper what to do: the owner asked for every sold-out line to be listed
     * at once, with one press to clear them. So each line is asked on its own.
     *
     * THE SAME QUESTION, NOT A SECOND COPY OF IT. Each probe is a real
     * StockClaim::claim() of that one line — the set rule, the variant/parent
     * shelf, the status check and the counted stock all exactly as Place order
     * will apply them — inside a transaction that is ALWAYS rolled back, with
     * no order id, so nothing is decremented and nothing is recorded. A second
     * implementation of "is this in stock" is how the dialog and the refusal
     * would come to disagree.
     *
     * Only ever run after a refusal or on the dialog's own press, never on a
     * page view, so it costs the shop nothing on the paths that are measured.
     *
     * @param  list<int>|null  $onlyIds  restrict to these line ids
     * @return array<int, array{subject:string, keep:int}>  keyed by line id;
     *         keep 0 = the line has to go, n = only n of it can be bought
     */
    public function unavailableLines(Cart $cart, ?array $onlyIds = null): array
    {
        $cart->loadMissing('items.product', 'items.variant');

        $out = [];

        foreach ($cart->items as $item) {
            if ($item->product_id === null || ($onlyIds !== null && ! in_array((int) $item->id, $onlyIds, true))) {
                continue;
            }

            $quantity = max(1, (int) $item->quantity);
            $refusal = $this->probe($item, $quantity);

            if ($refusal === null) {
                continue;
            }

            /*
             * How many CAN be bought, when more than one was asked for: the
             * largest quantity a probe accepts, found by halving (at most seven
             * probes for the 99 a line can hold). Zero means the line goes.
             */
            $keep = 0;

            if ($quantity > 1 && $this->probe($item, 1) === null) {
                $low = 1;
                $high = $quantity - 1;

                while ($low < $high) {
                    $mid = intdiv($low + $high + 1, 2);

                    if ($this->probe($item, $mid) === null) {
                        $low = $mid;
                    } else {
                        $high = $mid - 1;
                    }
                }

                $keep = $low;
            }

            $out[(int) $item->id] = [
                'subject' => (string) ($refusal->subject ?? $this->lineLabel($item)),
                'keep' => $keep,
            ];
        }

        return $out;
    }

    /** One line, claimed and rolled back: the refusal, or null if it would go through. */
    private function probe(CartItem $item, int $quantity): ?StockUnavailable
    {
        DB::beginTransaction();

        try {
            app(StockClaim::class)->claim([$this->claimLine($item, $quantity)], null);

            return null;
        } catch (StockUnavailable $e) {
            return $e;
        } finally {
            DB::rollBack();
        }
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
                    $existing->update(['quantity' => min(99, $existing->quantity + $item->quantity)]
                        // A guest's bundle line landing on an ungrouped line
                        // of the same product keeps the guest's bundle whole.
                        + ($item->bt_group && ! $existing->bt_group
                            ? ['bt_group' => $item->bt_group, 'bt_size' => $item->bt_size] : []));
                } else {
                    $target->items()->create([
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        // (Lane RE) The bundle travels with the line.
                        'bt_group' => $item->bt_group,
                        'bt_size' => $item->bt_size,
                    ]);
                }
            }

            $guest->items()->delete();
            // (Lane CT) The lines moved: the guest basket is worth nothing now
            // and the account's is worth what its lines total.
            $this->tracker()->revalue($guest, 0);
            $guest->update(['status' => 'merged']);
            $this->tracker()->revalue($target);
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

        /*
         * "BUY THESE TOGETHER", PRICED BEFORE THE COUPON — Lane RE.
         *
         * The bundle comes first and the coupon second, on what the bundle
         * left ("and on top of it, the coupon can be apply"), so the quote is
         * handed to discountFor() rather than recomputed there. NONE — and no
         * query — for a basket with no group in it.
         *
         * `subtotal` STAYS THE SUM OF THE LINES AT THEIR OWN PRICES, and the
         * bundle is a row of its own beside the coupon's: the owner asked for
         * the two to be told apart. So the free-delivery bar and a coupon's
         * minimum spend, which read the subtotal, count the basket BEFORE the
         * bundle — the same customer-favouring reading the comment below gives
         * the coupon: a discount never pushes a basket back under a threshold
         * it had reached. The tax base and the total are after both.
         */
        $bundle = app(BuyTogetherPricing::class)->forCart($cart);
        $bundleOff = (int) $bundle['total'];

        $discount = 0;
        $couponCode = null;
        if ($cart->coupon) {
            $discount = $this->coupons->discountFor($cart->coupon, $cart, $bundle);
            $couponCode = $cart->coupon->code;
        }

        $afterDiscount = max(0, $subtotal - $bundleOff - $discount);
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
            /*
             * THE BUNDLE, and what each grouped line gave (Lane RE). The cart
             * page, the drawer and the checkout print `bundle_discount` as its
             * own row and read `bundle['lines'][item id]` for the line price;
             * the order writes both. `discount` is still the coupon and only
             * the coupon — it is what recordRedemption() spends.
             */
            'bundle_discount' => $bundleOff,
            'bundle' => $bundle,
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
                $subtotal, $bundleOff, $discount, $shipping, $taxableBase, $total,
                $threshold ?? 0, $toFree ?? 0,
            ),
        ];
    }
}
