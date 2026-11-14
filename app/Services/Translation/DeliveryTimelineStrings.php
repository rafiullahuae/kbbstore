<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * THE CUSTOMER DELIVERY TIMELINE'S WORDS, ENGLISH AND ARABIC TOGETHER
 * (Quiqup P3, QUIQUP-PLAN §6.2 / §7.1).
 *
 * One list, so the two languages cannot drift apart -- the AccountAreaStrings
 * shape: InterfaceStrings reads the English (group `store`), ArabicInterface-
 * Drafts the Arabic under `store.` + key, and
 * 2028_02_14_100000_delivery_timeline_arabic publishes the Arabic so /ar reads
 * Arabic the day the package lands. Feminine address, as the rest of the shop.
 *
 * The step names are the plan's §6.2 column, word for word. The owner can
 * still reword any step per Quiqup status on Store -> Quiqup -> Statuses; a
 * step he changed there wins over these (App\Support\DeliveryTimeline::label).
 */
final class DeliveryTimelineStrings
{
    /** key (without `store.`) => [English, Arabic] */
    public const ROWS = [
        'delivery.timeline_label' => ['Delivery progress', 'مراحل التوصيل'],
        'delivery.step_booked' => ['Booked with courier', 'تم حجز الشحن'],
        'delivery.step_collecting' => ['Courier on the way to collect', 'المندوب في طريقه لاستلام الطلب'],
        'delivery.step_picked' => ['Picked up by courier', 'تم الاستلام من المندوب'],
        'delivery.step_hub' => ['At the delivery hub', 'في مركز التوزيع'],
        'delivery.step_transit' => ['On the way to your emirate', 'في الطريق إلى إمارتك'],
        'delivery.step_scheduled' => ['Delivery scheduled', 'تم تحديد موعد التوصيل'],
        'delivery.step_out' => ['Out for delivery', 'خرج للتوصيل'],
        'delivery.step_delivered' => ['Delivered', 'تم التوصيل'],
        'delivery.step_missed' => ['Delivery attempt :n missed, we will try again', 'تعذّر التوصيل (المحاولة :n)، سنحاول مجدداً'],
        'delivery.step_delayed' => ['Delayed', 'متأخر'],
        'delivery.step_returning' => ['Returning to us', 'قيد الإرجاع إلينا'],
        'delivery.step_returned' => ['Returned to us', 'أُعيد إلينا'],
        'delivery.band_missed' => ['Delivery attempt missed, we will try again', 'تعذّر التوصيل، سنحاول مجدداً'],
        'delivery.expected_by' => ['Expected by :time', 'متوقع بحلول :time'],
        'delivery.live_view' => ['Courier\'s live view', 'تتبّع المندوب مباشرة'],
        'delivery.live_note' => ['This page updates by itself while your parcel is on its way.', 'تتحدّث هذه الصفحة تلقائياً أثناء توصيل طلبكِ.'],
        'delivery.updated' => ['Up to date', 'تم التحديث'],
        // Web Push (opt-in: only phones that allowed notifications).
        'push.delivery_title' => ['Order :order · :step', 'الطلب :order · :step'],
        'push.delivery_out_for_delivery_body' => ['Your parcel is out for delivery today. Tap to follow it.', 'طلبكِ خرج للتوصيل اليوم. اضغطي لمتابعته.'],
        'push.delivery_delivery_failed_body' => ['We could not deliver today. The courier will try again.', 'لم نتمكن من التوصيل اليوم. سيحاول المندوب مجدداً.'],
        'push.delivery_body' => ['Tap to follow your delivery.', 'اضغطي لمتابعة التوصيل.'],
    ];

    /** @return array<string, string> key => English, for InterfaceStrings' `store` group */
    public static function english(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::ROWS);
    }

    /** @return array<string, string> `store.` + key => Arabic, for ArabicInterfaceDrafts */
    public static function arabic(): array
    {
        $out = [];

        foreach (self::ROWS as $key => [, $arabic]) {
            $out['store.' . $key] = $arabic;
        }

        return $out;
    }
}
