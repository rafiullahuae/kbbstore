<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Support\AggregatesQueries;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Store → Coupons: creating, editing and deleting a code.
 *
 * WHY THIS EXISTS. Every coupon on the shop arrived in the WooCommerce import.
 * There was no way to make a new one, change one, or take one down —
 * CouponUsageApiController is a read-only usage report and says so in its own
 * docblock ("editing coupons is a different job with a different blast
 * radius"). This is that job. The owner ran the WooCommerce coupon editor for
 * years, so the three sections below are its three sections, in its order, with
 * its names where the behaviour matches.
 *
 * WHERE IT DELIBERATELY DOES NOT COPY WOOCOMMERCE. A field on this form must be
 * a field App\Services\CouponService actually enforces, because a restriction
 * the owner sets and the shop quietly ignores is worse than no field at all —
 * it is a discount the owner believes is fenced and is not. So:
 *
 *   - "Individual use only" is not writable: carts.coupon_id is a single
 *     nullable FK, so a basket has only ever held one coupon and the
 *     restriction is unconditionally in force for every code.
 *
 * THREE FIELDS THAT USED TO BE ON THAT LIST AND ARE NOT ANY MORE. They were
 * refused for one reason — CouponService did not enforce them — and that reason
 * has been removed rather than worked around. Each is now a live control here
 * because each is now a rule the till actually applies:
 *
 *   - "Limit usage to X items" writes coupons.limit_usage_to_x_items, which
 *     CouponService::discountFor() truncates the eligible lines against before
 *     it prices anything. Empty box -> NULL -> no cap, which is what every
 *     imported row holds. Where a cap BINDS it discounts the cheapest eligible
 *     units first; cappedLines() sets out why.
 *   - Brands / Exclude brands write coupons.brand_ids and
 *     excluded_brand_ids, which eligibleItems() compares against
 *     products.brand_id — the same shape, and the same "empty means NULL means
 *     no restriction" rule, as the four product and category lists beside them.
 *   - "Allow free shipping" writes coupons.free_shipping, which
 *     CartService::totals() now reads to zero the delivery line, and which
 *     Store\CheckoutController::place() honours because it takes the order's
 *     shipping_total from those same totals. The column always existed and the
 *     import always filled it; what was missing was anything that read it.
 *
 * All four of the new rule columns are named in CouponService::RULE_COLUMNS.
 * That is not optional bookkeeping: the storefront eager-loads the coupon as
 * `coupon:id,code,type,amount`, an unselected attribute reads null, and a null
 * rule is SKIPPED — so a rule missing from that list is a restriction the owner
 * sets on this screen and the shop silently ignores, which is the precise
 * failure this docblock's first paragraph is about.
 *
 * MONEY AND PERCENT ARE NOT THE SAME NUMBER. `coupons.amount` holds hundredths
 * of a percent for a percentage coupon (35% -> 3500) and minor units — fils —
 * for both fixed types. One column, two scales, decided by `type`. Everything
 * that reads or writes it here goes through amountToStorage() /
 * amountToInput(), and the conversion is integer arithmetic on the digits the
 * owner typed rather than a float multiply, for the reason CouponService's own
 * note on intdiv() gives at length.
 *
 * NOT PUBLIC. Same as the usage report: these are mounted only inside the
 * admin-api group, behind web + auth:admin. routes/coupons-admin.php holds the
 * guard and explains it; tests/Feature/CouponUsageScreenTest.php drives every
 * route the file registers — including these — unauthenticated and as a
 * customer, and expects each one refused.
 */
class CouponAdminApiController extends Controller
{
    use AggregatesQueries;

    /** Rows per page on the manage list. */
    private const PER_PAGE = 25;

    /** How many rows a picker lookup returns. */
    private const LOOKUP_LIMIT = 30;

    /**
     * The discount types CouponService can actually price.
     *
     * Read straight off the match() in discountFor(): 'percent' and
     * 'fixed_product' are named there, everything else falls to the default
     * arm, which is the whole-basket amount. Storing a fourth string would not
     * fail — it would silently become a fixed cart discount.
     */
    public const TYPES = [
        'percent' => 'Percentage discount',
        'fixed_cart' => 'Fixed cart discount',
        'fixed_product' => 'Fixed product discount',
    ];

    /**
     * MySQL's TIMESTAMP range, which `starts_at` and `expires_at` both are.
     *
     * Outside it, a strict-mode MySQL rejects the write outright while SQLite
     * takes it happily — precisely the dialect gap docs/MYSQL-PARITY.md exists
     * about. Validated rather than discovered in production.
     */
    private const DATE_MIN = '1970-01-02';

    private const DATE_MAX = '2038-01-18';

    /* ====================================================================
     | Reading
     ==================================================================== */

    /**
     * Every coupon, as the list screen reads it.
     *
     * Sorted newest first: the owner comes here to find the code they just
     * made, or the one they are about to take down, and both are recent. The
     * usage report next door sorts by usage instead, because that screen is
     * asking a different question.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $query = Coupon::query()
            ->when($search !== '', function ($q) use ($search) {
                $term = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%';

                $q->where(function ($w) use ($term) {
                    $w->whereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term]);
                });
            });

        $now = now();

        // Filtered in SQL rather than after paging: a filter applied to the
        // page would hide rows from page two onwards and print a total that
        // disagreed with what the table showed.
        $query->when($status === 'expired', fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<', $now))
            ->when($status === 'scheduled', fn ($q) => $q->whereNotNull('starts_at')->where('starts_at', '>', $now))
            ->when($status === 'exhausted', fn ($q) => $q->whereNotNull('usage_limit')->whereColumn('usage_count', '>=', 'usage_limit'))
            ->when($status === 'active', function ($q) use ($now) {
                $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
                    ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($w) => $w->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'));
            });

        /*
         * Through AggregatesQueries, which strips the row columns, the ORDER BY
         * and the page window from a clone before adding the aggregate. Doing
         * it by hand is MySQL error 1140 under ONLY_FULL_GROUP_BY, and a
         * surviving OFFSET makes every total read zero from page two on. Both
         * of those reached production; there is one implementation now.
         */
        $summary = $this->aggregate($query, implode(', ', [
            'COUNT(*) AS coupons',
            'COALESCE(SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < ? THEN 1 ELSE 0 END), 0) AS expired',
            'COALESCE(SUM(CASE WHEN usage_limit IS NOT NULL AND usage_count >= usage_limit THEN 1 ELSE 0 END), 0) AS exhausted',
        ]), [$now]);

        $page = max(1, (int) $request->query('page', 1));

        $coupons = (clone $query)
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        /*
         * Which of these codes carry redemption rows, in ONE query for the
         * page rather than one per row. The list needs it because a redeemed
         * coupon cannot be deleted (see destroy()), and a Delete button that
         * only fails when pressed is worse than one that is not offered.
         */
        $redeemed = $coupons->isEmpty()
            ? collect()
            : CouponRedemption::query()
                ->whereIn('coupon_id', $coupons->pluck('id')->all())
                ->groupBy('coupon_id')
                ->selectRaw('coupon_id, COUNT(*) AS rows_count')
                ->pluck('rows_count', 'coupon_id');

        $total = (int) ($summary->coupons ?? 0);

        return response()->json([
            'ok' => true,
            'summary' => [
                'coupons' => $total,
                'expired' => (int) ($summary->expired ?? 0),
                'exhausted' => (int) ($summary->exhausted ?? 0),
            ],
            'page' => $page,
            'pages' => (int) max(1, (int) ceil($total / self::PER_PAGE)),
            'types' => self::TYPES,
            'currency' => Money::currency(),
            'coupons' => $coupons->map(fn (Coupon $c) => $this->row($c, (int) ($redeemed[$c->id] ?? 0)))->all(),
        ]);
    }

    /** One coupon, with everything the three tabs need to draw themselves. */
    public function show(int $coupon): JsonResponse
    {
        $model = Coupon::find($coupon);

        if (! $model) {
            return response()->json(['ok' => false, 'error' => 'No such coupon.'], 404);
        }

        $redeemed = CouponRedemption::where('coupon_id', $model->id)->count();

        return response()->json([
            'ok' => true,
            'types' => self::TYPES,
            'currency' => Money::currency(),
            'coupon' => $this->row($model, $redeemed) + [
                'description' => (string) ($model->description ?? ''),
                'minimum_amount' => $this->moneyToInput($model->minimum_amount),
                'maximum_amount' => $this->moneyToInput($model->maximum_amount),
                'exclude_sale_items' => (bool) $model->exclude_sale_items,
                'free_shipping' => (bool) $model->free_shipping,
                'individual_use' => (bool) $model->individual_use,
                'allowed_emails' => $model->allowed_emails ?? [],
                'products' => $this->labelProducts($model->product_ids),
                'excluded_products' => $this->labelProducts($model->excluded_product_ids),
                'categories' => $this->labelCategories($model->category_ids),
                'excluded_categories' => $this->labelCategories($model->excluded_category_ids),
                'brands' => $this->labelBrands($model->brand_ids),
                'excluded_brands' => $this->labelBrands($model->excluded_brand_ids),
            ],
        ]);
    }

    /**
     * The product and category picker, for both the include and exclude lists.
     *
     * Its own endpoint rather than the catalogue lane's: those live in
     * routes/catalog-admin.php and routes/manual-orders-admin.php, and a screen
     * that reaches into another lane's route file stops working the day that
     * file is renamed or its payload reshaped. One small lookup here costs
     * nothing and keeps this screen's dependencies inside this screen.
     */
    public function lookup(Request $request): JsonResponse
    {
        $kind = match ($request->query('kind')) {
            'category' => 'category',
            'brand' => 'brand',
            default => 'product',
        };
        $search = trim((string) $request->query('q', ''));

        // `ids` resolves a stored selection back to names when the editor opens
        // a saved coupon; `q` searches. They are the same shape on the way out.
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $request->query('ids', ''))
        )));

        if ($kind === 'category') {
            $rows = Category::query()
                ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
                ->when($ids === [] && $search !== '', fn ($q) => $q->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%']
                ))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get(['id', 'name', 'path']);

            return response()->json([
                'ok' => true,
                'kind' => 'category',
                'items' => $rows->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'label' => (string) $c->name,
                    'hint' => (string) ($c->path ?? ''),
                ])->all(),
            ]);
        }

        if ($kind === 'brand') {
            $rows = Brand::query()
                ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
                ->when($ids === [] && $search !== '', fn ($q) => $q->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%']
                ))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get(['id', 'name', 'slug']);

            return response()->json([
                'ok' => true,
                'kind' => 'brand',
                'items' => $rows->map(fn ($b) => [
                    'id' => (int) $b->id,
                    'label' => (string) $b->name,
                    'hint' => (string) ($b->slug ?? ''),
                ])->all(),
            ]);
        }

        $rows = Product::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === [] && $search !== '', function ($q) use ($search) {
                $term = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)) . '%';

                /*
                 * Brand name too. The owner searched this picker for "anua"
                 * and got nothing: Anua is a brand, no product name contains
                 * it, and a name-and-sku search cannot see it. Every other
                 * product search in the admin matches the brand, so this one
                 * disagreeing is the surprise.
                 *
                 * It matters more here than elsewhere, because fencing a
                 * coupon to a brand's products is one of the main reasons to
                 * open this picker at all.
                 */
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$term])
                    ->orWhereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', [$term])));
            })
            ->with('brand:id,name')
            ->orderBy('name')
            ->limit(self::LOOKUP_LIMIT)
            ->get(['id', 'name', 'sku', 'brand_id']);

        return response()->json([
            'ok' => true,
            'kind' => 'product',
            'items' => $rows->map(fn ($p) => [
                'id' => (int) $p->id,
                'label' => (string) $p->name,
                // The brand is what the operator searched by, so show it back.
                'hint' => trim(implode(' · ', array_filter([
                    (string) ($p->brand?->name ?? ''),
                    (string) ($p->sku ?? ''),
                ]))),
            ])->all(),
        ]);
    }

    /* ====================================================================
     | Writing
     ==================================================================== */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $coupon = Coupon::create($data);

        return response()->json([
            'ok' => true,
            'id' => (int) $coupon->id,
            'coupon' => $this->row($coupon->refresh(), 0),
        ], 201);
    }

    public function update(Request $request, int $coupon): JsonResponse
    {
        $model = Coupon::find($coupon);

        if (! $model) {
            return response()->json(['ok' => false, 'error' => 'No such coupon.'], 404);
        }

        $data = $this->validated($request, $model);

        /*
         * forceFill + save rather than update($data): `usage_count` and `wc_id`
         * are never in $data, so neither can be reached from the browser. The
         * counter in particular is the one number on this row that must only
         * ever move through CouponService::recordRedemption(), under the lock
         * that method takes. An editor that could set it would hand back uses
         * of a code that had already been spent.
         */
        $model->forceFill($data)->save();

        $redeemed = CouponRedemption::where('coupon_id', $model->id)->count();

        return response()->json([
            'ok' => true,
            'id' => (int) $model->id,
            'coupon' => $this->row($model->refresh(), $redeemed),
        ]);
    }

    /**
     * Delete a coupon — unless it has been redeemed.
     *
     * coupon_redemptions.coupon_id is `constrained()->cascadeOnDelete()`, so
     * deleting the row does NOT orphan anything: the database takes every
     * redemption with it, silently and without a warning anywhere. What is lost
     * is the entire answer to "who used this code and for how much" — the whole
     * of the Coupon usage screen for that code — while `orders.coupon_code`
     * keeps printing the code on the order, so the order still claims a
     * discount whose record no longer exists.
     *
     * That is not a confirmation dialog's worth of risk, so it is refused
     * outright and the owner is pointed at the thing they almost certainly
     * meant: setting the expiry date, which stops the code working and keeps
     * the history. A coupon nobody has redeemed deletes normally.
     *
     * The imported-counter case is separate and deliberately allowed.
     * usage_count came over from WooCommerce verbatim, so a coupon can read
     * "used 40 times" with no redemption rows in this application at all;
     * refusing those would mean most of the imported codes could never be
     * removed. They delete, and the response says how many uses the counter
     * was carrying so the number is not merely gone.
     */
    public function destroy(int $coupon): JsonResponse
    {
        $model = Coupon::find($coupon);

        if (! $model) {
            return response()->json(['ok' => false, 'error' => 'No such coupon.'], 404);
        }

        $redeemed = CouponRedemption::where('coupon_id', $model->id)->count();

        if ($redeemed > 0) {
            return response()->json([
                'ok' => false,
                'error' => 'This code has been redeemed ' . $redeemed . ' time' . ($redeemed === 1 ? '' : 's')
                    . '. Deleting it would erase those redemptions from the usage report as well. '
                    . 'Set its expiry date instead — that stops the code working and keeps the record.',
                'redemptions' => $redeemed,
            ], 409);
        }

        $code = (string) $model->code;
        $carried = (int) $model->usage_count;

        $model->delete();

        return response()->json([
            'ok' => true,
            'deleted' => $code,
            'imported_usage_count' => $carried,
        ]);
    }

    /* ====================================================================
     | Validation
     ==================================================================== */

    /**
     * The request, checked and turned into columns.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Coupon $existing): array
    {
        $type = (string) $request->input('type', 'percent');

        $rules = [
            'code' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'amount' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],

            'starts_at' => ['nullable', 'date', 'after:' . self::DATE_MIN, 'before:' . self::DATE_MAX],
            'expires_at' => ['nullable', 'date', 'after:' . self::DATE_MIN, 'before:' . self::DATE_MAX],

            'minimum_amount' => ['nullable', 'string', 'max:20'],
            'maximum_amount' => ['nullable', 'string', 'max:20'],
            'exclude_sale_items' => ['sometimes', 'boolean'],

            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1', 'max:4294967295'],

            /*
             * WooCommerce's "limit usage to X items", now that CouponService
             * honours it. min:1 rather than min:0 on purpose: a cap of zero is
             * a code that discounts nothing, which is a coupon switched off
             * written in a way nobody would recognise as one. Leaving the box
             * empty is how you say "no cap", and that stores NULL.
             */
            'limit_usage_to_x_items' => ['nullable', 'integer', 'min:1', 'max:4294967295'],

            // Enforced since this lane: CartService::totals() zeroes the
            // delivery line for a code carrying it. Writable for the same
            // reason it is now shown ungreyed — the shop keeps the promise.
            'free_shipping' => ['sometimes', 'boolean'],

            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'excluded_product_ids' => ['nullable', 'array'],
            'excluded_product_ids.*' => ['integer'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'excluded_category_ids' => ['nullable', 'array'],
            'excluded_category_ids.*' => ['integer'],
            'brand_ids' => ['nullable', 'array'],
            'brand_ids.*' => ['integer'],
            'excluded_brand_ids' => ['nullable', 'array'],
            'excluded_brand_ids.*' => ['integer'],

            'allowed_emails' => ['nullable', 'array'],
            'allowed_emails.*' => ['string', 'max:190'],
        ];

        $input = $request->validate($rules, [
            'code.required' => 'Give the coupon a code.',
            'amount.required' => 'Give the coupon an amount.',
            'type.in' => 'That is not a discount type this shop can price.',
        ]);

        $code = trim((string) $input['code']);

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Give the coupon a code.']);
        }

        /*
         * DUPLICATES ARE MATCHED THE WAY THE SHOPPER'S CODE IS MATCHED.
         * Coupon::scopeCode() is `LOWER(code) = ?`, so GLOW10 and glow10 are
         * one code at the till. The unique index on `code` does not settle it:
         * production MySQL is utf8mb4_unicode_ci and would refuse the second
         * one, but the SQLite the suite runs on is case-SENSITIVE and would
         * accept it — leaving two rows that scopeCode() picks between by
         * whichever the database hands back first. Checked here so both engines
         * behave the same, and so the owner gets a sentence instead of a 500
         * from a constraint violation.
         */
        $clash = Coupon::code($code)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'code' => 'There is already a coupon with the code "' . $clash->code . '". Codes are matched without regard to capitals, so this would be the same code.',
            ]);
        }

        $amount = $this->amountToStorage((string) $input['amount'], $type);

        if ($amount === null || $amount < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The amount has to be a number, and not a negative one.',
            ]);
        }

        /*
         * A PERCENTAGE CANNOT EXCEED 100. discountFor() already floors the
         * payout at the eligible subtotal, so 500% would not hand out five
         * times the basket — but it would make every basket free while the
         * screen reported "500% off", and nothing downstream would flag it.
         * `amount` is hundredths of a percent, so the ceiling is 10000.
         */
        if ($type === 'percent' && $amount > 10000) {
            throw ValidationException::withMessages([
                'amount' => 'A percentage discount cannot be more than 100%.',
            ]);
        }

        $starts = $this->dayStart($input['starts_at'] ?? null);
        $expires = $this->dayEnd($input['expires_at'] ?? null);

        if ($starts && $expires && $starts->gt($expires)) {
            throw ValidationException::withMessages([
                'expires_at' => 'The coupon would expire before it started.',
            ]);
        }

        $emails = $this->cleanEmails($input['allowed_emails'] ?? []);

        return [
            'code' => $code,
            'type' => $type,
            'amount' => $amount,
            'description' => $this->blankToNull($input['description'] ?? null),

            'starts_at' => $starts,
            'expires_at' => $expires,

            'minimum_amount' => $this->moneyToStorage($input['minimum_amount'] ?? null),
            'maximum_amount' => $this->moneyToStorage($input['maximum_amount'] ?? null),
            'exclude_sale_items' => $request->boolean('exclude_sale_items'),

            /*
             * WRITTEN ONLY WHEN THE FORM ACTUALLY POSTS IT, which is why the
             * rule above is `sometimes` and why this is not a plain
             * $request->boolean().
             *
             * boolean() reads a missing key as FALSE. The screen drew this box
             * disabled for as long as the shop did not enforce the flag, and a
             * disabled input posts nothing — so an unconditional write here
             * would clear coupons.free_shipping on every single save made from
             * a screen that had not yet been un-greyed. Every coupon carrying
             * the flag arrived in the WooCommerce import and nothing else sets
             * it, so that loss would be silent and permanent, and the owner's
             * first sign of it would be a customer charged for delivery a code
             * promised them.
             *
             * Absent therefore means "leave it as it is", which is exactly what
             * this endpoint did before the flag was enforceable. On a new
             * coupon there is nothing to keep, so it falls to the column's own
             * default of false.
             */
            'free_shipping' => $request->has('free_shipping')
                ? $request->boolean('free_shipping')
                : (bool) ($existing->free_shipping ?? false),

            'usage_limit' => $this->nullableInt($input['usage_limit'] ?? null),
            'usage_limit_per_user' => $this->nullableInt($input['usage_limit_per_user'] ?? null),
            'limit_usage_to_x_items' => $this->nullableInt($input['limit_usage_to_x_items'] ?? null),

            // An empty selection is stored as NULL, not as []. CouponService
            // reads these with a plain truthiness test — `if ($coupon->
            // product_ids && ...)` — and [] and null both fall through it, but
            // null is what the import wrote and what every existing row holds.
            // Two spellings of "no restriction" in one column is how a later
            // `!== null` check ends up meaning the opposite of what it reads.
            'product_ids' => $this->idList($input['product_ids'] ?? null),
            'excluded_product_ids' => $this->idList($input['excluded_product_ids'] ?? null),
            'category_ids' => $this->idList($input['category_ids'] ?? null),
            'excluded_category_ids' => $this->idList($input['excluded_category_ids'] ?? null),
            'brand_ids' => $this->idList($input['brand_ids'] ?? null),
            'excluded_brand_ids' => $this->idList($input['excluded_brand_ids'] ?? null),

            'allowed_emails' => $emails === [] ? null : $emails,
        ];
    }

    /* ====================================================================
     | Conversions
     ==================================================================== */

    /**
     * What the owner typed -> what the column holds.
     *
     * The percentage arm is the one that matters. `coupons.amount` is hundredths
     * of a percent, so 35 must become 3500 — and it is reached by integer
     * arithmetic on the digits, never `(int) ($value * 100)`. A binary float
     * cannot hold 1.15, so the float route makes 1.15% into 114 hundredths
     * instead of 115, and 7.35% into 734. It is the same defect the note beside
     * intdiv() in CouponService::discountFor() describes from the other end.
     */
    private function amountToStorage(string $raw, string $type): ?int
    {
        return $type === 'percent'
            ? $this->hundredths($raw)
            : $this->moneyToStorage($raw);
    }

    /** The stored column -> the decimal the owner should see in the box. */
    private function amountToInput(Coupon $coupon): string
    {
        return $coupon->type === 'percent'
            ? $this->hundredthsToString((int) $coupon->amount)
            : Money::decimalString((int) $coupon->amount);
    }

    /**
     * A decimal string -> hundredths of that unit, exactly.
     *
     * Digits only, taken as two integers and recombined. No float appears at
     * any point, so "12.5" is 1250 and "7.35" is 735 on every platform. More
     * than two decimals are refused rather than silently truncated: a coupon
     * typed as 7.125% would otherwise be saved as 7.12% and read back looking
     * like a typo the owner did not make.
     */
    private function hundredths(string $raw): ?int
    {
        $raw = str_replace([' ', ','], ['', '.'], trim($raw));

        if ($raw === '' || ! preg_match('/^(\d*)(?:\.(\d*))?$/', $raw, $m)) {
            return null;
        }

        $whole = $m[1] ?? '';
        $frac = $m[2] ?? '';

        if ($whole === '' && $frac === '') {
            return null;
        }

        if (mb_strlen($frac) > 2) {
            return null;
        }

        return (int) ($whole === '' ? '0' : $whole) * 100
             + (int) str_pad($frac, 2, '0', STR_PAD_RIGHT);
    }

    /** Hundredths -> the shortest decimal that means the same thing. */
    private function hundredthsToString(int $value): string
    {
        $whole = intdiv($value, 100);
        $frac = $value % 100;

        if ($frac === 0) {
            return (string) $whole;
        }

        return rtrim($whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT), '0');
    }

    /**
     * A major-unit money string -> minor units.
     *
     * Money::fromMajor() rather than hundredths(), because the money columns
     * follow the store's configured currency exponent — two for AED, three for
     * KWD — while a percentage is always hundredths of a percent whatever the
     * currency is. Using one helper for both is how a store switched to a
     * three-decimal currency would start storing every percentage ten times
     * too small.
     */
    private function moneyToStorage(?string $raw): ?int
    {
        $raw = $raw === null ? '' : trim($raw);

        if ($raw === '') {
            return null;
        }

        if (! preg_match('/^\d*(?:[.,]\d*)?$/', $raw)) {
            return null;
        }

        return Money::fromMajor(str_replace(',', '.', $raw));
    }

    private function moneyToInput(?int $minor): string
    {
        return $minor === null ? '' : Money::decimalString($minor);
    }

    /* ====================================================================
     | Shaping
     ==================================================================== */

    /**
     * One coupon as both screens read it.
     *
     * `amount_display` is built from `type`, because the two scales print
     * differently and formatting them the same way shows "AED 10.00" for a 10%
     * code — which is the mistake this whole column invites.
     */
    private function row(Coupon $coupon, int $redemptions): array
    {
        $limit = $coupon->usage_limit === null ? null : (int) $coupon->usage_limit;
        $used = (int) $coupon->usage_count;
        $now = now();

        $expired = $coupon->expires_at !== null && $now->gt($coupon->expires_at);
        $scheduled = $coupon->starts_at !== null && $now->lt($coupon->starts_at);
        $exhausted = $limit !== null && $used >= $limit;

        /*
         * One status, in the order the owner needs to hear it. A code can be
         * expired AND fully redeemed; "expired" is the fact that explains why
         * it is not working today, so it wins.
         */
        $status = match (true) {
            $expired => 'expired',
            $exhausted => 'exhausted',
            $scheduled => 'scheduled',
            default => 'active',
        };

        return [
            'id' => (int) $coupon->id,
            'code' => (string) $coupon->code,
            'type' => (string) $coupon->type,
            'type_label' => self::TYPES[$coupon->type] ?? 'Fixed cart discount',
            'amount' => (int) $coupon->amount,
            'amount_input' => $this->amountToInput($coupon),
            'amount_display' => $coupon->type === 'percent'
                ? $this->hundredthsToString((int) $coupon->amount) . '%'
                : Money::plain((int) $coupon->amount),
            'description' => (string) ($coupon->description ?? ''),

            'usage_count' => $used,
            'usage_limit' => $limit,
            'usage_limit_per_user' => $coupon->usage_limit_per_user === null ? null : (int) $coupon->usage_limit_per_user,
            // NULL is "no cap" and has to survive the round trip as NULL: the
            // editor prints an empty box for it, and CouponService::
            // cappedLines() tells NULL from 0 with `=== null`.
            'limit_usage_to_x_items' => $coupon->limit_usage_to_x_items === null
                ? null
                : (int) $coupon->limit_usage_to_x_items,
            'remaining' => $limit === null ? null : max(0, $limit - $used),

            'starts_at' => $coupon->starts_at?->toDateString(),
            'expires_at' => $coupon->expires_at?->toDateString(),

            'status' => $status,
            'expired' => $expired,
            'exhausted' => $exhausted,
            'scheduled' => $scheduled,

            // Straight from the FK's own behaviour — see destroy().
            'redemptions' => $redemptions,
            'deletable' => $redemptions === 0,
            'imported' => $coupon->wc_id !== null,
        ];
    }

    /** @param array<int, int>|null $ids */
    private function labelProducts(?array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $rows = Product::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'sku']);

        /*
         * A selection is echoed back even when the product it names is gone.
         * Deleting a product does NOT clean these JSON columns — nothing does —
         * so a stale id sits in the rule for ever, and eligibleItems() compares
         * live product ids against it on every cart render. Dropping it
         * silently here would make the editor disagree with the pricing; shown
         * as "Product #123 (deleted)", the owner can see it and take it out.
         */
        $found = $rows->keyBy('id');

        return collect($ids)->map(fn ($id) => [
            'id' => (int) $id,
            'label' => $found->has($id) ? (string) $found[$id]->name : ('Product #' . $id . ' (deleted)'),
            'hint' => $found->has($id) ? (string) ($found[$id]->sku ?? '') : 'no longer in the catalogue',
            'missing' => ! $found->has($id),
        ])->all();
    }

    /** @param array<int, int>|null $ids */
    private function labelCategories(?array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $found = Category::whereIn('id', $ids)->get(['id', 'name', 'path'])->keyBy('id');

        return collect($ids)->map(fn ($id) => [
            'id' => (int) $id,
            'label' => $found->has($id) ? (string) $found[$id]->name : ('Category #' . $id . ' (deleted)'),
            'hint' => $found->has($id) ? (string) ($found[$id]->path ?? '') : 'no longer in the catalogue',
            'missing' => ! $found->has($id),
        ])->all();
    }

    /**
     * Brand ids -> picker chips, the same way products and categories do it.
     *
     * Including the "(deleted)" case, and for the same reason spelled out in
     * labelProducts(): deleting a brand nulls products.brand_id but leaves the
     * id sitting in the coupon's JSON column for ever, where
     * CouponService::eligibleItems() keeps comparing against it. An id the
     * editor dropped silently would make the screen disagree with the till.
     *
     * @param  array<int, int>|null  $ids
     */
    private function labelBrands(?array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $found = Brand::whereIn('id', $ids)->get(['id', 'name', 'slug'])->keyBy('id');

        return collect($ids)->map(fn ($id) => [
            'id' => (int) $id,
            'label' => $found->has($id) ? (string) $found[$id]->name : ('Brand #' . $id . ' (deleted)'),
            'hint' => $found->has($id) ? (string) ($found[$id]->slug ?? '') : 'no longer in the catalogue',
            'missing' => ! $found->has($id),
        ])->all();
    }

    /* ====================================================================
     | Small helpers
     ==================================================================== */

    /**
     * A date the owner picked -> the first instant of that day.
     *
     * CouponService refuses the code while `now < starts_at`, so midnight is
     * "works from this day onwards", which is what a date picker means.
     */
    private function dayStart(?string $raw): ?\Illuminate\Support\Carbon
    {
        return $raw === null || trim($raw) === ''
            ? null
            : \Illuminate\Support\Carbon::parse($raw)->startOfDay();
    }

    /**
     * A date the owner picked -> the LAST instant of that day.
     *
     * CouponService refuses the code while `now > expires_at`. Stored at
     * midnight, a coupon "expiring on the 31st" would stop working as the 30th
     * ended — a day early, which is a day of a sale. Stored at 23:59:59 it
     * works all through the 31st, which is what the field's help text on the
     * screen says and what WooCommerce does.
     *
     * An expiry in the past is accepted deliberately: the owner records codes
     * that have already run, and the list marks them Expired rather than
     * refusing the save.
     */
    private function dayEnd(?string $raw): ?\Illuminate\Support\Carbon
    {
        return $raw === null || trim($raw) === ''
            ? null
            : \Illuminate\Support\Carbon::parse($raw)->endOfDay();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableInt($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @return array<int, int>|null */
    private function idList(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));

        return $clean === [] ? null : $clean;
    }

    /**
     * The allowed-emails list, lower-cased.
     *
     * validate() compares `mb_strtolower($email)` against
     * `array_map('mb_strtolower', $coupon->allowed_emails)`, so case is already
     * irrelevant at the till. Stored lower-cased anyway, so the list the owner
     * reads back is the list that is actually being compared, and a duplicate
     * that differs only in capitals does not appear twice.
     *
     * @return array<int, string>
     */
    private function cleanEmails(array $raw): array
    {
        $out = [];

        foreach ($raw as $entry) {
            foreach (preg_split('/[\s,;]+/', (string) $entry) ?: [] as $one) {
                $one = mb_strtolower(trim($one));

                if ($one !== '' && ! in_array($one, $out, true)) {
                    $out[] = $one;
                }
            }
        }

        return $out;
    }
}
