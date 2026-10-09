<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Services\Pixels\ServerEvents;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The three hooks the server-side events hang off. (Lane MP)
 *
 * Registered from AppServiceProvider::register(), not bootstrap/providers.php,
 * for the reason OwnerAppServiceProvider gives: bootstrap/ never ships in a
 * package.
 *
 * Every hook is cheap when no platform has a server token (one in-memory
 * settings lookup, no query) and every hook is wrapped: a marketing fault must
 * never be the thing that fails a sale or an add to cart.
 *
 *   1. An order created by the shop's own checkout keeps the shopper's browser
 *      context (encrypted), so the Purchase sent later carries it.
 *   2. An order reaching a placed status — at creation or on any later status
 *      move — sends Purchase, after the transaction commits.
 *   3. A successful add-to-cart request sends AddToCart.
 */
final class MarketingPixelsServiceProvider extends ServiceProvider
{
    /** The controller actions that create an order for a shopper in a browser. */
    public const CHECKOUT_ACTIONS = [
        \App\Http\Controllers\Store\CheckoutController::class . '@place',
        \App\Http\Controllers\Api\CheckoutController::class . '@session',
    ];

    public const ADD_ACTION = \App\Http\Controllers\Store\CartController::class . '@add';

    public function boot(): void
    {
        Order::created(static function (Order $order): void {
            try {
                $request = app()->bound('request') ? app('request') : null;

                if ($request instanceof Request && in_array(self::action($request), self::CHECKOUT_ACTIONS, true)) {
                    app(ServerEvents::class)->captureContext($order, $request);
                }

                if (in_array((string) $order->status, ServerEvents::PLACED, true)) {
                    self::afterCommit(static fn () => app(ServerEvents::class)->purchase($order));
                }
            } catch (\Throwable) {
            }
        });

        Order::updated(static function (Order $order): void {
            try {
                if (! $order->wasChanged('status')) {
                    return;
                }

                $from = (string) $order->getOriginal('status');

                if (! in_array($from, ServerEvents::PLACED, true) && in_array((string) $order->status, ServerEvents::PLACED, true)) {
                    self::afterCommit(static fn () => app(ServerEvents::class)->purchase($order));
                }
            } catch (\Throwable) {
            }
        });

        Event::listen(RequestHandled::class, static function (RequestHandled $e): void {
            try {
                if (self::action($e->request) !== self::ADD_ACTION || ! $e->response instanceof JsonResponse || $e->response->getStatusCode() !== 200) {
                    return;
                }

                $answer = $e->response->getData(true);

                if (! is_array($answer) || ($answer['ok'] ?? null) === false) {
                    return;
                }

                $events = app(ServerEvents::class);

                if (! ($events->metaOn() || $events->tiktokOn())) {
                    return;
                }

                $productId = (int) $e->request->input('product_id');
                $variantId = (int) $e->request->input('variant_id') ?: null;
                $quantity = max(1, min(99, (int) $e->request->input('quantity', 1)));

                $product = Product::query()->visible()->find($productId);

                if ($product === null) {
                    return;
                }

                $unit = $product->effectivePrice();

                if ($variantId !== null) {
                    $variant = $product->variants()->whereKey($variantId)->first();
                    if ($variant !== null) {
                        $variant->setRelation('product', $product);
                        $unit = $variant->effectivePrice();
                    }
                }

                $events->addToCart($e->request, $productId, $variantId, $quantity, (int) $unit);
            } catch (\Throwable) {
            }
        });
    }

    private static function action(Request $request): string
    {
        $route = $request->route();

        return $route instanceof \Illuminate\Routing\Route ? (string) $route->getActionName() : '';
    }

    private static function afterCommit(\Closure $fn): void
    {
        try {
            DB::afterCommit(static function () use ($fn): void {
                try {
                    $fn();
                } catch (\Throwable) {
                }
            });
        } catch (\Throwable) {
        }
    }
}
