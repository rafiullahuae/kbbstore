<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\OutboundOptOut;
use Illuminate\Support\Facades\DB;

/**
 * Abandoned-cart recovery: the dangerous one (Lane EN).
 *
 * This feature stores a shopper's email address BEFORE they have bought
 * anything and then writes to them about it. That is the whole of what makes it
 * different from every other email this shop sends, and everything below is
 * shaped by it.
 *
 * ---------------------------------------------------------------------------
 * OFF, AND UNWRITTEN
 * ---------------------------------------------------------------------------
 * Three gates, all of which must be open:
 *
 *   the module switch    `abandoned_cart` in module_toggles, default false.
 *   the owner's wording  `cart_recovery_subject` / `_body`, default ''.
 *   the schedule         `cart_recovery_schedule`, default ''.
 *
 * The third is the one that has no counterpart in the back-in-stock feature and
 * it is deliberate. "How long after, and how many messages" is not a number a
 * lane gets to choose on an owner's behalf — the brief says so and it is right
 * — so the schedule box ships EMPTY and an empty schedule sends nothing, ever.
 * On with wording and no schedule is a feature that captures consent and never
 * uses it, which is the safe half of the wrong answer rather than a silent one:
 * the backlog panel on the Sent mail screen names it in words.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS STORED, WHEN, AND HOW SOMEBODY GETS OUT — IN FULL
 * ---------------------------------------------------------------------------
 * STORED: the address the shopper typed, lower-cased; the cart it belongs to;
 * the moment they ticked the box; and which form took it. Nothing else. The
 * basket contents are not copied — the cart rows already are the basket, and a
 * second copy is a second thing to find when somebody asks to be forgotten.
 *
 * WHEN: only on an explicit request. capture() requires an address AND a tick
 * that was not pre-ticked, and Store\CartRecoveryController rejects a post
 * without both. There is no implicit capture anywhere — not from a signed-in
 * customer's account address, not from a checkout field they were typing into
 * anyway. A shopper who is logged in has given this shop their address for
 * orders; they have not asked to be chased about a basket, and the two are not
 * the same permission. This is the single most important sentence in the file.
 *
 * ▲ ONE EXCEPTION, THE OWNER'S, 15 OCTOBER 2026 (Lane CAP): "only the left cart
 *   email should go auto. nothing else." An address typed into the checkout's
 *   email field and recorded by App\Support\CheckoutEmailCapture gets THIS
 *   reminder -- the same message, the same unsubscribe link, the same sweep --
 *   ONCE, after the first delay of the owner's schedule. captureTyped() is the
 *   only way in, behind its own switch (Cart Tracking → Settings → "Send the
 *   left-cart reminder to emails typed at checkout") as well as every gate
 *   above. Its rows are source 'checkout_email' and due() holds them to more
 *   than an opt-in row: stage 0 only, never a bot-scored cart, never an address
 *   on email_suppressions or with an uncleared bounce, and only while it is
 *   still the address on the cart (a typo the shopper corrected is never sent).
 *   The sentence above stays true of everything else.
 *
 * HOW THEY GET OUT: three ways, all of which work without signing in.
 *   1. Every message carries an unsubscribe link (Support\OutboundOptOut). It
 *      cancels this sequence AND writes the address to `outbound_optouts`, so a
 *      later capture is refused rather than merely stopped.
 *   2. Buying. An order carrying the address cancels every live sequence for
 *      it — see cancelForEmail() and the race section below.
 *   3. Doing nothing. The sequence runs to the end of the owner's schedule and
 *      stops. It has no next stage to claim, so nothing further is ever sent.
 *
 * ---------------------------------------------------------------------------
 * WHAT COUNTS AS ABANDONED
 * ---------------------------------------------------------------------------
 * A cart is abandoned when it is still `active` (not converted), still has
 * something in it, and the owner's first interval has elapsed since the shopper
 * gave consent. Consent time, not last-activity time, is the clock — because
 * `carts.last_activity_at` moves whenever anything touches the cart, so a
 * shopper idly adding an item on day three would restart a sequence that is
 * supposed to run once. The brief's "never restarted by a reload" is enforced
 * by the unique index on cart_id; using consent time as the clock is what stops
 * it being restarted by activity either.
 *
 * ---------------------------------------------------------------------------
 * THE RACE THAT MATTERS: THEY ORDER WHILE THE MESSAGE IS BEING PREPARED
 * ---------------------------------------------------------------------------
 * A recovery email for an order already placed is worse than sending nothing.
 * There are four barriers, and they are in order of how much they are worth:
 *
 *  1. THE ORDER CANCELS THE SEQUENCE, INSIDE THE ORDER'S OWN TRANSACTION.
 *     cancelForEmail() runs from an Eloquent `created` hook on Order, which
 *     fires inside Store\CheckoutController's DB::transaction(). So the
 *     cancellation commits with the order or rolls back with it — there is no
 *     state in which an order exists and its recovery row is still live. It
 *     cancels by ADDRESS, not by cart id, which deliberately over-cancels: a
 *     shopper with two baskets who buys from one will not be chased about the
 *     other. Over-cancelling costs a marketing email; under-cancelling costs
 *     the thing this whole section exists to prevent.
 *
 *  2. THE CLAIM IS A COMPARE-AND-SWAP THAT INCLUDES `cancelled_at IS NULL`.
 *     A single UPDATE, so it cannot interleave with itself. If the order's
 *     transaction commits first, the claim changes zero rows and nothing is
 *     sent. If the claim lands first, the order's cancellation writes over a
 *     row whose stage has already moved — the message goes out a moment BEFORE
 *     the order, which is harmless: it says "your basket is waiting" to
 *     somebody who was in the act of buying it.
 *
 *  3. A FINAL RE-READ IMMEDIATELY BEFORE THE TRANSPORT. Between the claim
 *     committing and the mailer being called there is a real gap — the
 *     mailable has to be built and rendered. sendable() re-asks, and that
 *     narrows the window to the microseconds between the last SELECT and the
 *     handoff. It does NOT give the stage back: the row it is protecting is
 *     cancelled, and a cancelled row can never claim again, so there is nothing
 *     to restore and nothing that could double-send.
 *
 *  4. THE CART STATUS IS CHECKED TOO, not only the cancellation flag. A cart
 *     that converted through a path that never created an Order row carrying
 *     this address — a manual order built in the admin, say, which
 *     ManualOrderBuilder marks converted directly — is still caught, because
 *     due() only ever returns carts that are `active` and non-empty.
 *
 * What remains is a window measured in microseconds in which a shopper pays at
 * the exact instant a message is handed to the transport. That window cannot be
 * closed without holding a database lock across an SMTP conversation, which on
 * this host is a 20-second table lock on the orders table — a cure that takes
 * the checkout down. The trade is recorded here rather than left to be
 * rediscovered.
 */
class CartRecovery
{
    /** The module switch. */
    public const MODULE = 'abandoned_cart';

    /** Settings keys. All default to '' — see the header. */
    public const KEY_OPTIN_LABEL = 'cart_recovery_optin_label';

    public const KEY_SUBJECT = 'cart_recovery_subject';

    public const KEY_BODY = 'cart_recovery_body';

    public const KEY_SCHEDULE = 'cart_recovery_schedule';

    /**
     * The most messages a sequence may ever contain, whatever the owner types.
     *
     * Not a tuning knob — a ceiling. The schedule box is free text, and
     * "2,6,12,24,48,72,96,120" is a sequence that would make this shop's domain
     * a spam source from one typo. Eight is far above any defensible sequence
     * and far below a mailbox flood.
     */
    public const MAX_STAGES = 8;

    /** The shortest interval the box will accept, in hours. */
    public const MIN_INTERVAL_HOURS = 0.25;

    /** The one answer a capture ever gets. See StockAlerts::CONFIRM_MESSAGE. */
    public const CONFIRM_MESSAGE = 'Thank you — we will email you a reminder about this basket. There is an unsubscribe link in every message.';

    public const OUTCOME_STORED = 'stored';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_SUPPRESSED = 'suppressed';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public function __construct(private SettingsService $settings) {}

    /* ------------------------------------------------------------- the gates */

    /**
     * The module switch, read in the one place anything reads it. The key is
     * spelled out rather than passed as self::MODULE — see
     * StockAlerts::enabled() for why a constant here would make the switch
     * invisible to the registry guard and therefore, by that guard's
     * definition, not a switch at all.
     */
    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('abandoned_cart', false);
    }

    /** The wording beside the tick box, or null when the owner has written none. */
    public function optInLabel(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $text = trim((string) $this->settings->get(self::KEY_OPTIN_LABEL, ''));

        return $text === '' ? null : $text;
    }

    /**
     * The message's wording, or null. Both halves required — see
     * StockAlerts::messageWording().
     *
     * @return array{subject: string, body: string}|null
     */
    public function messageWording(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $subject = trim((string) $this->settings->get(self::KEY_SUBJECT, ''));
        $body = trim((string) $this->settings->get(self::KEY_BODY, ''));

        if ($subject === '' || $body === '') {
            return null;
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * The owner's schedule, as hours-after-consent per stage.
     *
     * "4, 24" means two messages: one four hours after the box was ticked and
     * one twenty-four hours after it. NOT four hours and then another
     * twenty-four on top — every entry is measured from the same fixed point,
     * because a sequence measured from the previous SEND drifts by however long
     * the tick took to notice, and a shopper who was promised a message at
     * twenty-four hours should not get it at thirty-one because the shop was
     * quiet overnight.
     *
     * Empty, unparseable or all-rejected returns [] and [] SENDS NOTHING. The
     * entries are sorted and de-duplicated, so an owner who types "24,4,4"
     * gets the sequence they plainly meant rather than an error.
     *
     * @return list<float>
     */
    public function schedule(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $raw = trim((string) $this->settings->get(self::KEY_SCHEDULE, ''));

        if ($raw === '') {
            return [];
        }

        $hours = [];

        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $piece) {
            if ($piece === '' || ! is_numeric($piece)) {
                // Silently dropped rather than fatal: a stray comma must not
                // turn a working schedule into no schedule at all, which would
                // be a feature that stopped without saying so.
                continue;
            }

            $value = (float) $piece;

            if ($value < self::MIN_INTERVAL_HOURS || $value > 24 * 60) {
                continue;
            }

            $hours[] = $value;
        }

        $hours = array_values(array_unique($hours, SORT_REGULAR));
        sort($hours);

        return array_slice($hours, 0, self::MAX_STAGES);
    }

    /* ---------------------------------------------------------- the capture */

    /**
     * Store an address against a cart, because the shopper asked us to.
     *
     * $consented is passed in rather than read from a request here, so that the
     * one place that decides whether a tick box was ticked is the controller,
     * and this method cannot be called in a way that captures without it.
     */
    public function capture(int $cartId, string $email, bool $consented, string $source = 'cart'): string
    {
        if (! $this->enabled() || ! $consented) {
            return self::OUTCOME_UNAVAILABLE;
        }

        $email = OutboundOptOut::normalise($email);

        if ($email === '' || OutboundOptOut::suppressed($email)) {
            return self::OUTCOME_SUPPRESSED;
        }

        // A closed list. `source` reaches a database column and a screen; it is
        // never free text off a request.
        if (! in_array($source, ['cart', 'checkout'], true)) {
            $source = 'cart';
        }

        $now = now();

        try {
            /*
             * insertOrIgnore against `cart_recoveries_one_per_cart`.
             *
             * THIS IS "NEVER RESTARTED BY A RELOAD", and it is the database
             * saying so. A shopper who reloads the cart page, or whose browser
             * re-posts the form, produces a statement that writes zero rows —
             * `consented_at` keeps the value the first capture wrote and
             * `stage` keeps whatever the sequence has reached. There is no
             * SELECT here whose answer could be stale by the time the INSERT
             * ran.
             *
             * A SECOND ADDRESS ON THE SAME CART DOES NOT REPLACE THE FIRST, and
             * that is the right way round: the sequence belongs to the cart, one
             * person consented for it, and letting a later visitor to the same
             * browser overwrite the address would send one shopper's basket to
             * another shopper's inbox.
             */
            $written = DB::table('cart_recoveries')->insertOrIgnore([
                'cart_id' => $cartId,
                'email' => $email,
                'source' => $source,
                'stage' => 0,
                'consented_at' => $now,
                'last_sent_at' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
            return self::OUTCOME_UNAVAILABLE;
        }

        return $written > 0 ? self::OUTCOME_STORED : self::OUTCOME_DUPLICATE;
    }

    /** cart_recoveries.source for an address typed at checkout (Lane CAP). */
    public const SOURCE_TYPED = 'checkout_email';

    /** A typed address gets at most one reminder in this many days, however many baskets it left. */
    public const TYPED_ONCE_PER_DAYS = 7;

    /**
     * The owner's exception (see the header): a reminder for the address a
     * shopper TYPED at checkout. Called only by CheckoutEmailCapture, behind
     * its switch. The same one-row-per-cart index as capture():
     *
     *   - no row yet: one is written, source 'checkout_email', its clock
     *     starting now;
     *   - the shopper's OWN opt-in row (source cart/checkout): untouched, ever;
     *   - a typed row that has not been sent and is not cancelled: it MOVES to
     *     the new address, so the typo before a tapped fix is never emailed;
     *   - a typed row already sent, or cancelled: untouched. Never a second.
     */
    public function captureTyped(int $cartId, string $email): string
    {
        if (! $this->enabled()) {
            return self::OUTCOME_UNAVAILABLE;
        }

        $email = OutboundOptOut::normalise($email);

        if ($email === '') {
            return self::OUTCOME_UNAVAILABLE;
        }

        $suppressed = OutboundOptOut::suppressed($email);
        $now = now();

        try {
            $row = DB::table('cart_recoveries')->where('cart_id', $cartId)
                ->first(['id', 'source', 'email', 'stage', 'cancelled_at']);

            if ($row === null) {
                if ($suppressed) {
                    return self::OUTCOME_SUPPRESSED;
                }

                $written = DB::table('cart_recoveries')->insertOrIgnore([
                    'cart_id' => $cartId,
                    'email' => $email,
                    'source' => self::SOURCE_TYPED,
                    'stage' => 0,
                    'consented_at' => $now,
                    'last_sent_at' => null,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $written > 0 ? self::OUTCOME_STORED : self::OUTCOME_DUPLICATE;
            }

            if ($row->source !== self::SOURCE_TYPED || $row->email === $email
                || (int) $row->stage > 0 || $row->cancelled_at !== null) {
                return self::OUTCOME_DUPLICATE;
            }

            DB::table('cart_recoveries')
                ->where('id', $row->id)
                ->where('source', self::SOURCE_TYPED)
                ->where('stage', 0)
                ->whereNull('cancelled_at')
                ->update($suppressed
                    ? ['email' => $email, 'cancelled_at' => $now, 'cancel_reason' => 'optout', 'updated_at' => $now]
                    : ['email' => $email, 'updated_at' => $now]);
        } catch (\Throwable) {
            return self::OUTCOME_UNAVAILABLE;
        }

        return $suppressed ? self::OUTCOME_SUPPRESSED : self::OUTCOME_STORED;
    }

    /* ------------------------------------------------------- the cancellation */

    /**
     * An order was placed by this address. Stop chasing it.
     *
     * CALLED FROM AN ELOQUENT `created` HOOK ON Order, which fires inside
     * Store\CheckoutController's DB::transaction(). That placement is the whole
     * point and it is not an optimisation: it means the cancellation and the
     * order commit together. There is no instant at which an order exists and a
     * live recovery row for its address does not.
     *
     * Returns the number of sequences stopped, for the tests.
     *
     * NOTHING HERE MAY THROW. It runs inside the transaction that is writing a
     * customer's order. A missing table — this package landed without its
     * migration, which has happened on this host — must not roll back a sale.
     * OrderMailObserver's header states the same rule for the same reason.
     */
    public function cancelForEmail(string $email, string $reason = 'ordered'): int
    {
        $email = OutboundOptOut::normalise($email);

        if ($email === '') {
            return 0;
        }

        try {
            return DB::table('cart_recoveries')
                ->where('email', $email)
                ->whereNull('cancelled_at')
                ->update([
                    'cancelled_at' => now(),
                    'cancel_reason' => substr($reason, 0, 20),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /* ------------------------------------------------------------ the sweep */

    /**
     * Sequences whose next message is due now.
     *
     * Everything this returns has been checked four ways: the sequence is live,
     * the cart is still `active`, the cart still has something in it, and the
     * owner's interval for the NEXT stage has elapsed since consent.
     *
     * The interval comparison is done in PHP rather than in SQL. The schedule
     * is a per-stage list, so the SQL for "due" would be a CASE over `stage`
     * built from owner-supplied numbers — string-built SQL over configuration
     * text, on two engines whose date arithmetic differs. Reading the small set
     * of live sequences and filtering them here is a few rows and no dialect.
     *
     * @return list<object>
     */
    public function due(int $limit): array
    {
        $schedule = $this->schedule();

        if ($schedule === []) {
            return [];
        }

        try {
            $rows = DB::table('cart_recoveries')
                ->join('carts', 'carts.id', '=', 'cart_recoveries.cart_id')
                ->whereNull('cart_recoveries.cancelled_at')
                ->where('cart_recoveries.stage', '<', count($schedule))
                // The cart is still open. See barrier 4 in the header.
                ->where('carts.status', 'active')
                // ... and still has something in it. A basket somebody emptied
                // is not an abandoned basket, and an email about it would
                // describe nothing.
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('cart_items')
                        ->whereColumn('cart_items.cart_id', 'cart_recoveries.cart_id');
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('outbound_optouts')
                        ->whereColumn('outbound_optouts.email', 'cart_recoveries.email');
                })
                // Lane CAP: an address typed at checkout, held to more. See the header.
                ->where(fn ($w) => $this->typedRule($w))
                ->orderBy('cart_recoveries.id')
                // Read more than the budget, because some will not be due yet
                // once the interval is applied below; without the headroom a
                // single not-yet-due row at the front would starve the rest.
                ->limit(max($limit * 4, 20))
                ->get([
                    'cart_recoveries.id',
                    'cart_recoveries.cart_id',
                    'cart_recoveries.email',
                    'cart_recoveries.stage',
                    'cart_recoveries.consented_at',
                    'cart_recoveries.source',
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }

        $now = now();
        $out = [];
        $typed = [];

        foreach ($rows as $row) {
            $stage = (int) $row->stage;

            if (! isset($schedule[$stage])) {
                continue;
            }

            $consented = $row->consented_at !== null ? strtotime((string) $row->consented_at) : false;

            if ($consented === false) {
                continue;
            }

            if ($now->getTimestamp() < $consented + (int) round($schedule[$stage] * 3600)) {
                continue;
            }

            // Lane CAP: and within one sweep, one typed reminder per address.
            if (($row->source ?? null) === self::SOURCE_TYPED) {
                if (isset($typed[$row->email])) {
                    continue;
                }

                $typed[$row->email] = true;
            }

            $out[] = $row;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Every row that is not typed at checkout passes, exactly as before. A
     * typed row passes only with both switches on, at stage 0 (once), on a
     * cart that is not bot-scored, while it is still the cart's address, and
     * for an address with no suppression and no uncleared bounce. A table it
     * needs that is missing fails the typed rows CLOSED, never the others.
     */
    private function typedRule(\Illuminate\Database\Query\Builder $w): void
    {
        $w->where('cart_recoveries.source', '!=', self::SOURCE_TYPED);

        try {
            $ct = app(\App\Services\CartTracking\CartTrackingSettings::class);
            $on = (bool) $ct->get('capture_email') && (bool) $ct->get('capture_reminder');
            $threshold = (int) $ct->get('bot_threshold');
            $ready = $on && \Illuminate\Support\Facades\Schema::hasColumn('carts', 'ct_email')
                && \Illuminate\Support\Facades\Schema::hasTable('email_suppressions')
                && \Illuminate\Support\Facades\Schema::hasTable('email_bounces');
        } catch (\Throwable) {
            $ready = false;
        }

        if (! $ready) {
            return;
        }

        $w->orWhere(function ($t) use ($threshold) {
            $t->where('cart_recoveries.source', self::SOURCE_TYPED)
                ->where('cart_recoveries.stage', 0)
                ->whereRaw('COALESCE(carts.ct_bot_score, 0) < ?', [$threshold])
                ->whereColumn('carts.ct_email', 'cart_recoveries.email')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('email_suppressions as es')
                    ->whereRaw('LOWER(es.email) = cart_recoveries.email'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('email_bounces as eb')
                    ->whereNull('eb.cleared_at')
                    ->whereRaw('LOWER(eb.email) = cart_recoveries.email'))
                // One reminder per ADDRESS, not one per basket: three baskets
                // left from three visits are one shopper and one email. Never
                // when this address was reminded in the last week, nor for a
                // basket left BEFORE a reminder that address already had.
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('cart_recoveries as cr2')
                    ->whereColumn('cr2.email', 'cart_recoveries.email')
                    ->whereColumn('cr2.id', '!=', 'cart_recoveries.id')
                    ->whereNotNull('cr2.last_sent_at')
                    ->where(fn ($w) => $w->where('cr2.last_sent_at', '>', now()->subDays(self::TYPED_ONCE_PER_DAYS))
                        ->orWhereColumn('cr2.last_sent_at', '>=', 'cart_recoveries.consented_at')));
        });
    }

    /**
     * Take ownership of one stage, or discover somebody else has.
     *
     * THE COMPARE-AND-SWAP, and the second barrier in the header. `stage` is
     * both the compare and the swap, so two sweeps racing to move 0 -> 1
     * produce one winner and one zero-row update. `cancelled_at IS NULL` is in
     * the same statement, so an order that committed a microsecond earlier
     * takes the claim with it.
     */
    public function claim(int $id, int $fromStage): bool
    {
        try {
            $affected = DB::table('cart_recoveries')
                ->where('id', $id)
                ->where('stage', $fromStage)
                ->whereNull('cancelled_at')
                ->update([
                    'stage' => $fromStage + 1,
                    'last_sent_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            return false;
        }

        return $affected === 1;
    }

    /**
     * The last look before the transport. Barrier 3 in the header.
     *
     * Asked AFTER the claim and immediately BEFORE the mailer, because building
     * and rendering a mailable takes long enough for a checkout to complete.
     * A false here abandons the send and leaves the claim standing: the row it
     * is protecting is cancelled, a cancelled row can never claim again, and so
     * there is nothing to give back and nothing that could double-send.
     */
    public function sendable(int $id): bool
    {
        try {
            return DB::table('cart_recoveries')
                ->join('carts', 'carts.id', '=', 'cart_recoveries.cart_id')
                ->where('cart_recoveries.id', $id)
                ->whereNull('cart_recoveries.cancelled_at')
                ->where('carts.status', 'active')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The basket, as the message will describe it.
     *
     * Read at send time rather than copied at capture time, so the email says
     * what is in the cart NOW. A shopper who removed the expensive thing must
     * not be chased about the expensive thing.
     *
     * ── AND WHAT IS INSIDE A SET (Lane SE) ────────────────────────────────
     *
     * A Set is ONE cart line at ONE price, so a basket holding one was chased
     * with a single anonymous name — "Glow Set" — and the shopper being asked
     * to come back could not see what they had left behind. `setContents` is
     * the member list, one string per member, from the same
     * App\Support\SetContents every other surface in this application reads.
     *
     * ▲ THE LIVE PIVOT HERE, AND THAT IS THE OPPOSITE OF THE ORDER RULE, ON
     *   PURPOSE. Every ORDER document reads the `order_items.set_contents`
     *   SNAPSHOT, because an order is a record of a day. A basket is not: it is
     *   read at send time precisely so the message says what is in the cart NOW
     *   — the paragraph above this one is the whole reason this method exists —
     *   and a set whose contents the owner changed this morning should be
     *   described as it is this morning. fromProduct() is the right reader for
     *   the same reason the cart drawer and the checkout summary use it.
     *
     * ▲ NO MONEY COMES FROM THE MEMBERS. SetContents::lines() prints a quantity
     *   and a name and nothing else; the figure in the message stays
     *   `unit_price * quantity` off the cart line, exactly as before. A set is
     *   sold as one product at one price and this email does not re-derive it.
     *
     * ▲ NOT ONE EXTRA QUERY FOR A SHOP WITH NO SETS. The flat join below is
     *   unchanged apart from two more columns on the same statement; the
     *   Eloquent lookup underneath runs only when a set is actually in the
     *   basket, and SetEagerLoad then batches the members for all of them at
     *   once rather than one query per member. A basket of twenty ordinary
     *   products costs exactly what it cost before this paragraph was written.
     *
     * @return list<array{name: string, slug: string, quantity: int, unit_price: int, setContents: list<string>}>
     */
    public function basket(int $cartId): array
    {
        try {
            $rows = DB::table('cart_items')
                ->leftJoin('products', 'products.id', '=', 'cart_items.product_id')
                ->where('cart_items.cart_id', $cartId)
                ->orderBy('cart_items.id')
                ->limit(20)
                ->get([
                    'products.id as product_id',
                    'products.type as product_type',
                    'products.name',
                    'products.slug',
                    'cart_items.quantity',
                    'cart_items.unit_price',
                ]);

            /*
             * The ids of the SETS only, so a basket with none of them never
             * reaches the database a second time. `whereKey` and not a fresh
             * filter: these ids came from the statement above.
             */
            $setIds = $rows
                ->filter(fn ($row) => (string) ($row->product_type ?? '') === 'set' && $row->product_id !== null)
                ->pluck('product_id')
                ->unique()
                ->all();

            $sets = [];

            if ($setIds !== []) {
                $products = \App\Models\Product::whereKey($setIds)->get();
                \App\Support\SetEagerLoad::on($products);

                foreach ($products as $product) {
                    $sets[(int) $product->getKey()] = \App\Support\SetContents::lines(
                        \App\Support\SetContents::fromProduct($product)
                    );
                }
            }

            return $rows
                ->map(fn ($row) => [
                    'name' => (string) ($row->name ?? 'Item'),
                    'slug' => (string) ($row->slug ?? ''),
                    'quantity' => (int) $row->quantity,
                    'unit_price' => (int) $row->unit_price,
                    // An EMPTY LIST for every ordinary line, which is every line
                    // in a basket with no set in it — so the message such a
                    // basket produces is byte-for-byte the message it was.
                    'setContents' => $sets[(int) ($row->product_id ?? 0)] ?? [],
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
