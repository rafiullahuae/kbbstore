<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Services\SettingsService;

/**
 * Which status changes email the customer, and the one-order exception to it.
 *
 * The owner asked for two things and they are different things:
 *
 *   "give options on the backend view order page, to control auto emails to
 *    such statuses. So I can select to which status the auto emails should be
 *    sent upon changing the status."
 *
 *   1. A STANDING RULE, per status. Already half-existed as the module switches
 *      Store → Modules → Order emails — `email_order_shipped` and
 *      `email_order_cancelled`. This class does not invent a second place to
 *      keep that answer, because two places to keep one answer is how they
 *      drift; it reads and writes those same toggles and adds the vocabulary
 *      around them so a screen can render the whole list rather than the two
 *      rows somebody happened to expose.
 *
 *   2. A DECISION ABOUT ONE ORDER, taken while looking at it. Suppressing the
 *      dispatch note on an order the customer is standing in the shop for, or
 *      forcing one on an order they asked to be told about. That is NOT a
 *      setting: it applies to a single save and is then gone.
 *
 * WHERE THE GATE SITS, AND WHY IT IS NOT ON THE CONTROLLER. `orders.status` is
 * written from five places in this application, one of which
 * (OrdersApiController::bulkStatus) is a query-builder `update()` that fires no
 * model events at all — which is how bulk status changes once emailed nobody in
 * this store, silently, with the biggest batches the most likely to be silent.
 * A gate implemented in the order-detail controller would have exactly that
 * shape of hole. So the gate is consulted inside OrderMailer::statusChanged(),
 * the single method both the observer and the bulk path already call, and every
 * writer is governed by it whether it knows this class exists or not.
 *
 * A STATUS WITH NO MESSAGE CANNOT BE SWITCHED ON. OrderStatusChanged::WORDING
 * is the closed list of statuses a customer is owed a message about, and its
 * header sets out why `processing`, `refunded`, `completed`, `failed`, `draft`
 * and `onhold` are deliberately silent — chiefly that two of them would mean a
 * second email about an event another email already covers. A toggle whose only
 * effect would be to do nothing is the exact fault CLAUDE.md records this
 * project shipping three times, so `supported` is reported alongside `enabled`
 * and the screen says why rather than offering a dead tick box.
 */
class OrderStatusMailPolicy
{
    /**
     * The store's whole status vocabulary, in the order a screen should list it.
     *
     * Taken from AdminController::updateOrderStatus's own validation rule —
     * `in:draft,pending,processing,onhold,shipped,completed,cancelled,refunded,failed`
     * — which is the widest set anything in this application will accept. It is
     * repeated rather than imported because that rule is a validation string in
     * another lane's controller and there is nothing there to import; the test
     * that pins the two against each other is what keeps them honest.
     *
     * @var list<string>
     */
    public const STATUSES = [
        'draft',
        'pending',
        'processing',
        'onhold',
        'shipped',
        'completed',
        'cancelled',
        'refunded',
        'failed',
    ];

    /**
     * status => the module toggle that governs it.
     *
     * The same two keys OrderMailer reads by name. Spelled out per status
     * rather than assembled from the status, because Phase3ModuleSwitchesTest
     * greps the source for `moduleEnabled('<key>'` to prove a registry row
     * marked `live` really has a reader, and a key built at runtime defeats that
     * check — the same reasoning OrderMailer's own header gives.
     *
     * @var array<string, string>
     */
    public const MODULE_KEYS = [
        'shipped' => 'email_order_shipped',
        'cancelled' => 'email_order_cancelled',
    ];

    /**
     * Why each silent status is silent, in the words a screen can print.
     *
     * Not decoration. An operator who cannot switch `processing` on deserves to
     * be told it is because the confirmation email went out seconds earlier,
     * rather than left to conclude the tick box is broken.
     *
     * @var array<string, string>
     */
    public const SILENT_REASONS = [
        'draft' => 'Internal bookkeeping — a draft has not been placed yet.',
        'pending' => 'The order has only just been placed; the confirmation email covers it.',
        'processing' => 'The confirmation email already said so, seconds earlier. Cash on delivery moves an order here during checkout itself.',
        'onhold' => 'Internal bookkeeping. Nothing about the order has changed for the customer.',
        'completed' => 'Internal bookkeeping. The dispatch email is what the customer is waiting for.',
        'refunded' => 'Money going back is its own email, sent when the refund actually settles rather than when this column is typed.',
        'failed' => 'Written when a gateway declines during checkout, where the shopper is already looking at the error.',
    ];

    /**
     * Per-order decisions taken in THIS request, keyed by order id.
     *
     * Request-scoped because the service is bound scoped (MailServiceProvider),
     * which is what lets a controller record the operator's choice before
     * saving and lets the Eloquent observer — which never sees the request —
     * act on it a moment later. Nothing here is written to the database and
     * nothing survives the response.
     *
     * @var array<int, bool>
     */
    private array $perOrder = [];

    /**
     * A decision for every order this request touches, when no per-order one
     * was recorded. This is what the bulk screen sends.
     */
    private ?bool $forRequest = null;

    /**
     * Why customer mail is being held back right now, or null when it is not.
     *
     * NOT A THIRD KIND OF DECISION. The two above are somebody CHOOSING whether
     * a customer hears about a change. This is the opposite: a statement that
     * the writes happening right now are not events in the customer's life at
     * all, so there is nothing to tell them about. The WooCommerce importer
     * re-syncs historical statuses onto orders that already exist, and every one
     * of those saves fires Eloquent's `updated` event and reaches this class —
     * which is how re-running an import could tell a real person that the order
     * they placed in 2023 had just shipped.
     *
     * IT OUTRANKS EVERYTHING, including a per-order `notify` tick. Nothing that
     * could be ticked on a screen exists during an import, so the only way a
     * decision could be in the bag at that moment is by having leaked from
     * earlier in the process — and a leak must not be able to turn the
     * suppression off.
     */
    private ?string $suppressedBecause = null;

    /** How many messages the suppression has held back, for the caller to report. */
    private int $suppressed = 0;

    public function __construct(private SettingsService $settings) {}

    /* --------------------------------------------------- bulk, machine writes */

    /**
     * Run $work with customer status mail held back, and put it back afterwards.
     *
     * ── WHAT THIS SUPPRESSES, EXACTLY ──────────────────────────────────────
     *
     * The status-change email to the CUSTOMER, and nothing else. Refund mail is
     * driven by the `refunds` row settling rather than by a status column and
     * never passes through here; the merchant copies, the invoice, the test send
     * and everything else in OrderMailer are equally untouched. An import that
     * silently muted the whole application would be a worse hazard than the one
     * it was fixing, so the narrowness is the point and it is asserted by tests
     * rather than described here.
     *
     * ── AND IT IS COUNTED ──────────────────────────────────────────────────
     *
     * suppressedCount() rises once per message held back, so the caller can say
     * so out loud. The importer does: the run's report carries a note naming the
     * number of customers who were not written to. Silence that nobody can see
     * afterwards is indistinguishable from mail that failed.
     *
     * ── try/finally, DELIBERATELY ──────────────────────────────────────────
     *
     * This service is bound `scoped`, which in a CLI import means it lives as
     * long as the process. A suppression that leaked past an import that threw
     * would leave the shop unable to email anybody for the rest of that process,
     * with nothing on any screen to say why. The previous value is restored
     * rather than null, so nesting cannot silently un-suppress an outer run.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public function whileSuppressed(string $reason, callable $work): mixed
    {
        $previous = $this->suppressedBecause;
        $this->suppressedBecause = $reason;

        try {
            return $work();
        } finally {
            $this->suppressedBecause = $previous;
        }
    }

    /** How many customer messages have been held back in this process so far. */
    public function suppressedCount(): int
    {
        return $this->suppressed;
    }

    /* ------------------------------------------------------- the standing rule */

    /** Is there a customer message designed for this status at all? */
    public function supported(string $status): bool
    {
        return OrderStatusChanged::handles($status);
    }

    /**
     * Does this status email the customer, as things stand?
     *
     * False for every status with no message, whatever any setting says — see
     * the class header. The default for the two that do have one is ON, which
     * is what the store did before any of this existed.
     */
    public function enabled(string $status): bool
    {
        /*
         * Asked of OrderMailer rather than read here, so each module key keeps
         * exactly ONE reader in this application. Those two methods carry the
         * literal key spellings that Phase3ModuleSwitchesTest greps for, and a
         * second `moduleEnabled('email_order_shipped', ...)` in this file would
         * be a second answer waiting to disagree with the first — including
         * about the default, which is what a store with nothing configured runs
         * on. Resolved on use, not injected: OrderMailer fetches this class the
         * same way, and two scoped services that construct each other would not
         * resolve at all.
         */
        $mailer = app(OrderMailer::class);

        return match ($status) {
            'shipped' => $mailer->shippedEnabled(),
            'cancelled' => $mailer->cancelledEnabled(),
            default => false,
        };
    }

    /**
     * Turn one status on or off.
     *
     * Through SettingsService, never by writing the row: the toggle map is held
     * in a forever-cache that setModule() clears and a direct write would not,
     * leaving every reader in the application on the old value until something
     * else happened to clear it.
     *
     * Returns false for a status there is no message for, rather than storing a
     * toggle that could never take effect.
     */
    public function setEnabled(string $status, bool $on): bool
    {
        $key = self::MODULE_KEYS[$status] ?? null;

        if ($key === null) {
            return false;
        }

        $this->settings->setModule($key, $on);

        return true;
    }

    /**
     * Every status, with what a screen needs to draw it.
     *
     * @return list<array{status:string,label:string,supported:bool,enabled:bool,reason:string}>
     */
    public function all(): array
    {
        return array_map(fn (string $status): array => [
            'status' => $status,
            'label' => ucfirst($status),
            'supported' => $this->supported($status),
            'enabled' => $this->enabled($status),
            'reason' => $this->supported($status) ? '' : (self::SILENT_REASONS[$status] ?? ''),
        ], self::STATUSES);
    }

    /* --------------------------------------------------- the one-order exception */

    /**
     * "Email the customer about this change", as ticked on one order.
     *
     * Recorded BEFORE the status is saved, because saving is what fires the
     * observer that sends. `null` clears any decision and hands the order back
     * to the standing rule, which is what a caller that sent no checkbox means.
     */
    public function decideFor(Order $order, ?bool $notify): void
    {
        $id = (int) $order->getKey();

        if ($notify === null) {
            unset($this->perOrder[$id]);

            return;
        }

        $this->perOrder[$id] = $notify;
    }

    /** The same, for every order in a bulk action. */
    public function decideForRequest(?bool $notify): void
    {
        $this->forRequest = $notify;
    }

    /**
     * The answer for one order and one status, which is what OrderMailer asks.
     *
     * A status with no message is silent no matter who ticked what: `notify`
     * can suppress an email and it can restore one the standing rule switched
     * off, but it cannot invent wording that does not exist. Anything else
     * would be a tick box that produces a blank email.
     */
    public function shouldNotify(Order $order, string $status): bool
    {
        if (! $this->supported($status)) {
            return false;
        }

        /*
         * BEFORE the decisions, not after. See $suppressedBecause: a bulk or
         * per-order `notify` that had leaked into this process from earlier must
         * not be able to turn an import's suppression back on. Counted here
         * rather than at the send, because this is the point at which a message
         * that would have gone out stops going out, and the count is what the
         * importer reports to the owner.
         */
        if ($this->suppressedBecause !== null) {
            $this->suppressed++;

            return false;
        }

        $decision = $this->perOrder[(int) $order->getKey()] ?? $this->forRequest;

        return $decision ?? $this->enabled($status);
    }
}
