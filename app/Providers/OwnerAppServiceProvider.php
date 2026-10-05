<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * The owner app's three hooks into the shop (Lane MAC).
 *
 *   an order is created already placed (COD)   -> "New order"
 *   an order's status changes                  -> "New order" when it leaves
 *                                                 pending/failed, otherwise a
 *                                                 status / failed / refunded
 *                                                 event
 *   a product's stock column is saved          -> "Low stock" / "Out of stock"
 *                                                 when it crosses the line
 *
 *   an admin account's password changes       -> every owner-app session of
 *                                                 that member ends (Lane SEC):
 *                                                 a password reset after a
 *                                                 suspected compromise must
 *                                                 not leave an unlocked phone
 *
 * Every hook defers to DB::afterCommit, so a rolled-back checkout leaves no
 * event behind, and every hook is wrapped: an owner-app fault must never be
 * the thing that fails a sale. Registered in bootstrap/providers.php
 * (tools/mac-wire.php writes the line).
 */
final class OwnerAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // My store's cached sales and top sellers (Lane OA4, OwnerAppSales):
        // any order write starts a new cache generation, so a sale is never
        // hidden behind the 30-second cache; a refund likewise. A row written
        // through the query builder relies on that 30 s.
        Order::saved(static fn () => \App\Services\OwnerApp\OwnerAppSales::bump());
        Order::deleted(static fn () => \App\Services\OwnerApp\OwnerAppSales::bump());
        \App\Models\Refund::saved(static fn () => \App\Services\OwnerApp\OwnerAppSales::bump());

        Order::created(static function (Order $order): void {
            self::later(static fn () => OwnerAppEvents::orderCreated($order));
        });

        Order::updated(static function (Order $order): void {
            if (! $order->wasChanged('status')) {
                return;
            }
            $from = $order->getOriginal('status');
            $to = (string) $order->status;
            self::later(static fn () => OwnerAppEvents::orderMoved($order, is_string($from) ? $from : null, $to));
        });

        AdminUser::updated(static function (AdminUser $admin): void {
            if (! $admin->wasChanged('password')) {
                return;
            }
            $id = (int) $admin->getKey();
            self::later(static function () use ($id): void {
                foreach (DB::table('owner_app_members')->where('admin_user_id', $id)->pluck('id') as $memberId) {
                    OwnerAppAuth::endSessions((int) $memberId);
                }
            });
        });

        Product::updated(static function (Product $product): void {
            if (! $product->wasChanged('stock')) {
                return;
            }
            $before = $product->getOriginal('stock');
            $after = $product->stock;
            $id = (int) $product->getKey();
            $name = (string) $product->getRawOriginal('name', (string) $product->getAttribute('name'));
            $managed = (bool) $product->manage_stock;
            self::later(static fn () => OwnerAppEvents::stockChanged(
                $id, $name, $before === null ? null : (int) $before, $after === null ? null : (int) $after, $managed,
            ));
        });
    }

    private static function later(\Closure $fn): void
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
