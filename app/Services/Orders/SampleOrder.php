<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\DemoSeed;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

/**
 * One order the owner can look at, and throw away.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Four documents in this shop can only be read by opening a real order: the
 * invoice, the packing slip, the delivery note and the order emails. A store
 * that has not taken its first order therefore has no way to look at any of
 * them, and no way to look again after the next change to them. The owner's
 * own words were "i don't have any demo orders in the system", said while he
 * was being asked to check that a fix to one of those documents had landed.
 *
 * So: one button that makes a single order worth looking at, and one that
 * removes it completely.
 *
 * ── THE THREE MARKS, AND WHICH QUESTION EACH ONE ANSWERS ────────────────────
 *
 * A sample order that can be mistaken for a customer's is worse than no
 * feature at all, so it is marked three times over. The marks are not
 * redundant: each answers a different question, and each is read by a
 * different part of the application.
 *
 *   1. `demo_seed_log` — AUTHORITATIVE FOR MONEY. A row (type SEED_TYPE,
 *      model Order) is written for the order the instant it is created.
 *      App\Support\DemoSeed is what the Dashboard, Analytics, the Customers
 *      screen and the Orders list already consult, and it answers from this
 *      table. Logging here is therefore the whole of "kept out of every
 *      figure": no reporting query is edited, and a figure added next month
 *      that follows the existing pattern is correct without anyone
 *      remembering this feature exists. That is the guarantee most likely to
 *      rot, and this is the shape that does not rot.
 *
 *   2. `orders.origin = 'sample'` — AUTHORITATIVE FOR REFUSALS. The column
 *      already exists and already means "how did this order come to be"
 *      (`woocommerce-import`, a manual channel); no new column, and so no
 *      second answer to a question DemoSeed's own header explains is already
 *      answered. It is read where a QUERY MUST NOT BE ISSUED and where the
 *      answer must be available from the row in hand: OrderMailer's refusal
 *      to send, and the SAMPLE banner on the documents.
 *
 *   3. `orders.order_number = 'SAMPLE-…'` — VISIBLE EVERYWHERE, BY
 *      CONSTRUCTION. Every screen, every document and every export in this
 *      application prints the order number. Marking the number is therefore
 *      the only mark that needs no screen to cooperate, including screens
 *      written after this one. Real numbers are decimal and minted by
 *      OrderNumbers; that allocator compares order numbers AS INTEGERS when
 *      it resyncs, so `SAMPLE-0001` casts to 0 and can never drag the live
 *      sequence anywhere.
 *
 * WHY TWO DIFFERENT AUTHORITIES IS NOT TWO ANSWERS THAT CAN DRIFT. It is the
 * opposite: each is the one that FAILS SAFE for its own question. If the log
 * row were ever lost while the order remained, the money question would answer
 * "real" — which over-counts nothing and under-counts nothing that matters,
 * because the safe error for a figure is to include a row you can see. If
 * `origin` were ever lost, the mail question would answer "send" — which is
 * the unsafe error, so the mail question does NOT read the log, whose row can
 * be deleted independently by DemoContentController::removeType(). Each
 * question reads the mark that cannot go missing underneath it. SampleOrderTest
 * pins that all three marks land together on one row.
 *
 * ── WHAT IT DELIBERATELY DOES NOT DO ────────────────────────────────────────
 *
 * NO EMAIL, EVER. Creating a row fires `Order::created`, never `Order::updated`
 * — OrderMailObserver only mails on a status CHANGE, so nothing is sent from
 * here. That is an argument about today's code, so it is not the guarantee:
 * OrderMailer refuses outright on `origin === 'sample'`, at all five of its
 * entry points including the two "resend" buttons an operator can press on the
 * order screen. And the address below is in `.invalid`, the TLD RFC 2606
 * reserves as permanently unresolvable, so a message that escaped both guards
 * still could not reach a person.
 *
 * NO STOCK. Stock moves in exactly two places: StockClaim, called by the
 * checkout, and OrderTransitionStock, called by OrderStatus when a status
 * CHANGES. This writes rows and changes no status, so it reaches neither. The
 * lines also carry `product_id = null`, so there is no product for a claim to
 * be made against even in principle.
 *
 * NO PRODUCTS, NO CUSTOMER, NOTHING ON THE SHOP. `product_id` is null on every
 * line and `customer_id` is null on the order. The lines are pure snapshots,
 * which is what `order_items` is built to hold — the columns are documented in
 * the schema as "snapshots, so an order still reads correctly after a product
 * is renamed or deleted". Three consequences worth having, all of them free:
 *
 *   - Nothing is added to the catalogue, so no new row appears on the
 *     storefront. (Store -> Demo Content's own `orders` type publishes two
 *     real products to hang its lines on; this does not.)
 *   - Catalog -> Products' "times ordered" and Catalog -> Reorder's sales
 *     figures join `order_items` to `orders` ON `product_id` and skip nulls,
 *     so the sample order is invisible to them without their being told.
 *   - App\Support\RepeatPurchase does the same join, so the storefront's
 *     best-seller ordering cannot see it either.
 *
 * NOT PAID. `paid_at` and `captured_at` stay null. Payments -> Reconciliation
 * selects local orders with `WHERE paid_at IS NOT NULL` and has no demo
 * exclusion of its own, so a sample order with a payment date would appear
 * there as an unmatched transaction. Leaving the columns null keeps it out of
 * that screen by construction rather than by a reporting fix.
 *
 * NOT CASH ON DELIVERY, AND THAT IS LOAD-BEARING. Reconciliation's
 * CashOnDeliveryPosition aggregates `orders` WHERE `payment_method = 'cod'`
 * with no demo exclusion either. PAYMENT_METHOD below is a card gateway for
 * that reason and not for realism, and SampleOrderTest pins it so a later
 * change to "make it look more local" cannot quietly put sample money into the
 * COD drawer figure.
 */
final class SampleOrder
{
    /**
     * The `demo_seed_log` type. Its OWN type, not the existing `orders` one,
     * so Store -> Demo Content's "Demo Orders" card and this feature's Remove
     * button each delete exactly their own rows and neither can take the
     * other's with it.
     */
    public const SEED_TYPE = 'sample-order';

    /** What `orders.origin` reads. The mark the mailer and the documents ask. */
    public const ORIGIN = 'sample';

    /** The prefix on `order_number`. Never decimal, so OrderNumbers cannot follow it. */
    public const NUMBER_PREFIX = 'SAMPLE-';

    /**
     * Where the "confirmation" would go if every guard in this file failed.
     *
     * `.invalid` is reserved by RFC 2606 and guaranteed never to resolve, so
     * this is not a placeholder that might one day belong to somebody — it is
     * an address that cannot exist. It is also why the `Order::created` hook in
     * MailServiceProvider, which cancels abandoned-cart chasing for the buyer's
     * address, is a no-op here: no real cart can carry it.
     */
    public const EMAIL = 'sample-order@example.invalid';

    /** A card gateway, deliberately. See the class header's last paragraph. */
    public const PAYMENT_METHOD = 'stripe';

    /**
     * Is this a sample order? Answered from the row in hand, with no query.
     *
     * Takes the loose shape so the mailer, the document presenter and a plain
     * `DB::table('orders')` row can all ask the same question of whatever they
     * happen to be holding.
     */
    public static function is(mixed $order): bool
    {
        if ($order === null) {
            return false;
        }

        $origin = is_array($order)
            ? ($order['origin'] ?? null)
            : ($order->origin ?? null);

        return is_string($origin) && $origin === self::ORIGIN;
    }

    /** The one sample order, if there is one. There is at most one at a time. */
    public function current(): ?Order
    {
        return Order::query()->where('origin', self::ORIGIN)->latest('id')->first();
    }

    /**
     * Make one, in the language given.
     *
     * ONE AT A TIME, and the existing one is removed first rather than refused.
     * The button's job is "show me the documents in Arabic now"; making the
     * owner find and press Remove before he can change language would be a
     * worse screen, and there is nothing to preserve — a sample order carries
     * no information that did not come out of this file.
     *
     * @param  string  $locale  'en' or 'ar'; anything else falls back to English
     */
    public function create(string $locale): Order
    {
        /*
         * Validated against Locale's own table rather than a literal list here,
         * so this cannot be the place that disagrees about what languages
         * exist, and an unrecognised value becomes English rather than being
         * written to the column. `orders.locale` decides which language the
         * invoice, the order emails and the delivery note render in, so a value
         * the renderer does not understand would be an order whose paperwork
         * has no language at all.
         */
        $locale = Locale::isSupported($locale) ? $locale : Locale::DEFAULT;

        return DB::transaction(function () use ($locale): Order {
            $this->destroy();

            $lines = self::lines();

            $subtotal = array_sum(array_column($lines, 'total'));
            $discount = self::DISCOUNT;
            $shipping = self::SHIPPING;

            $order = Order::create([
                'order_number' => $this->nextNumber(),

                /*
                 * A GUEST order: no `customers` row is created and none is
                 * linked. Store -> Customers derives lifetime spend and order
                 * counts from `orders` grouped by customer and by email, so a
                 * sample order attached to a real customer would sit inside a
                 * real person's history. With no customer id and an address
                 * nobody owns, there is no history for it to land in but its
                 * own.
                 */
                'customer_id' => null,
                'email' => self::EMAIL,
                'phone' => '+971 50 000 0000',

                /*
                 * `processing` — squarely inside Order::REAL_STATUSES, which is
                 * this shop's definition of revenue.
                 *
                 * The safe-looking choice was a status OUTSIDE that list, so
                 * that the order could not count as revenue whatever else went
                 * wrong. It is the wrong choice twice over. The documents this
                 * order exists to show are the documents of a LIVE order, and a
                 * `pending` order prints differently. And a guarantee that
                 * holds only because the row was never eligible in the first
                 * place is a guarantee nothing tests: the exclusion would go on
                 * passing for months after it had stopped working. This order
                 * is eligible for revenue and is kept out of it by the log row
                 * alone, so the test that says so is testing the thing.
                 */
                'status' => 'processing',
                'currency' => 'AED',

                'locale' => $locale,
                'origin' => self::ORIGIN,

                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'shipping_total' => $shipping,
                'fee_total' => 0,

                /*
                 * Zero, with `tax_basis` left NULL. Null is what every order
                 * placed while the shop is in its shipped `display` tax mode
                 * carries, and App\Support\OrderTax reads null as "this order
                 * predates the tax engine" and falls back to exactly the code
                 * path the shop uses today. Inventing a rate here would print a
                 * tax line on a sample invoice that no real order in this shop
                 * currently prints — which is the opposite of showing the owner
                 * what his invoice looks like.
                 */
                'tax_total' => 0,

                'total' => $subtotal - $discount + $shipping,

                'coupon_code' => self::COUPON,
                'shipping_method' => self::SHIPPING_METHOD,
                'payment_method' => self::PAYMENT_METHOD,
                'payment_method_title' => self::PAYMENT_TITLE,

                /*
                 * Never paid and never captured. See the class header: the
                 * reconciliation screen selects on `paid_at IS NOT NULL`.
                 */
                'paid_at' => null,

                'billing_address' => self::ADDRESS,
                'shipping_address' => self::ADDRESS,

                'customer_note' => self::NOTE,
            ]);

            foreach ($lines as $line) {
                /*
                 * `product_id` and `product_variant_id` are absent from every
                 * line on purpose — see the class header. `variant_attributes`
                 * still carries the chosen option, because that is a snapshot
                 * too and it is what the invoice prints under the line name.
                 */
                $order->items()->create($line);
            }

            /*
             * THE LOG ROW, WRITTEN INSIDE THE SAME TRANSACTION AS THE ORDER.
             *
             * Not after it, and the difference is the whole guarantee. An order
             * committed without its log row is an order that DemoSeed reports
             * as real, sitting in the owner's revenue with nothing marking it —
             * precisely the failure this feature exists to make impossible. In
             * one transaction there is no instant at which the order exists and
             * the row that excludes it does not.
             *
             * Written straight to the table, in the shape
             * DemoContentController::log() writes, because that controller's
             * own removeType() and DemoSeed both read it and this must be the
             * same row they would have written.
             */
            $this->ensureLogTable();

            DB::table(DemoSeed::TABLE)->insert([
                'type' => self::SEED_TYPE,
                'model' => Order::class,
                'record_id' => $order->id,
                'created_at' => now(),
            ]);

            return $order;
        });
    }

    /**
     * Remove every sample order and its log rows, completely.
     *
     * FORCE-DELETED, not trashed. `Order` uses SoftDeletes, and a soft-deleted
     * row keeps its `order_number` in the unique index and keeps being visible
     * to the several reporting queries that run on the query builder rather
     * than the model — Reconciler says so in its own comment, deliberately. A
     * sample order in the trash is a sample order still in the database, which
     * is not what the button says.
     *
     * Driven from `origin`, and the log rows cleaned up beside it, rather than
     * the other way round. Deleting what the log points at would leave behind
     * any sample order whose log row had gone missing — the one case where
     * something invented is sitting in the figures unmarked, and so the one
     * case this method most needs to clear.
     *
     * @return int how many orders were removed
     */
    public function destroy(): int
    {
        return DB::transaction(function (): int {
            $orders = Order::withTrashed()->where('origin', self::ORIGIN)->get();

            if ($orders->isEmpty()) {
                // Still sweep the log: a row pointing at an order that is
                // already gone would keep Demo Content's counter above zero.
                $this->purgeLog([]);

                return 0;
            }

            $ids = $orders->pluck('id')->all();

            foreach ($orders as $order) {
                // order_items and order_notes are ON DELETE CASCADE in the
                // schema, so the lines go with the row.
                $order->forceDelete();
            }

            $this->purgeLog($ids);

            return count($ids);
        });
    }

    /**
     * Both halves of the log: the rows for the ids just deleted, and any row of
     * this type left pointing at nothing.
     *
     * @param  array<int, int>  $ids
     */
    private function purgeLog(array $ids): void
    {
        if (! DemoSeed::tableExists()) {
            return;
        }

        DB::table(DemoSeed::TABLE)->where('type', self::SEED_TYPE)->delete();

        if ($ids !== []) {
            DB::table(DemoSeed::TABLE)
                ->where('model', Order::class)
                ->whereIn('record_id', $ids)
                ->delete();
        }
    }

    /**
     * Create `demo_seed_log` if it is not there, for the reason
     * DemoContentController::ensureTable() gives in full: a build whose
     * migration step was skipped would otherwise throw here, and on production
     * with APP_DEBUG off that reaches the screen as an HTML error page the
     * caller's response.json() cannot parse.
     *
     * The same columns and the same indexes as that method, because both write
     * rows the other one reads.
     */
    private function ensureLogTable(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable(DemoSeed::TABLE)) {
            return;
        }

        \Illuminate\Support\Facades\Schema::create(
            DemoSeed::TABLE,
            function (\Illuminate\Database\Schema\Blueprint $t): void {
                $t->id();
                $t->string('type');
                $t->string('model');
                $t->unsignedBigInteger('record_id');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['type']);
                $t->index(['model', 'record_id']);
            }
        );

        // The memo DemoSeed::tableExists() holds for this request was taken
        // before the table existed. Forget it, or every exclusion for the rest
        // of this request answers "nothing is demo".
        app()->forgetInstance('kbb.demo_seed_log.exists');
    }

    /**
     * A free sample number. Prefixed, so it cannot collide with a real one and
     * cannot be read as one; the counter only ever walks forward past a number
     * a previous sample left behind in the unique index.
     */
    private function nextNumber(): string
    {
        $n = 1;

        while (Order::withTrashed()
            ->where('order_number', self::NUMBER_PREFIX . str_pad((string) $n, 4, '0', STR_PAD_LEFT))
            ->exists()) {
            $n++;
        }

        return self::NUMBER_PREFIX . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------------
    // The order's contents. Constants, not settings — rule 5 of CLAUDE.md, and
    // also because an invoice with one line and no address proves nothing.
    // -----------------------------------------------------------------------

    private const DISCOUNT = 2500;       // fils — the coupon below
    private const SHIPPING = 1500;       // fils
    private const COUPON = 'SAMPLE10';
    private const SHIPPING_METHOD = 'Standard delivery (2–4 days)';
    private const PAYMENT_TITLE = 'Card payment';
    private const NOTE = 'Sample order — created from Safety → Demo Content. Not a real customer.';

    /**
     * A real-looking UAE address. Invented street, real emirate, so the
     * delivery note and the dispatch label have the shape of lines they
     * actually carry.
     */
    private const ADDRESS = [
        'name' => 'Sample Customer',
        'first_name' => 'Sample',
        'last_name' => 'Customer',
        'company' => '',
        'line1' => 'Flat 1203, Marina Heights Tower',
        'line2' => 'Al Marsa Street, Dubai Marina',
        'city' => 'Dubai',
        'state' => 'Dubai',
        'postcode' => '',
        'country' => 'AE',
        'phone' => '+971 50 000 0000',
        'email' => self::EMAIL,
    ];

    /**
     * Three lines, one of them a variable product with a chosen option.
     *
     * Quantities and prices are chosen so the arithmetic on the sheet is worth
     * checking: 2 x 8900 + 1 x 23900 + 3 x 4500 = 55200, less the 2500 coupon,
     * plus 1500 delivery = 54200. A sheet whose lines do not add up to its
     * total is the single most likely thing to be wrong with an invoice, and
     * the owner can only see that if the numbers are not all the same.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lines(): array
    {
        return [
            [
                'name' => 'Rice Water Brightening Cream 50ml',
                'brand' => 'Sample Beauty Co.',
                'sku' => 'SAMPLE-CRM-50',
                'quantity' => 2,
                'unit_price' => 8900,
                'subtotal' => 17800,
                'total' => 17800,
                'tax_total' => 0,
            ],
            [
                /*
                 * THE VARIABLE ONE. `variant_attributes` is the column the
                 * invoice and the packing slip print the chosen option from, so
                 * this is the line that proves an order with options renders
                 * its options — which a sheet of three plain lines would not.
                 */
                'name' => 'Velvet Lip Tint',
                'brand' => 'Sample Beauty Co.',
                'sku' => 'SAMPLE-LIP-03',
                'variant_attributes' => ['Shade' => 'Rose Petal', 'Size' => '4g'],
                'quantity' => 1,
                'unit_price' => 23900,
                'subtotal' => 23900,
                'total' => 23900,
                'tax_total' => 0,
            ],
            [
                'name' => 'Aloe Soothing Sheet Mask',
                'brand' => 'Sample Beauty Co.',
                'sku' => 'SAMPLE-MSK-01',
                'quantity' => 3,
                'unit_price' => 4500,
                'subtotal' => 13500,
                'total' => 13500,
                'tax_total' => 0,
            ],
        ];
    }
}
