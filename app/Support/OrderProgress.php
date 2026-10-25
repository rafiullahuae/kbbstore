<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * Where an order is, as the redesigned account area draws it (Lane ACC):
 * a TONE for the coloured strip and pill, the strip's SENTENCE, and the
 * four-step progress (Placed, Confirmed, Shipped, Delivered).
 *
 * The steps say only what the order's own record supports. "Shipped" and
 * "completed" are the shop's real statuses (the shipped email says "on its
 * way", the completed one "your order is complete"), so completed reads as
 * Delivered. A cancelled, refunded or failed order has no steps at all: a bar
 * half-filled in red would claim progress that never happened.
 *
 * Dates come from the order's own columns (created_at, paid_at, completed_at);
 * a step with no recorded date shows none rather than a guess.
 */
final class OrderProgress
{
    public const STEPS = ['placed', 'confirmed', 'shipped', 'delivered'];

    /** pay | hold | proc | ship | done | off -- a fixed class suffix, never a setting. */
    public static function tone(?string $status): string
    {
        return match (self::norm($status)) {
            'pending' => 'pay',
            'onhold', 'on-hold' => 'hold',
            'processing', 'paid' => 'proc',
            'shipped' => 'ship',
            'completed' => 'done',
            'cancelled', 'refunded', 'failed' => 'off',
            default => 'proc',
        };
    }

    /** The strip's sentence key (store.orders.band_*). */
    public static function bandKey(?string $status): string
    {
        $s = self::norm($status);

        return 'store.orders.band_' . match ($s) {
            'pending', 'shipped', 'completed', 'cancelled', 'refunded', 'failed' => $s,
            'onhold', 'on-hold' => 'onhold',
            default => 'processing',
        };
    }

    /**
     * The step the order is AT (0-3), 4 for a delivered order (every step
     * ticked), or null for an order that stopped (cancelled, refunded,
     * failed): no bar is drawn for it. The ring marks where the order is NOW
     * -- a processing order is at "Confirmed", not at "Shipped", so nobody
     * reads a ring on Shipped as "it has shipped".
     */
    public static function at(?string $status): ?int
    {
        return match (self::norm($status)) {
            'cancelled', 'refunded', 'failed' => null,
            'pending', 'onhold', 'on-hold' => 0,
            'shipped' => 2,
            'completed' => 4,
            default => 1,
        };
    }

    /**
     * The four steps, each ['key', 'state' => done|now|hold|wait, 'date' => ?string].
     * `hold` is the step of an order waiting on payment or on hold: amber,
     * because it is stuck rather than moving. A date shows where the order
     * recorded one (placed, paid, completed), never a guess.
     *
     * @return list<array{key:string,state:string,date:?string}>
     */
    public static function steps(Order $order, string $format = 'j M'): array
    {
        $at = self::at((string) $order->status);

        if ($at === null) {
            return [];
        }

        $stuck = in_array(self::tone((string) $order->status), ['pay', 'hold'], true);
        $dates = [$order->created_at, $order->paid_at, null, $order->completed_at];
        $out = [];

        foreach (self::STEPS as $i => $key) {
            $state = $i < $at ? 'done' : ($i === $at ? ($stuck ? 'hold' : 'now') : 'wait');
            $date = $state !== 'wait' && $dates[$i] !== null ? LocalDate::format($dates[$i], $format) : null;
            $out[] = ['key' => $key, 'state' => $state, 'date' => $date];
        }

        return $out;
    }

    private static function norm(?string $status): string
    {
        return mb_strtolower(trim((string) ($status ?: 'pending')));
    }
}
