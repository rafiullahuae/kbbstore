<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Routine;
use App\Services\BuildMyRoutine;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\RoutineConcerns;
use App\Support\RoutineRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalog → Build my routine. The owner's half of Phase 10 — Lane FM.
 *
 * Three jobs, and the FIRST one is the one that makes the other two honest:
 *
 *   1. WHAT IS UNTAGGED. `products.routine_role` starts NULL on every row in
 *      the catalogue, and a routine engine that silently skips half the shop
 *      looks like it is working. So the screen leads on the count and the list
 *      can be filtered to exactly the untagged rows.
 *   2. Tagging: the role a product plays, and the concerns it is for.
 *   3. The routines themselves and the module's settings.
 *
 * Every screen this console has shipped with a control that saved nothing is
 * named in ModuleSchema's header. Nothing here is drawn that this file cannot
 * save, and tests/Feature/BuildMyRoutineTest.php posts to every endpoint below
 * and reads the row back.
 */
class RoutinesApiController extends Controller
{
    /** The page size of the tagging table. */
    private const PER_PAGE = 25;

    /** The LIKE escape character, and it in SQL. See products(). */
    private const LIKE_ESCAPE = '!';

    private const LIKE_ESCAPE_SQL = "'!'";

    public function __construct(
        private BuildMyRoutine $routines,
        private SettingsService $settings,
    ) {}

    /** GET /admin-api/routines — the whole screen, in one request. */
    public function show(): JsonResponse
    {
        $values = $this->routines->all();
        $overrides = Routine::overrides();
        $coverage = $this->routines->coverage();

        $rows = [];

        foreach (RoutineConcerns::slugs() as $concern) {
            $override = $overrides[$concern] ?? null;

            $rows[] = [
                'concern' => $concern,
                'label' => RoutineConcerns::adminLabel($concern),
                'title' => $override?->ownTitle(),
                'blurb' => $override?->ownBlurb(),
                // What the owner saved, which may be nothing.
                'own_steps' => $override?->stepRoles(),
                // What the storefront will actually draw, which honours
                // steps_mode. The screen shows both, because "I saved a step
                // list and the shop ignores it" is a support question the
                // screen should answer before it is asked.
                'steps' => $this->routines->rolesFor($override),
                'coupon_code' => $override?->ownCouponCode(),
                'is_enabled' => $override === null ? true : (bool) $override->is_enabled,
                'position' => (int) ($override?->position ?? 0),
                'empty' => $coverage['routines'][$concern]['empty'] ?? [],
            ];
        }

        return response()->json([
            'module_on' => $this->routines->enabled(),
            'tabs' => ModuleSchema::tabs(BuildMyRoutine::SCHEMA, BuildMyRoutine::TABS, $values),
            'settings' => $values,
            'roles' => array_map(
                static fn (string $role) => ['key' => $role, 'label' => RoutineRoles::ADMIN_LABELS[$role] ?? $role],
                RoutineRoles::ORDER
            ),
            'concerns' => array_map(
                static fn (string $slug) => ['key' => $slug, 'label' => RoutineConcerns::adminLabel($slug)],
                RoutineConcerns::slugs()
            ),
            'coverage' => $coverage,
            'routines' => $rows,
            /*
             * The real coupon codes, so the offer strip is CHOSEN rather than
             * typed. A typed code that does not exist shows nothing at all on
             * the storefront and nothing anywhere explains why — which is the
             * silent-configuration-error shape this project has paid for
             * repeatedly. Capped, because a shop can hold thousands and this is
             * a dropdown.
             */
            'coupons' => Coupon::query()
                ->orderByDesc('id')
                ->limit(100)
                ->get(['code', 'type', 'amount'])
                ->map(static fn (Coupon $c) => [
                    'code' => (string) $c->code,
                    'type' => (string) $c->type,
                    'amount' => (int) $c->amount,
                ])->all(),
        ]);
    }

    /** POST /admin-api/routines-settings — the module's own settings. */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(BuildMyRoutine::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        /*
         * AN EMPTY TEXT BOX IS A VALUE, AND IT HAS TO SURVIVE THIS.
         *
         * `offer_coupon` is deliberately clearable — emptying it is how the
         * owner takes the offer strip down. ConvertEmptyStringsToNull is global
         * middleware, so an emptied box arrives here as NULL, and
         * ModuleSchema::cast() answers `is_scalar(null) ? ... : null` for a text
         * field, which the writer reads as "rejected" and this endpoint would
         * turn into a 422 saying the value is invalid. That is the exact defect
         * another lane is fixing on Store → Ecommerce, and a schema that only
         * works while it is unfixed is a schema built on a bug.
         *
         * So the coercion is here, in this lane's own endpoint, and it is
         * correct whichever way that fix lands: a null arriving for a text field
         * means the box was emptied, and an empty box means the empty string.
         */
        foreach (ModuleSchema::normalise(BuildMyRoutine::SCHEMA) as $key => $field) {
            if (array_key_exists($key, $data['settings'])
                && $data['settings'][$key] === null
                && in_array($field['type'], ['text', 'textarea', 'colour'], true)) {
                $data['settings'][$key] = '';
            }
        }

        $rejected = $this->routines->save($data['settings']);

        if ($rejected !== []) {
            return response()->json([
                'ok' => false,
                'error' => '“'.implode('”, “', $rejected).'” is not a valid value.',
            ], 422);
        }

        return response()->json(['ok' => true, 'settings' => $this->routines->all()]);
    }

    /** POST /admin-api/routines/{concern} — one routine's overrides. */
    public function saveRoutine(Request $request, string $concern): JsonResponse
    {
        if (! RoutineConcerns::exists($concern)) {
            return response()->json(['ok' => false, 'error' => 'No such concern.'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'blurb' => ['sometimes', 'nullable', 'string', 'max:400'],
            'steps' => ['sometimes', 'nullable', 'array', 'max:'.count(RoutineRoles::ORDER)],
            'steps.*' => ['string', 'in:'.implode(',', RoutineRoles::ORDER)],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'between:-999,999'],
        ]);

        $row = Routine::firstOrNew(['concern' => $concern]);

        foreach (['title', 'blurb', 'coupon_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                // NULL rather than '' for an emptied box, so "the owner has not
                // said" has exactly one representation in the column and the
                // model's own* accessors do not have to test for two.
                $row->{$field} = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('steps', $data)) {
            $steps = [];

            foreach ($data['steps'] ?? [] as $role) {
                if (RoutineRoles::isRole($role) && ! in_array($role, $steps, true)) {
                    $steps[] = $role;
                }
            }

            $row->steps = $steps === [] ? null : $steps;
        }

        if (array_key_exists('is_enabled', $data)) {
            $row->is_enabled = (bool) $data['is_enabled'];
        }

        if (array_key_exists('position', $data)) {
            $row->position = (int) $data['position'];
        }

        $row->save();

        return response()->json([
            'ok' => true,
            'routine' => [
                'concern' => $concern,
                'title' => $row->ownTitle(),
                'blurb' => $row->ownBlurb(),
                'own_steps' => $row->stepRoles(),
                'steps' => $this->routines->rolesFor($row),
                'coupon_code' => $row->ownCouponCode(),
                'is_enabled' => (bool) $row->is_enabled,
                'position' => (int) $row->position,
            ],
        ]);
    }

    /**
     * GET /admin-api/routine-products — the tagging table.
     *
     * `role=none` is the whole point of the filter and is NOT an absent role
     * parameter: "show me everything" and "show me what nobody has tagged" are
     * different questions and the second is the one the owner comes here to
     * ask.
     */
    public function products(Request $request): JsonResponse
    {
        $query = Product::query()->with('brand');

        $term = trim((string) $request->query('q', ''));

        if ($term !== '') {
            /*
             * The escape character is DECLARED, and that is not decoration.
             * MySQL treats a backslash as LIKE's escape by default and SQLite
             * has no default escape at all — so a pattern escaped with
             * backslashes matches what the operator typed on the live shop and
             * matches a literal backslash in the test suite. A search for "50%"
             * would quietly return the whole catalogue on one engine and
             * nothing on the other.
             *
             * Same character and same doubling as
             * ProductEditorApiController::escapeLike(); this console already
             * has one convention for it.
             */
            $pattern = '%'.str_replace(
                [self::LIKE_ESCAPE, '%', '_'],
                [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
                $term
            ).'%';

            /*
             * NAME, SKU **AND INGREDIENTS** — Lane Q.
             *
             * docs/SEO-CONCERN-MAPPING.md §3 is a worksheet of about thirty
             * words the owner is meant to type into this box, and §7 item A
             * names the reason most of them would have returned nothing:
             * `ingredients` is a real column on this table
             * (2026_10_05_000000_add_product_editor_columns.php:84) and this
             * search could not see it.
             *
             * It matters most for exactly the concern that is hardest to shop
             * for. The sensitivity signal is "fragrance-free", "centella",
             * "panthenol", "ceramide" — words that live in an ingredient list
             * and almost never in a product name. Without this, tagging
             * `sensitivity` means opening products one at a time.
             *
             * SAME PATTERN, SAME DECLARED ESCAPE. Not a second escaping scheme:
             * §7 item A's guard, and the reason the escape character is spelled
             * out at all, is in the note above.
             *
             * NO NEW QUERY. One more OR inside the WHERE this method already
             * builds, so the endpoint still costs the same two round trips (the
             * count and the page) however many columns are searched.
             */
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw('name LIKE ? ESCAPE '.self::LIKE_ESCAPE_SQL, [$pattern])
                    ->orWhereRaw('sku LIKE ? ESCAPE '.self::LIKE_ESCAPE_SQL, [$pattern])
                    ->orWhereRaw('ingredients LIKE ? ESCAPE '.self::LIKE_ESCAPE_SQL, [$pattern]);
            });
        }

        $role = (string) $request->query('role', '');

        if ($role === 'none') {
            $query->whereNull('routine_role');
        } elseif (RoutineRoles::isRole($role)) {
            $query->where('routine_role', $role);
        }

        $page = max(1, (int) $request->query('page', 1));

        $total = (clone $query)->count();

        $rows = $query
            ->orderBy('name')
            ->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'products' => $rows->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'brand' => $p->brand?->name,
                'sku' => $p->sku,
                'role' => RoutineRoles::normalise($p->routine_role),
                'concerns' => RoutineConcerns::clean($p->routine_concerns),
                /*
                 * Whether a shopper could be shown this row at all. The engine
                 * reads visible() and inStock(), so a tagged draft contributes
                 * nothing to any routine — and an owner who tagged five
                 * cleansers and sees an empty step needs to be told which of
                 * them are not on the storefront, not left to guess.
                 */
                'live' => $p->status === 'publish'
                    && (bool) $p->is_visible
                    && ! $p->isScheduled()
                    && $p->stock_status === 'instock',
                /*
                 * WHY THIS ROW CAME BACK, when the reason is not on the screen.
                 * A search for "centella" now matches an ingredient list, and a
                 * product whose name says "Calming Toner" would otherwise appear
                 * with nothing about it explaining the hit -- which reads as a
                 * broken search and is the fastest way to make the owner stop
                 * trusting the box. Null whenever the name or the SKU already
                 * shows the answer.
                 */
                'ingredient_hit' => $this->ingredientHit($p, $term),
            ])->all(),
        ]);
    }

    /**
     * A short piece of this product's ingredient list around the search term.
     *
     * Null when there is no term, when the term is already visible in the name
     * or the SKU, or when it is not in the ingredients at all.
     *
     * A SNIPPET AND NOT THE COLUMN. An ingredient list runs to hundreds of
     * characters and this endpoint returns twenty-five rows; shipping all of
     * them would multiply the payload for something the eye cannot read anyway.
     * The window is deliberately small -- enough to show the matched word in the
     * company it keeps, which is what tells the owner whether "centella" here is
     * the third ingredient or the thirtieth.
     *
     * mb_* throughout: ingredient lists carry accented Latin and the occasional
     * Korean, and splitting a multi-byte character in half would put invalid
     * UTF-8 into a json response.
     */
    private function ingredientHit(Product $product, string $term): ?string
    {
        if ($term === '') {
            return null;
        }

        $ingredients = (string) ($product->ingredients ?? '');

        if ($ingredients === '') {
            return null;
        }

        // Already answered by something the row prints.
        if (mb_stripos((string) $product->name, $term) !== false
            || mb_stripos((string) ($product->sku ?? ''), $term) !== false) {
            return null;
        }

        // Collapse whitespace first, so a list stored with newlines does not
        // produce a snippet that is mostly blank.
        $flat = trim((string) preg_replace('/\s+/u', ' ', $ingredients));
        $at = mb_stripos($flat, $term);

        if ($at === false) {
            return null;
        }

        $pad = 34;
        $start = max(0, $at - $pad);
        $length = mb_strlen($term) + ($at - $start) + $pad;

        $snippet = mb_substr($flat, $start, $length);

        return ($start > 0 ? '…' : '')
            . $snippet
            . ($start + $length < mb_strlen($flat) ? '…' : '');
    }

    /** POST /admin-api/routine-products/{id} — tag one product. */
    public function tag(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['ok' => false, 'error' => 'No such product.'], 404);
        }

        $data = $request->validate([
            'role' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', RoutineRoles::ORDER)],
            'concerns' => ['sometimes', 'nullable', 'array', 'max:'.count(RoutineConcerns::LIST)],
            'concerns.*' => ['string', 'in:'.implode(',', RoutineConcerns::slugs())],
        ]);

        if (array_key_exists('role', $data)) {
            $product->routine_role = RoutineRoles::normalise($data['role']);
        }

        if (array_key_exists('concerns', $data)) {
            $concerns = RoutineConcerns::clean($data['concerns']);
            // NULL and [] mean the same thing to the engine — "suits any
            // routine" — and NULL is what an untouched row already holds, so
            // clearing the boxes puts the row back into exactly that state
            // rather than beside it.
            $product->routine_concerns = $concerns === [] ? null : json_encode($concerns);
        }

        $product->save();

        return response()->json([
            'ok' => true,
            'product' => [
                'id' => (int) $product->id,
                'role' => RoutineRoles::normalise($product->routine_role),
                'concerns' => RoutineConcerns::clean($product->routine_concerns),
            ],
            // The counts move on every save and the header prints them, so they
            // ride back with the write rather than costing a second request.
            'coverage' => $this->routines->coverage(),
        ]);
    }
}
