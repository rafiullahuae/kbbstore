<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Services\CartRecovery;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Growth & Marketing → Cart Tracking → Reminders (the owner, 23 October 2026).
 *
 *   "when user enter the email in the email field, and we silently captured
 *    it ... the abandoned cart email not sending. i have checked multiple
 *    times, but nothing showing in sent emails. make sure such customers must
 *    get abandoned cart emails, and i need proper record for those in the cart
 *    tracking page as new tab"
 *
 * WHY HE SAW NOTHING. A typed address becomes a reminder only behind gates
 * that each fail silently: the Abandoned cart module, an English subject and
 * message, and a "When to send" schedule -- the last two ship EMPTY, and an
 * empty one sends nothing, ever (CartRecovery's header). With any of them shut,
 * the Carts tab still says "Typed at checkout" and no screen said why no email
 * followed. This answers both halves: gates() names every gate and whether it
 * is open, and rows() gives every basket with an address its own status line.
 *
 * COST: rows() is one query and one count, whatever the number of baskets
 * (the per-row facts are correlated sub-selects in the same statement), so the
 * tab stays flat as the shop grows. Read-only: nothing here sends or writes.
 */
final class ReminderLog
{
    public const PER_PAGE = 50;

    public function __construct(
        private CartRecovery $recovery,
        private SettingsService $settings,
        private CartTrackingSettings $ct,
    ) {}

    /**
     * Every gate between a typed address and a sent email, in the order he
     * would fix them, each with where to fix it.
     *
     * @return list<array{key: string, ok: bool, label: string, where: string}>
     */
    public function gates(): array
    {
        $schedule = $this->recovery->schedule();
        $subject = trim((string) $this->settings->get(CartRecovery::KEY_SUBJECT, ''));
        $body = trim((string) $this->settings->get(CartRecovery::KEY_BODY, ''));

        return [
            ['key' => 'module', 'ok' => $this->recovery->enabled(), 'label' => 'Abandoned cart reminders are switched on',
                'where' => 'Store → Modules → Abandoned Cart Recovery'],
            ['key' => 'subject', 'ok' => $subject !== '', 'label' => 'The reminder has a subject line',
                'where' => 'Store → Ecommerce → Basket reminders → Reminder subject line'],
            ['key' => 'body', 'ok' => $body !== '', 'label' => 'The reminder has a message',
                'where' => 'Store → Ecommerce → Basket reminders → Reminder message'],
            ['key' => 'schedule', 'ok' => $schedule !== [], 'label' => $schedule === []
                    ? 'A time to send is set'
                    : 'Sent '.self::hours($schedule[0]).' after the email is typed',
                'where' => 'Store → Ecommerce → Basket reminders → When to send'],
            ['key' => 'capture', 'ok' => (bool) $this->ct->get('capture_email'), 'label' => 'Emails typed at checkout are recorded',
                'where' => 'Cart Tracking → Settings'],
            ['key' => 'remind', 'ok' => (bool) $this->ct->get('capture_reminder'), 'label' => 'Typed emails get the reminder',
                'where' => 'Cart Tracking → Settings'],
        ];
    }

    /**
     * One page of baskets that carry an address -- typed at checkout or given
     * at the cart's tick box -- newest first, each with what happened.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function rows(int $page = 1): array
    {
        $page = max(1, $page);

        if (! Schema::hasTable('cart_recoveries') || ! Schema::hasColumn('carts', 'ct_email')) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => self::PER_PAGE];
        }

        $base = DB::table('carts as c')
            ->leftJoin('cart_recoveries as r', 'r.cart_id', '=', 'c.id')
            ->where(fn ($w) => $w->whereNotNull('c.ct_email')->orWhereNotNull('r.id'));

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->orderByDesc('c.id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->select([
                'c.id as cart_id', 'c.status', 'c.ct_email', 'c.ct_bot_score', 'c.updated_at as cart_updated_at',
                'r.id as rid', 'r.email', 'r.source', 'r.stage', 'r.consented_at', 'r.last_sent_at', 'r.cancelled_at', 'r.cancel_reason',
            ])
            ->selectRaw('(select count(*) from cart_items ci where ci.cart_id = c.id) as items')
            ->selectRaw('(select coalesce(sum(ci.quantity * ci.unit_price), 0) from cart_items ci where ci.cart_id = c.id) as value_fils')
            ->selectRaw('exists(select 1 from outbound_optouts o where o.email = coalesce(r.email, c.ct_email)) as opted_out')
            ->when(Schema::hasTable('email_bounces'), fn ($q) => $q->selectRaw(
                'exists(select 1 from email_bounces b where b.cleared_at is null and lower(b.email) = coalesce(r.email, c.ct_email)) as bounced'))
            ->get();

        $schedule = $this->recovery->schedule();
        $threshold = (int) $this->ct->get('bot_threshold');
        $out = [];

        foreach ($rows as $row) {
            [$state, $text] = $this->status($row, $schedule, $threshold);
            $out[] = [
                'cart' => (int) $row->cart_id,
                'email' => (string) ($row->email ?? $row->ct_email ?? ''),
                'how' => $row->rid === null || $row->source === CartRecovery::SOURCE_TYPED ? 'Typed at checkout' : 'Asked at the cart',
                'value' => (int) $row->value_fils,
                'value_label' => \App\Support\Money::plain((int) $row->value_fils),
                'items' => (int) $row->items,
                'state' => $state,
                'status' => $text,
                'at' => self::iso($row->consented_at ?? $row->cart_updated_at),
            ];
        }

        return ['rows' => $out, 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'pages' => max(1, (int) ceil($total / self::PER_PAGE))];
    }

    /**
     * What happened to one basket's reminder, as a state for the badge
     * (sent / due / waiting / stopped / none) and a sentence.
     *
     * @param  list<float>  $schedule
     * @return array{0: string, 1: string}
     */
    private function status(object $row, array $schedule, int $threshold): array
    {
        if ((int) ($row->stage ?? 0) > 0) {
            return ['sent', 'Sent '.self::when((string) $row->last_sent_at)];
        }

        if ($row->rid === null) {
            return ['none', 'Not queued: reminders were off when this email was typed'];
        }

        if ($row->cancelled_at !== null) {
            return ['stopped', match ((string) $row->cancel_reason) {
                'ordered' => 'Not needed: they placed an order',
                'optout' => 'Stopped: they unsubscribed',
                default => 'Stopped ('.(string) $row->cancel_reason.')',
            }];
        }

        if ($row->status !== 'active') {
            return ['stopped', 'Not needed: the basket was checked out'];
        }

        if ((int) $row->items === 0) {
            return ['stopped', 'Not sent: the basket is empty now'];
        }

        if ((bool) $row->opted_out) {
            return ['stopped', 'Not sent: this address unsubscribed'];
        }

        if ((bool) ($row->bounced ?? false)) {
            return ['stopped', 'Not sent: earlier mail to this address bounced'];
        }

        if ($row->source === CartRecovery::SOURCE_TYPED) {
            if ((int) ($row->ct_bot_score ?? 0) >= $threshold && $threshold > 0) {
                return ['stopped', 'Not sent: the visit looked like a bot'];
            }

            if ($row->ct_email !== null && $row->ct_email !== $row->email) {
                return ['stopped', 'Not sent: the email on the basket was changed'];
            }
        }

        if ($schedule === []) {
            return ['blocked', 'Waiting: reminders are off or not set up (see the checks above)'];
        }

        $due = strtotime((string) $row->consented_at) + (int) round($schedule[0] * 3600);

        return $due > time()
            ? ['waiting', 'Due '.date('j M, H:i', $due)]
            : ['due', 'Sending: goes out with the next visit to the shop'];
    }

    /** @param  float  $h */
    private static function hours(float $h): string
    {
        if ($h < 1) {
            return (int) round($h * 60).' minutes';
        }

        return ($h == (int) $h ? (string) (int) $h : rtrim(rtrim(number_format($h, 2), '0'), '.')).' hour'.($h == 1.0 ? '' : 's');
    }

    private static function iso(mixed $at): ?string
    {
        $t = is_string($at) ? strtotime($at) : false;

        return $t === false ? null : date('Y-m-d\TH:i', $t);
    }

    private static function when(string $at): string
    {
        $t = strtotime($at);

        return $t === false ? '' : date('j M, H:i', $t);
    }
}
