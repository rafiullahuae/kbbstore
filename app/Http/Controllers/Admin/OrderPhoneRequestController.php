<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Mail\OrderMailer;
use App\Support\StoreTime;
use App\Support\UaePhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store -> Orders -> (an order) -> Billing -> Phone -> "Ask for UAE number"
 * (2.60.562).
 *
 *   POST /admin-api/orders/{id}/phone-request   orders.manage (as Resend confirmation)
 *
 * The owner: "we want one button / option to require the local UAE's number
 * from the customer ... currently we do email manual, but i want it auto upon
 * just 1 click", and "the system will also show that the missing contact
 * number email is already sent, with date and time ... so i can be sure by not
 * sending the emails twice."
 *
 * NEVER TWICE BY ACCIDENT, three ways:
 *   1. The screen shows "sent · <date, time>" from orders.phone_request_sent_at
 *      (panel()), and asks again before a reminder.
 *   2. A second send must say so (`again`), or it is refused with the time of
 *      the first -- an order open in a second tab that never saw the first.
 *   3. Nothing goes within MIN_GAP_MINUTES of the last, `again` or not. The
 *      column is CLAIMED with a conditional update before the email is sent,
 *      so two presses that arrive together cannot both pass the check; a send
 *      that fails puts the old value back.
 *
 * The capability is checked here as well as by EnforceAdminCapability.
 */
final class OrderPhoneRequestController extends Controller
{
    public const MIN_GAP_MINUTES = 10;

    public function send(Request $request, int $id): JsonResponse
    {
        $admin = $request->user('admin') ?? auth('admin')->user();

        if (! ($admin instanceof \App\Models\AdminUser && \App\Support\AdminRoles::can($admin, 'orders.manage'))) {
            return response()->json(['ok' => false, 'code' => 'forbidden', 'message' => 'Your role does not include asking customers for their number.'], 403);
        }

        $order = Order::query()->find($id);

        if ($order === null) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'No such order.'], 404);
        }

        $before = $order->phone_request_sent_at;

        if ($before !== null && ! $request->boolean('again')) {
            return response()->json(['ok' => false, 'code' => 'already_sent',
                'message' => 'Already sent on ' . self::label($before) . '.', 'panel' => self::panel($order)], 409);
        }

        $now = now();
        $claimed = Order::query()->whereKey($order->getKey())
            ->where(fn ($q) => $q->whereNull('phone_request_sent_at')
                ->orWhere('phone_request_sent_at', '<=', $now->copy()->subMinutes(self::MIN_GAP_MINUTES)))
            ->update(['phone_request_sent_at' => $now]);

        if ($claimed === 0) {
            $order->refresh();

            return response()->json(['ok' => false, 'code' => 'recent',
                'message' => 'Already sent on ' . self::label($order->phone_request_sent_at) . '. Wait ' . self::MIN_GAP_MINUTES . ' minutes before a reminder.',
                'panel' => self::panel($order)], 409);
        }

        $sent = app(OrderMailer::class)->phoneRequest($order);

        if (! $sent['ok']) {
            Order::query()->whereKey($order->getKey())->update(['phone_request_sent_at' => $before]);

            return response()->json(['ok' => false, 'code' => 'mail', 'message' => $sent['message'], 'panel' => self::panel($order->refresh())], 422);
        }

        $order->notes()->create([
            'author' => (string) ($admin->name ?? 'Admin'),
            'is_customer_note' => false,
            'content' => ($before === null ? 'UAE number requested' : 'UAE number reminder sent')
                . ' from the customer by email (' . ((string) $order->locale === 'ar' ? 'Arabic' : 'English') . ').',
        ]);

        return response()->json(['ok' => true, 'message' => $sent['message'], 'panel' => self::panel($order->refresh())]);
    }

    /**
     * What the order screen draws beside the phone. `uae` is whether the phone
     * on the order is already a UAE mobile -- the "Not a UAE mobile" tag shows
     * when it is not; the button shows either way, for a UAE-looking number
     * that turned out to be wrong.
     *
     * @return array{uae: bool, has_email: bool, sent_at: ?string, sent_label: string, updated_label: string}
     */
    public static function panel(Order $order): array
    {
        return [
            'uae' => UaePhone::isMobile((string) $order->phone),
            'has_email' => str_contains((string) $order->email, '@'),
            'sent_at' => $order->phone_request_sent_at?->toIso8601String(),
            'sent_label' => self::label($order->phone_request_sent_at),
            'updated_label' => self::label($order->phone_updated_at),
        ];
    }

    private static function label(mixed $at): string
    {
        return StoreTime::display($at)?->format('j M Y, g:i A') ?? '';
    }
}
