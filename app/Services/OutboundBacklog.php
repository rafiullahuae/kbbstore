<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "What is owed, and why has it not gone?" (Lane EN)
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 *
 * Both features work by writing a row and letting a later request send it. On a
 * host with no worker that is the only design available (see OutboundTick), and
 * it has one failure mode that matters: A PILE OF UNSENT ROWS NOBODY WATCHES.
 *
 * It is the same defect the mail delivery log was built to close, in a new
 * place. CLAUDE.md's complaint about swallowed mail failures is not that they
 * are swallowed — OrderMailer swallows them deliberately so a dead mail server
 * cannot fail an order — but that the evidence goes to storage/logs, which the
 * owner of this shop cannot read because he has no shell. A backlog that only
 * shows up as an absence of emails is worse than that, because there is no
 * evidence anywhere at all.
 *
 * So this counts the owed rows and, more usefully, says WHY each pile is not
 * moving. There are five distinct reasons and they need five different actions
 * from the owner, which is exactly why a bare number would not do:
 *
 *   'off'          the module is switched off. Nothing is owed and the pile, if
 *                  there is one, is history. Not a fault.
 *   'unwritten'    the module is on and the owner has not written the message.
 *                  THIS IS THE ONE THAT LOOKS LIKE SUCCESS AND IS NOT: consent
 *                  is being collected, rows are accumulating and nothing will
 *                  ever be sent. It is the state the "off and unwritten"
 *                  shipping rule produces the moment somebody flips the switch
 *                  and stops.
 *   'unscheduled'  cart recovery only: written, but the schedule box is empty,
 *                  so there is no time at which anything is due.
 *   'waiting'      everything is configured and these rows are not due yet.
 *                  Healthy.
 *   'due'          everything is configured, these rows ARE due, and they are
 *                  waiting for the next tick. Healthy if small and recent;
 *                  a fault if the oldest is hours old, which means the shop has
 *                  had no traffic and nothing has swept.
 *
 * ── AND WHEN THE SWEEP ITSELF STOPPED ──────────────────────────────────────
 *
 * `last_swept_at` is the other half. "Nothing is due" and "nothing has looked"
 * are indistinguishable from the outside and need opposite responses, so the
 * tick records when it last ran and this reports it. A null means no sweep has
 * run since the cache was last cleared — which, on a host where applying a
 * package clears the caches, is also what the morning after an update looks
 * like. Said in those words rather than presented as an alarm.
 *
 * ── ADMIN ONLY ─────────────────────────────────────────────────────────────
 *
 * Counts and timestamps only; no addresses, ever. Even so this is served from
 * one authenticated endpoint in the admin-api group, never from `/api/*`, which
 * CLAUDE.md records as unauthenticated by design and leaky three times over. A
 * count of who is waiting for a product is still commercial information.
 */
class OutboundBacklog
{
    public function __construct(
        private StockAlerts $alerts,
        private CartRecovery $recovery,
    ) {}

    /**
     * The whole picture, as the screen draws it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $last = Cache::get(OutboundTick::LAST_RUN_KEY);

        return [
            'stock' => $this->stock(),
            'cart' => $this->cart(),
            'last_swept_at' => is_numeric($last) ? date(DATE_ATOM, (int) $last) : null,
            'sweep_interval_seconds' => OutboundTick::INTERVAL,
            'sends_per_sweep' => OutboundTick::BUDGET,
            /*
             * Said in the payload rather than left for the screen to know. The
             * person reading this needs to understand that "due" does not mean
             * "late" until a visitor arrives, and a screen that explains the
             * mechanism from a string the server sent cannot drift out of step
             * with the mechanism.
             */
            'trigger' => 'Messages are sent on the tail of ordinary page views, at most one sweep every '
                . (int) round(OutboundTick::INTERVAL / 60) . ' minutes and at most '
                . OutboundTick::BUDGET . ' messages each time. This host has no scheduler, so a shop with no '
                . 'visitors sends nothing until somebody arrives. Nothing is lost while it waits.',
        ];
    }

    /**
     * Back-in-stock: how many are owed, and why they have not gone.
     *
     * "Owed" is deliberately narrower than "unsent". A request for a product
     * that is still sold out is not owed anything — it is doing exactly what it
     * was written to do. Only a request whose shelf is BACK is a message this
     * shop has not sent, which is why the due count goes through
     * StockAlerts::due() rather than counting NULLs.
     *
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        $waiting = $this->count('stock_alerts', fn ($q) => $q->whereNull('notified_at'));

        /*
         * Bounded. due() is a join and this is a screen, not a sweep; a shop
         * with fifty thousand pending alerts must not make this endpoint the
         * slowest thing in the admin. The number is presented as "at least" by
         * the screen when it hits the ceiling.
         */
        $due = count($this->alerts->due(500));

        return [
            'pending' => $waiting,
            'due' => $due,
            'oldest_due_at' => $this->oldestDue(),
            'state' => $this->state(
                enabled: $this->alerts->enabled(),
                written: $this->alerts->messageWording() !== null,
                scheduled: true,
                due: $due,
            ),
        ];
    }

    /**
     * Cart recovery: the same question, with one more way to be stuck.
     *
     * @return array<string, mixed>
     */
    private function cart(): array
    {
        $live = $this->count(
            'cart_recoveries',
            fn ($q) => $q->whereNull('cancelled_at'),
        );

        $due = count($this->recovery->due(500));

        return [
            'live' => $live,
            'due' => $due,
            'schedule_hours' => $this->recovery->schedule(),
            'state' => $this->state(
                enabled: $this->recovery->enabled(),
                written: $this->recovery->messageWording() !== null,
                scheduled: $this->recovery->schedule() !== [],
                due: $due,
            ),
        ];
    }

    /**
     * The five reasons, in the order they stop things.
     *
     * Order matters: a module that is off is off whatever else is unset, and
     * telling an owner to write a subject line for a feature he has not
     * switched on is noise that teaches him to ignore this panel.
     */
    private function state(bool $enabled, bool $written, bool $scheduled, int $due): string
    {
        if (! $enabled) {
            return 'off';
        }

        if (! $written) {
            return 'unwritten';
        }

        if (! $scheduled) {
            return 'unscheduled';
        }

        return $due > 0 ? 'due' : 'waiting';
    }

    /** The oldest request that is owed a message, as an ISO timestamp or null. */
    private function oldestDue(): ?string
    {
        $due = $this->alerts->due(1);

        if ($due === []) {
            return null;
        }

        try {
            $at = DB::table('stock_alerts')->where('id', $due[0]->id)->value('requested_at');
        } catch (\Throwable) {
            return null;
        }

        if ($at === null) {
            return null;
        }

        $stamp = strtotime((string) $at);

        return $stamp === false ? null : date(DATE_ATOM, $stamp);
    }

    /**
     * A count that tolerates the table not being there.
     *
     * The package can land before its migration runs — PackageMigrationFlagTest
     * exists because that has happened on this host — and this endpoint is one
     * an owner opens precisely when something looks wrong. A 500 on the screen
     * that was supposed to explain the problem is the worst possible answer.
     */
    private function count(string $table, callable $filter): int
    {
        try {
            $q = DB::table($table);
            $filter($q);

            return (int) $q->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
