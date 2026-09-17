<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

/**
 * What makes anything send, on a host with no worker (Lane EN).
 *
 * ===========================================================================
 * THE CONSTRAINT, STATED PLAINLY
 * ===========================================================================
 * There is no queue worker on this host. There is no shell, no supervisor and
 * no cron — CLAUDE.md says so, Support\ProductVisibility's header says so, and
 * the image-sizes and product-editor screens both exist in the shape they do
 * because of it. So `queue:work` is not an option that is merely inconvenient;
 * there is nothing on this server that could run it.
 *
 * That forces one thing above all others: NOTHING HAPPENS UNLESS A REQUEST
 * HAPPENS. Whatever triggers a send has to be a web request, because a web
 * request is the only thing that ever executes PHP here.
 *
 * ===========================================================================
 * WHAT WAS CHOSEN, AND WHY IT IS RIGHT HERE
 * ===========================================================================
 * A tick on the tail of ordinary requests. Every request the application
 * finishes asks this class whether a sweep is due; at most one sweep runs per
 * interval, it sends at most a handful of messages, and it runs AFTER the
 * response has gone to the browser.
 *
 * The three properties that make it the right answer on this host:
 *
 *  1. NO SHOPPER WAITS. The work is inside `defer()`, which runs after the
 *     response is sent. That is the same primitive, chosen for the same
 *     reason, as Store\SubscribeController::dispatchConfirmation() —
 *     `defer()` and not `app()->terminating()`, because terminating callbacks
 *     are never cleared from the Application and would send twice under
 *     anything that handles two requests in one process. And it is NAMED, so
 *     two calls in one request collapse to one.
 *
 *  2. IT COSTS NOTHING WHEN THE FEATURES ARE OFF. Both modules ship off, so the
 *     shipped state of this class is an array lookup against a settings cache
 *     the request has already loaded, and then a return. No query, no cache
 *     write, no lock. A feature that is off must not be a tax on every page of
 *     a shop that never turns it on.
 *
 *  3. THE RESTOCK ITSELF TRIGGERS IT. The commonest way a product comes back is
 *     somebody saving it in the admin, and an admin save is a request, so it
 *     ticks like any other. In practice an alert goes out within one interval
 *     of the restock that caused it, driven by the very action that caused it.
 *
 * ===========================================================================
 * WHAT HAPPENS IF NOTHING TRIGGERS FOR A WEEK
 * ===========================================================================
 * Nothing is sent, and NOTHING IS LOST. Every owed message is a row: a
 * `stock_alerts` row with `notified_at` still NULL, or a `cart_recoveries` row
 * whose `stage` has not moved. When the next request arrives the sweep finds
 * them and works through the backlog a budget at a time.
 *
 * That is the honest failure mode and it must be said out loud: on a shop with
 * no visitors, this feature does not run. A shop with no visitors also has no
 * restocks to announce and no baskets to chase, so the case is largely
 * self-limiting — but not entirely, because the owner restocking products in
 * the admin at midnight IS traffic, and a week of silence on a live shop is a
 * real thing that can happen during a holiday.
 *
 * So the pile is not allowed to be silent. Services\OutboundBacklog counts
 * exactly these rows and names the oldest, and it is served to the Sent mail
 * screen beside the delivery log. CLAUDE.md's standing complaint about
 * swallowed mail failures is that the owner has no shell and cannot read
 * storage/logs; a pile of unsent rows nobody watches would be the same defect
 * in a new place. The answer is the same answer: put it on a screen he already
 * looks at.
 *
 * ===========================================================================
 * WHAT WAS REJECTED
 * ===========================================================================
 *   A QUEUE. Nothing would run it. `QUEUE_CONNECTION=sync` means a queued job
 *   executes inline, which is this class without the rate limit or the budget.
 *
 *   AN EXTERNAL PINGER (cron-job.org hitting a URL). It works, and it makes the
 *   shop's outbound mail depend on a third party the owner must set up, must
 *   remember, and will not notice has stopped. It also needs a public endpoint
 *   that causes mail to be sent, which is a thing to guard forever. The tick
 *   below needs no setup at all, which on a shop whose updates arrive as zip
 *   files applied by hand is worth more than precision. If the owner later
 *   wants punctuality, an endpoint is a small addition ON TOP of this, not
 *   instead of it — and this stays as the floor.
 *
 *   SENDING AT THE MOMENT OF RESTOCK, from wherever the stock was written.
 *   `products.stock_status` is written from at least four places, one of them
 *   (Services\StockClaim::release(), when a cancelled order puts units back)
 *   with a raw query-builder update that fires no model events at all. A hook
 *   wired to the writers would miss that one silently — and would put an SMTP
 *   conversation inside the transaction that is returning stock. StockAlerts'
 *   header has the long version.
 */
class OutboundTick
{
    /**
     * At most one sweep per this many seconds, per shop.
     *
     * Five minutes. Short enough that a restock is announced while the shopper
     * who asked still remembers asking; long enough that a shop under load is
     * not running a sweep on a meaningful fraction of its requests. It is a
     * ceiling on frequency and not a promise of it: on a quiet shop the real
     * interval is however long it is between visitors.
     */
    public const INTERVAL = 300;

    /**
     * The most messages one tick may send, across both features.
     *
     * A BUDGET, not a batch size. The work runs on the tail of a stranger's
     * page view, in a PHP-FPM worker that other requests are waiting for; an
     * unbounded sweep would tie that worker up for as long as the backlog took.
     * Ten sends against MailSettings::DEFAULT_TRANSPORT — the server's own
     * mail() — is a few milliseconds. Against a remote SMTP server it is the
     * one case worth being conservative about, which is why the number is ten
     * and not a hundred.
     *
     * A backlog larger than the budget is not dropped; it is worked through one
     * tick at a time, oldest first.
     */
    public const BUDGET = 10;

    /** The rate-limit key, and the record of when a sweep last ran. */
    public const LOCK_KEY = 'kbb.outbound.tick';

    public const LAST_RUN_KEY = 'kbb.outbound.tick.last';

    public function __construct(
        private SettingsService $settings,
        private OutboundSender $sender,
    ) {}

    /**
     * Called on the tail of every request the application finishes.
     *
     * THE CHEAP GUARD COMES FIRST AND IS NOT NEGOTIABLE. `moduleEnabled()`
     * reads a cache the storefront has already loaded, so the shipped state
     * (both modules off) costs an array lookup. Only once something is switched
     * on does this touch the cache store at all.
     */
    public function onRequest(): void
    {
        try {
            if (! $this->anythingOn()) {
                return;
            }

            /*
             * Cache::add is the rate limit and it is atomic on every store this
             * app can be configured with: it writes only if the key is absent
             * and reports whether it did. A get-then-put would let two
             * simultaneous requests both decide a sweep was due.
             *
             * The lock is taken BEFORE the work is deferred, not after it
             * succeeds. If the worker dies before the deferred callback runs,
             * this interval is simply skipped — which is the correct failure
             * mode, because the alternative is a lock that is never taken and a
             * sweep on every request.
             */
            if (! Cache::add(self::LOCK_KEY, time(), self::INTERVAL)) {
                return;
            }

            // Named, so two calls in one request are one sweep. See the header.
            defer(fn () => $this->run(), 'kbb-outbound-tick');
        } catch (\Throwable) {
            /*
             * Silently. This is attached to the end of every request in the
             * application, including the checkout's. A cache store that is
             * momentarily unwritable must not turn a shopper's page into a 500
             * over an email that could just as well go out five minutes later.
             */
        }
    }

    /**
     * One sweep. Public so a test — and, later, an admin "send now" button —
     * can run it without waiting for a lock to expire.
     *
     * @return array{stock: int, cart: int}
     */
    public function run(): array
    {
        $result = ['stock' => 0, 'cart' => 0];

        try {
            /*
             * Back-in-stock first, and the ordering is deliberate rather than
             * alphabetical. An alert is a message somebody explicitly asked for
             * and is time-critical — the product is in stock NOW and may not be
             * in an hour. A basket reminder is the shop's idea, and an hour
             * later it is the same reminder. When the budget is short, the
             * thing that was asked for wins.
             */
            $result['stock'] = $this->sender->sendStockAlerts(self::BUDGET);

            $left = self::BUDGET - $result['stock'];

            if ($left > 0) {
                $result['cart'] = $this->sender->sendCartReminders($left);
            }

            /*
             * Recorded whether or not anything went out. "The sweep ran and
             * there was nothing to do" and "no sweep has run for two days" are
             * completely different situations and the backlog panel has to be
             * able to tell them apart — otherwise a shop with no traffic looks
             * exactly like a shop whose feature is working.
             */
            Cache::put(self::LAST_RUN_KEY, time(), 86400 * 7);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('The outbound tick failed.', [
                'exception' => $e::class,
            ]);
        }

        return $result;
    }

    /**
     * Is either feature switched on? The whole of the shipped-state guard.
     *
     * ASKED WITHOUT A QUERY, and that is the point rather than a refinement.
     *
     * This runs on the tail of every request in the application. The ordinary
     * `moduleEnabled()` builds the module snapshot when it is cold, which is
     * one SELECT — invisible on the server, where the cache is a warm file that
     * every storefront page has already read, and one extra query on a cold
     * cache. That is a real cost imposed by two features that are switched OFF,
     * and tests/Feature/AdminCustomersTest.php counts the queries on the
     * customer detail page and was right to fail when it appeared.
     *
     * `moduleEnabledIfKnown()` returns null when nobody has warmed the
     * snapshot, and null here means "not now". A skipped tick costs one
     * interval at most, and costs nothing at all in the end, because everything
     * owed is a row in a table and the next tick will find it. A feature that is
     * off must not be a tax on every page of a shop that never turns it on.
     */
    public function anythingOn(): bool
    {
        return $this->settings->moduleEnabledIfKnown('back_in_stock', false) === true
            || $this->settings->moduleEnabledIfKnown('abandoned_cart', false) === true;
    }
}
