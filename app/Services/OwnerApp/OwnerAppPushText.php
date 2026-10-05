<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use Illuminate\Support\Facades\DB;

/**
 * Users & Roles → Owner app → Security · Lock-screen notification text
 * (Lane SEC).
 *
 *   detailed  (default) what the owner asked for: "New order #33001",
 *             "AED 161.00 · Sabina · Tabby" on the lock screen.
 *   generic   "New order" / "Open the app to see it." — no customer name, no
 *             amount, no order number, no product. For a phone that lies on a
 *             shop counter.
 *
 * Applied on the SERVER when the push payload is built, so a generic setting
 * means the details never leave this server for the push service at all —
 * not that the phone is asked to hide them.
 *
 * Read straight from `settings` (one query, only when a push is about to go):
 * Setting::map() is a process-level memo (CLAUDE.md, landmines).
 */
final class OwnerAppPushText
{
    public const SETTING = 'owner_app_push_text';

    public const OPTIONS = ['detailed' => 'Detailed', 'generic' => 'Generic'];

    public const DEFAULT = 'detailed';

    public static function current(): string
    {
        try {
            $v = (string) DB::table('settings')->where('key', self::SETTING)->value('value');
        } catch (\Throwable) {
            return self::DEFAULT;
        }

        return array_key_exists($v, self::OPTIONS) ? $v : self::DEFAULT;
    }

    public static function generic(): bool
    {
        return self::current() === 'generic';
    }

    /** Stores one of its own options, or the default. */
    public static function put(mixed $value): string
    {
        $v = is_string($value) && array_key_exists($value, self::OPTIONS) ? $value : self::DEFAULT;

        DB::table('settings')->updateOrInsert(
            ['key' => self::SETTING],
            ['value' => $v, 'autoload' => false, 'updated_at' => now(), 'created_at' => now()],
        );
        \App\Models\Setting::flushMap();

        return $v;
    }

    /** The words for one event type when the details are withheld. */
    public static function genericTitle(string $type): string
    {
        return match ($type) {
            'order.new' => 'New order',
            'order.status' => 'Order updated',
            'order.failed' => 'Payment failed',
            'order.refunded' => 'Order refunded',
            'stock.low' => 'Low stock',
            'stock.out' => 'Out of stock',
            default => 'Shop update',
        };
    }
}
