<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Every email this shop sent for an order, on the order screens. (Lane TM.)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Read from `mail_deliveries`, which MailLog writes for EVERY message the
 * shop's mailer sends — kind, recipient, subject, the transport's verdict and
 * its error. It is the only table that knows whether a message went:
 * `order_emails` is the de-duplication ledger OrderEmailLog keeps (which kinds
 * an order has had) and carries neither a recipient nor a result.
 *
 * WHICH ROWS: the ones addressed to the order's email, or whose subject names
 * the order number (the shop's new-order alert goes to the shop, not the
 * buyer), from the moment the order was placed. ONE query, on the created_at
 * index, capped at 40.
 *
 * NO "VIEW" LINK, AND THAT IS A FACT ABOUT THE SHOP, NOT AN OMISSION: the body
 * of a sent email is not kept anywhere linkable (mail_web_copies holds only
 * the "view in browser" copy, keyed by a hash of a token that went out in the
 * email itself, and expires). The screen says so.
 *
 * BOUNCES: this branch records the transport's answer (sent / failed); there is
 * no bounce feed, so "sent" means the mail server accepted it.
 */
final class OrderEmails
{
    private const LIMIT = 40;

    /** @return array{rows: list<array<string, mixed>>, note: string} */
    public static function for(Order $order): array
    {
        $email = mb_strtolower(trim((string) $order->email));
        $number = trim((string) $order->order_number);
        $from = $order->created_at?->copy()->subMinutes(2);

        $rows = [];

        try {
            if ($from !== null && ($email !== '' || $number !== '')) {
                $rows = DB::table('mail_deliveries')
                    ->where('created_at', '>=', $from)
                    ->where(function ($q) use ($email, $number) {
                        if ($email !== '') {
                            $q->orWhere('recipient', $email)
                                ->orWhere('recipient', 'like', $email . ',%')
                                ->orWhere('recipient', 'like', '%, ' . $email)
                                ->orWhere('recipient', 'like', '%, ' . $email . ',%');
                        }
                        if ($number !== '') {
                            $q->orWhere('subject', 'like', '%' . $number . '%');
                        }
                    })
                    ->orderBy('id')
                    ->limit(self::LIMIT)
                    ->get(['kind', 'recipient', 'subject', 'status', 'error', 'created_at'])
                    // `like` on the subject is a wildcard match: #5618 must not
                    // pick up #56187's mail. Exact number check here.
                    ->filter(fn ($r) => self::addressedTo((string) $r->recipient, $email)
                        || preg_match('/(?<![0-9])' . preg_quote($number, '/') . '(?![0-9])/', (string) $r->subject) === 1)
                    ->map(fn ($r) => self::row($r))
                    ->values()
                    ->all();
            }
        } catch (\Throwable) {
            $rows = [];
        }

        return [
            'rows' => $rows,
            'note' => 'From the shop\'s mail log. "Sent" means the mail server accepted it; the email itself is not stored, so there is no copy to open.',
        ];
    }

    private static function addressedTo(string $recipients, string $email): bool
    {
        return $email !== '' && in_array($email, array_map(fn ($a) => mb_strtolower(trim($a)), explode(',', $recipients)), true);
    }

    /** @return array<string, string> */
    private static function row(object $r): array
    {
        $status = (string) $r->status;
        $at = StoreTime::display(is_string($r->created_at) ? $r->created_at : null);

        // MailLog's own reading: a message still "sending" after the request that
        // sent it has long finished was handed over and never confirmed.
        if ($status === 'sending' && $at !== null && $at->lt(now()->subMinutes(5))) {
            $status = 'failed';
        }

        return [
            'type' => self::label((string) $r->kind),
            'to' => (string) $r->recipient,
            'subject' => (string) $r->subject,
            'status' => in_array($status, ['sent', 'failed', 'sending'], true) ? $status : 'unknown',
            'error' => $r->error !== null && $status === 'failed' ? mb_substr((string) $r->error, 0, 160) : '',
            'at_label' => $at?->format('j M Y, g:i A') ?? '',
        ];
    }

    /** A plain-words name for MailLog's kind label. */
    public static function label(string $kind): string
    {
        $k = str_starts_with($kind, 'order.') ? substr($kind, 6) : $kind;

        return match (true) {
            $k === 'confirmation' => 'Order confirmation',
            $k === 'merchant_alert' => 'New-order alert (to the shop)',
            $k === 'refund' => 'Refund',
            $k === 'status_onhold' => 'On-hold email',
            str_starts_with($k, 'reminder_') => 'Payment reminder (' . str_replace('_', ' ', substr($k, 9)) . ')',
            str_starts_with($k, 'status_') => 'Status email: ' . ucfirst(str_replace('_', ' ', substr($k, 7))),
            $k === 'cart.recovery' => 'Basket reminder',
            $k === 'account.password_reset' => 'Password reset',
            $k === 'account.verify_email' => 'Email verification',
            default => ucfirst(str_replace(['.', '_'], ' ', $k)),
        };
    }
}
