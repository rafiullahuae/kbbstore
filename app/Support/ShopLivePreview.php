<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The REAL cart page, checkout or cart panel, as the shop draws it, for the
 * live preview beside the text-size controls (Lane CK, 22 October 2026).
 *
 * "Reuse the shop's real markup and CSS." So this does not draw a mock: it
 * runs the shop's own controller and views on a sample basket of three
 * products and hands back the HTML. The admin screen shows it in a sandboxed
 * same-origin iframe and moves the custom properties on it as a slider moves
 * — one request when a frame opens, none per change.
 *
 * ── NOTHING IT DOES IS KEPT ─────────────────────────────────────────────
 *
 *   - The basket is written inside a database transaction that is ALWAYS
 *     rolled back, so no cart row, no line, no tracking row survives.
 *   - The cart cookie the shop queues for a new basket is taken back off the
 *     response, and any other cookie queued during the render with it.
 *   - The session is put back exactly as it was, so the owner's admin session
 *     carries nothing of the render.
 *   - Every <script> is taken out and the <body> is marked inert: nothing on
 *     the page runs, takes a click or posts a form, even if the URL is
 *     opened on its own. (The admin also frames it sandboxed, without scripts.)
 *   - The locale and the bound request are restored in `finally`.
 */
final class ShopLivePreview
{
    public const PAGES = ['checkout', 'cart', 'panel'];

    /** How many products the sample basket carries — the "three-item basket" Lane TS used. */
    private const LINES = 3;

    public static function render(Request $admin, string $page, string $lang): string
    {
        $page = in_array($page, self::PAGES, true) ? $page : 'checkout';
        $locale = $lang === 'ar' && Locale::enabled('ar') ? 'ar' : Locale::DEFAULT;
        $path = ($locale === 'ar' ? '/ar' : '').($page === 'checkout' ? '/checkout/' : '/cart/');

        $app = app();
        $before = [
            'locale' => $app->getLocale(),
            'request' => $app->make('request'),
            'session' => $admin->hasSession() ? $admin->session()->all() : null,
            'cookies' => Cookie::getQueuedCookies(),
        ];

        $sub = Request::create($path, 'GET', [], $admin->cookies->all(), [], $admin->server->all());
        if ($admin->hasSession()) {
            $sub->setLaravelSession($admin->session());
        }
        $sub->setUserResolver($admin->getUserResolver());
        $sub->setRouteResolver($admin->getRouteResolver());

        $carts = $app->make(CartService::class);
        DB::beginTransaction();

        try {
            $app->setLocale($locale);
            $app->instance('request', $sub);
            URL::setRequest($sub);

            $cart = self::sampleCart($carts);

            if ($cart === null) {
                return self::message('There is no product in stock to put in a sample basket, so there is nothing to preview yet.');
            }

            $carts->forget();
            $carts->adopt($cart);

            $result = $page === 'checkout'
                ? $app->make(CheckoutController::class)->page($sub)
                : $app->make(CartController::class)->page($sub);

            if (! $result instanceof View) {
                return self::message('The page did not draw for the sample basket.');
            }

            $html = $result->render();
        } finally {
            DB::rollBack();
            $carts->forget();
            Cookie::flushQueuedCookies();
            foreach ($before['cookies'] as $cookie) {
                Cookie::queue($cookie);
            }
            if ($before['session'] !== null) {
                $admin->session()->flush();
                $admin->session()->put($before['session']);
            }
            $app->instance('request', $before['request']);
            URL::setRequest($before['request']);
            $app->setLocale($before['locale']);
        }

        // No script, and nothing that takes a click: a preview, not a till.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
        $html = preg_replace('#<body\b#i', '<body inert', $html, 1) ?? $html;

        // The panel is the cart page's own drawer, opened.
        if ($page === 'panel') {
            $html = preg_replace('/<aside class="drawer /', '<aside class="drawer on ', $html, 1) ?? $html;
            $html = preg_replace('/<div class="ov" id="ov"/', '<div class="ov on" id="ov"', $html, 1) ?? $html;
        }

        return $html;
    }

    /** Three in-stock, priced, simple products in a fresh basket — inside the caller's transaction. */
    private static function sampleCart(CartService $carts): ?Cart
    {
        $products = Product::query()
            ->visible()
            ->where('stock_status', 'instock')
            ->where('price', '>', 0)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', 'simple'))
            ->orderBy('id')
            ->limit(self::LINES)
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        $cart = Cart::create([
            'token' => 'kbb-live-'.bin2hex(random_bytes(12)),
            'status' => 'active',
            'currency' => 'AED',
            'shipping_country' => 'AE',
            'last_activity_at' => now(),
        ]);

        foreach ($products as $product) {
            $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => $carts->unitPriceFor($product, null, 1),
            ]);
        }

        return $cart;
    }

    private static function message(string $text): string
    {
        return '<!doctype html><meta charset="utf-8"><body style="font:14px system-ui;padding:24px;color:#5E545A">'.e($text).'</body>';
    }
}
