<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OwnerApp\OwnerAppUi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screens and functions switched off under Users & Roles → Owner app →
 * Customise app are REFUSED here, for every member (Lane OA4) — not merely
 * hidden by the app. Runs after OwnerAppSession, so a stranger still gets the
 * PIN answer and learns nothing about the switches.
 *
 * Fails closed: each guarded route names the screen it belongs to and, for an
 * action, the function; either one off is 403 `off`. The controller's own
 * capability check still runs after this one, so both must allow.
 *
 * Saving a product names its function by the FIELDS it carries: a request
 * that changes the price and the stock needs both switches on.
 */
final class OwnerAppUiGate
{
    /** Route name (without "owner-app.") => [screen|null, function|null]. */
    public const ROUTES = [
        'dashboard' => ['store', null],
        'orders' => ['orders', null],
        'order' => ['orders', null],
        'order.status' => ['orders', null],
        'orders.bulk' => ['orders', 'bulk'],
        'order.note' => ['orders', 'order_notes'],
        'order.paid' => ['orders', 'mark_paid'],
        'order.paylink' => ['orders', null],
        'products' => ['products', null],
        'product' => ['products', null],
        'categories' => ['products', null],
        'product.update' => ['products', null],
        'customers' => ['customers', null],
        'customer' => ['customers', null],
        'notifications' => ['notifications', null],
        'changes' => [null, 'live'],
    ];

    /** Behind the PIN but never switched off: More holds lock, sign-out and notifications. */
    // 'analytics', 'analytics.live' (Lane AN): no Customise-app switch; the
    // controller refuses them without analytics.view.
    // 'carts' (Lane QK10): likewise; refused without carttracking.view.
    public const ALWAYS = ['lock', 'forget', 'push', 'push.off', 'push.test', 'notify', 'analytics', 'analytics.live', 'carts'];

    /** Product fields => the function that may change them (OwnerApp\ProductsController::EDITABLE). */
    public const PRODUCT_FIELDS = [
        'price_aed' => 'edit_price',
        'sale_aed' => 'edit_price',
        'stock' => 'edit_stock',
        'stock_status' => 'edit_stock',
        'manage_stock' => 'edit_stock',
        'status' => 'edit_catalogue',
        'is_visible' => 'edit_catalogue',
        'category_ids' => 'edit_catalogue',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        $name = str_starts_with($name, 'owner-app.') ? substr($name, 10) : $name;

        if (! isset(self::ROUTES[$name])) {
            return $next($request);
        }

        [$screen, $function] = self::ROUTES[$name];

        if ($screen !== null && ! OwnerAppUi::screenOn($screen)) {
            return self::off();
        }
        if ($function !== null && ! OwnerAppUi::functionOn($function)) {
            return self::off();
        }
        if ($name === 'product.update') {
            foreach (self::PRODUCT_FIELDS as $field => $fn) {
                if ($request->exists($field) && ! OwnerAppUi::functionOn($fn)) {
                    return self::off();
                }
            }
        }

        return $next($request);
    }

    private static function off(): Response
    {
        return response()->json([
            'ok' => false,
            'code' => 'off',
            'message' => 'This is switched off for the app. The owner can turn it on in Users & Roles → Owner app → Customise app.',
        ], 403);
    }
}
