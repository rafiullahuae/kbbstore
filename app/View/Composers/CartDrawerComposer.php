<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\Product;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fills the mini-cart drawer on every page render.
 *
 * The drawer used to be an empty shell that only JavaScript populated, after an
 * add. So on a hard refresh the header badge showed a count — that comes from
 * the server — while the drawer itself was blank until you added something new.
 *
 * Rule 27: when the cart cookie is absent there is nothing to show and no query
 * runs at all, which covers every first visit and every crawler.
 */
class CartDrawerComposer
{
    /**
     * Set by whoever renders this partial with a payload of its own, to say
     * "the drawer's data is already here — do not fill it in again".
     */
    public const SUPPLIED = 'drawerSupplied';

    private const LINE_COLUMNS = [
        'id', 'wc_id', 'slug', 'name', 'brand_id', 'price', 'sale_price',
        'sale_starts_at', 'sale_ends_at', 'stock_status', 'image', 'type',
    ];

    public function __construct(
        private CartService $carts,
        private SettingsService $settings,
        private Request $request,
    ) {}

    public function compose(View $view): void
    {
        /*
         * A composer runs AFTER the data handed to the view and overwrites it,
         * so anything set here replaces what the caller already worked out.
         *
         * CartController renders this same partial with a payload of its own on
         * every add, quantity change and drawer refresh. Filling it in a second
         * time from a fresh look at the request is not a harmless duplicate: on
         * the first add of a session it wrote the EMPTY state over a correct
         * one (the panel opened blank until a second product was added), and on
         * every add after that it swapped the controller's Browsed list for
         * this one, which drops a product from the list the moment it enters
         * the bag — the reason the Browsed tab's Add appeared to work exactly
         * once.
         *
         * The flag is the test, not the presence of `items` or `totals`:
         * @include hands the whole parent scope down, and both the cart page
         * and the checkout carry variables of those names that have nothing to
         * do with this panel. Only CartController sets this one, and only on
         * the renders where it has supplied the drawer's own payload.
         */
        if ($view->offsetExists(self::SUPPLIED)) {
            return;
        }

        $empty = [
            'item_count' => 0, 'subtotal' => 0, 'discount' => 0, 'total' => 0,
            'coupon_code' => null, 'shipping' => 0,
            'free_shipping_threshold' => 0, 'free_shipping_remaining' => 0,
            'free_shipping_percent' => 0, 'free_shipping_unlocked' => false,
        ];

        /*
         * Rule 27: when there is nothing to show, no query runs at all — every
         * first visit and every crawler.
         *
         * The cookie alone is the wrong test for that. It is issued on the
         * RESPONSE that creates the cart, so a request which creates one still
         * carries no cookie; asking the service what it has already resolved
         * covers that case, and costs nothing when it has resolved nothing.
         */
        $cart = $this->carts->resolved();

        if ($cart === null && ! $this->request->hasCookie(CartService::COOKIE)) {
            /*
             * RULE 27 IS ABOUT THE CART, AND THIS LINE USED TO READ `collect()`.
             *
             * Reported from the live shop: "the browsed tab is not giving real
             * browsed pages result, it's stucks and dead." It was neither stale
             * nor cached. The Browsed tab is fed from the `kbb_viewed` cookie,
             * which ProductController::rememberViewed() writes on every product
             * page and which has nothing to do with the cart — but this early
             * return is reached whenever there is no CART, and it threw that
             * history away and handed the panel an empty list.
             *
             * So the tab was permanently empty for exactly the people it is for:
             * anyone browsing who has not yet added anything. Add one product
             * and the cart cookie appears, this branch stops being taken, and
             * the whole history shows up at once — which is why it read as
             * something stuck rather than something switched off.
             *
             * Rule 27 still holds. browsed() returns without touching the
             * database when the cookie is absent or empty, so a first visit and
             * a crawler — which carry no cookies at all — still run no query.
             * The cart path below is untouched: nothing here resolves a cart.
             */
            $view->with([
                'items' => collect(),
                'totals' => $empty,
                'promo' => '',
                'browsed' => $this->browsed(null),
            ]);

            return;
        }

        $cart = $cart ?? $this->carts->current($this->request, create: false);

        /*
         * Do not fetch what this request has already fetched.
         *
         * `load()` re-queries unconditionally, so on /cart and /checkout — the
         * two pages whose controller has just loaded this exact relation set —
         * the panel repeated the lines, their products and those products'
         * brands, three queries each, for rows already in memory. Measured:
         * /cart 16 -> 13 and /checkout 22 -> 19 from this line alone.
         *
         * The flag is cleared by every cart mutator, so an add, a quantity
         * change or a removal still re-reads. Off those two pages nothing has
         * loaded anything and the load below runs exactly as before.
         */
        if ($cart !== null && ! $this->carts->displayLoaded()) {
            $cart->load([
                'items' => fn ($q) => $q->orderBy('id'),
                'items.product' => fn ($q) => $q->select(self::LINE_COLUMNS),
                'items.product.brand:id,name,slug',
                'items.variant:id,product_id,sku,price,sale_price,image,stock_status',
                'coupon:id,code,type,amount',
            ]);

            $this->carts->markDisplayLoaded();
        }

        $view->with([
            'items' => $cart?->items ?? collect(),
            'totals' => $cart ? $this->carts->totals($cart) : $empty,
            'promo' => $this->settings->moduleEnabled('minicart_promo', true)
                ? (string) $this->settings->get('minicart_promo', '')
                : '',
            'browsed' => $this->browsed($cart),
        ]);
    }

    private function browsed($cart)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $this->request->cookie('kbb_viewed', ''))));

        if ($ids === []) {
            return collect();
        }

        $inCart = $cart ? $cart->items->pluck('product_id')->all() : [];
        $ids = array_slice(array_values(array_diff($ids, $inCart)), 0, 6);

        if ($ids === []) {
            return collect();
        }

        return Product::query()->select(self::LINE_COLUMNS)->visible()
            ->whereIn('id', $ids)->with('brand:id,name,slug')->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();
    }
}
