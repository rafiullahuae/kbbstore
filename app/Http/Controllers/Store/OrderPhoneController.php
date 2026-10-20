<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderLinks;
use App\Support\UaePhone;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Update my number" (2.60.562): the page the UAE number email opens.
 *
 * WHAT THE SIGNED LINK MAY DO: put one UAE mobile number on one order, while
 * that order is still on its way to being delivered. Nothing else. It shows the
 * order number and the first name and nothing more -- not the address, not the
 * old number, not the email -- because a forwarded email is a forwarded link.
 *
 * WHAT IT WRITES: orders.phone, and the phone in the billing and shipping
 * addresses (the courier's label reads the shipping one), plus phone_updated_at
 * and a note that keeps the old number, so nothing is lost and the order screen
 * says "Updated by customer".
 */
final class OrderPhoneController extends Controller
{
    /** The order is still being prepared or delivered: a number can still help. */
    public const OPEN = ['pending', 'processing', 'onhold'];

    public function show(Request $request): Response
    {
        $order = $this->resolve($request);

        if ($order === null) {
            return $this->page(null, $request, 404);
        }

        return $this->page($order, $request);
    }

    public function save(Request $request): Response|RedirectResponse
    {
        $order = $this->resolve($request);

        if ($order === null) {
            return $this->page(null, $request, 404);
        }

        if (! self::open($order)) {
            return $this->page($order, $request, 422);
        }

        $typed = (string) $request->input('phone', '');
        $phone = UaePhone::e164(mb_substr($typed, 0, 40));

        if ($phone === null) {
            return $this->page($order, $request, 422, __('store.order_phone.invalid'), $typed);
        }

        $was = trim((string) $order->phone);
        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $shipping = is_array($order->shipping_address) ? $order->shipping_address : null;

        $order->forceFill([
            'phone' => $phone,
            'billing_address' => ['phone' => $phone] + $billing,
            'shipping_address' => $shipping === null ? null : ['phone' => $phone] + $shipping,
            'phone_updated_at' => now(),
        ])->save();

        $order->notes()->create([
            'author' => 'Customer',
            'is_customer_note' => false,
            'content' => 'Customer added UAE mobile ' . UaePhone::pretty($phone)
                . ($was !== '' && $was !== $phone ? ' (was ' . $was . ')' : '') . ' through the "Update my number" link.',
        ]);

        return redirect()->to(Url::to('/order-phone') . '?' . http_build_query([
            'order' => (string) $order->order_number,
            't' => (string) $request->input('t', ''),
            'saved' => 1,
        ]));
    }

    public static function open(Order $order): bool
    {
        return in_array((string) $order->status, self::OPEN, true) && ! $order->trashed();
    }

    private function resolve(Request $request): ?Order
    {
        return OrderLinks::resolve(
            OrderLinks::PHONE,
            (string) $request->input('order', ''),
            (string) $request->input('t', ''),
        );
    }

    private function page(?Order $order, Request $request, int $status = 200, string $error = '', string $typed = ''): Response
    {
        $first = trim((string) (($order?->billing_address ?? [])['first_name'] ?? ''));

        return response()->view('store.order-phone', [
            'order' => $order,
            'token' => (string) $request->input('t', ''),
            'firstName' => $first === '' ? '' : mb_convert_case(mb_strtolower($first), MB_CASE_TITLE),
            'saved' => $order !== null && $request->boolean('saved') && $order->phone_updated_at !== null,
            'open' => $order !== null && self::open($order),
            'error' => $error,
            'typed' => $typed,
        ], $status)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
