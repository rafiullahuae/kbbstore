<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Mail\OrderMailer;
use App\Services\Orders\OrderPayLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Store → Orders → (a failed or unpaid order) → Payment → "Send order link"
 * (Lane OL). The admin console and the owner app both call share(); the app's
 * own controller checks its member's capability and hands over.
 *
 *   POST /admin-api/orders/{id}/pay-link        orders.paylink
 *        via=''        mint (or re-mint — same link all day) and return it
 *        via=copy|whatsapp   ... and write down that it was sent that way
 *        via=email     ... and email it, then write that down
 *   GET  /admin-api/order-pay-link-settings     orders.paylink.settings
 *   PUT  /admin-api/order-pay-link-settings     orders.paylink.settings
 *
 * The capability is ALSO checked here, not only by EnforceAdminCapability, so
 * the owner app's delegation cannot reach it without one.
 */
final class OrderPayLinkController extends Controller
{
    /** Emails per order per hour: a stuck finger, not a customer, is what this stops. */
    public const EMAILS_PER_HOUR = 3;

    public function __construct(private OrderPayLink $links) {}

    public function share(Request $request, int $id): JsonResponse
    {
        if (! $this->can($request, 'orders.paylink')) {
            return response()->json(['ok' => false, 'code' => 'forbidden', 'message' => 'Your role does not include sending order links.'], 403);
        }

        $order = Order::query()->with(['items', 'notes'])->find($id);

        if ($order === null) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'No such order.'], 404);
        }

        if (! OrderPayLink::offered($order)) {
            return response()->json(['ok' => false, 'code' => 'not_offered',
                'message' => 'Only a failed order, or a pending one with no payment, can be sent a link to pay.'], 422);
        }

        $via = (string) $request->input('via', '');

        if ($via !== '' && ! in_array($via, OrderPayLink::VIA, true)) {
            return response()->json(['ok' => false, 'code' => 'via', 'message' => 'Choose Copy, WhatsApp or Email.'], 422);
        }

        $url = $this->links->url($order);
        $by = (string) ($request->user('admin')?->name ?? $request->user()?->name ?? 'Admin');
        $message = '';

        if ($via === 'email') {
            $key = 'kbb-paylink-mail:' . $order->getKey();

            if (RateLimiter::tooManyAttempts($key, self::EMAILS_PER_HOUR)) {
                return response()->json(['ok' => false, 'code' => 'throttled',
                    'message' => 'This order has been emailed ' . self::EMAILS_PER_HOUR . ' times in the last hour. Try again in ' . max(1, (int) ceil(RateLimiter::availableIn($key) / 60)) . ' min.'], 429);
            }

            RateLimiter::hit($key, 3600);

            $sent = app(OrderMailer::class)->payLink($order, $url, $this->links->settings()['email_subject']);

            if (! $sent['ok']) {
                return response()->json(['ok' => false, 'code' => 'mail', 'message' => $sent['message']], 422);
            }

            $this->links->record($order, 'email', $by, $url, self::maskEmail((string) $order->email));
            $message = $sent['message'];
        } elseif ($via !== '') {
            $this->links->record($order, $via, $by, $url, $via === 'whatsapp' ? self::maskPhone((string) OrderPayLink::whatsappDigits($order)) : '');
            $message = $via === 'whatsapp' ? 'Noted: sent by WhatsApp.' : 'Link copied.';
        }

        if ($via !== '') {
            $order->load('notes');
        }

        $problems = OrderPayLink::problems($order);
        $names = $order->items->filter(fn ($i) => isset($problems[(int) $i->id]))
            ->map(fn ($i) => (string) $i->name . ' — ' . ($problems[(int) $i->id] === 'out_of_stock' ? 'out of stock' : 'no longer available'))
            ->values()->all();

        return response()->json([
            'ok' => true,
            'url' => $url,
            'expires_label' => OrderPayLink::expiresLabel($url),
            'whatsapp_url' => $this->links->whatsappUrl($order, $url),
            'has_phone' => OrderPayLink::whatsappDigits($order) !== null,
            'email' => str_contains((string) $order->email, '@') ? (string) $order->email : '',
            'last' => OrderPayLink::last($order),
            'problems' => $names,
            'message' => $message,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        if (! $this->can($request, 'orders.paylink.settings')) {
            return response()->json(['ok' => false, 'code' => 'forbidden'], 403);
        }

        return response()->json(['ok' => true, 'settings' => $this->links->settings(), 'max_days' => OrderPayLink::MAX_DAYS,
            'defaults' => ['whatsapp_en' => OrderPayLink::DEFAULT_WA_EN, 'whatsapp_ar' => OrderPayLink::DEFAULT_WA_AR]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        if (! $this->can($request, 'orders.paylink.settings')) {
            return response()->json(['ok' => false, 'code' => 'forbidden'], 403);
        }

        $error = $this->links->save($request->only(['days', 'whatsapp_en', 'whatsapp_ar', 'email_subject']));

        if ($error !== null) {
            return response()->json(['ok' => false, 'message' => $error], 422);
        }

        return response()->json(['ok' => true, 'settings' => $this->links->settings()]);
    }

    private function can(Request $request, string $capability): bool
    {
        $admin = $request->user('admin') ?? auth('admin')->user();

        return $admin instanceof \App\Models\AdminUser && \App\Support\AdminRoles::can($admin, $capability);
    }

    /** a***@example.com: the note is read by every member with orders.view. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain === '' ? '' : mb_substr($local, 0, 1) . '***@' . $domain;
    }

    /** +971 50 *** 3841 */
    public static function maskPhone(string $digits): string
    {
        return strlen($digits) < 8 ? '' : '+' . substr($digits, 0, 5) . '***' . substr($digits, -4);
    }
}
