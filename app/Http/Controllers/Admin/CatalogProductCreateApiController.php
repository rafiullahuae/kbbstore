<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\MajorUnits;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Catalog → Products → Add product, and the product image.
 *
 * THE TWO HOLES THIS CLOSES. Package 2.60.131 shipped a Products screen that
 * lists, searches, filters, inline-edits, bulk-edits and exports — and could
 * not add a product, or change a product's picture. Those are the first two
 * things a shop owner does. "Add product" reached openProduct(-1), a preview
 * mock whose every control raised toast('… (preview)'), and the real edit panel
 * (cpOpenDetail) edited fifteen fields and not the image.
 *
 * WHY A NEW FILE RATHER THAN MORE OF CatalogProductsApiController. Creating a
 * row and updating one are different problems. update() is `sometimes`
 * throughout, because an inline cell sends one key; a create must be `required`
 * throughout, because a half-built product that saves is the failure mode this
 * whole lane exists to prevent. Folding both into one validator means every
 * rule carries a conditional and the create guarantees below stop being
 * readable. The money parse, which IS shared, is shared properly — both files
 * call App\Support\MajorUnits.
 *
 * ------------------------------------------------------------------------
 * THE GUARANTEE: A PRODUCT THAT SAVES IS A PRODUCT THAT APPEARS
 * ------------------------------------------------------------------------
 *
 * A create form that answers 201 and leaves the owner with something the shop
 * does not show is worse than one that refuses, because nothing tells them.
 * There are four separate ways to build that row in this schema, and each is
 * refused here by name:
 *
 *  1. THE STATUS VOCABULARY. products.status is publish | draft | private —
 *     the Phase 0 schema declares exactly those, and
 *     Services\Import\Entities\ProductImporter::STATUS_MAP maps every
 *     WooCommerce status onto them rather than inventing a fourth.
 *     Product::scopeVisible() filters on `status = 'publish'`. A sibling lane
 *     has just finished paying for the other spelling: the old editor
 *     validated `in:active,draft,archived`, so the ONE action meaning "put this
 *     on the shop" wrote a value nothing recognises, and the row left the
 *     storefront, every category page and the sitemap with {"ok":true} in the
 *     response. STATUSES below is read out of the schema, not guessed.
 *
 *  2. CATALOGUE VISIBILITY. scopeVisible() is `status = 'publish' AND
 *     is_visible = 1`. Publishing a product with is_visible false produces a
 *     row that is published and invisible — not on /shop, not on its category
 *     page, not in /sitemap.xml — and the Products list would show it under the
 *     "Published" chip the whole time. Refused, in those words.
 *
 *  3. THE CATEGORY PIVOT, which is the one that actually bites. The category
 *     archive does NOT read products.category_id. ShopController::index()
 *     filters with whereHas('categories', …), i.e. the category_product
 *     many-to-many. A product created with category_id set and no pivot row is
 *     live on /shop, live at /product/{slug}/, in the sitemap — and absent from
 *     the category page the owner picked, which is the page they will look at
 *     to check their work. Both are written here, always, from the same id.
 *
 *  4. NO PRICE. price is nullable and the import fills it with null often
 *     enough to deserve its own chip, but a product an owner types in by hand
 *     with no price is one Product::effectivePrice() values at zero and the
 *     cart will happily sell for nothing. Required here. Imported rows keep
 *     their null; this is a rule about what this form may create.
 *
 * THE SLUG IS A URL CONTRACT. Products are addressed as /product/{slug}/ (URL
 * contract U-01). It is generated from the name, SHOWN to the operator before
 * they save and editable at create time — and it is not writable afterwards,
 * which is why there is no slug field on the update path and none is added
 * here. A slug already handed to Google and printed in customers' order
 * histories is not a text box. Uniqueness is enforced by the validator AND by
 * the unique index underneath: two operators saving "Snail Mucin Essence" in
 * the same second get one 201 and one 422, never a 500.
 *
 * MONEY IS INTEGER FILS AND IS PARSED AS TEXT. Prices arrive as the decimal
 * string the operator typed and go through MajorUnits::fils(), which never
 * constructs a float — `(int) (1.15 * 100)` is 114. The shape rule is an
 * anchored digits-only regex rather than `numeric`, because `numeric` accepts
 * "1e3" and "0.145" and neither survives a digit-by-digit parse — and it allows
 * no more decimal places than the currency actually has, so a price fils cannot
 * express is refused out loud rather than truncated in silence. Every parsed
 * value is then bounded at MajorUnits::MAX_FILS, because products.price is a
 * signed 32-bit column: past that MySQL in strict mode raises 1264 and SQLite
 * stores it happily, which is a parity break discovered in production rather
 * than here.
 *
 * BRAND AND CATEGORY ARE RELATIONS, NOT COLUMNS. `$product->brand = 'Anua'`
 * puts a string in the attribute bag and save() issues `UPDATE products SET
 * brand = ?` — no such column, SQLSTATE 42S22, HTTP 500. The FK ids are what is
 * written, validated with exists:, and neither a brand nor a category is ever
 * created as a side effect of saving a product: a typo in a picker must not
 * silently add a ninety-fourth brand to the storefront's brand directory.
 *
 * THE IMAGE GOES THROUGH THE ONE UPLOAD PATH. Files are posted by the screen to
 * /admin-api/media/upload (Admin\MediaUploadController) and what reaches this
 * controller is the URL that endpoint returned. There is no second upload
 * route, for the reason routes/brands-admin.php and routes/catalog-admin.php
 * both spell out: two upload paths drift, and the one that drifts is the one
 * with the type and size rules in it. The URL is still checked here, because
 * the field is a plain string and the screen also lets an operator paste one —
 * see safeImageUrl().
 *
 * DELETING A PRODUCT IS NOT HERE, and is not an omission to be quietly filled
 * in. order_items.product_id points at these rows.
 */
class CatalogProductCreateApiController extends Controller
{
    /**
     * The statuses this schema declares. Read out of the Phase 0 schema
     * (`publish | draft | private`) and out of ProductImporter::STATUS_MAP,
     * which refuses anything else rather than inventing a fourth.
     *
     * Deliberately NOT 'active' or 'archived'. Those are the values the old
     * editor accepted, and a product saved as 'active' is a product that
     * silently left the shop.
     */
    public const STATUSES = ['publish', 'draft', 'private'];

    /** products.stock_status, same source. */
    public const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    /** Long enough for a real description, short enough not to be a payload. */
    private const MAX_DESCRIPTION = 200000;

    private const MAX_SHORT_DESCRIPTION = 5000;

    /* ------------------------------------------------------------- the slug */

    /**
     * What slug this name would get, and whether it is free.
     *
     * The screen calls this as the operator types the name, so the permalink
     * under the name field is the real one before anything is saved rather than
     * a JavaScript guess at it. The suggestion is deliberately computed on the
     * server: Str::slug() and the browser's own
     * `name.toLowerCase().replace(/[^a-z0-9]+/g,'-')` disagree on accents, on
     * '&' and on every non-Latin character, and the preview matching what is
     * actually stored is the entire point of showing it.
     *
     * A GET would be the obvious verb, but the name is operator text that can
     * contain '/', '?' and '#', and a name in a query string is a name in the
     * web server's access log. POST keeps it in the body.
     */
    public function slug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $typed = trim((string) ($data['slug'] ?? ''));
        $source = $typed !== '' ? $typed : (string) ($data['name'] ?? '');
        $slug = Str::slug($source);

        if ($slug === '') {
            return response()->json([
                'slug' => '',
                'available' => false,
                'reason' => 'A product needs a name with letters or numbers in it to make a web address from.',
                'suggestion' => null,
            ]);
        }

        $taken = self::slugTaken($slug);

        return response()->json([
            'slug' => $slug,
            'available' => ! $taken,
            'reason' => $taken ? 'Another product already uses that web address.' : null,
            // Offered, never applied behind the operator's back: the address
            // this product will live at is their decision to confirm.
            'suggestion' => $taken ? self::freeSlugNear($slug) : null,
            'url' => '/product/'.$slug.'/',
        ]);
    }

    /* ----------------------------------------------------------- the create */

    /**
     * Create one product.
     *
     * Everything is validated here and nowhere else — the screen's own checks
     * are a courtesy to the operator, not a control, exactly as on the update
     * path. Every guarantee in the class comment is enforced below and is
     * covered by a test that breaks it first.
     */
    public function store(Request $request): JsonResponse
    {
        // Derive the slug from the name when the operator left it alone, and
        // validate the DERIVED value — otherwise a second "Snail Mucin
        // Essence" skips the uniqueness rule and surfaces as a QueryException,
        // i.e. a 500 on a duplicate name. Same shape as the categories and
        // brands screens.
        $request->merge([
            'slug' => Str::slug(
                trim((string) $request->input('slug')) !== ''
                    ? (string) $request->input('slug')
                    : (string) $request->input('name')
            ),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],

            'slug' => [
                'required', 'string', 'max:255',
                // Lower-case words joined by single hyphens. This is a path
                // segment in /product/{slug}/; anything else either does not
                // round-trip or has to be encoded at every use site.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Against the whole table, soft-deleted rows included: the
                // unique index underneath does not know about deleted_at, so a
                // rule that ignored trashed rows would hand the operator a 500
                // instead of a message.
                Rule::unique('products', 'slug'),
            ],

            'sku' => ['nullable', 'string', 'max:255'],

            // Relations. The FK id, never the name — see the class comment.
            // `exists` and nothing else: a picker must not be able to create a
            // brand or a category as a side effect of saving a product.
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],

            // Money: the decimal STRING, shape-checked here and parsed by
            // integer arithmetic below. Required, because a hand-typed product
            // with no price is one the cart sells for nothing.
            'price' => ['required', 'string', MajorUnits::shape()],
            'sale_price' => ['nullable', 'string', MajorUnits::shape()],

            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'stock_status' => ['required', 'string', Rule::in(self::STOCK_STATUSES)],
            'is_visible' => ['required', 'boolean'],

            'manage_stock' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'short_description' => ['nullable', 'string', 'max:'.self::MAX_SHORT_DESCRIPTION],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION],

            'image' => ['nullable', 'string', 'max:2048'],
        ], [
            'slug.required' => 'A product needs a name with letters or numbers in it to make a web address from.',
            'slug.regex' => 'The web address may contain only lower-case letters, numbers and single hyphens.',
            'slug.unique' => 'Another product already uses that web address.',
            'category_id.required' => 'Pick the category this product belongs in — a product with no category '
                .'is not on any category page, which is where shoppers look for it.',
            'category_id.exists' => 'That category no longer exists.',
            'brand_id.exists' => 'That brand no longer exists.',
            'price.required' => 'A product needs a price. It is what the cart charges for it.',
            'price.regex' => 'The price must be a plain number like 99.50 — no currency symbol, no spaces.',
            'sale_price.regex' => 'The sale price must be a plain number like 79.00 — no currency symbol, no spaces.',
            'status.in' => 'A product is published, a draft, or private. Nothing else.',
        ]);

        $price = MajorUnits::fils($data['price']);
        $sale = MajorUnits::fils($data['sale_price'] ?? null);

        if (($fail = $this->moneyOutOfRange(['price' => $price, 'sale_price' => $sale])) !== null) {
            return $fail;
        }

        if (($fail = $this->saleBelowPrice($price, $sale)) !== null) {
            return $fail;
        }

        if (($fail = $this->publishableOrRefused((string) $data['status'], (bool) $data['is_visible'])) !== null) {
            return $fail;
        }

        $image = $this->safeImageUrl($data['image'] ?? null);

        if ($image instanceof JsonResponse) {
            return $image;
        }

        $categoryId = (int) $data['category_id'];

        try {
            $product = DB::transaction(function () use ($data, $price, $sale, $image, $categoryId) {
                $product = Product::create([
                    'name' => trim((string) $data['name']),
                    'slug' => (string) $data['slug'],
                    'sku' => self::blankToNull($data['sku'] ?? null),

                    'brand_id' => isset($data['brand_id']) && $data['brand_id'] !== null
                        ? (int) $data['brand_id']
                        : null,
                    'category_id' => $categoryId,

                    'type' => 'simple',
                    'status' => (string) $data['status'],
                    'is_visible' => (bool) $data['is_visible'],

                    'price' => $price,
                    'sale_price' => $sale,

                    'manage_stock' => (bool) ($data['manage_stock'] ?? false),
                    'stock' => array_key_exists('stock', $data) && $data['stock'] !== null
                        ? (int) $data['stock']
                        : null,
                    'stock_status' => (string) $data['stock_status'],

                    'short_description' => self::blankToNull($data['short_description'] ?? null),
                    'description' => self::blankToNull($data['description'] ?? null),

                    'image' => $image,

                    // wc_id stays null. It is the WooCommerce post id and a
                    // public identifier (?add-to-cart={wc_id}); a product born
                    // here never had one, and inventing a number in that space
                    // would collide with the import the day it runs again.
                ]);

                // BOTH, from the same id, in the same transaction. category_id
                // is the primary category the admin list and the breadcrumb
                // read; the pivot is what ShopController::index() filters the
                // category archive on. One without the other is a product that
                // is live everywhere except the page the owner just filed it
                // under.
                $product->categories()->sync([$categoryId]);

                return $product;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // The slug rule above catches every duplicate this process can see.
            // What it cannot see is another admin saving the same slug between
            // this request's SELECT and its INSERT, which the unique index then
            // refuses. That is a message, not a 500.
            if (self::isDuplicateSlug($e)) {
                return response()->json([
                    'message' => 'Another product took that web address a moment ago.',
                    'errors' => ['slug' => ['Another product already uses that web address.']],
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'ok' => true,
            'id' => (int) $product->id,
            'slug' => (string) $product->slug,
            'url' => '/product/'.$product->slug.'/',
            // What the storefront will actually do with it, in the response,
            // so the screen can say "it is on the shop" or "it is saved as a
            // draft" rather than guessing from the status string.
            'live' => $product->status === 'publish' && $product->is_visible,
        ], 201);
    }

    /* ------------------------------------------------------------ the image */

    /**
     * Set, replace or remove the image on a product that already exists.
     *
     * Its own endpoint rather than a key on /catalog-products-save/{id},
     * because that method belongs to the list screen's lane and its whole
     * contract is `sometimes` — and because removing an image has to be
     * expressible. On a `sometimes` validator, "image not sent" and "image set
     * to null" are the same request, so there would be no way to say "take the
     * picture off this product" at all. Here the key is REQUIRED and may be
     * null, which makes the two different requests different.
     *
     * The file itself never reaches this method. The screen posts it to
     * /admin-api/media/upload and sends the URL that came back.
     */
    public function image(Request $request, int $id): JsonResponse
    {
        $product = Product::withTrashed()->find($id);

        if ($product === null) {
            return response()->json(['message' => 'No such product.'], 404);
        }

        // `present` rather than `required`: required rejects null, and null is
        // exactly how this endpoint is told to remove the image.
        $data = $request->validate([
            'image' => ['present', 'nullable', 'string', 'max:2048'],
        ]);

        $image = $this->safeImageUrl($data['image']);

        if ($image instanceof JsonResponse) {
            return $image;
        }

        $product->image = $image;
        $product->save();

        return response()->json([
            'ok' => true,
            'id' => (int) $product->id,
            'image' => $product->image,
        ]);
    }

    /* ------------------------------------------------------------- refusals */

    /**
     * Refuse a price the column cannot hold.
     *
     * products.price and products.sale_price are `$t->integer(...)`, i.e.
     * signed 32-bit. MySQL in strict mode raises 1264 on the insert and SQLite
     * stores the value happily, so without this the suite is green and the
     * server 500s — the parity break docs/MYSQL-PARITY.md exists for.
     *
     * @param  array<string, int|null>  $values
     */
    private function moneyOutOfRange(array $values): ?JsonResponse
    {
        foreach ($values as $field => $fils) {
            if (! MajorUnits::exceedsColumn($fils)) {
                continue;
            }

            return response()->json([
                'message' => 'That price is larger than this store can hold.',
                'errors' => [$field => [
                    'The most a product can cost is '.MajorUnits::maxMajor().'.',
                ]],
            ], 422);
        }

        return null;
    }

    /**
     * A sale price at or above the regular price is not a sale.
     *
     * Product::isOnSale() would answer false while the screen showed a
     * struck-through price. The comparison is done on the integers, never on
     * the strings. Same rule and same words as the update path, so an operator
     * gets the same answer whichever screen they are on.
     */
    private function saleBelowPrice(?int $price, ?int $sale): ?JsonResponse
    {
        if ($sale === null) {
            return null;
        }

        if ($price === null) {
            return response()->json([
                'message' => 'A sale price needs a regular price to be a discount from.',
                'errors' => ['sale_price' => ['Set a regular price first.']],
            ], 422);
        }

        if ($sale >= $price) {
            return response()->json([
                'message' => 'The sale price has to be below the regular price.',
                'errors' => ['sale_price' => [
                    'Regular price is '.Money::plain($price).'.',
                ]],
            ], 422);
        }

        return null;
    }

    /**
     * Refuse "published and invisible", which is the quiet way to ship nothing.
     *
     * Product::scopeVisible() is `status = 'publish' AND is_visible = 1`, and
     * every storefront surface goes through it: /shop, the category archive,
     * /product/{slug}/, the sitemap, search, the cart's own re-check. A row
     * that is published with is_visible false satisfies none of them while
     * sitting under the "Published" chip on this very screen. The operator
     * meant one of the two things this message names; the form cannot guess
     * which, so it asks.
     */
    private function publishableOrRefused(string $status, bool $isVisible): ?JsonResponse
    {
        if ($status !== 'publish' || $isVisible) {
            return null;
        }

        return response()->json([
            'message' => 'A published product with catalogue visibility off would not appear anywhere.',
            'errors' => ['is_visible' => [
                'Published products have to be visible in the catalogue — otherwise the product is not on the '
                .'shop, not on its category page and not in the sitemap. Save it as a draft instead, or turn '
                .'catalogue visibility on.',
            ]],
        ], 422);
    }

    /**
     * Keep the image to something that is safe as an <img src>.
     *
     * The value normally arrives straight from MediaUploadController, but the
     * field is a plain string and the screen also lets an operator paste a URL
     * by hand. `javascript:` in a src is inert, but `data:` is not — a data:
     * URL of type image/svg+xml renders as a document and can carry script,
     * which is the same stored-XSS shape MediaUploadController already refuses
     * for uploaded SVG. Only http(s) and site-relative paths are kept.
     *
     * Deliberately the same rule, and the same sentence, as
     * BrandsApiController::safeLogoUrl() and CategoriesApiController's image
     * check. An image URL that is safe on a brand and unsafe on a product would
     * be a distinction nobody could defend.
     *
     * @return string|null|JsonResponse the cleaned URL, null, or the refusal
     */
    private function safeImageUrl(?string $image): string|null|JsonResponse
    {
        $image = trim((string) ($image ?? ''));

        if ($image === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $image) === 1) {
            return $image;
        }

        // A site-relative path: one leading slash, and no "..", so a stored
        // value cannot be walked outside the web root by whatever consumes it.
        if (str_starts_with($image, '/') && ! str_contains($image, '..')) {
            return $image;
        }

        return response()->json([
            'message' => 'That image address cannot be used.',
            'errors' => ['image' => [
                'The image must be an uploaded file or an http(s) URL.',
            ]],
        ], 422);
    }

    /* --------------------------------------------------------------- pieces */

    /** Is this slug already spoken for, soft-deleted rows included? */
    private static function slugTaken(string $slug): bool
    {
        // withTrashed(), because the unique index underneath does not know
        // about deleted_at: a trashed product still owns its address.
        return Product::withTrashed()->where('slug', $slug)->exists();
    }

    /**
     * The first free slug of the form {slug}-2, {slug}-3, …
     *
     * Bounded, and honest when it gives up: a suggestion loop that can run
     * forever on a pathological catalogue is a request that never returns.
     */
    private static function freeSlugNear(string $slug): ?string
    {
        for ($n = 2; $n <= 50; $n++) {
            $candidate = $slug.'-'.$n;

            if (! self::slugTaken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Was this the slug unique index, rather than some other constraint? */
    private static function isDuplicateSlug(\Illuminate\Database\QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        // MySQL 1062 and SQLite's "UNIQUE constraint failed" read differently;
        // both name the column, which is what distinguishes this from any other
        // integrity error and stops a real bug being reported as a duplicate.
        return str_contains($message, 'slug')
            && (str_contains($message, 'duplicate') || str_contains($message, 'unique'));
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
