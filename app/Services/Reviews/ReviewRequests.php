<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Mail\ReviewRequestMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
use App\Support\Url;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "What did you think?" — the review request, N days after an order is
 * completed (Lane RV).
 *
 * The owner, 10 October: "i need another email template, which will be sent
 * after 2 days of order completion, as feedback ask ... make sure on front-end
 * real reviews submitted from this feedback email and stored in the list to
 * approve etc."
 *
 * ── WHEN THE CLOCK STARTS ──────────────────────────────────────────────────
 *
 * `orders.completed_at` is written by the WooCommerce importer and nothing
 * else, so it cannot be the clock. record() writes one `review_requests` row at
 * the moment an order's status turns `completed`, through an Eloquent
 * `updated` listener — every status writer in the shop goes through
 * Services\Orders\OrderStatus::moveTo(), which saves the model, so the listener
 * sees all of them. The row is the clock: due at completed_at + N days.
 *
 * ── WHY APPLYING THE PACKAGE MAILS NOBODY ──────────────────────────────────
 *
 * No row is ever back-filled. An order completed before this code ran has no
 * row, and the sweep reads nothing but rows — so "only orders completed after
 * the package" is a property of the data, not a date comparison that could be
 * got wrong. An order completed while the switch is off gets no row either.
 *
 * ── HOW IT IS SENT ─────────────────────────────────────────────────────────
 *
 * The same two drivers as the "Complete your order" reminders and the
 * feedback request: OrderReminders::sweep() calls sweep() here, and that runs
 * from the scheduler (`kbb:order-reminders`, every minute, when the cron line
 * is installed) and from OrderReminderTick (after an ordinary page has been
 * answered, at most once a minute) when it is not. A row is claimed with a
 * conditional UPDATE (scheduled → sending), so both running is harmless.
 *
 * ── THE LINK ───────────────────────────────────────────────────────────────
 *
 * A random 256-bit token per order, base64url, 43 characters; only its
 * SHA-256 is stored. No order id in the URL. find() hashes whatever it is given
 * and asks for that hash, so a forged token, a token that was never issued and
 * an expired one do the same work and get the same null — the decoy shape
 * QuizSubmission::findByPublicToken() set for this repo.
 *
 * NOTHING HERE MAY THROW into the request that triggered it: record() runs
 * after a status change commits, sweep() after a page has been answered.
 */
final class ReviewRequests
{
    public const TABLE = 'review_requests';

    public const OPTOUTS = 'review_request_optouts';

    /** `reviews.source` of a review written from this email. */
    public const SOURCE = 'review_request';

    /**
     * `reviews.source_id` of such a review is order_id × KEY_BASE + product_id.
     *
     * Not the bare order id, because `reviews` carries UNIQUE(source, source_id)
     * (reviews_source_unique, the importer's external-id key): one order with
     * three products would collide on its second review. Encoded this way the
     * same index becomes the rule the brief asks for — ONE review per product
     * per order, refused by the database rather than by a prior read — and the
     * order is still recoverable (intdiv) and still range-scannable on that
     * index. Product ids are far below a billion; one that is not is skipped.
     */
    public const KEY_BASE = 1_000_000_000;

    public static function sourceId(int $orderId, int $productId): int
    {
        return $orderId * self::KEY_BASE + $productId;
    }

    /**
     * Restrict a reviews query to the ones written from $orderId's request.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function reviewsOf($query, int $orderId)
    {
        return $query->where('source', self::SOURCE)
            ->whereBetween('source_id', [$orderId * self::KEY_BASE, $orderId * self::KEY_BASE + self::KEY_BASE - 1]);
    }

    /** The page's path. Not under any InstantNav prefix, so it is never prefetched. */
    public const PATH = '/review-order/';

    public function __construct(private ReviewRequestSettings $settings) {}

    /* ------------------------------------------------------------ the clock */

    /** Hook the clock to the order model. Attaches a listener; reads nothing. */
    public static function register(): void
    {
        Order::updated(static function (Order $order): void {
            try {
                if (! $order->wasChanged('status') || (string) $order->status !== 'completed') {
                    return;
                }

                DB::afterCommit(static function () use ($order): void {
                    try {
                        app(self::class)->record($order);
                    } catch (\Throwable $e) {
                        Log::warning('review request not scheduled', ['order_id' => $order->getKey(), 'exception' => class_basename($e)]);
                    }
                });
            } catch (\Throwable) {
                // A status change must never fail because of this email.
            }
        });
    }

    /** An order has just been completed: start its clock. True when a row was written. */
    public function record(Order $order): bool
    {
        if (! $this->settings->enabled()
            || $order->wc_order_id !== null
            || \App\Services\Orders\SampleOrder::is($order)
            || trim((string) $order->email) === '') {
            return false;
        }

        try {
            $now = now();

            return DB::table(self::TABLE)->insertOrIgnore([
                'order_id' => (int) $order->getKey(),
                'email' => self::normalise((string) $order->email),
                'completed_at' => $now,
                'status' => 'scheduled',
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;
        } catch (\Throwable $e) {
            // The table is missing until the package's migration has run.
            Log::warning('review request not recorded', ['exception' => class_basename($e)]);

            return false;
        }
    }

    /* ------------------------------------------------------------ the sweep */

    /** Send what is due, up to $limit. Returns how many went. */
    public function sweep(int $limit = 10, ?Carbon $now = null): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $now ??= now();
        $sent = 0;

        try {
            if (! $this->settings->enabled()) {
                return 0;
            }

            foreach ($this->due($limit, $now) as $row) {
                if ($this->sendOne($row, $now)) {
                    $sent++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('review request sweep failed', ['exception' => class_basename($e), 'message' => $e->getMessage()]);
        }

        return $sent;
    }

    /** Rows whose time has come, oldest first. One indexed query. */
    public function due(int $limit, Carbon $now): Collection
    {
        $cutoff = $now->copy()->subDays($this->settings->int('review_request_days'));

        return DB::table(self::TABLE)
            ->where('status', 'scheduled')
            ->where('completed_at', '<=', $cutoff)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->limit(max(0, $limit))
            ->get();
    }

    /** Claim one row and send it, or record why not. True only when it was handed to the transport. */
    public function sendOne(object $row, Carbon $now): bool
    {
        $claimed = DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'sending', 'updated_at' => $now]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            return $this->sendClaimed($row, $now);
        } catch (\Throwable $e) {
            // Claimed and not sent: recorded as failed, never retried — an
            // email sent twice is worse than one lost (OrderEmailLog's rule).
            Log::warning('review request failed', ['order_id' => $row->order_id, 'exception' => class_basename($e)]);

            return $this->finish((int) $row->id, 'failed', 'error');
        }
    }

    private function sendClaimed(object $row, Carbon $now): bool
    {
        $days = $this->settings->int('review_request_days');
        $completed = Carbon::parse((string) $row->completed_at);

        if ($completed->copy()->addDays($days + ReviewRequestSettings::GIVE_UP_DAYS)->lt($now)) {
            return $this->finish($row->id, 'skipped', 'too_late');
        }

        $order = Order::query()->with('items')->find((int) $row->order_id);

        if ($order === null) {
            return $this->finish($row->id, 'skipped', 'order_gone');
        }

        $reason = $this->refusal($order, $row, $now);

        if ($reason !== null) {
            return $this->finish($row->id, 'skipped', $reason);
        }

        $ids = $this->productIdsFor($order);

        if ($ids === []) {
            return $this->finish($row->id, 'skipped', 'nothing_to_review');
        }

        $token = self::newToken();

        DB::table(self::TABLE)->where('id', $row->id)->update([
            'token_hash' => self::hash($token),
            'expires_at' => $now->copy()->addDays($this->settings->int('review_request_link_days')),
            'products' => json_encode($ids),
            'updated_at' => $now,
        ]);

        $ok = app(OrderMailer::class)->reviewRequest($order, static fn () => new ReviewRequestMail($order, $token, $ids));

        DB::table(self::TABLE)->where('id', $row->id)->update([
            'status' => $ok ? 'sent' : 'failed',
            'reason' => $ok ? null : 'send_failed',
            'sent_at' => $ok ? $now : null,
            'updated_at' => $now,
        ]);

        if ($ok) {
            try {
                $order->notes()->create([
                    'author' => 'system',
                    'is_customer_note' => false,
                    'content' => sprintf('Emailed the customer the review request ("What did you think?") for %d product%s to %s.', count($ids), count($ids) === 1 ? '' : 's', (string) $order->email),
                ]);
            } catch (\Throwable) {
                // A courtesy for the order screen; the send stands.
            }
        }

        return $ok;
    }

    /** Why this order is not asked, or null when it may be. */
    private function refusal(Order $order, object $row, Carbon $now): ?string
    {
        if ($order->wc_order_id !== null || \App\Services\Orders\SampleOrder::is($order) || trim((string) $order->email) === '') {
            return 'not_eligible';
        }

        if (self::optedOut((string) $order->email)) {
            return 'unsubscribed';
        }

        if (! ReviewSettings::get(app(SettingsService::class), 'sr_allow_submit')) {
            // Store → Reviews → Accept new reviews is off: asking for one the
            // page would refuse is worse than not asking.
            return 'reviews_closed';
        }

        if ($this->settings->bool('review_request_require_completed') && (string) $order->status !== 'completed') {
            return 'not_completed';
        }

        if ($this->settings->bool('review_request_skip_refunded')) {
            try {
                if (app(\App\Services\Payments\PaymentRefunder::class)->refundedFils($order) > 0 || (string) $order->status === 'refunded') {
                    return 'refunded';
                }
            } catch (\Throwable) {
                return 'refunded';
            }
        }

        $cooldown = $this->settings->int('review_request_cooldown_days');

        if ($cooldown > 0 && DB::table(self::TABLE)
            ->where('email', (string) $row->email)
            ->where('id', '!=', $row->id)
            ->where('status', 'sent')
            ->where('sent_at', '>', $now->copy()->subDays($cooldown))
            ->exists()) {
            return 'asked_recently';
        }

        return null;
    }

    private function finish(int $id, string $status, string $reason): bool
    {
        DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'reason' => $reason, 'updated_at' => now()]);

        return false;
    }

    /* ---------------------------------------------------------- what to ask */

    /**
     * The products of $order to ask about: still on sale, once each, in the
     * order they were bought, capped. Two queries at most, whatever the order
     * holds.
     *
     * @return list<int>
     */
    public function productIdsFor(Order $order, ?bool $skipReviewed = null): array
    {
        $skipReviewed ??= $this->settings->bool('review_request_skip_reviewed');
        $order->loadMissing('items');
        $ids = [];

        foreach ($order->items as $item) {
            $id = (int) ($item->product_id ?? 0);

            if ($id > 0 && $id < self::KEY_BASE && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $visible = array_flip(array_map('intval', Product::query()->visible()->whereIn('id', $ids)->pluck('id')->all()));
        $ids = array_values(array_filter($ids, static fn (int $id) => isset($visible[$id])));

        if ($skipReviewed && $ids !== []) {
            $done = array_flip(array_map('intval', Review::query()
                ->whereIn('product_id', $ids)
                ->where('author_email', (string) $order->email)
                ->pluck('product_id')->all()));
            $ids = array_values(array_filter($ids, static fn (int $id) => ! isset($done[$id])));
        }

        return array_slice($ids, 0, $this->settings->int('review_request_max_products'));
    }

    /* -------------------------------------------------------------- the link */

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', 'kbb-review-request:' . $token);
    }

    /** The review page. A blank token (a preview, a test send) gives a link that opens the "not valid" page. */
    public static function pageUrl(string $token, array $query = []): string
    {
        $path = self::PATH . rawurlencode($token !== '' ? $token : 'preview') . '/';

        return Url::external($path) . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    public static function stopUrl(string $token): string
    {
        return Url::external(self::PATH . rawurlencode($token !== '' ? $token : 'preview') . '/stop/');
    }

    /**
     * The request a token opens, or null. Null for a forged token, one never
     * issued, one whose email did not go, and one past its expiry — the same
     * work and the same answer for all of them.
     */
    public function find(string $token, bool $ignoreExpiry = false): ?object
    {
        $hash = self::hash($token);

        try {
            $row = DB::table(self::TABLE)->where('token_hash', $hash)->first();
        } catch (\Throwable) {
            $row = null;
        }

        $stored = is_object($row) ? (string) $row->token_hash : str_repeat('0', 64);
        $match = hash_equals($stored, $hash);

        if (! $match || ! is_object($row) || (string) $row->status !== 'sent') {
            return null;
        }

        if (! $ignoreExpiry && ($row->expires_at === null || Carbon::parse((string) $row->expires_at)->isPast())) {
            return null;
        }

        return $row;
    }

    /* ------------------------------------------------------------ opting out */

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Has this address said stop? Unreadable list → true (fail closed: never mail someone who asked us not to). */
    public static function optedOut(string $email): bool
    {
        try {
            return DB::table(self::OPTOUTS)->where('email', self::normalise($email))->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    public static function optOut(string $email): void
    {
        $email = self::normalise($email);

        if ($email === '') {
            return;
        }

        try {
            DB::table(self::OPTOUTS)->insertOrIgnore(['email' => $email, 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::TABLE)->where('email', $email)->where('status', 'scheduled')
                ->update(['status' => 'skipped', 'reason' => 'unsubscribed', 'updated_at' => now()]);
        } catch (\Throwable) {
            // The page the recipient is on must not 500.
        }
    }

    /* ---------------------------------------------------------- the report */

    /**
     * The small report on Schedule & rules. Three grouped queries, whatever
     * the size of the shop.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        try {
            $byStatus = DB::table(self::TABLE)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
            $reasons = DB::table(self::TABLE)->where('status', 'skipped')->groupBy('reason')->selectRaw('reason, COUNT(*) AS n')->pluck('n', 'reason')->map(fn ($n) => (int) $n)->all();
            $reviews = DB::table('reviews')->where('source', self::SOURCE)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
            $last = DB::table(self::TABLE)->where('status', 'sent')->max('sent_at');
        } catch (\Throwable) {
            return ['ready' => false];
        }

        $sent = (int) ($byStatus['sent'] ?? 0);
        $received = array_sum($reviews);
        $answered = 0;

        try {
            $answered = (int) DB::table(self::TABLE)->where('status', 'sent')->where('reviews_count', '>', 0)->count();
        } catch (\Throwable) {
        }

        return [
            'ready' => true,
            'scheduled' => (int) ($byStatus['scheduled'] ?? 0) + (int) ($byStatus['sending'] ?? 0),
            'sent' => $sent,
            'skipped' => (int) ($byStatus['skipped'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'skipped_by' => $reasons,
            'reviews' => $received,
            'pending' => (int) ($reviews['pending'] ?? 0),
            'approved' => (int) ($reviews['approved'] ?? 0),
            'answered' => $answered,
            'rate' => $sent > 0 ? (int) round(100 * $answered / $sent) : 0,
            'last_sent' => $last === null ? null : Carbon::parse((string) $last)->toIso8601String(),
        ];
    }
}
