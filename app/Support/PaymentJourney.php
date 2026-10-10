<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Services\Payments\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An order's PAYMENT JOURNEY, step by step. (Lane TM.)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * WHY IT EXISTS. Order #56187 (10 Oct 2026, Tamara, AED 590) failed with one
 * note, "The payment could not be started.", and the owner asked the only
 * question that matters: was it us, did the shopper walk away, or did Tamara
 * say no? Nothing on either order screen could answer it. This puts the
 * answer in one line (the verdict) above the steps it is read from.
 *
 * BUILT ONLY FROM WHAT IS ALREADY WRITTEN DOWN, nothing guessed:
 *   - the order row (placed, method, total);
 *   - payment_logs rows naming this order — the payment session the gateway
 *     opened or was refused, the shopper coming back, a verified webhook;
 *   - payment_events through `payments` — confirmations, declines, captures,
 *     refunds, as PaymentConfirmer / PaymentCapturer / PaymentRefunder wrote
 *     them;
 *   - the order's own notes, which carry the three sentences that decide the
 *     verdict on orders written before payment_logs knew about them;
 *   (the basket and the landing source are CustomerJourney's.)
 *
 * FLAT COST: two queries whatever the order or the catalogue, each on an
 * index or on payment_logs, which PaymentLog caps at KEEP rows — and none at
 * all on an order that was not paid through Tamara, Tabby or Stripe.
 *
 * Every string goes out as data. Both screens escape it; nothing here is HTML.
 */
final class PaymentJourney
{
    private const NAMES = ['tamara' => 'Tamara', 'tabby' => 'Tabby', 'stripe' => 'Stripe'];

    /** Session key that keeps a refreshed return page from logging twice. */
    private const RETURNED_KEY = 'kbb_journey_returned';

    /** What a return link may say about itself; anything else is dropped. */
    private const RETURN_WORDS = ['approved', 'declined', 'canceled', 'cancelled', 'expired', 'failed', 'success',
        // Stripe's redirect_status after a 3-D Secure / wallet redirect.
        'succeeded', 'processing', 'requires_capture', 'requires_payment_method'];

    /**
     * The shopper landed back on this shop from a hosted payment page.
     *
     * The CALLER has already proved this session placed the order (the order
     * number matches `kbb_last_order`), so a stranger requesting the URL writes
     * nothing; and it is logged once per order and leg per session, so
     * refreshing the page cannot fill the bounded log. Only redirect gateways.
     */
    public static function returned(Request $request, Order $order, string $leg): void
    {
        $gateway = (string) $order->payment_method;

        if (! isset(self::NAMES[$gateway])) {
            return;
        }

        try {
            $key = $order->order_number . '|' . $leg;

            if ($request->hasSession() && $request->session()->get(self::RETURNED_KEY) === $key) {
                return;
            }

            $said = $request->query('paymentStatus') ?? $request->query('redirect_status') ?? $request->query('status') ?? '';
            $said = is_string($said) ? strtolower(trim($said)) : '';
            $said = in_array($said, self::RETURN_WORDS, true) ? $said : null;

            PaymentLog::record($gateway, 'info', 'returned',
                sprintf($gateway === 'stripe' ? 'The shopper reached the %2$s page (%1$s)%3$s.' : 'The shopper came back from %s to the %s page%s.', self::NAMES[$gateway],
                    $leg === 'success' ? 'order-received' : 'not-finished',
                    $said !== null ? ' (the return link said "' . $said . '"; the webhook decides)' : ''),
                ['order' => (string) $order->order_number, 'outcome' => $leg, 'status' => $said]);

            if ($request->hasSession()) {
                $request->session()->put(self::RETURNED_KEY, $key);
            }
        } catch (\Throwable) {
            // A diagnostic must never stand between a shopper and their page.
        }
    }

    /**
     * Null for an order not paid through a provider (cash on delivery, bank
     * transfer, an import): there is no journey to tell, and those orders pay
     * nothing for it — no query at all, which keeps the order screen's own
     * budget (OrderMoneyAuditTest) where it was for them.
     *
     * @return array{verdict: array{tone: string, text: string}, steps: list<array<string, mixed>>}|null
     */
    public static function for(Order $order): ?array
    {
        $gateway = (string) $order->payment_method;

        if (! isset(self::NAMES[$gateway])) {
            return null;
        }
        $name = self::NAMES[$gateway];
        $steps = [];

        $steps[] = self::step($order->created_at, '', 'Order placed at checkout',
            trim(($order->paymentLabel() ?: $gateway) . ' · ' . Money::plain((int) $order->total)));

        $logged = [];

        foreach (self::logs($gateway, (string) $order->order_number) as $row) {
            $logged[$row->event] = true;
            [$tone, $title] = match ($row->event) {
                'checkout_created' => ['g', 'Payment session opened — shopper sent to ' . $name],
                'checkout_refused', 'intent.failed' => ['w', 'Payment session NOT opened'],
                'intent.created' => ['g', 'Card / Apple Pay / Google Pay payment opened on the checkout page'],
                'intent.retry' => ['', 'Stripe asked again'],
                'intent.last_error' => ['w', 'Card or wallet did not go through'],
                'returned' => ['', $gateway === 'stripe' ? 'Shopper reached the order-received page' : 'Shopper came back from ' . $name],
                'webhook', 'webhook.received' => ['', $name . ' notification received'],
                default => ['', ucfirst(str_replace('_', ' ', (string) $row->event))],
            };
            $steps[] = self::step($row->created_at, $tone, $title, (string) $row->message);
        }

        foreach (self::events((int) $order->getKey()) as $ev) {
            $type = (string) $ev->type;
            $tone = match (true) {
                in_array($type, ['paid', 'capture', 'late_confirmation'], true) => 'g',
                str_contains($type, 'fail') || str_contains($type, 'declin') || str_contains($type, 'expir') || str_contains($type, 'mismatch') => 'w',
                default => '',
            };
            $steps[] = self::step($ev->received_at, $tone,
                self::NAMES[(string) $ev->provider] ?? ucfirst((string) $ev->provider),
                'Recorded: ' . str_replace('_', ' ', $type) . ((int) $ev->amount > 0 && ! str_contains($type, 'fail') ? ' · ' . Money::plain((int) $ev->amount) : ''));
        }

        $verdict = null;
        $notes = $order->relationLoaded('notes') ? $order->notes : $order->notes()->get();
        $refusedLogged = isset($logged['checkout_refused']) || isset($logged['intent.failed']);

        foreach ($notes->sortBy('id') as $note) {
            $text = (string) $note->content;

            if (str_contains($text, 'The payment could not be started.')) {
                $reason = trim(substr($text, strpos($text, 'The payment could not be started.') + 33));
                $reason = trim(preg_replace('/\s*Coupon .*$/', '', $reason) ?? $reason);

                if (! $refusedLogged) {
                    $steps[] = self::step($note->created_at, 'w', 'Payment session NOT opened',
                        $reason !== '' ? $reason : 'The reason was not recorded — this order was placed before the shop wrote it down. The shopper never reached ' . $name . '.');
                }

                $verdict ??= ['tone' => 'w', 'text' => 'Never reached ' . $name . '. The payment session was not created, so the shopper was not sent to ' . $name . ', was not charged, and ' . $name . ' made no approve/decline decision.'
                    . ($reason !== '' ? ' Reason: ' . $reason : ' The reason was not recorded for this order.')];
            } elseif (str_contains($text, 'card payment was cancelled before it completed')) {
                $steps[] = self::step($note->created_at, 'w', 'Card or wallet payment not completed', 'The shopper\'s card or wallet did not complete on the checkout page; the payment was cancelled at Stripe and the basket restored.');
                $verdict ??= ['tone' => 'w', 'text' => 'The card or wallet payment did not complete on the checkout page. Nothing was charged.'];
            } elseif (str_contains($text, 'came back without finishing the payment')) {
                $steps[] = self::step($note->created_at, 'w', 'Shopper left ' . $name . ' without paying', 'Nothing was charged and the basket was given back.');
                $verdict ??= ['tone' => 'w', 'text' => 'The shopper reached ' . $name . ' and came back without finishing. Nothing was charged.'];
            } elseif (preg_match('/(\w+) reported the payment as ([\w ]+)\./', $text, $m)) {
                $steps[] = self::step($note->created_at, 'w', (self::NAMES[strtolower($m[1])] ?? $m[1]) . ' said no', 'Reported the payment as ' . str_replace('_', ' ', $m[2]) . '.');
                $verdict ??= ['tone' => 'w', 'text' => $name . ' reported the payment as ' . str_replace('_', ' ', $m[2]) . '. The shopper reached ' . $name . '; the decision was ' . $name . '\'s.'];
            }
        }

        if ($order->paid_at !== null || in_array((string) $order->status, OrderPaymentPanel::PAID_STATUSES, true)) {
            $verdict = ['tone' => 'g', 'text' => 'Paid.'];
        }

        $verdict ??= ['tone' => '', 'text' => (string) $order->status === 'pending'
            ? 'Waiting: the payment has not been confirmed yet.'
            : 'No payment problem recorded.'];

        usort($steps, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return ['verdict' => $verdict, 'steps' => $steps];
    }

    /** @return list<object> */
    private static function logs(string $gateway, string $number): array
    {
        if ($gateway === '' || $number === '') {
            return [];
        }

        try {
            // The order number is inside a JSON blob. LIKE over a table PaymentLog
            // caps at KEEP rows is a bounded scan, and the needle is the exact
            // JSON fragment PaymentLog writes, quotes included, so #5618 does not
            // match #56187.
            $needle = trim(json_encode(['order' => $number], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', '{}');

            return DB::table(PaymentLog::TABLE)
                ->where('gateway', $gateway)
                ->where('context', 'like', '%' . $needle . '%')
                ->orderBy('id')
                ->limit(40)
                ->get(['event', 'message', 'context', 'created_at'])
                // LIKE treats `_` as a wildcard; the exact match is made here.
                ->filter(fn ($r) => (string) (json_decode((string) $r->context, true)['order'] ?? '') === $number)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<object> */
    private static function events(int $orderId): array
    {
        try {
            return DB::table('payment_events as e')
                ->join('payments as p', 'p.id', '=', 'e.payment_id')
                ->where('p.order_id', $orderId)
                ->orderBy('e.id')
                ->limit(40)
                ->get(['e.type', 'e.provider', 'e.received_at', 'p.amount'])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{at: string|null, at_label: string, tone: string, title: string, detail: string} */
    private static function step(mixed $at, string $tone, string $title, string $detail): array
    {
        $local = StoreTime::display($at instanceof \DateTimeInterface || is_string($at) ? $at : null);

        return [
            'at' => $local?->toIso8601String(),
            'at_label' => $local?->format('j M Y, g:i:s A') ?? '',
            'tone' => $tone,
            'title' => $title,
            'detail' => $detail,
        ];
    }
}
