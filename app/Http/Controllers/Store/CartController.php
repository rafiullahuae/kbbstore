<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cart page, mini-cart drawer, and the endpoints behind both.
 *
 * The drawer and the page are rendered from the same partials on the server and
 * swapped in whole. Two renderers for one cart is how the two drift apart — the
 * WordPress build had exactly that problem until it moved to fragments.
 *
 * Rule 27: one query for the cart, one for its lines with products and brands
 * eager loaded, and only the product columns a line actually renders.
 */
class CartController extends Controller
{
    /** Columns a cart line needs. The rest of the row is never read here. */
    private const LINE_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image', 'sku', 'type',
    ];

    public function __construct(
        private CartService $carts,
        private CouponService $coupons,
        private SettingsService $settings,
    ) {}

    public function page(Request $request): View
    {
        return view('store.cart', $this->payload($this->loadCart($request), $request));
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::query()->select(self::LINE_COLUMNS)->visible()->find($data['product_id']);

        if (! $product) {
            return response()->json(['ok' => false, 'error' => 'That product is not available.'], 404);
        }

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)->find($data['variant_id']);

            if (! $variant) {
                return response()->json(['ok' => false, 'error' => 'That option is not available.'], 404);
            }
        }

        if (($variant?->stock_status ?? $product->stock_status) !== 'instock') {
            return response()->json(['ok' => false, 'error' => 'That product is sold out.'], 422);
        }

        $cart = $this->carts->current($request);
        $this->carts->add($cart, $product, (int) ($data['quantity'] ?? 1), $variant);

        return $this->fragments($this->loadCart($request), $request, 'Added to bag');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $cart = $this->loadCart($request, create: false);

        if ($cart) {
            $this->carts->updateQuantity($cart, (int) $data['item_id'], (int) $data['quantity']);
        }

        return $this->fragments($this->loadCart($request), $request);
    }

    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate(['item_id' => ['required', 'integer']]);

        $cart = $this->loadCart($request, create: false);

        if ($cart) {
            $this->carts->remove($cart, (int) $data['item_id']);
        }

        return $this->fragments($this->loadCart($request), $request, 'Removed');
    }

    public function coupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:60'],
            'remove' => ['nullable', 'boolean'],
        ]);

        $cart = $this->loadCart($request);

        if ($request->boolean('remove')) {
            $cart->forceFill(['coupon_id' => null])->save();

            return $this->fragments($this->loadCart($request), $request, 'Coupon removed');
        }

        $result = $this->coupons->validate((string) ($data['code'] ?? ''), $cart, $request->user('customer')?->email);

        if (! $result['ok']) {
            return $this->fragments($cart, $request, null, $result['error']);
        }

        $cart->forceFill(['coupon_id' => $result['coupon']->id])->save();

        return $this->fragments($this->loadCart($request), $request, 'Coupon applied');
    }

    /**
     * Read-only view of what the server believes is in the cart.
     *
     * Exists so "the cart is empty" can be diagnosed by opening a URL rather
     * than by shipping another diagnostic file. It reports the cookie, the cart
     * row, the lines and the totals — no writes, nothing sensitive.
     */
    public function debug(Request $request): JsonResponse
    {
        $cart = $this->loadCart($request, create: false);
        $all = \App\Models\Cart::query()
            ->where('status', 'active')
            ->latest('id')
            ->limit(5)
            ->get(['id', 'token', 'customer_id', 'status', 'created_at'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'token' => substr((string) $c->token, 0, 8) . '…',
                'items' => $c->items()->count(),
                'created' => (string) $c->created_at,
            ]);

        return response()->json([
            'cookie_present' => $request->hasCookie(CartService::COOKIE),
            'cookie_value' => substr((string) $request->cookie(CartService::COOKIE), 0, 8) . '…',
            'resolved_cart_id' => $cart?->id,
            'resolved_item_count' => $cart?->itemCount() ?? 0,
            'lines' => $cart?->items->map(fn ($i) => [
                'item_id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->product?->name,
                'qty' => $i->quantity,
            ]) ?? [],
            'recent_active_carts' => $all,
            'products_visible' => \App\Models\Product::visible()->count(),
            'url_resolution' => \App\Support\Url::debugBase(),
            'sample_links' => [
                'cart' => \App\Support\Url::to('/cart/'),
                'checkout' => \App\Support\Url::to('/checkout/'),
                'cart_api' => \App\Support\Url::to('/api/cart'),
            ],
            'hint' => 'More than one recent cart with items means the cookie is not being sent back. '
                . 'If sample_links lack the /kbb-upgrade prefix, url_resolution shows why.',
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /** The drawer alone, for the header cart button. */
    public function drawer(Request $request): JsonResponse
    {
        $cart = $this->loadCart($request, create: false);
        $payload = $this->payload($cart, $request);

        return response()->json([
            'ok' => true,
            'count' => $payload['totals']['item_count'],
            'html' => $this->drawerHtml($payload),
        ]);
    }

    /**
     * One query for the cart, one for its lines with products and brands.
     * Without the eager load this is an N+1 across every line in the basket.
     */
    private function loadCart(Request $request, bool $create = true)
    {
        $cart = $this->carts->current($request, create: $create);

        $cart?->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.product' => fn ($q) => $q->select(self::LINE_COLUMNS),
            'items.product.brand:id,name,slug',
            // `sku` added so this set is a superset of the drawer composer's,
            // which now stands down rather than loading its own copy.
            'items.variant:id,product_id,sku,price,sale_price,image,stock_status',
            'coupon:id,code,type,amount',
        ]);

        // Said AFTER the load, and unconditionally: this method always fetches,
        // so whatever a mutation changed a moment ago is in hand before the
        // drawer is told it need not look.
        if ($cart !== null) {
            $this->carts->markDisplayLoaded();
        }

        return $cart;
    }

    /**
     * The drawer fragment, rendered from the payload computed HERE.
     *
     * The flag is what stops CartDrawerComposer filling the same partial in a
     * second time from its own look at the request. A composer runs after the
     * data handed to a view and overwrites it, and on the first add of a
     * session that meant the panel was painted EMPTY over a payload that was
     * already correct: the cart is created during that request and its cookie
     * only goes out on the response, so the composer's cookie test said there
     * was nothing to show.
     */
    private function drawerHtml(array $payload): string
    {
        return view('partials.cart-drawer', $payload + [
            \App\View\Composers\CartDrawerComposer::SUPPLIED => true,
        ])->render();
    }

    private function payload($cart, Request $request): array
    {
        $country = (string) $this->settings->get('store_country', 'AE');

        $empty = [
            'item_count' => 0, 'subtotal' => 0, 'discount' => 0, 'coupon_code' => null,
            'shipping' => 0, 'total' => 0, 'free_shipping_threshold' => null,
            'free_shipping_remaining' => null, 'free_shipping_unlocked' => false,
            'free_shipping_percent' => null, 'vat' => null,
        ];

        return [
            'cart' => $cart,
            'items' => $cart?->items ?? collect(),
            'totals' => $cart ? $this->carts->totals($cart, $country) : $empty,
            'promo' => $this->settings->moduleEnabled('minicart_promo', true)
                ? (string) $this->settings->get('minicart_promo', '')
                : '',
            /*
             * THE CODE IS CHECKED BEFORE IT IS ADVERTISED.
             *
             * This was `$this->settings->get('cart_coupon_text', 'Use code <b
             * data-code="GLOW30">GLOW30</b> for an extra 30% off.')` — a code
             * and a percentage both written into the default, on a shop that
             * has never had either. The badge is clickable, so a shopper who
             * tapped it was answered "That code is not valid." by the same
             * panel that had just offered it; and this payload feeds the
             * mini-cart drawer, which the layout renders on EVERY page.
             *
             * Support\CheckoutCouponHint holds the one set of conditions —
             * exists, started, not expired, not exhausted — so the cart, the
             * drawer and the checkout cannot advertise different things. It
             * costs no query at all until the owner actually names a code.
             */
            'couponHint' => $this->settings->moduleEnabled('coupon_hint', true)
                ? \App\Support\CheckoutCouponHint::html(
                    \App\Support\CheckoutCouponHint::cartOffer($this->settings)
                )
                : '',
            // Only fetched when the drawer is being rendered for display.
            'browsed' => $request->boolean('skip_browsed') ? collect() : $this->browsed($request, $cart),
            // Which browsed products are already in the bag, so the add control
            // can show a tick instead of a plus. Products are not removed from
            // the list any more, so the list itself no longer carries that fact.
            'inCart' => $cart ? $cart->items->pluck('product_id')->unique()->values()->all() : [],
        ];
    }

    /**
     * The drawer's Browsed tab, from the cookie the product page writes.
     *
     * Rule 27: no query at all when the cookie is empty, which is every first
     * visit and every crawler.
     */
    private function browsed(Request $request, $cart)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->cookie('kbb_viewed', ''))));

        if ($ids === []) {
            return collect();
        }

        // Browsed items stay listed after they are added. Removing them was why
        // the add button appeared to work exactly once: the product left the
        // list the moment it entered the bag, so there was nothing left to press
        // a second time. Adding again increments the line, which is what a
        // shopper reaching for + expects.
        $ids = array_slice($ids, 0, 6);

        if ($ids === []) {
            return collect();
        }

        return Product::query()
            ->select(self::LINE_COLUMNS)
            ->visible()
            ->whereIn('id', $ids)
            ->with('brand:id,name,slug')
            ->get()
            // Preserve most-recently-viewed order, which the SQL IN() does not.
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))
            ->values();
    }

    private function fragments($cart, Request $request, ?string $toast = null, ?string $error = null): JsonResponse
    {
        $payload = $this->payload($cart, $request);

        // The cart-page body is only rendered when the client is actually
        // showing it. Off the cart page that was a full Blade view compiled and
        // thrown away on every add — the single biggest cost of opening the
        // drawer. (Rule 27)
        $wantsPage = $request->boolean('with_page');

        return response()->json([
            'ok' => $error === null,
            'error' => $error,
            'toast' => $toast,
            'count' => $payload['totals']['item_count'],
            'drawer' => $this->drawerHtml($payload),
            'page' => $wantsPage ? view('store.cart-inner', $payload)->render() : null,
            'subtotal' => Money::format($payload['totals']['subtotal']),
            'total' => Money::format($payload['totals']['total']),
        ]);
    }
}
