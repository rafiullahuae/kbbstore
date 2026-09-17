<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\OutboundOptOut;
use Illuminate\Support\Facades\DB;

/**
 * Back-in-stock alerts: the whole rule, in one class (Lane EN).
 *
 * A shopper on a sold-out product asks to be told. When it comes back they get
 * ONE message. That sentence is the entire feature, and every awkward-looking
 * thing below is there because one of its words is load-bearing.
 *
 * ---------------------------------------------------------------------------
 * OFF, AND UNWRITTEN
 * ---------------------------------------------------------------------------
 * Two independent gates, and BOTH must be open before anything happens:
 *
 *   the module switch    `back_in_stock` in module_toggles, default false.
 *   the owner's wording  `stock_alert_*` in settings, default ''.
 *
 * Switching the module on with no wording shows no form and SENDS NOTHING. That
 * is not a degraded state to be tidied up later; it is the shipped state, and
 * it is the same shape Support\CheckoutLegalNotice ships in — for the reason
 * that class records at length, which is that this app arrives on a live shop
 * as a signed zip and must not put words in the owner's mouth on apply.
 *
 * SettingsService::get() returns its default only when the ROW IS ABSENT, and
 * an owner who clears a box stores ''. Both land in the same place here because
 * the default is '' too, and everything is trim()ed: a box holding a space is a
 * cleared box.
 *
 * ---------------------------------------------------------------------------
 * CONSENT: ONE PRODUCT, ONE MESSAGE, AND NOT THE MARKETING LIST
 * ---------------------------------------------------------------------------
 * A request here is consent to be told about ONE product coming back. It is not
 * a newsletter signup and this class never touches `subscribers`. That is worth
 * stating as a property rather than an intention: NewsletterList::marketable()
 * is the only query anything may send marketing from, it reads `subscribers`
 * and nothing else, and `stock_alerts` is invisible to it. There is no code
 * path from pressing "tell me when it's back" to being on the mailing list,
 * because there is no line of code that would have to be deleted to create one.
 *
 * Nor is a request an oracle. `request()` answers the same way whether the
 * address is new, already waiting, or suppressed — see the constant below and
 * Store\SubscribeController::CONFIRM_MESSAGE, which closes the identical hole
 * in the identical way.
 *
 * NO DOUBLE OPT-IN HERE, and that is a considered difference from the
 * newsletter rather than an omission. Double opt-in exists because a marketing
 * list is a standing permission that a stranger could create for somebody else
 * and that lasts until it is revoked. This is one message, about one product,
 * that says why it arrived and carries an unsubscribe — the confirmation email
 * and the alert would be the same size, sent to the same address, and requiring
 * two would mean a shopper who typed their address and walked away gets
 * nothing, which is the feature not working. The abuse ceiling is one email per
 * address per product per restock, and the route is throttled.
 *
 * ---------------------------------------------------------------------------
 * ONE MESSAGE: WHERE THE GUARANTEE ACTUALLY LIVES
 * ---------------------------------------------------------------------------
 * Two mechanisms, neither of which is a read followed by a write:
 *
 *   ASKING TWICE   the unique index `stock_alerts_one_pending_per_shelf`. The
 *                  second press is an INSERT the database refuses. See the
 *                  migration for why `slot` is not a nullable column.
 *
 *   SENDING TWICE  claim(). A single UPDATE that moves the row out of the
 *                  pending state and reports how many rows it changed. One
 *                  means this process owns the send; zero means somebody else
 *                  got there first and this one sends nothing. Two concurrent
 *                  sweeps therefore produce exactly one email, with no lock
 *                  held across an SMTP conversation.
 *
 * ---------------------------------------------------------------------------
 * RESTOCKED, THEN SOLD OUT AGAIN BEFORE THEY CLICK
 * ---------------------------------------------------------------------------
 * They got their one email and it was true when it was sent. The link lands on
 * a product page that now says Sold out — and the notify-me form is there
 * again, because it renders for anyone looking at a sold-out product and does
 * not care whether they have been alerted before. Their spent row does not
 * block a new one: `slot` has moved off 'pending', so the unique index permits
 * the fresh request.
 *
 * The alternative — keeping the row armed so it fires again on the next
 * restock — was rejected. It turns one consent into a standing subscription to
 * a product's stock level, which is not what the button said.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SWEEP LOOKS AT STOCK RATHER THAN WATCHING FOR A RESTOCK
 * ---------------------------------------------------------------------------
 * There is no single place a restock happens. `products.stock_status` is
 * written by the catalogue screen, by the bulk editor, by the importer and —
 * with a raw query builder update that fires no model events at all — by
 * Services\StockClaim::release() when an order is cancelled and its units go
 * back on the shelf. An observer wired to Eloquent would miss that last one
 * silently, which is the failure mode OrderMailObserver's header warns about
 * from the other side.
 *
 * So nothing is watched. due() asks the only question that matters — "is this
 * shelf in stock NOW" — against the table that holds the answer. It is correct
 * for a restock that happened by any means, including one that happened before
 * this code was installed.
 */
class StockAlerts
{
    /** The module switch. */
    public const MODULE = 'back_in_stock';

    /** Settings keys. All default to '' — see the header. */
    public const KEY_FORM_LABEL = 'stock_alert_form_label';

    public const KEY_SUBJECT = 'stock_alert_subject';

    public const KEY_BODY = 'stock_alert_body';

    /**
     * The one answer a request ever gets.
     *
     * Deliberately identical for "we wrote your request", "you already had
     * one" and "this address has opted out". Three different sentences would
     * let anyone with a form and a list of addresses learn which of them had
     * asked about a product, and which had opted out of this shop's email —
     * the membership oracle Store\SubscribeController's header describes,
     * rebuilt on a different table. It is true in all three cases: if that
     * address is waiting, we will write to it once.
     */
    public const CONFIRM_MESSAGE = 'Thank you — if that address is on the list for this product, we will email it once, as soon as the product is back.';

    /** Outcomes, for the delivery record and the tests. Never for the shopper. */
    public const OUTCOME_STORED = 'stored';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_SUPPRESSED = 'suppressed';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public function __construct(private SettingsService $settings) {}

    /* ------------------------------------------------------------- the gates */

    /**
     * The module switch, read in the one place anything reads it.
     *
     * THE KEY IS SPELLED OUT rather than passed as self::MODULE, and that is
     * not carelessness. tests/Feature/ModuleFrameworkGuardTest.php tokenises
     * every file in app/, resources/views/ and routes/ looking for a
     * `moduleEnabled('<key>')` call with a LITERAL first argument, and treats a
     * `live` registry row with no such call as a switch that does nothing. A
     * constant is invisible to a tokeniser, so a version written the tidy way
     * would leave both of these modules provably unread — which is the exact
     * defect that guard was written to catch, and it would be right to.
     */
    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('back_in_stock', false);
    }

    /**
     * The prose above the notify-me form, or null when there is none.
     *
     * null and not '' — the caller must render NO ELEMENT, and an empty string
     * tempts a template into printing an empty box around it. Support\TrustClaims
     * and Support\CheckoutLegalNotice both make the same choice.
     */
    public function formLabel(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $text = trim((string) $this->settings->get(self::KEY_FORM_LABEL, ''));

        return $text === '' ? null : $text;
    }

    /**
     * The message's wording, or null when the owner has not written it.
     *
     * BOTH halves are required. A subject with no body is an empty email and a
     * body with no subject is a blank subject line; either is worse than the
     * silence that is the shipped default.
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

    /* ---------------------------------------------------------- the request */

    /**
     * Take a request. Returns an outcome for the record; the CALLER decides
     * what the shopper is told, and the controller tells them the same thing
     * whatever comes back.
     *
     * The variant sentinel: 0 means "the product itself". See the migration for
     * why this column is not nullable.
     */
    public function request(int $productId, int $variantId, string $email): string
    {
        if (! $this->enabled()) {
            return self::OUTCOME_UNAVAILABLE;
        }

        $email = OutboundOptOut::normalise($email);

        /*
         * Checked here as well as in due(). The point of refusing at capture is
         * that a suppressed address never becomes a row at all, so there is
         * nothing to leak, nothing to forget to filter and nothing for a later
         * change to the due-query to accidentally include.
         */
        if ($email === '' || OutboundOptOut::suppressed($email)) {
            return self::OUTCOME_SUPPRESSED;
        }

        $now = now();

        try {
            /*
             * insertOrIgnore, so the duplicate is the DATABASE's answer.
             *
             * `insert()` inside a try/catch would work on one engine and throw
             * a differently-shaped exception on the other; insertOrIgnore
             * returns the number of rows written on both, which is the same
             * number this method needs to distinguish stored from duplicate.
             * Either way the decision is made by the unique index and never by
             * a SELECT this method ran first — see the header.
             */
            $written = DB::table('stock_alerts')->insertOrIgnore([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'email' => $email,
                'slot' => 'pending',
                'requested_at' => $now,
                'notified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
            /*
             * The table is not there — this package landed without its
             * migration, which has happened on this host before. The shopper
             * still gets CONFIRM_MESSAGE, because telling them "our database is
             * misconfigured" helps nobody; the absence shows up on the Sent
             * mail screen's backlog panel, which is where the owner looks.
             */
            return self::OUTCOME_UNAVAILABLE;
        }

        return $written > 0 ? self::OUTCOME_STORED : self::OUTCOME_DUPLICATE;
    }

    /* ------------------------------------------------------------ the sweep */

    /**
     * Requests whose shelf is back in stock and whose address may be mailed.
     *
     * The variant join is the fiddly half and it is not optional. A shopper who
     * asked about one shade of a foundation must be told when THAT shade
     * returns, not when any shade does — and a shopper who asked about a simple
     * product has no variant at all. `product_variant_id = 0` selects between
     * the two.
     *
     * Ordered by id: oldest request first. When the per-tick budget is smaller
     * than the backlog, the person who has been waiting longest goes first,
     * which is both fairer and the only ordering that cannot starve somebody
     * forever.
     *
     * @return list<object>
     */
    public function due(int $limit): array
    {
        try {
            return DB::table('stock_alerts')
                ->join('products', 'products.id', '=', 'stock_alerts.product_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_alerts.product_variant_id')
                ->whereNull('stock_alerts.notified_at')
                ->where(function ($q) {
                    // The shelf the request names, and only that shelf.
                    $q->where(function ($q) {
                        $q->where('stock_alerts.product_variant_id', 0)
                            ->where('products.stock_status', 'instock');
                    })->orWhere(function ($q) {
                        $q->where('stock_alerts.product_variant_id', '!=', 0)
                            ->where('product_variants.stock_status', 'instock');
                    });
                })
                /*
                 * A product that has been unpublished, hidden, soft-deleted or
                 * scheduled forward is not "back": the alert would carry a link
                 * to a page that 404s, which is a worse answer than silence.
                 *
                 * ProductVisibility::raw() is the SAME predicate the storefront
                 * and the sitemap use, qualified for a join, so the alert and
                 * the page it links to cannot drift apart. It costs one column
                 * listing per sweep — which the class header warns about for
                 * per-page callers and which is irrelevant here, because this
                 * runs once per tick and not once per request.
                 */
                ->where(fn ($q) => \App\Support\ProductVisibility::raw($q, 'products'))
                /*
                 * The second suppression check. A row written before an address
                 * opted out is still in this table; this is what stops it.
                 */
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('outbound_optouts')
                        ->whereColumn('outbound_optouts.email', 'stock_alerts.email');
                })
                ->orderBy('stock_alerts.id')
                ->limit($limit)
                ->get([
                    'stock_alerts.id',
                    'stock_alerts.email',
                    'stock_alerts.product_id',
                    'stock_alerts.product_variant_id',
                    'products.name as product_name',
                    'products.slug as product_slug',
                ])
                ->all();
        } catch (\Throwable) {
            // No table, or no column: nothing is due, and the backlog panel
            // reports the same absence in words.
            return [];
        }
    }

    /**
     * Take ownership of one request, or discover that somebody else has.
     *
     * THE COMPARE-AND-SWAP. One statement, no transaction, no lock held over a
     * network call. `whereNull('notified_at')` is the compare and the update is
     * the swap; the return value of update() is the number of rows the database
     * actually changed. A second sweeper running the same statement a
     * microsecond later changes zero rows and must not send.
     *
     * `slot` moves to a value containing the row's own id, which is unique by
     * construction and therefore cannot collide inside the unique index —
     * computed here in PHP rather than as `SET slot = id`, because the SQL for
     * casting an integer to text differs between SQLite and MySQL and this
     * repo has already paid for one such divergence.
     */
    public function claim(int $id): bool
    {
        try {
            $affected = DB::table('stock_alerts')
                ->where('id', $id)
                ->whereNull('notified_at')
                ->update([
                    'notified_at' => now(),
                    'slot' => 'sent:' . $id,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            return false;
        }

        return $affected === 1;
    }

    /* --------------------------------------------------------- the demand list */

    /**
     * What shoppers are waiting for, most-wanted first. A genuine reorder
     * signal, and cheap: one grouped query over an indexed column.
     *
     * Addresses are COUNTED, NOT LISTED. The owner's question is "what should I
     * reorder", and that is answered by a number. A screen that printed the
     * addresses would turn a reorder report into an export of people who have
     * asked this shop for one thing — CLAUDE.md's standing rule about what an
     * endpoint returns, applied before anybody asks for the export.
     *
     * @return list<array<string, mixed>>
     */
    public function demand(int $limit = 50): array
    {
        try {
            return DB::table('stock_alerts')
                ->join('products', 'products.id', '=', 'stock_alerts.product_id')
                ->whereNull('stock_alerts.notified_at')
                ->groupBy('stock_alerts.product_id', 'products.name', 'products.sku', 'products.stock_status')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->orderBy('products.name')
                ->limit($limit)
                ->get([
                    'stock_alerts.product_id',
                    'products.name',
                    'products.sku',
                    'products.stock_status',
                    DB::raw('COUNT(*) as waiting'),
                    DB::raw('MIN(stock_alerts.requested_at) as first_requested_at'),
                ])
                ->map(fn ($row) => [
                    'product_id' => (int) $row->product_id,
                    'name' => (string) $row->name,
                    'sku' => (string) ($row->sku ?? ''),
                    'stock_status' => (string) $row->stock_status,
                    'waiting' => (int) $row->waiting,
                    'first_requested_at' => $row->first_requested_at,
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
