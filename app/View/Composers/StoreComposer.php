<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Services\CartService;
use App\Services\NavigationService;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Support\Color;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Everything the store layout needs, resolved once per request.
 *
 * Bound to the layout rather than fetched inside each partial, so rendering the
 * header cannot accidentally trigger a second cart lookup.
 */
class StoreComposer
{
    public function __construct(
        private SettingsService $settings,
        private NavigationService $nav,
        private CartService $carts,
        private ShippingService $shipping,
        private Request $request,
    ) {}

    /**
     * The trending chips beside the search box.
     *
     * Editable in settings; falls back to the terms the live site shows so the
     * row is never empty on a fresh install.
     */
    private function trending(): array
    {
        // Set in Appearance → Header → Search. The older search_trending
        // setting is still honoured so an existing list is not lost.
        $header = app(\App\Services\HeaderSettings::class)->trendingWords();

        if ($header !== []) {
            return $header;
        }

        $raw = $this->settings->get('search_trending');

        if (is_string($raw) && trim($raw) !== '') {
            $terms = array_map('trim', explode(',', $raw));
        } elseif (is_array($raw) && $raw !== []) {
            $terms = $raw;
        } else {
            $terms = ['Madeca', 'PDRN', 'Retinol', 'Dark spots', 'Age-R Booster Pro', 'Capsule Cream',
                      'Medicube', 'Anua', 'Beauty of Joseon', 'COSRX', 'SKIN 1004', 'Acne', 'Centella'];
        }

        return array_values(array_filter(array_slice($terms, 0, 14)));
    }

    /** Wishlist count from the cookie the storefront already uses. */
    private function wishlistCount(): int
    {
        $raw = (string) $this->request->cookie('kbb_wishlist', '');

        return $raw === '' ? 0 : count(array_filter(explode(',', $raw)));
    }

    public function compose(View $view): void
    {
        $cart = $this->carts->current($this->request, create: false);
        $accent = (string) $this->settings->get('brand_accent', '#E0567B');
        $mobile = $this->nav->menu('mobile');
        // Filtered per-request, after the shared cached tree comes back — see
        // NavigationService::tree()'s own comment for why this can't happen
        // inside the cache. One check here covers every view that reads
        // kbbNav/kbbMobileNav/kbbFooterNav, rather than each template having
        // to remember to do it itself.
        $loggedIn = \Illuminate\Support\Facades\Auth::guard('customer')->check();

        $view->with([
            'kbbSettings' => $this->settings,
            'kbbNav' => $this->nav->filterVisible($this->nav->menu('primary'), $loggedIn),
            'kbbMobileNav' => $this->nav->filterVisible($mobile ?: $this->nav->menu('primary'), $loggedIn),
            'kbbFooterNav' => $this->nav->filterVisible($this->nav->menu('footer'), $loggedIn),
            'kbbCartCount' => $cart?->itemCount() ?? 0,
            'kbbWishlistCount' => $this->wishlistCount(),

            /*
             * Header extras — and ONE of them is cached, which is not what this
             * comment used to say.
             *
             * It read "Cached, since the header is on every page and none of
             * this varies per visitor" over a block of four keys, of which
             * exactly one goes through Cache::remember(). The other three are
             * computed per request and always were. That mattered the moment a
             * value here started varying per visitor: read literally, the
             * comment invites the next reader to wrap the lot in one global
             * cache key, and the first shopper to warm it would decide what
             * every other shopper is told.
             *
             * kbbProductCount IS cached, and may be: it is a count of the
             * catalogue, identical for everyone.
             *
             * kbbFreeShipThreshold IS NOT, and must never be. It is the
             * free-delivery figure for the country THIS VISITOR is in — the
             * home page's delivery band, its ticker and its trust row all print
             * it, as would partials/announcement.blade.php if any layout
             * included it, which none does (this comment used to say the
             * announcement bar prints it "on every page of the site"; Lane DM
             * corrected that) — and it was
             * resolved from `store_country`, the shop's own country, so a
             * shopper in Riyadh was quoted the Dubai threshold. It is memoised
             * on the Request (see ShippingService::thresholdHere()), which is
             * per visitor by construction: nothing it computes can outlive the
             * request that asked.
             */
            'kbbProductCount' => Cache::remember('kbb.count.products', 900,
                fn () => Product::query()->visible()->count()),
            'kbbTrending' => $this->trending(),
            'kbbFreeShipThreshold' => $this->shipping->thresholdHere($this->request),
            // Emitted only when it differs from the design default, matching the
            // theme, which ships no override in the common case.
            'kbbAccent' => Color::isValidHex($accent) && strtolower($accent) !== '#e0567b'
                ? ['base' => $accent, 'deep' => Color::darken($accent, 12)]
                : null,
        ]);
    }
}
