<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Gtin;
use App\Support\MajorUnits;
use App\Support\Money;
use App\Support\ProductSeo;
use App\Support\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Catalog -> Products -> the product editor.  (Lane AO)
 *
 * The screen the owner asked for: one page that owns a product's gallery, its
 * categories, its written copy, its search appearance and when it goes live.
 *
 * ------------------------------------------------------------------ THE RULES
 *
 * MONEY IS INTEGER FILS, AND THIS FILE NEVER PARSES ONE ITSELF. Every amount on
 * the wire is a decimal STRING in major units, validated by MajorUnits::shape()
 * — an anchored digits-only regex, not `numeric`, which would accept "1e3" and
 * "0.145" — and converted by MajorUnits::fils(), which splits on the decimal
 * point and does integer arithmetic on the halves. There is no float anywhere
 * on this path, and no fourth copy of the parse: CLAUDE.md names three that
 * already existed and MajorUnits is where the third one went to live.
 * exceedsColumn() is checked before the write so a value past the signed 32-bit
 * ceiling is a 422 naming the limit in dirhams rather than a 500 naming a MySQL
 * driver.
 *
 * `products.status` IS publish | draft | private. Not `active`, not
 * `archived`. A lane shipped `active` once: the endpoint answered 200, and the
 * product vanished from the shop, its category page and the sitemap at the same
 * moment, because scopeVisible() and the sitemap both filter on the literal
 * 'publish'. The editor offers a FOURTH word — Scheduled — and it is
 * deliberately not a fourth column value; see status handling below.
 *
 * SCHEDULED IS A DATE, NOT A STATUS. A scheduled product is stored as
 * `status = 'publish'` with `published_at` in the future, and
 * Product::scopeVisible() tests that timestamp against now() on every read.
 * There is no cron on this host and no queue worker, so a job that flips a
 * column on the day would never run. The precedent is already here:
 * Product::effectivePrice() has always honoured sale_starts_at / sale_ends_at
 * exactly this way. The consequence worth stating is the good one — the moment
 * the clock passes the date, every query in the application is already correct,
 * with nothing having fired.
 *
 * DESCRIPTIONS ARE SANITISED ON THE SERVER, ALWAYS. partials/product-tabs
 * renders them with {!! !!} on a public page. The editor's toolbar is a
 * convenience; App\Support\RichText is the control, and it runs on every write
 * whatever the payload claims to be.
 *
 * SEO GOES IN `seo`, NOT `seo_json`. See App\Support\ProductSeo — the admin
 * wrote one column and every reader in the application reads the other, so the
 * feature had never worked. Both the column and the key names are corrected
 * here and by 2026_10_05_000001 for rows already saved.
 *
 * IDENTITY IS NOT EDITABLE AFTER CREATE. `slug` is a live URL contract (U-01,
 * /product/{slug}/) that Google holds and that sits in customers' order
 * histories, and `wc_id` is the public identifier legacy add-to-cart links use
 * (U-02). Both are accepted by store() and ignored by save(). `total_sales`,
 * `rating` and `review_count` are computed from orders and reviews and are
 * never writable at all.
 *
 * CATEGORIES ARE WRITTEN IN ONE TRANSACTION WITH category_id, and that is the
 * landmine in this file. ShopController::index() filters the archive through
 * `whereHas('categories', ...)` — the many-to-many — while `products.category_id`
 * is the primary-category column used for breadcrumbs and the product's own
 * page. The two must move together: a save that writes one and not the other
 * produces a product that looks correct in the editor and never appears on the
 * category page it says it is in.
 */
class ProductEditorApiController extends Controller
{
    /** The four words the editor's status control speaks. */
    private const EDITOR_STATUSES = ['publish', 'draft', 'private', 'scheduled'];

    /** What `products.status` may actually hold. */
    private const COLUMN_STATUSES = ['publish', 'draft', 'private'];

    private const LIKE_ESCAPE = '!';

    private const LIKE_ESCAPE_SQL = "'!'";

    /**
     * Fils -> the exact decimal string this editor puts in a money input.
     *
     * NOT Money::toMajor(), which returns a FLOAT, and not Money::amount()
     * on its own, which is correct to the fil but inserts thousands separators:
     * it renders 1,500,000 fils as "15,000.00". MajorUnits::shape() — the
     * anchored, digits-only regex this controller validates every incoming
     * amount with — refuses a comma on purpose, because a comma means different
     * things in different locales and guessing is how a price becomes a
     * thousand times itself. So a product priced at AED 15,000 would load into
     * the form correctly, look right, and then refuse to save with "enter the
     * price as a plain number" on a value the editor itself produced.
     *
     * amount() does the integer arithmetic; this strips the grouping it adds,
     * and pins the decimals to the currency's own exponent rather than to the
     * display setting, so a shop configured to show whole dirhams still round-
     * trips the fils it actually stores instead of silently truncating them.
     */
    private function editorAmount(?int $fils): ?string
    {
        if ($fils === null) {
            return null;
        }

        return str_replace(',', '', Money::amount($fils, Money::minorExponent()));
    }

    /* ------------------------------------------------------------ bootstrap */

    /**
     * Everything the screen needs once, on open: the pickers and the currency.
     *
     * One request rather than three, because the editor is opened from a phone
     * on hotel wifi as often as from a desk.
     */
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'categories' => Category::query()
                ->select('id', 'name', 'slug', 'parent_id')
                ->orderBy('name')
                ->get()
                ->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'slug' => (string) $c->slug,
                    'parent_id' => $c->parent_id === null ? null : (int) $c->parent_id,
                ])
                ->values(),
            'brands' => Brand::query()
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
                ->values(),
            'currency' => [
                'code' => Money::currency(),
                'exponent' => Money::minorExponent(),
                'max_major' => MajorUnits::maxMajor(),
            ],
            'statuses' => self::EDITOR_STATUSES,
            'stock_statuses' => ['instock', 'outofstock', 'onbackorder'],
            // The editor posts files here. There is exactly one upload endpoint
            // in this application and a second one is what routes/brands-admin
            // and routes/catalog-admin each went out of their way to avoid:
            // two of them drift, and the one that drifts is the one with the
            // content-type, size and SVG rules in it.
            'upload_path' => '/admin-api/media/upload',
        ]);
    }

    /* ----------------------------------------------------------------- list */

    /**
     * A short, searchable list, so the editor can be opened without going back
     * to the Products grid first.
     *
     * The search is a LIKE with an explicit ESCAPE '!'. MySQL treats a
     * backslash as the default LIKE escape and SQLite has no default escape at
     * all, so `str_replace(['\\','%','_'])` with a plain ->where(...,'like',...)
     * finds "KBB-50%-OFF" on exactly one of the two engines. '!' has no special
     * meaning to either, and it is doubled first so a term containing '!' does
     * not escape the character after it.
     */
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $query = Product::query()
            ->select('id', 'name', 'slug', 'sku', 'status', 'is_visible', 'published_at', 'image', 'price')
            ->with('brand:id,name');

        if ($term !== '') {
            $pattern = '%'.$this->escapeLike($term).'%';

            $query->where(function ($q) use ($pattern) {
                $q->whereRaw('name LIKE ? ESCAPE '.self::LIKE_ESCAPE_SQL, [$pattern])
                    ->orWhereRaw('sku LIKE ? ESCAPE '.self::LIKE_ESCAPE_SQL, [$pattern])
                    ->orWhereRaw('slug LIKE ? ESCAPE '.self::LIKE_ESCAPE_SQL, [$pattern]);
            });
        }

        $rows = $query->orderByDesc('id')->limit(40)->get();

        return response()->json([
            'ok' => true,
            'products' => $rows->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'slug' => (string) $p->slug,
                'sku' => $p->sku,
                'brand' => $p->brand?->name,
                'image' => $p->image,
                'status' => $p->editorStatus(),
                'price_aed' => $this->editorAmount($p->price === null ? null : (int) $p->price),
            ])->values(),
        ]);
    }

    /* ----------------------------------------------------------------- load */

    public function show(int $id): JsonResponse
    {
        $product = Product::with(['brand:id,name', 'categories:id,name'])->find($id);

        if (! $product) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }

        return response()->json(['ok' => true, 'product' => $this->payload($product)]);
    }

    /**
     * The editor's view of one product.
     *
     * An explicit projection, not the model. `products` carries wc_id, sku and
     * total_sales, and while this endpoint is behind auth:admin, building the
     * habit of returning a model is how a column added next month ends up on a
     * screen nobody decided to put it on. It also means `status` can be the
     * editor's four-word vocabulary rather than the column's three.
     */
    private function payload(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'slug' => (string) $product->slug,
            'sku' => $product->sku,
            'gtin' => $product->gtin,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'type' => $product->type,

            'status' => $product->editorStatus(),
            'is_visible' => (bool) $product->is_visible,
            'featured' => (bool) $product->featured,
            'position' => $product->position === null ? null : (int) $product->position,
            'published_at' => $product->published_at?->format('Y-m-d\TH:i'),

            'category_ids' => $product->categories->pluck('id')->map(fn ($i) => (int) $i)->values(),
            'primary_category_id' => $product->category_id === null ? null : (int) $product->category_id,

            'price_aed' => $this->editorAmount($product->price === null ? null : (int) $product->price),
            'sale_aed' => $this->editorAmount($product->sale_price === null ? null : (int) $product->sale_price),
            'sale_starts_at' => $product->sale_starts_at?->format('Y-m-d\TH:i'),
            'sale_ends_at' => $product->sale_ends_at?->format('Y-m-d\TH:i'),

            'manage_stock' => (bool) $product->manage_stock,
            'stock' => $product->stock === null ? null : (int) $product->stock,
            'stock_status' => $product->stock_status,

            'short_description' => (string) ($product->short_description ?? ''),
            'description' => (string) ($product->description ?? ''),
            'ingredients' => (string) ($product->ingredients ?? ''),
            'how_to_use' => (string) ($product->how_to_use ?? ''),

            'image' => $product->image,
            'images' => array_values(array_filter((array) ($product->images ?? []))),
            'image_alts' => is_array($product->image_alts) ? $product->image_alts : (object) [],

            'seo' => is_array($product->seo) ? $product->seo : null,

            // Read-only, and shown as such: these are computed from orders and
            // reviews and the editor has no control that writes them.
            'readonly' => [
                'total_sales' => (int) ($product->total_sales ?? 0),
                'rating' => (float) ($product->rating ?? 0),
                'review_count' => (int) ($product->review_count ?? 0),
                'url' => $product->url(),
            ],
        ];
    }

    /* --------------------------------------------------------------- create */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            $this->rules(creating: true),
            $this->messages()
        );

        $product = null;

        $failure = DB::transaction(function () use ($data, &$product) {
            $product = new Product;

            // Identity, and only at create. See the class note on U-01/U-02.
            $product->slug = $this->uniqueSlug((string) $data['slug']);

            if (array_key_exists('wc_id', $data) && $data['wc_id'] !== null) {
                $product->wc_id = (int) $data['wc_id'];
            }

            return $this->apply($product, $data);
        });

        if ($failure instanceof JsonResponse) {
            return $failure;
        }

        return response()->json([
            'ok' => true,
            'created' => true,
            'product' => $this->payload($product->fresh(['brand', 'categories'])),
        ], 201);
    }

    /* ----------------------------------------------------------------- save */

    public function save(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }

        $data = $request->validate(
            $this->rules(creating: false),
            $this->messages()
        );

        $failure = DB::transaction(fn () => $this->apply($product, $data));

        if ($failure instanceof JsonResponse) {
            return $failure;
        }

        return response()->json([
            'ok' => true,
            'product' => $this->payload($product->fresh(['brand', 'categories'])),
        ]);
    }

    /* ---------------------------------------------------------------- rules */

    private function rules(bool $creating): array
    {
        $money = ['nullable', 'string', MajorUnits::shape()];

        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gtin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')],
            'type' => ['sometimes', 'nullable', 'string', 'max:40'],

            'status' => ['sometimes', Rule::in(self::EDITOR_STATUSES)],
            // Required only when the status says scheduled — a date with no
            // schedule is meaningless and a schedule with no date is a product
            // that never launches.
            'published_at' => ['nullable', 'date', 'required_if:status,scheduled'],
            'is_visible' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],

            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
            'primary_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],

            'price_aed' => $money,
            'sale_aed' => $money,
            'sale_starts_at' => ['sometimes', 'nullable', 'date'],
            'sale_ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:sale_starts_at'],

            'manage_stock' => ['sometimes', 'boolean'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'stock_status' => ['sometimes', Rule::in(['instock', 'outofstock', 'onbackorder'])],

            'short_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'ingredients' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'how_to_use' => ['sometimes', 'nullable', 'string', 'max:100000'],

            'image' => ['sometimes', 'nullable', 'string', 'max:500'],
            'images' => ['sometimes', 'array', 'max:24'],
            'images.*' => ['string', 'max:500'],
            'image_alts' => ['sometimes', 'nullable', 'array'],
            'image_alts.*' => ['nullable', 'string', 'max:250'],

            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:200'],
            'seo.desc' => ['nullable', 'string', 'max:400'],
            'seo.canonical' => ['nullable', 'string', 'max:500', 'url'],
            'seo.og_image' => ['nullable', 'string', 'max:500'],
            'seo.noindex' => ['nullable', 'boolean'],
        ];

        if ($creating) {
            $rules['slug'] = ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
            $rules['wc_id'] = ['nullable', 'integer', 'min:1', Rule::unique('products', 'wc_id')];
        }

        return $rules;
    }

    private function messages(): array
    {
        $unit = Money::currency();

        return [
            'price_aed.regex' => 'Enter the price as a plain number, like 99.50 — no currency symbol, no commas, and at most '
                . Money::minorExponent() . ' decimal places.',
            'sale_aed.regex' => 'Enter the sale price as a plain number, like 79.00 — no currency symbol, no commas, and at most '
                . Money::minorExponent() . ' decimal places.',
            'published_at.required_if' => 'Pick the date and time this product should go live.',
            'sale_ends_at.after_or_equal' => 'The sale cannot end before it starts.',
            'seo.canonical.url' => 'The canonical URL must be a full address, including https://.',
            'slug.regex' => 'The web address can use lowercase letters, numbers and hyphens only.',
            'name.required' => 'A product needs a name.',
        ];
    }

    /* ---------------------------------------------------------------- apply */

    /**
     * Write the validated payload onto the model.
     *
     * Runs inside the caller's transaction, and returns a JsonResponse instead
     * of writing when a value is refused — a money overflow is only detectable
     * after the parse, which is after validation has already passed.
     */
    private function apply(Product $product, array $data): ?JsonResponse
    {
        /* ------------------------------------------------------------ money */
        foreach (['price_aed' => 'price', 'sale_aed' => 'sale_price'] as $field => $column) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $fils = MajorUnits::fils($data[$field]);

            if (MajorUnits::exceedsColumn($fils)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'That amount is larger than this shop can store (maximum '
                        . Money::currency() . ' ' . MajorUnits::maxMajor() . ').',
                    'errors' => [$field => ['Too large.']],
                ], 422);
            }

            $product->{$column} = $fils;
        }

        // A sale price above the regular price is not a sale. Checked against
        // whatever the row will hold AFTER this write, so sending only one of
        // the two is compared against the stored value of the other rather
        // than against nothing.
        if ($product->sale_price !== null && $product->price !== null
            && (int) $product->sale_price > (int) $product->price) {
            return response()->json([
                'ok' => false,
                'message' => 'The sale price is higher than the regular price.',
                'errors' => ['sale_aed' => ['Must not be more than the regular price.']],
            ], 422);
        }

        /* ------------------------------------------------------------ gtin */
        if (array_key_exists('gtin', $data)) {
            $raw = trim((string) ($data['gtin'] ?? ''));

            if ($raw === '') {
                $product->gtin = null;
            } else {
                /*
                 * The check digit is verified, not the digit count.
                 *
                 * A GTIN's last digit is a mod-10 checksum over the others, and
                 * its only job is to catch the two mistakes someone makes
                 * copying fourteen digits off a box: one wrong digit, and a
                 * transposed pair. A rule that merely counted digits would
                 * accept both — and the shop would then publish a confident,
                 * structured claim that this product is a different product.
                 * A wrong GTIN is worse for a merchant listing than none.
                 */
                if (! Gtin::isValid($raw)) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'That barcode number is not a valid GTIN. Check the digits against the '
                            . 'barcode — it should be 8, 12, 13 or 14 digits, and the last one is a checksum.',
                        'errors' => ['gtin' => ['Not a valid GTIN.']],
                    ], 422);
                }

                // Stored without the grouping the operator may have typed, so
                // "400-6381-33393-1" and "4006381333931" are one value.
                $product->gtin = Gtin::normalise($raw);
            }
        }

        /* ----------------------------------------------------------- plain */
        foreach (['name', 'sku', 'type', 'stock', 'stock_status', 'position'] as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = $data[$field];
            }
        }

        foreach (['is_visible', 'featured', 'manage_stock'] as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = (bool) $data[$field];
            }
        }

        foreach (['sale_starts_at', 'sale_ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = $data[$field] ?: null;
            }
        }

        /* ------------------------------------------------- written content */
        // Sanitised without exception. The storefront prints all four of these
        // with {!! !!}; see App\Support\RichText.
        foreach (['short_description', 'description', 'ingredients', 'how_to_use'] as $field) {
            if (array_key_exists($field, $data)) {
                $clean = RichText::clean($data[$field]);

                $product->{$field} = RichText::isBlank($clean) ? null : $clean;
            }
        }

        /* ---------------------------------------------------------- images */
        if (array_key_exists('image', $data)) {
            $product->image = $data['image'] ?: null;
        }

        if (array_key_exists('images', $data)) {
            /*
             * Order is the payload's order, and it is the contract with the
             * storefront: Store\ProductController::gallery() merges [image]
             * with images and labels the strip in that sequence, so the
             * thumbnails the owner drags into place are the thumbnails the
             * customer sees, left to right.
             *
             * The main image is filtered out of the gallery rather than kept.
             * gallery() de-duplicates anyway, but storing it twice means the
             * count shown in the editor and the count on the page disagree,
             * and the owner is the one who has to work out why.
             */
            $main = (string) ($product->image ?? '');

            $images = array_values(array_unique(array_filter(
                array_map(static fn ($u) => trim((string) $u), $data['images']),
                static fn (string $u) => $u !== '' && $u !== $main
            )));

            $product->images = $images;
        }

        if (array_key_exists('image_alts', $data)) {
            /*
             * Kept only for images this product still has.
             *
             * The map is keyed by URL, so a shot the operator removed would
             * otherwise leave its alt text behind forever — invisible in the
             * editor, growing every time a picture is swapped, and liable to be
             * re-attached if the same URL is uploaded again later.
             */
            $keep = array_values(array_filter(array_merge(
                [(string) ($product->image ?? '')],
                (array) ($product->images ?? [])
            )));

            $alts = [];

            foreach ((array) ($data['image_alts'] ?? []) as $url => $alt) {
                $alt = trim((string) $alt);

                if ($alt !== '' && in_array((string) $url, $keep, true)) {
                    $alts[(string) $url] = $alt;
                }
            }

            $product->image_alts = $alts === [] ? null : $alts;
        }

        /* ------------------------------------------------------------- seo */
        if (array_key_exists('seo', $data)) {
            $product->seo = ProductSeo::normalise($data['seo']);
        }

        /* ---------------------------------------------------------- status */
        if (array_key_exists('status', $data)) {
            $editorStatus = (string) $data['status'];

            if ($editorStatus === 'scheduled') {
                /*
                 * Stored as a PUBLISHED product with a future date — never as a
                 * fourth value in `products.status`. scopeVisible(), the
                 * sitemap, every category page and BrandController all filter
                 * on the literal 'publish', so a new word there is a product
                 * that disappears from the shop with a 200 in the response;
                 * that has already happened once in this repo with 'active'.
                 */
                $product->status = 'publish';
                $product->published_at = $data['published_at'];
            } else {
                $product->status = in_array($editorStatus, self::COLUMN_STATUSES, true)
                    ? $editorStatus
                    : 'draft';

                // Choosing any plain status clears a pending schedule. Leaving
                // the date behind would mean a product marked Published that is
                // still invisible, with the reason no longer shown anywhere.
                $product->published_at = null;
            }
        } elseif (array_key_exists('published_at', $data)) {
            $product->published_at = $data['published_at'] ?: null;
        }

        /* --------------------------------------------------- the defaults */
        if (! $product->exists) {
            $product->status ??= 'draft';
            $product->is_visible ??= true;
            $product->stock_status ??= 'instock';
            $product->type ??= 'simple';
        }

        $product->save();

        /* -------------------------------------------------- categories */
        if (array_key_exists('category_ids', $data) || array_key_exists('primary_category_id', $data)) {
            $ids = array_values(array_unique(array_map(
                'intval',
                $data['category_ids'] ?? $product->categories()->pluck('categories.id')->all()
            )));

            $primaryGiven = array_key_exists('primary_category_id', $data);

            $primary = $primaryGiven
                ? ($data['primary_category_id'] === null ? null : (int) $data['primary_category_id'])
                : ($product->category_id === null ? null : (int) $product->category_id);

            /*
             * A primary the operator NAMED in this request is an instruction,
             * so it joins the selection rather than being discarded — ticking
             * "make this the primary category" is a reasonable way to add one.
             */
            if ($primaryGiven && $primary !== null && ! in_array($primary, $ids, true)) {
                $ids[] = $primary;
            }

            /*
             * A primary that merely SURVIVED from the stored row, and is no
             * longer among the selected categories, is stale and is replaced.
             *
             * This is the case that matters. When the owner unticks the
             * category that happened to be primary, the old value must not stay
             * in products.category_id: ShopController::index() filters the
             * archive through the pivot while the breadcrumb and the product
             * page read category_id, so the product would go on naming a
             * category whose page no longer lists it — the two halves out of
             * step in the quietest possible way. Falling back to the first
             * remaining selection is the behaviour that cannot produce that
             * state.
             */
            if ($primary === null || ! in_array($primary, $ids, true)) {
                $primary = $ids[0] ?? null;
            }

            // Both halves, inside the caller's transaction. See the class note:
            // ShopController::index() reads the pivot and everything else reads
            // category_id, and a product whose two disagree saves cleanly and
            // then cannot be found.
            $product->categories()->sync($ids);
            $product->category_id = $primary;
            $product->save();
        }

        return null;
    }

    /* --------------------------------------------------------------- slugs */

    /**
     * POST /admin-api/product-editor-slug — is this web address free?
     *
     * Create-time only. A published product's slug is a URL Google holds and a
     * line in every past customer's order history; it is not a field after the
     * product exists, and save() does not accept one.
     */
    public function slug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required_without:slug', 'nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
        ]);

        $base = trim((string) ($data['slug'] ?? '')) !== ''
            ? (string) $data['slug']
            : (string) ($data['name'] ?? '');

        $slug = $this->slugify($base);

        if ($slug === '') {
            return response()->json(['ok' => false, 'message' => 'Type a product name first.'], 422);
        }

        $taken = Product::withTrashed()->where('slug', $slug)->exists();

        return response()->json([
            'ok' => true,
            'slug' => $slug,
            'available' => ! $taken,
            'suggestion' => $taken ? $this->uniqueSlug($slug) : $slug,
            'url' => '/product/' . $slug . '/',
        ]);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * A free slug, counting soft-deleted rows.
     *
     * withTrashed() matters: products use SoftDeletes, the slug column is a
     * URL, and reusing the address of a deleted product sends anyone holding
     * the old link — or Google — to a different item than the one they meant.
     */
    private function uniqueSlug(string $wanted): string
    {
        $slug = $this->slugify($wanted);
        $slug = $slug === '' ? 'product' : $slug;

        $candidate = $slug;
        $n = 1;

        while (Product::withTrashed()->where('slug', $candidate)->exists()) {
            $n++;
            $candidate = $slug . '-' . $n;
        }

        return $candidate;
    }

    /* ---------------------------------------------------------------- LIKE */

    /** See the note on index(): the escape character is doubled first. */
    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }
}
