<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

/**
 * Quiqup's last-mile state machine and the shop's default reaction to each
 * state (QUIQUP-PLAN §1.5 and §6.2). Every value here is a code constant; the
 * owner's edits live in quiqup_config.options['statuses'] and are validated
 * against these lists before they are stored.
 */
final class QuiqupStates
{
    /** Every documented `state`, in lifecycle order (§1.5). */
    public const ALL = [
        'pending', 'ready_for_collection', 'out_for_collection', 'collection_failed', 'collected',
        'received_at_depot', 'at_depot', 'in_transit', 'scheduled', 'out_for_delivery',
        'delivery_failed', 'on_hold', 'delivery_complete', 'return_to_origin', 'out_for_return',
        'returned_to_origin', 'cancelled', 'error',
    ];

    public const END = ['delivery_complete', 'returned_to_origin', 'cancelled'];

    /** The order statuses a Quiqup state may move an order to (§3.4). '' = no change. */
    public const TARGETS = ['', 'shipped', 'completed', 'onhold', 'cancelled'];

    /** What a state is called in the admin. */
    public const ADMIN_LABELS = [
        'pending' => 'Booked, not yet released',
        'ready_for_collection' => 'Ready for collection',
        'out_for_collection' => 'Courier on the way to collect',
        'collection_failed' => 'Collection failed',
        'collected' => 'Picked up',
        'received_at_depot' => 'Received at depot',
        'at_depot' => 'At depot',
        'in_transit' => 'In transit',
        'scheduled' => 'Delivery scheduled',
        'out_for_delivery' => 'Out for delivery',
        'delivery_failed' => 'Delivery attempt failed',
        'on_hold' => 'On hold',
        'delivery_complete' => 'Delivered',
        'return_to_origin' => 'Returning to us',
        'out_for_return' => 'Out for return',
        'returned_to_origin' => 'Returned to us',
        'cancelled' => 'Cancelled at Quiqup',
        'error' => 'Error at Quiqup',
    ];

    /**
     * §6.2, shipped as the defaults. collected -> shipped and
     * delivery_complete -> completed are ON (the owner's open question Q2,
     * shipped at the plan's stated default); returned_to_origin changes nothing
     * and is flagged on Activity instead (Q3 default).
     *
     * @var array<string, array{status: string, en: string, ar: string, email: bool, push: bool}>
     */
    public const DEFAULTS = [
        'pending' => ['status' => '', 'en' => 'Booked with courier', 'ar' => 'تم حجز الشحن', 'email' => false, 'push' => false],
        'ready_for_collection' => ['status' => '', 'en' => 'Booked with courier', 'ar' => 'تم حجز الشحن', 'email' => false, 'push' => false],
        'out_for_collection' => ['status' => '', 'en' => 'Courier on the way to collect', 'ar' => 'المندوب في طريقه لاستلام الطلب', 'email' => false, 'push' => false],
        'collection_failed' => ['status' => '', 'en' => '', 'ar' => '', 'email' => false, 'push' => false],
        'collected' => ['status' => 'shipped', 'en' => 'Picked up by courier', 'ar' => 'تم الاستلام من المندوب', 'email' => false, 'push' => false],
        'received_at_depot' => ['status' => '', 'en' => 'At the delivery hub', 'ar' => 'في مركز التوزيع', 'email' => false, 'push' => false],
        'at_depot' => ['status' => '', 'en' => 'At the delivery hub', 'ar' => 'في مركز التوزيع', 'email' => false, 'push' => false],
        'in_transit' => ['status' => '', 'en' => 'On the way to your emirate', 'ar' => 'في الطريق إلى إمارتك', 'email' => false, 'push' => false],
        'scheduled' => ['status' => '', 'en' => 'Delivery scheduled', 'ar' => 'تم تحديد موعد التوصيل', 'email' => false, 'push' => false],
        'out_for_delivery' => ['status' => '', 'en' => 'Out for delivery', 'ar' => 'خرج للتوصيل', 'email' => false, 'push' => true],
        'delivery_failed' => ['status' => '', 'en' => 'Delivery attempt {n} missed, we will try again', 'ar' => 'تعذّر التوصيل (المحاولة {n})، سنحاول مجدداً', 'email' => false, 'push' => true],
        'on_hold' => ['status' => '', 'en' => 'Delayed', 'ar' => 'متأخر', 'email' => false, 'push' => false],
        'delivery_complete' => ['status' => 'completed', 'en' => 'Delivered', 'ar' => 'تم التوصيل', 'email' => false, 'push' => false],
        'return_to_origin' => ['status' => '', 'en' => 'Returning to us', 'ar' => 'قيد الإرجاع إلينا', 'email' => false, 'push' => false],
        'out_for_return' => ['status' => '', 'en' => 'Returning to us', 'ar' => 'قيد الإرجاع إلينا', 'email' => false, 'push' => false],
        'returned_to_origin' => ['status' => '', 'en' => 'Returned to us', 'ar' => 'أُعيد إلينا', 'email' => false, 'push' => false],
        'cancelled' => ['status' => '', 'en' => '', 'ar' => '', 'email' => false, 'push' => false],
        'error' => ['status' => '', 'en' => '', 'ar' => '', 'email' => false, 'push' => false],
    ];

    /** A well-formed state string, known or not ("handle every value", §1.5). */
    public static function wellFormed(mixed $state): bool
    {
        return is_string($state) && preg_match('/^[a-z_]{3,32}$/', $state) === 1;
    }

    public static function known(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }

    public static function adminLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return '';
        }

        return self::ADMIN_LABELS[$state] ?? $state;
    }
}
