<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ShopLivePreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The real page behind each text-size screen's live preview (Lane CK).
 *
 *   GET /admin-api/checkout-page/live?lang=en|ar   Appearance → Checkout page
 *   GET /admin-api/cart-page/live?lang=en|ar       Appearance → Cart page
 *   GET /admin-api/cart-panel/live?lang=en|ar      Appearance → Cart panel
 *
 * Each sits under its own screen's capability prefix in AdminCapabilities, so
 * the role that may move a slider is the role that may see what it does, and
 * nobody else. GET because it writes nothing (ShopLivePreview rolls back
 * everything it touches). The HTML carries no script and an inert body, so
 * nothing in it can run, be clicked or post a form wherever it is opened.
 */
class ShopLivePreviewController extends Controller
{
    public function checkout(Request $request): Response
    {
        return $this->answer($request, 'checkout');
    }

    public function cart(Request $request): Response
    {
        return $this->answer($request, 'cart');
    }

    public function panel(Request $request): Response
    {
        return $this->answer($request, 'panel');
    }

    private function answer(Request $request, string $page): Response
    {
        $lang = $request->query('lang') === 'ar' ? 'ar' : 'en';

        return response(ShopLivePreview::render($request, $page, $lang), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
