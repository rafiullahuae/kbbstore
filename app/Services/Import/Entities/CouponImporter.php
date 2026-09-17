<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Services\Import\ImportContext;
use App\Services\Import\Money;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Support\WholeDirhams;
use Illuminate\Support\Facades\DB;

/**
 * WooCommerce coupons — the `shop_coupon` post type and its meta — matched on
 * `coupons.wc_id`.
 *
 * WHY THIS EXISTS AT ALL. `ImportRunner::entities()` was seven importers and
 * `coupons.csv` was never opened. The dry-run lane made that visible, which is
 * the honest limit of what a dry run can do; this is the half that carries the
 * data. A coupon that does not arrive is a customer holding a card, printed
 * with a code, that this shop has never heard of.
 *
 * ── THE ONE NUMBER THAT IS TWO NUMBERS ──────────────────────────────────────
 *
 * `coupons.amount` is a single integer column whose UNIT DEPENDS ON `type`,
 * and the Phase 0 schema says so in four words beside it: "percent ×100, or
 * fils". CouponService::discountFor() is where that becomes money:
 *
 *     'percent'       => intdiv($subtotal * $coupon->amount + 5000, 10000)
 *     'fixed_product' => min($coupon->amount, $unit_price) * $quantity
 *     'fixed_cart'    => $coupon->amount
 *
 * So for a percentage coupon `amount` is HUNDREDTHS OF A PERCENT — 20% is
 * 2000, 12.5% is 1250 — and for both fixed types it is FILS: AED 50 is 5000.
 * WooCommerce writes ONE decimal string, `coupon_amount`, for both, and says
 * which it means in `discount_type`.
 *
 * THE CONVERSION IS THE SAME ARITHMETIC IN BOTH CASES, and that is a fact
 * worth stating rather than a coincidence worth relying on: both units are the
 * source value times one hundred. So Money::fils() — the parser that splits
 * the decimal string and never constructs a float — is correct for both, and
 * is used for both. Worked, at both types:
 *
 *     discount_type=percent      coupon_amount="20"     -> amount 2000  = 20%
 *     discount_type=percent      coupon_amount="12.5"   -> amount 1250  = 12.5%
 *     discount_type=fixed_cart   coupon_amount="50"     -> amount 5000  = AED 50.00
 *     discount_type=fixed_cart   coupon_amount="99.50"  -> amount 9950  = AED 99.50
 *
 * WHAT IS NOT SHARED IS THE CEILING. A fixed amount is bounded only by the
 * money column; a percentage over 100 is not a discount this shop can express
 * — discountFor() clamps the result to the eligible subtotal, so a coupon
 * imported at 150% quietly becomes a 100% coupon and the basket is free. That
 * is refused here with the value quoted, because a free basket arriving from a
 * mis-typed export is not something to discover from the orders report.
 *
 * ── USAGE COUNTS, AND THE INVARIANT THIS IMPORTER MAINTAINS ─────────────────
 *
 * Three columns, and they are enforced by two DIFFERENT mechanisms in this
 * application, which is the whole difficulty:
 *
 *   usage_limit           checked against `coupons.usage_count`, a counter.
 *   usage_limit_per_user  checked by COUNTING unreleased `coupon_redemptions`
 *                         rows carrying that email.
 *
 * An imported coupon has a counter and NO redemption rows, because this shop
 * was not the shop those uses happened in. The schema already committed to
 * that: "Migrated verbatim, so an exhausted code cannot be re-used after
 * cutover. (VM-06)". CouponService agrees in its own words — "usage_count was
 * migrated from WooCommerce verbatim and can legitimately be higher than the
 * number of redemption rows this application holds".
 *
 * SO THIS IMPORTER DOES NOT SYNTHESISE REDEMPTION ROWS. A redemption row is a
 * claim that a named order, belonging to a named customer, spent one use. Woo's
 * `_used_by` meta carries user ids or email addresses and no order at all, so
 * every synthesised row would carry `order_id` NULL — a fiction that
 * Store → Coupons would then report as usage history, and that
 * CouponAdminApiController::destroy() would count when refusing to delete a
 * code. Inventing rows to make a counter look tidy is the exact shape of
 * "a second source of truth that agrees most of the time", which this
 * repository has already paid for once.
 *
 * WHAT IT WRITES INSTEAD IS AN INVARIANT, and it is what makes a re-import
 * safe on a shop that has been trading:
 *
 *     usage_count  =  the export's usage_count  +  this shop's own unreleased
 *                                                  redemptions of this coupon
 *
 * On a fresh import the second term is zero and the counter is verbatim, which
 * is VM-06. On a delta pass run three weeks after cutover, this shop has taken
 * (say) three live uses: recordRedemption() incremented the counter to 465 and
 * wrote three rows, the export still says 462, and 462 + 3 = 465 — the value
 * already stored, so the row reports UNCHANGED and the three live uses are not
 * clobbered back to Woo's number. Without this, every delta pass would hand
 * three uses of an exhausted code back to the public.
 *
 * It holds through the release and revival paths too, which is why it is
 * expressed against UNRELEASED rows rather than all of them:
 * releaseRedemptions() stamps `released_at` and decrements, so both sides fall
 * by one together; reclaimRedemptions() clears the stamp and increments, so
 * both rise. tests/Feature/CouponReviewImportTest.php pins all four.
 *
 * WHEN THE TWO DISAGREE IN THE OTHER DIRECTION — the export's count is lower
 * than what this shop has already counted for reasons the invariant does not
 * explain — the row is imported at the invariant's value and the disagreement
 * is reported as an adjustment with both numbers. It is not refused: a coupon
 * missing from the shop is worse than a coupon whose counter is a use out.
 *
 * AND THE PER-CUSTOMER LIMIT CANNOT BE CARRIED, at all, by anything. It is
 * enforced by counting redemption rows and there are none, so a shopper who
 * used a `usage_limit_per_user = 1` code in WooCommerce can use it again here.
 * There is no column in this schema that could hold "who has already used
 * this", so it is not a mapping that was skipped — it is a fact about the two
 * data models. Named in the DISCARD channel, per coupon, with the limit's real
 * value on it, because it is money and the owner is the only one who can
 * decide whether to shorten the code's life instead.
 *
 * ── EXPIRY ──────────────────────────────────────────────────────────────────
 *
 * AN EXPIRED COUPON IS STILL IMPORTED. It is referenced by the orders that
 * used it — `orders.coupon_code` is written by OrderImporter as a bare string
 * — and Store → Coupons' usage report is drawn from these rows. Dropping it
 * would make historic orders name a code the shop cannot show. CouponService
 * refuses it at checkout on `expires_at` exactly as WooCommerce did, so
 * importing it costs nothing and carries the history.
 *
 * WooCommerce stores `date_expires` as an END-OF-DAY-INCLUSIVE date: a coupon
 * expiring "2027-01-01" is usable throughout the 1st. This schema's check is
 * `now() > expires_at`, so importing the bare date would kill the code at
 * midnight on the 31st — a day early, silently. A DATE with no time is
 * therefore moved to 23:59:59 of that day and the move is reported as an
 * adjustment. A value that already carries a time is honoured as written.
 *
 * ── THE ID LISTS, AND THE ONE WAY THIS COULD GIVE AWAY MONEY ────────────────
 *
 * `product_ids`, `excluded_product_ids`, `category_ids`, `excluded_category_ids`
 * hold LOCAL primary keys — CouponService::eligibleItems() tests them against
 * `$product->id` and `$product->categories->pluck('id')`. WooCommerce's export
 * carries WordPress POST and TERM ids. They are different id spaces and copying
 * one into the other would produce a restriction list that matches whatever
 * products happen to hold those local ids, which is arbitrary.
 *
 * So every id is translated through ImportContext's maps, and an id that
 * cannot be translated is dropped with a note. That is safe for an EXCLUDE
 * list by construction: a product this catalogue does not have cannot be in a
 * basket, so excluding it is inert either way.
 *
 * IT IS NOT SAFE FOR AN INCLUDE LIST, AND THIS IS THE ONE PLACE THIS IMPORTER
 * REFUSES A ROW OVER SOMETHING THAT LOOKS COSMETIC. CouponService reads an
 * EMPTY include list as "no restriction" — `if ($coupon->product_ids && ...)`.
 * So a coupon restricted in WooCommerce to three products, none of which
 * translated, would import with `product_ids = []` and become a coupon valid
 * on the ENTIRE CATALOGUE. A 50% code meant for three discontinued lines,
 * silently applied to everything, is money leaving the shop. When an include
 * list is non-empty in the export and NOTHING in it translates, the row is
 * refused and says which ids it could not find.
 *
 * ── WHOLE DIRHAMS, WHICH THIS IS A SECOND DOOR PAST ────────────────────────
 *
 * The owner priced this shop in whole dirhams and App\Support\WholeDirhams is
 * where that rule lives. It is REFUSED where he types a price and ADJUSTED
 * where a figure is derived. An import is neither, so a fixed coupon arriving
 * from WooCommerce with fils is IMPORTED EXACTLY AND REPORTED -- a 40,000-row
 * migration must not die on a rounding policy, and it must not quietly edit his
 * money either. reportFils() below, and `kbb:whole-dirhams` finishes the job.
 *
 * IT MATTERS MORE HERE THAN ON A PRICE. CouponService::discountFor() rounds a
 * discount UP through WholeDirhams::away(), and reasons that a fixed coupon is
 * untouched by that "because its amount is refused unless it is whole
 * (CouponAdminApiController)". True of every coupon the owner types. NOT true
 * of an imported one, because this class writes `coupons` without going through
 * that controller. AED 99.50 imported is AED 100.00 handed back, on every order
 * that uses the code, and Store -> Coupons shows 99.50.
 *
 * ── WHAT WOOCOMMERCE HAS AND THIS SHOP DOES NOT ─────────────────────────────
 *
 * Less than the brief for this lane assumed, and that is worth saying plainly:
 * `free_shipping`, `individual_use`, `exclude_sale_items`, the product and
 * category include/exclude lists, `limit_usage_to_x_items`, `minimum_amount`,
 * `maximum_amount` and `customer_email` ALL have real columns here and are all
 * enforced by CouponService. They are mapped, not discarded.
 *
 * What genuinely has no home is in self::NO_HOME, is reported in the discard
 * channel with the value the export really held, and is three things:
 *
 *   post_status   `coupons` has no status column. A coupon that was a DRAFT or
 *                 in the TRASH in WordPress has nowhere to be either, and
 *                 importing it would make a code the owner had withdrawn live
 *                 again. Trash and draft are REFUSED rather than discarded —
 *                 the discard channel is for data that is lost, and this would
 *                 be data that is lost AND a working discount code.
 *   used_by       who has already used the code. See the usage section above.
 *
 * Everything else a given export happens to carry is caught by the runner's
 * own ignored-column channel, which names every unread column of the file
 * with the first real value found in it. That is the designed mechanism and
 * this list does not duplicate it; NO_HOME is only for the ones that need a
 * sentence rather than a name.
 */
final class CouponImporter extends EntityImporter
{
    /**
     * Woo's `discount_type` => this schema's `type`.
     *
     * `percent_product` is the pre-2.5 spelling of a per-product percentage.
     * This schema has no per-product percentage — `discountFor()` offers
     * `percent` (of the eligible subtotal) and `fixed_product` (per unit) and
     * nothing between them — and the two are NOT the same sum on a basket
     * holding an ineligible line. It is refused rather than folded onto
     * `percent`, because folding it changes what the shopper is charged and
     * nothing in the report would say so.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'percent' => 'percent',
        'percentage' => 'percent',
        'percent_cart' => 'percent',
        'fixed_cart' => 'fixed_cart',
        'cart' => 'fixed_cart',
        'fixed' => 'fixed_cart',
        'fixed_product' => 'fixed_product',
        'product' => 'fixed_product',
    ];

    /**
     * Woo coupon columns this schema has nowhere to put, each with what the
     * owner loses. Reported once per coupon that actually carries a value for
     * one, with that value — "post_status" is a column name and
     * "post_status = draft" is a decision.
     *
     * @var array<string, array{0: list<string>, 1: string}>  key => [aliases, what is lost]
     */
    private const NO_HOME = [
        'used_by' => [
            ['used_by', 'meta_used_by', '_used_by'],
            'who has already redeemed this code. `usage_limit_per_user` is enforced by counting '
            .'coupon_redemptions rows carrying the shopper\'s email, and an imported coupon has none, '
            .'so every customer starts again from zero uses of this code on this shop',
        ],
    ];

    public function name(): string
    {
        return 'coupons';
    }

    public function conventionalFile(): string
    {
        return 'coupons.csv';
    }

    /**
     * Adjustments and discards for the row being imported, held until the row
     * is known to survive.
     *
     * WHY THEY ARE NOT WRITTEN AS THEY ARE FOUND. Found by running this against
     * the fixture: a coupon whose amount is refused three checks later had
     * ALREADY reported "code lower-cased" and "expiry moved to end of day", so
     * a run that imported eleven coupons said nineteen values went in changed.
     * The adjusted channel's whole promise is "this row IS in the database and
     * is not what the export said"; an entry for a row that was refused breaks
     * it in the direction that matters, because the owner reads that list to
     * decide what to approve and a rejected row needs no approval.
     *
     * A REJECTED ROW THEREFORE REPORTS NOTHING BUT ITS REJECTION, which is also
     * what the seven existing entities' counts mean. The buffer is flushed
     * after apply() returns and is discarded by the throw otherwise.
     *
     * @var list<array{0: 'adjusted'|'discarded', 1: string, 2: int|string, 3: string, 4: string, 5: string, 6: string}>
     */
    private array $pending = [];

    /**
     * Notes for the row being imported, held for the same reason.
     *
     * @var list<string>
     */
    private array $pendingNotes = [];

    public function import(Row $row, ImportContext $context): void
    {
        $this->pending = [];
        $this->pendingNotes = [];

        $wcId = $row->requireId('id', 'id', 'wc_id', 'coupon_id', 'post_id');

        /*
         * The code IS the coupon. `coupons.code` is UNIQUE and it is what the
         * shopper types; a coupon row without one is unreachable by every path
         * in the application.
         *
         * Lower-cased, because WooCommerce lower-cases coupon codes on save
         * (wc_format_coupon_code) and Coupon::scopeCode() looks them up with
         * LOWER(code) = ?. Importing "WELCOME20" would work on lookup and then
         * print in the admin in a case the owner never typed; more to the
         * point, "Welcome20" and "WELCOME20" as two Woo posts are ONE code and
         * must collide here rather than become two rows that the scope cannot
         * tell apart.
         */
        $rawCode = $row->requireText('code', 'code', 'coupon_code', 'post_title', 'title', 'name');
        $code = mb_strtolower(trim($rawCode));

        if ($code !== trim($rawCode)) {
            $this->later('adjusted',
                'coupon code lower-cased, which is what WooCommerce stores and what Coupon::scopeCode() '
                .'matches on -- the code the shopper types is unaffected, the code the admin prints changes',
                $row->line,
                $this->identify($row),
                'code',
                $rawCode,
                $code,
            );
        }

        $this->assertImportableStatus($row);

        $type = $this->mapType($row);
        $amount = $this->amount($row, $type);

        $coupon = Coupon::query()->where('wc_id', $wcId)->first();

        if ($coupon === null) {
            $held = Coupon::query()->code($code)->first();

            if ($held !== null) {
                /*
                 * Not adopted, and deliberately not. `coupons.code` is unique
                 * and this row is a DIFFERENT WooCommerce coupon claiming a
                 * code this shop already has — either two Woo posts that
                 * lower-case onto one code, or a code somebody typed into
                 * Store -> Coupons by hand. Writing the Woo id onto the
                 * existing row (which is what --adopt-by-slug does for brands)
                 * would silently replace that coupon's amount, its limits and
                 * its expiry with this row's. Only the owner can say which one
                 * is the real code.
                 */
                throw RowRejected::because(
                    "code '".$code."' already belongs to "
                    .($held->wc_id === null
                        ? 'a coupon with no WooCommerce id (id '.$held->id.'), created in this shop'
                        : 'WooCommerce coupon '.$held->wc_id)
                    .'. coupons.code is UNIQUE and the two cannot both exist. Decide which one keeps the '
                    .'code, delete or rename the other, and re-run.'
                );
            }

            $coupon = new Coupon;
        }

        $attributes = [
            'wc_id' => $wcId,
            'code' => $code,
            'type' => $type,
            'amount' => $amount,
            'description' => $row->text('description', 'post_excerpt', 'excerpt', 'coupon_description'),

            'minimum_amount' => $row->money('minimum_amount', 'minimum_amount', 'min_amount', 'minimum_spend'),
            'maximum_amount' => $row->money('maximum_amount', 'maximum_amount', 'max_amount', 'maximum_spend'),

            'free_shipping' => (bool) $row->bool(false, 'free_shipping'),
            'individual_use' => (bool) $row->bool(false, 'individual_use', 'individual_use_only'),
            'exclude_sale_items' => (bool) $row->bool(false, 'exclude_sale_items', 'exclude_sale'),

            'usage_limit' => $this->limit($row, 'usage_limit', 'usage_limit', 'usage_limit_per_coupon'),
            'usage_limit_per_user' => $this->limit($row, 'usage_limit_per_user', 'usage_limit_per_user', 'usage_limit_per_customer'),
            'limit_usage_to_x_items' => $this->limit($row, 'limit_usage_to_x_items', 'limit_usage_to_x_items'),

            'allowed_emails' => $this->emails($row),

            // NOT date_created -- see startsAt().
            'starts_at' => $this->startsAt($row, $context),
        ];

        $attributes['expires_at'] = $this->expiresAt($row, $context);

        $attributes += $this->idLists($row, $context);

        $attributes['usage_count'] = $this->usageCount($row, $coupon);

        $created = $row->date('date_created', $context->timezone(), 'date_created', 'post_date', 'created_at');

        if ($created !== null) {
            $attributes['created_at'] = $created;
        }

        $outcome = $context->apply($coupon, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember('coupons', $wcId, (int) $coupon->id);

        $this->reportNoHome($row);
        $this->reportPerUserLimit($row, $attributes);
        $this->reportFils($row, $attributes);

        // The row is in. Everything held back above is now true of a row that
        // really is in the database.
        $this->flush($context);
    }

    /**
     * Hold an adjustment or a discard until this row has actually been written.
     *
     * @param  'adjusted'|'discarded'  $bucket
     */
    private function later(string $bucket, string $kind, int|string $line, string $id, string $field, string $before, string $after = '(nothing)'): void
    {
        $this->pending[] = [$bucket, $kind, $line, $id, $field, $before, $after];
    }

    private function noteLater(string $note): void
    {
        $this->pendingNotes[] = $note;
    }

    private function flush(ImportContext $context): void
    {
        $report = $context->report->for($this->name());

        foreach ($this->pendingNotes as $note) {
            $report->note($note);
        }

        foreach ($this->pending as [$bucket, $kind, $line, $id, $field, $before, $after]) {
            if ($bucket === 'adjusted') {
                $report->adjusted($kind, $line, $id, $field, $before, $after);
            } else {
                $report->discarded($kind, $line, $id, $field, $before, $after);
            }
        }

        $this->pending = [];
        $this->pendingNotes = [];
    }

    /**
     * A coupon whose WordPress post is not published has nowhere to be.
     *
     * `coupons` has no status column, so an imported draft or trashed coupon is
     * a LIVE, WORKING discount code — the opposite of what the owner did when
     * they unpublished it. ProductImporter refuses a trashed product for the
     * same reason and in the same words; this is that rule applied to the thing
     * where the consequence is money rather than a visible row.
     *
     * @throws RowRejected
     */
    private function assertImportableStatus(Row $row): void
    {
        $status = $row->text('post_status', 'post_status', 'status', 'coupon_status');

        if ($status === null) {
            return;
        }

        $value = mb_strtolower($status);

        if (in_array($value, ['publish', 'published', 'active', '1', 'enabled'], true)) {
            return;
        }

        throw RowRejected::because(
            "post_status '".$status."': `coupons` has no status column, so importing this row would make a "
            .'code the owner had withdrawn into a live, working discount. Publish it in WooCommerce and '
            .'re-export, or delete this row from the export.'
        );
    }

    /**
     * @throws RowRejected
     */
    private function mapType(Row $row): string
    {
        $raw = $row->text('discount_type', 'discount_type', 'type', 'coupon_type');

        if ($raw === null) {
            /*
             * WooCommerce's own default, and the schema's: `type` defaults to
             * 'percent'. Not a guess — an export column that is absent entirely
             * means the exporter did not carry it, and Woo's stored default for
             * a coupon with no _discount_type is percent.
             */
            return 'percent';
        }

        $key = mb_strtolower(trim($raw));

        if (isset(self::TYPE_MAP[$key])) {
            return self::TYPE_MAP[$key];
        }

        if ($key === 'percent_product') {
            throw RowRejected::because(
                "discount_type 'percent_product' is a per-product percentage, and this shop has no such type. "
                .'CouponService offers `percent` (of the whole eligible subtotal) and `fixed_product` (a fixed '
                .'amount per unit); on a basket with an ineligible line those are different sums, so folding '
                .'this onto either one would change what the shopper is charged. Convert it in WooCommerce first.'
            );
        }

        throw RowRejected::because(
            "discount_type '".$raw."' is not one this shop has. Known: "
            .implode(', ', array_keys(self::TYPE_MAP))
        );
    }

    /**
     * `coupon_amount` into this schema's single `amount` column.
     *
     * Both units are the source times one hundred, so Money::fils() — which
     * splits the decimal string and never constructs a float — is the parser
     * for both. See the class header for worked examples at each type.
     *
     * @throws RowRejected
     */
    private function amount(Row $row, string $type): int
    {
        $raw = $row->raw('coupon_amount', 'amount', 'discount_amount', 'coupon_value');

        $value = Money::fils($raw, $type === 'percent' ? 'coupon_amount (a percentage)' : 'coupon_amount');

        if ($value === null) {
            throw RowRejected::because(
                'coupon_amount is empty. A coupon with no amount discounts nothing, and importing it as zero '
                .'would put a code in the shop that appears to work and takes nothing off.'
            );
        }

        if ($value < 0) {
            throw RowRejected::because(
                "coupon_amount '".(string) $raw."' is negative. A negative discount is a surcharge, which is "
                .'not something this shop can express.'
            );
        }

        if ($type === 'percent' && $value > 10000) {
            throw RowRejected::because(
                "coupon_amount '".(string) $raw."' is more than 100% (amount would be ".$value
                .', where 10000 is 100%). CouponService::discountFor() clamps the discount to the eligible '
                .'subtotal, so this would import as a code that makes the basket free.'
            );
        }

        if ($type === 'percent' && $value === 10000) {
            // Legal, and worth saying out loud once per code rather than
            // finding in the orders report.
            $this->later(
                'adjusted',
                'a 100% coupon -- legal, imported as written, and it makes the eligible lines free',
                $row->line,
                $this->identify($row),
                'amount',
                (string) $raw,
                '10000 (100%)',
            );
        }

        return $value;
    }

    /**
     * A coupon amount this shop's own money cannot express.
     *
     * THE OWNER'S RULE IS WHOLE DIRHAMS and App\Support\WholeDirhams is where
     * that lives. Every screen he types a price into REFUSES a value carrying
     * fils; every figure that is DERIVED is adjusted instead. An import is
     * neither -- nobody typed these numbers and nobody is standing in front of
     * them -- so it does the third thing: it imports the value exactly and
     * NAMES it, which is what ProductImporter::reportFils() does for a price
     * and this is the same rule applied to a discount.
     *
     * A 40,000-ROW MIGRATION MUST NOT DIE ON A ROUNDING POLICY. Refusing the
     * row would drop a live discount code over 50 fils, and a coupon that does
     * not arrive is a customer holding a card this shop has never heard of.
     * Rounding it silently would be an edit to the owner's money that nothing
     * in the report mentions. So: imported as written, reported here, and
     * `kbb:whole-dirhams` finishes the job -- its couponGroup() already reads
     * exactly these three columns and can write the rounding with the owner
     * watching. This channel is what tells him to go and run it.
     *
     * ── WHY THE DISCOUNT ITSELF IS WORSE THAN A PRICE ──────────────────────
     *
     * CouponService::discountFor() ends with `min(WholeDirhams::away($d), ...)`
     * and its comment says a fixed-amount coupon "PASSES THROUGH UNTOUCHED,
     * because its amount is refused unless it is whole
     * (CouponAdminApiController)". THAT IS TRUE OF EVERY COUPON THE OWNER TYPES
     * AND IT IS NOT TRUE OF AN IMPORTED ONE. This importer is a second door
     * into `coupons` and it does not pass through that controller, so a
     * WooCommerce coupon of AED 99.50 lands at 9950 and away() rounds it UP at
     * checkout to 10000: the shop hands back AED 100.00 for a code that says
     * AED 99.50, on every order that uses it, and the only figure anyone can
     * see is the one in Store -> Coupons that says 99.50. So the `after` column
     * here is what the shopper will really be given, not a formatting of the
     * `before`.
     *
     * ── AND NOT ON A PERCENTAGE ────────────────────────────────────────────
     *
     * `amount` is hundredths of a PERCENT on a percentage coupon, so reading it
     * as money there would report 12.5% as "AED 0.125 carries fils" and invite
     * the audit to turn a 12.5% sale into a 13% one. The audit command excludes
     * percentage coupons from this check for that exact reason and this excludes
     * them in the same place, on the same test. The thresholds beside it are
     * money on every type.
     */
    private function reportFils(Row $row, array $attributes): void
    {
        /*
         * ONE HEADLINE FOR ALL THREE COLUMNS, which is what EntityReport's
         * `kind` is for -- it groups by that string, and a headline that varies
         * per field turns one channel the owner can act on into three groups of
         * one that his eye slides over. The field is already its own column and
         * the consequence rides in `after`. ProductImporter reports `price` and
         * `sale_price` under a single kind for the same reason.
         */
        $fields = [
            'minimum_amount' => 'compared against the basket exactly as stored, and printed rounded',
            'maximum_amount' => 'compared against the basket exactly as stored, and printed rounded',
        ];

        if ($attributes['type'] !== 'percent') {
            $fields['amount'] = 'rounded UP to a whole dirham by CouponService::discountFor(), so this is '
                .'what the shopper is really given';
        }

        foreach ($fields as $column => $consequence) {
            $value = $attributes[$column] ?? null;

            if ($value === null || WholeDirhams::isWhole((int) $value)) {
                continue;
            }

            $this->later(
                'adjusted',
                'a coupon amount carrying fils in a shop that prices in whole '.WholeDirhams::plural()
                .' -- imported exactly as the export wrote it, because dropping a live discount code over a '
                .'rounding policy is worse, and named here so `kbb:whole-dirhams` can settle it with the '
                .'owner watching',
                $row->line,
                $this->identify($row),
                $column,
                \App\Support\Money::amount((int) $value, 2),
                \App\Support\Money::amount(
                    $column === 'amount' ? WholeDirhams::away((int) $value) : (int) $value,
                    0
                ).' ('.$consequence.')',
            );
        }
    }

    /**
     * A usage limit column: a non-negative integer, or NULL for "no cap".
     *
     * NULL AND 0 ARE DIFFERENT and both are storable, which is exactly the trap
     * Row::int() would walk into — it takes a default and cannot express the
     * distinction. `usage_limit` NULL is "unlimited" and `usage_limit = 0` is a
     * code that cannot be used at all: CouponService checks
     * `usage_count >= usage_limit`, and 0 >= 0 refuses it on the first attempt.
     * An empty cell is the first of those, never the second.
     *
     * @throws RowRejected
     */
    private function limit(Row $row, string $label, string ...$aliases): ?int
    {
        $value = $row->text(...$aliases);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw RowRejected::because(
                $label.": '".$value."' is not a whole number. The column is unsigned; leave it empty for "
                .'"no limit" rather than writing a word there.'
            );
        }

        $limit = (int) $value;

        /*
         * WooCommerce writes an empty string for "no limit" and some exporters
         * write 0 for the same thing. Those are the same cell in Woo's UI and
         * they are NOT the same row here — 0 would import a code nobody can
         * ever use. Treated as "no limit", which is what Woo means by it, and
         * reported so the owner can say otherwise.
         */
        return $limit === 0 ? null : $limit;
    }

    /**
     * `coupons.usage_count`, holding the invariant the class header states.
     *
     *     usage_count = export usage_count + this shop's own unreleased uses
     *
     * @throws RowRejected
     */
    private function usageCount(Row $row, Coupon $coupon): int
    {
        $raw = $row->text('usage_count', 'usage_count', 'used', 'times_used');

        if ($raw !== null && preg_match('/^\d+$/', $raw) !== 1) {
            throw RowRejected::because(
                "usage_count: '".$raw."' is not a whole number. The column is unsigned, and a code whose "
                .'counter cannot be read is a code whose usage limit cannot be enforced.'
            );
        }

        $fromExport = $raw === null ? 0 : (int) $raw;

        // Rows this shop itself wrote for this coupon and has not handed back.
        // Zero for a coupon that has only ever been imported, which is the
        // fresh-import case and makes this verbatim (VM-06).
        $local = $coupon->exists
            ? (int) CouponRedemption::query()
                ->where('coupon_id', $coupon->getKey())
                ->whereNull('released_at')
                ->count()
            : 0;

        $target = $fromExport + $local;

        if ($coupon->exists && (int) $coupon->usage_count !== $target) {
            $this->later(
                'adjusted',
                'usage_count in this shop does not equal the export\'s count plus this shop\'s own '
                .'unreleased redemptions -- the counter is being set to that sum, which is what the usage '
                .'limit is checked against',
                $row->line,
                $this->identify($row),
                'usage_count',
                'stored '.(int) $coupon->usage_count,
                'export '.$fromExport.' + '.$local.' local = '.$target,
            );
        }

        if ($local > 0) {
            $this->noteLater(
                'this shop has taken uses of an imported code since the last pass; the export\'s usage_count '
                .'was added to them rather than overwriting them'
            );
        }

        return $target;
    }

    /**
     * `starts_at`, from Woo's optional start date.
     *
     * NOT `date_created`. A WooCommerce coupon has no start date in core — the
     * common "scheduled coupons" plugins add `_wc_sc_start_date` — and
     * `coupons.starts_at` is checked as "not active yet". Writing the creation
     * date into it would be harmless today and wrong the moment the owner
     * back-dates a coupon, so only a real start column is read.
     *
     * @throws RowRejected
     */
    private function startsAt(Row $row, ImportContext $context): ?\Carbon\CarbonImmutable
    {
        return $row->date(
            'date_starts',
            $context->timezone(),
            'date_starts', 'start_date', 'starts_at', 'wc_sc_start_date',
        );
    }

    /**
     * `expires_at`, moved to the end of the day where the export gives a bare
     * date. See the class header: Woo's expiry is inclusive of the day named
     * and this schema's check is `now() > expires_at`.
     *
     * @throws RowRejected
     */
    private function expiresAt(Row $row, ImportContext $context): ?\Carbon\CarbonImmutable
    {
        $raw = $row->text('date_expires', 'date_expires', 'expiry_date', 'expires_at', 'coupon_expiry_date');
        $parsed = $row->date('date_expires', $context->timezone(), 'date_expires', 'expiry_date', 'expires_at', 'coupon_expiry_date');

        if ($parsed === null || $raw === null) {
            return $parsed;
        }

        // A bare date: no time part at all in what the export wrote.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return $parsed;
        }

        $inclusive = $parsed->addDay()->subSecond();

        $this->later(
            'adjusted',
            'a bare expiry date moved to the end of that day -- WooCommerce treats date_expires as inclusive '
            .'of the day named and this shop refuses a coupon once now() is past expires_at, so importing '
            .'midnight would retire the code a day early',
            $row->line,
            $this->identify($row),
            'expires_at',
            $raw,
            $inclusive->toDateTimeString().' UTC',
        );

        return $inclusive;
    }

    /**
     * `customer_email` into `allowed_emails`.
     *
     * Woo writes a comma-separated list. Lower-cased, because
     * CouponService::validate() compares with mb_strtolower on both sides and
     * a stored mixed-case address would work there and read wrong in the admin.
     * An empty list is NULL, not [] — both are falsy to the check, and NULL is
     * what "no restriction" means in every other list column on this row.
     *
     * @return list<string>|null
     */
    private function emails(Row $row): ?array
    {
        $emails = $row->list(',', 'customer_email', 'allowed_emails', 'email_restrictions');

        $emails = array_values(array_unique(array_map(
            static fn (string $email): string => mb_strtolower(trim($email)),
            $emails,
        )));

        return $emails === [] ? null : $emails;
    }

    /**
     * The four id-list columns, translated out of WordPress's id space and
     * into this one.
     *
     * @return array<string, list<int>|null>
     *
     * @throws RowRejected
     */
    private function idLists(Row $row, ImportContext $context): array
    {
        /** @var list<array{0: string, 1: string, 2: bool, 3: list<string>}> $lists */
        $lists = [
            ['product_ids', 'products', true, ['product_ids', 'products']],
            ['excluded_product_ids', 'products', false, ['exclude_product_ids', 'excluded_product_ids', 'excluded_products']],
            ['category_ids', 'categories', true, ['product_categories', 'category_ids', 'categories']],
            ['excluded_category_ids', 'categories', false, ['exclude_product_categories', 'excluded_category_ids', 'excluded_categories']],
        ];

        $out = [];

        foreach ($lists as [$column, $entity, $isInclude, $aliases]) {
            $source = $row->list(',', ...$aliases);

            if ($source === []) {
                $out[$column] = null;

                continue;
            }

            $local = [];
            $lost = [];

            foreach ($source as $external) {
                if (preg_match('/^\d+$/', $external) !== 1) {
                    $lost[] = $external;

                    continue;
                }

                $id = $context->localId($entity, (int) $external);

                if ($id === null) {
                    $lost[] = $external;

                    continue;
                }

                $local[] = $id;
            }

            $local = array_values(array_unique($local));

            if ($lost !== [] && $local === [] && $isInclude) {
                /*
                 * The money case. See the class header: CouponService reads an
                 * empty include list as "no restriction", so this would import
                 * as a coupon valid on the entire catalogue.
                 */
                throw RowRejected::because(
                    $column.': none of the WooCommerce ids in this list ('.implode(', ', $lost).') is in this '
                    .'shop. The column holds local ids, and an EMPTY restriction list means "no restriction" '
                    .'-- so importing this row would turn a coupon limited to those '.$entity
                    .' into one valid on the whole catalogue. Import '.$entity.' first, or remove the '
                    .'restriction in WooCommerce.'
                );
            }

            if ($lost !== []) {
                $this->later(
                    'adjusted',
                    $isInclude
                        ? $column.': some WooCommerce ids in this restriction are not in this shop and were '
                            .'dropped -- the coupon is NARROWER here than it was in WooCommerce'
                        : $column.': some WooCommerce ids in this exclusion are not in this shop and were '
                            .'dropped -- they cannot be in a basket either, so the coupon prices the same',
                    $row->line,
                    $this->identify($row),
                    $column,
                    implode(', ', $source),
                    $local === [] ? '(nothing)' : implode(', ', array_map('strval', $local)),
                );
            }

            $out[$column] = $local === [] ? null : $local;
        }

        return $out;
    }

    /**
     * Woo columns this schema has nowhere to put, named with the value the
     * export really carried.
     */
    private function reportNoHome(Row $row): void
    {
        foreach (self::NO_HOME as $field => [$aliases, $lost]) {
            $value = $row->text(...$aliases);

            if ($value === null) {
                continue;
            }

            $this->later(
                'discarded',
                $field.' is in this export and this schema has no column for it -- '.$lost,
                $row->line,
                $this->identify($row),
                $field,
                $value,
            );
        }
    }

    /**
     * The per-customer limit that cannot be carried by anything.
     *
     * Reported for every coupon that HAS one, whether or not the export
     * carried `used_by`, because the loss is not about that column: it is that
     * the limit is enforced by counting redemption rows and an imported coupon
     * has none.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reportPerUserLimit(Row $row, array $attributes): void
    {
        $perUser = $attributes['usage_limit_per_user'] ?? null;

        if ($perUser === null) {
            return;
        }

        $this->later(
            'discarded',
            'which shoppers have already used this code -- usage_limit_per_user is enforced here by counting '
            .'coupon_redemptions rows carrying the shopper\'s email, an imported coupon has none, and nothing '
            .'in WooCommerce\'s export identifies the ORDER a use belonged to. Every customer starts again '
            .'from zero uses of this code',
            $row->line,
            $this->identify($row),
            'usage_limit_per_user',
            'limit of '.$perUser.' per customer, '.($attributes['usage_count'] ?? 0).' uses already made',
            'the limit is imported and enforced from zero',
        );
    }

    public function identify(Row $row): string
    {
        $code = $row->raw('code', 'coupon_code', 'post_title');

        if ($code !== null && trim($code) !== '') {
            return 'code='.trim($code);
        }

        $id = $row->raw('id', 'wc_id', 'coupon_id');

        return $id !== null && $id !== '' ? 'id='.$id : 'line '.$row->line;
    }
}
