<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Support\Gtin;
use App\Support\ImageVariants;
use App\Support\MajorUnits;
use App\Support\Money;
use App\Support\ProductSeo;
use App\Support\RichText;
use App\Support\SetContents;
use App\Support\SetEagerLoad;
use App\Support\SetPricing;
use App\Support\TranslationInput;
use App\Support\WholeDirhams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    /**
     * The product columns the storefront prints with {!! !!}.
     *
     * One list, read by the English sanitiser loop and by the Arabic one, so a
     * field cannot be sanitised in one language and not the other. See
     * partials/product-tabs.blade.php, which prints Description, Ingredients
     * and How to use through {!! !!} without knowing which language it holds.
     *
     * @var list<string>
     */
    private const RICH_FIELDS = ['short_description', 'description', 'ingredients', 'how_to_use'];

    /** The four words the editor's status control speaks. */
    private const EDITOR_STATUSES = ['publish', 'draft', 'private', 'scheduled'];

    /** What `products.status` may actually hold. */
    private const COLUMN_STATUSES = ['publish', 'draft', 'private'];

    /**
     * The three types this editor offers, and the only three it will WRITE.
     * (Lane SP)
     *
     * ▲ A `products.type` OUTSIDE THIS LIST IS LEFT ALONE, NOT REWRITTEN.
     *   `products.type` stores an unknown value verbatim -- the WooCommerce
     *   import writes 'grouped' and 'external' and
     *   docs/PRODUCT-FIELD-PARITY.md row 6 says so -- and an editor that
     *   silently turned every imported `grouped` row into `simple` the first
     *   time somebody fixed a typo in its name would be rewriting the
     *   catalogue by opening it. So the screen shows an unrecognised type as
     *   itself and does not send it back, and apply() writes only these three.
     */
    private const EDITOR_TYPES = ['simple', 'variable', 'set'];

    /** How many products one box may hold. A box, not a catalogue. */
    private const MAX_SET_MEMBERS = 40;

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

    /**
     * The phone-sized copy of each of this product's photographs, keyed by the
     * original's URL, for the boxes this screen draws them in.
     *
     * A URL is present ONLY when a real file backs it, so the screen's
     * `thumbs[u] || u` falls through to the original for every photograph the
     * batch has not reached — which, on a catalogue that has never been through
     * Content -> Media Library -> Make phone-sized copies, is all of them, and
     * the screen then behaves exactly as it does today.
     *
     * The widths are the boxes': ~300px for the main-image card, 56px for a
     * gallery tile. See the payload's own note.
     *
     * @return array<string, string>
     */
    private function editorThumbs(Product $product): array
    {
        $thumbs = [];

        $add = static function ($image, int $width) use (&$thumbs): void {
            if (! is_string($image) || trim($image) === '') {
                return;
            }

            $image = trim($image);
            $thumb = ImageVariants::variantUrl($image, $width);

            // Only a real copy earns a row. variantUrl() hands back its
            // argument when there is nothing on disk, and a map of a URL to
            // itself is bytes that tell the screen nothing it did not have.
            if ($thumb !== $image) {
                $thumbs[$image] = $thumb;
            }
        };

        $add($product->image, 400);

        foreach ((array) ($product->images ?? []) as $shot) {
            $add($shot, 200);
        }

        return $thumbs;
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
            /*
             * THE EMPTY SHAPE OF THE ARABIC BOXES. (Lane EX, T4b)
             *
             * A product being CREATED has no row and therefore no translations,
             * but the blank form still has to draw a box for every translatable
             * field — that is the whole requirement, in the owner's words: "for
             * addition of everything… we should must have arabic place for every
             * field". Handing the shape down with the bootstrap is what lets the
             * create form know which fields those are without this screen
             * holding its own second copy of Product::$translatable, which is
             * exactly the kind of list that drifts.
             */
            'translations' => (new Product)->translationsForEditor(),

            'statuses' => self::EDITOR_STATUSES,
            'stock_statuses' => ['instock', 'outofstock', 'onbackorder'],
            /*
             * What selling a set does to the shelves, so the screen can SAY it
             * when a product is turned into a set and on a set's Stock card
             * (Lane PI-A, item 10). One of StockSetRule's two values, read --
             * the switch itself stays on Catalog -> Sets -> Stock.
             */
            'set_stock_mode' => app(\App\Services\StockSetRule::class)->mode(),
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
                // (Lane IM2) `.peo-item img` is a 38px square, so 200w covers
                // it at device-pixel-ratio 3 with room to spare and the row
                // stops pulling the catalogue original. 40 rows a page, so this
                // is ~11.6MB down to ~190KB. The original stays on `image` for
                // anything that wants the file itself.
                'thumb' => ImageVariants::variantUrl((string) ($p->image ?? ''), 200),
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

        // A set's members, in three batched queries, and NOTHING AT ALL for a
        // product that is not a set -- which is every product but the sets.
        // (Lane SP)
        SetEagerLoad::on([$product]);

        return response()->json(['ok' => true, 'product' => $this->payload($product)]);
    }

    /**
     * What is in the box, for the editor's own panel. (Lane SP)
     *
     * Built from App\Support\SetContents -- the one description of a set's
     * contents in this application -- plus the two ids the panel has to send
     * back, which SetContents deliberately does not carry because no storefront
     * surface has any use for them.
     *
     * Empty for a product that is not a set, so the screen reads the same shape
     * whatever it is editing.
     *
     * @return list<array<string, mixed>>
     */
    private function setMembersPayload(Product $product): array
    {
        if (! $product->isSet()) {
            return [];
        }

        $contents = SetContents::fromProduct($product);
        $out = [];

        foreach ($product->setItems as $i => $row) {
            $member = $contents['members'][$i] ?? null;

            if ($member === null || $row->member === null) {
                continue;
            }

            $out[] = [
                'product_id' => (int) $row->member_product_id,
                'variant_id' => $row->member_variant_id === null ? null : (int) $row->member_variant_id,
                'quantity' => (int) $member['quantity'],
                'name' => $member['name'],
                'brand' => $member['brand'],
                'sku' => $member['sku'],
                'variant' => $member['variant'],
                'image' => $member['image'],
                'unit_price_aed' => $this->editorAmount((int) $member['unit']),
            ];
        }

        return $out;
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

            /*
             * ── THE SET, ON THE PRODUCT EDITOR (Lane SP) ───────────────────
             *
             * Present on EVERY product, set or not, and empty for the ones that
             * are not: the screen decides what to draw from `type`, and a
             * payload whose shape changed with the type would be a screen that
             * has to guard every read. `price_mode` reads `fixed` for every
             * ordinary product and every set built before the rule existed,
             * which is the value that changes nothing.
             *
             * `set_effective_aed` is what a shopper is charged TODAY, derived
             * from the members' current prices. The screen prints it and never
             * computes it -- the live preview beside it is the operator's own
             * arithmetic on what he is typing, and this is the server's.
             */
            'tags' => $product->exists
                ? $product->tags()->orderBy('name')->pluck('name')->all()
                : [],
            'set_members' => $this->setMembersPayload($product),
            // Catalog → Products → (edit) → You may also like. (Lane PS) No query
            // for a product with no picks, which is every product until one.
            'also_like' => \App\Support\AlsoLikePicks::editorPayload($product),
            'price_mode' => SetPricing::mode($product),
            'discount_percent' => SetPricing::mode($product) === SetPricing::MODE_PERCENT
                ? rtrim(rtrim(number_format(((int) ($product->set_discount ?? 0)) / 100, 2, '.', ''), '0'), '.')
                : '',
            'discount_amount' => SetPricing::mode($product) === SetPricing::MODE_AMOUNT
                ? $this->editorAmount((int) ($product->set_discount ?? 0))
                : '',
            'set_parts_total_aed' => $product->isSet()
                ? $this->editorAmount(SetPricing::partsTotal($product))
                : '',
            'set_effective_aed' => $product->isSet()
                ? $this->editorAmount((int) $product->effectivePrice())
                : '',

            /*
             * ── WHY A PRICE MOVED ON ITS OWN, IN FOUR NUMBERS (Lane SP2) ───
             *
             * A hand-typed set price that follows its members down is the one
             * figure on this screen the operator did not last write. So the
             * screen shows its whole working: what the box was worth when he
             * typed the price (`set_basis_aed`), what it is worth now
             * (`set_parts_total_aed`, above), the difference that is coming off
             * (`set_adjustment_aed`) and the two resulting figures
             * (`set_effective_aed` and `set_sale_now_aed`).
             *
             * '' rather than '0.00' for the basis when there is none: no anchor
             * is a DIFFERENT state from an anchor of zero, and the panel prints
             * a different sentence for it. Every set built before this feature
             * is in that state and stays in it until somebody types a price.
             *
             * `set_members_missing` is the count of membership rows that no
             * longer name a buyable product. It is not decoration -- it is the
             * reason the reduction is paused, and a paused reduction with
             * nothing on the screen saying so is precisely the "a price moved
             * and I do not know why" this block exists to prevent.
             */
            'set_basis_aed' => $product->isSet() && SetPricing::basis($product) !== null
                ? $this->editorAmount((int) SetPricing::basis($product))
                : '',
            'set_adjustment_aed' => $product->isSet()
                ? $this->editorAmount(SetPricing::adjustment($product))
                : '',
            'set_sale_now_aed' => $product->isSet() && $product->sale_price !== null
                ? $this->editorAmount(SetPricing::afterAdjustment($product, (int) $product->sale_price))
                : '',
            'set_members_missing' => $product->isSet()
                ? SetPricing::tally($product)['missing']
                : 0,
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
            /*
             * (Lane IM2) THE COPY EACH OF THOSE SHOULD BE *DRAWN* FROM.
             *
             * The gallery strip on this screen paints every shot into a 56px
             * square (.peo-tile img) and the main-image card into a box about
             * 300px across. Both were pointed straight at the catalogue
             * original, so opening one product with an eight-shot gallery cost
             * about 2.3MB of photographs to fill eight thumbnails and one card.
             *
             * A MAP KEYED BY THE ORIGINAL URL, and not a parallel array, for
             * one reason that is specific to this screen: the gallery is
             * EDITABLE. Tiles are dragged to reorder and removed individually,
             * and `image_alts` is already keyed by URL for exactly the same
             * reason — a parallel array would have to be kept in step by every
             * reorder and every delete, and the first one that was not would
             * slide one photograph's thumbnail onto another. A map cannot go
             * out of order, so the screen reads `thumbs[u] || u` and the drag
             * handler stays as it is.
             *
             * ONLY THE ENTRIES THAT HAVE A COPY. variantUrl() returns its
             * argument when nothing is on disk, so writing those would be a map
             * of a URL to itself — bytes on the wire that say nothing. The
             * screen's fallback is the original either way.
             *
             * 400 for the main image because its card is ~300px wide; 200 for
             * the strip because 56px cannot use more. Both are what the boxes
             * measure, not a round number.
             */
            'thumbs' => $this->editorThumbs($product),

            // Lane RPL: pictures a save of this product took off the server,
            // still within their 30 days -- the editor offers Undo on each.
            'picture_trash' => $product->exists ? \App\Services\Media\PictureTrash::pendingFor((int) $product->id) : [],

            'seo' => is_array($product->seo) ? $product->seo : null,

            /*
             * WHAT THE STOREFRONT WILL ACTUALLY PUT IN THIS PRODUCT'S HEAD.
             *
             * The editor draws a Google-style snippet under the Search
             * appearance panel. It used to draw it out of the operator's own two
             * boxes, falling back to the product name and to an invented
             * sentence — "Add a description so Google shows the right words
             * here." — neither of which the site has ever emitted. That is the
             * same defect SnippetPreviewTruthTest was written to kill in the
             * OLD Yoast-shaped panel, and it was fixed there; this screen is the
             * one the owner actually uses (Lane AT retired the other two), and
             * it still lied.
             *
             * Both strings are built by the SAME methods render() uses —
             * Seo::titleFor() and Seo::describe(), through ProductSeo — so the
             * preview cannot drift from the page by construction.
             *
             * `true` means "answer as though the operator's box were empty".
             * The preview's own logic is `typed || fallback`, so the endpoint's
             * job is only the second operand: what appears the moment the box is
             * cleared. Note these are NOT symmetrical, and that asymmetry is the
             * whole reason the title is here too:
             *
             *   - cleared DESCRIPTION box -> the sitewide default, or nothing.
             *   - cleared TITLE box       -> brand + name THROUGH the title
             *     template, so " | K-Beauty Bliss" is appended. A filled title
             *     box is `title_is_final` and gets no such suffix.
             *
             * So the emitted title is longer than the box in one case and equal
             * to it in the other, which is also why the editor's character
             * counter has to count THIS rather than the box.
             *
             * '' is a real answer for the description and means the page
             * publishes no description tag at all. The preview shows that as an
             * empty line, which is what Google would show.
             */
            'seo_fallback_title' => \App\Support\ProductSeo::metaTitle($product, true),
            'seo_fallback_description' => \App\Support\ProductSeo::metaDescription($product, true),

            // And what it emits RIGHT NOW, with whatever is stored. The counter
            // measures this, so "58 / 60" refers to the tag and not to the box.
            'seo_title' => \App\Support\ProductSeo::metaTitle($product),
            'seo_description' => \App\Support\ProductSeo::metaDescription($product),

            /*
             * THE ARABIC BOXES' PREFILL. (Lane EX, T4b)
             *
             * Shaped by HasTranslations::translationsForEditor(): locale =>
             * field => {value, status, source, stale}. DRAFTS ARE INCLUDED, and
             * that is the point — a machine translation waiting for approval
             * has to appear in the box the owner is looking at, or "Approve"
             * means approving something invisible.
             *
             * A product being created has no id and no rows, so this is the
             * empty shape rather than absent: the screen draws the same boxes
             * on a blank form as on a saved one, which is the whole requirement
             * — the Arabic is entered AT THE MOMENT OF CREATION, not on a
             * screen visited afterwards.
             */
            'translations' => $product->translationsForEditor(),

            // Read-only, and shown as such: these are computed from orders and
            // reviews and the editor has no control that writes them.
            'readonly' => [
                'total_sales' => (int) ($product->total_sales ?? 0),
                'rating' => (float) ($product->rating ?? 0),
                'review_count' => (int) ($product->review_count ?? 0),
                'url' => $product->url(),
                /*
                 * WOULD `url` OPEN THIS PRODUCT ON THE SHOP RIGHT NOW? (Lane PK)
                 *
                 * The editor's Visit button opens `url` in a new tab, and the
                 * owner asked for it on every product -- but a draft, a private
                 * product, a hidden one or one scheduled for next week is a 404
                 * on the shop, and a button that opens a 404 is worse than no
                 * button. So the screen is told, and says "Not live yet".
                 *
                 * ProductVisibility::isLive() and not a re-spelling of it: it is
                 * the in-memory twin of the scope the product page itself runs
                 * (Product::visible() -> status, is_visible, published_at), so
                 * this cannot call a product live that the shop would refuse.
                 * `url` is Product::url(), which goes through Url::to(), so the
                 * KBB_BASE_PATH prefix is the shop's own -- empty on the live
                 * site, /kbb-upgrade on the old staging box.
                 */
                'live' => $product->exists && ! $product->trashed()
                    && \App\Support\ProductVisibility::isLive($product),
            ],
        ];
    }

    /* --------------------------------------------------------------- create */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            $this->rules(creating: true, payload: $request->all()),
            $this->messages()
        );

        $product = null;

        // Lane RPL: every picture on a new product is new -- its empty
        // description boxes get the Image SEO proposal for their slot.
        \App\Services\ImageSeo\NewPictures::proposeAlts($data, new Product, []);

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

        $this->namePictures($product, []);

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
            $this->rules(creating: false, payload: $request->all()),
            $this->messages()
        );

        /*
         * "You may also like" picks (Lane PS). Checked BEFORE anything is
         * written -- a pick that is not on the shop is a 422 like any other
         * field -- and written in the SAME transaction as the product, so the
         * two cannot be half-saved. Absent from the request means "leave them".
         * App\Support\AlsoLikePicks carries the rules and why it is JSON.
         */
        $alsoLike = \App\Support\AlsoLikePicks::fromRequest($request, $product);

        /*
         * Lane RPL. The pictures as they were, before anything is written: a
         * picture this save takes off the product is cleaned up afterwards
         * (PictureTrash), and one it puts on gets its description now and its
         * SEO name afterwards (NewPictures). Only THIS product's own pictures
         * can ever be candidates -- nothing a request names.
         */
        $picturesBefore = \App\Services\Media\PictureTrash::slots($product);
        \App\Services\ImageSeo\NewPictures::proposeAlts($data, $product, $picturesBefore);

        $failure = DB::transaction(function () use ($product, $data, $alsoLike) {
            $failure = $this->apply($product, $data);

            if (! $failure instanceof JsonResponse && $alsoLike !== null) {
                \App\Support\AlsoLikePicks::write($product, $alsoLike);
            }

            return $failure;
        });

        if ($failure instanceof JsonResponse) {
            return $failure;
        }

        $renamed = $this->namePictures($product, $picturesBefore);
        $photos = ['trashed' => [], 'kept' => []];

        /*
         * The product is saved; nothing below may turn that into an error. A
         * picture that could not be cleaned up stays where it was -- on the
         * server, which is where it was before the owner pressed Save.
         */
        try {
            $hints = [];

            foreach ((array) ($data['replaced'] ?? []) as $pair) {
                $to = (string) ($pair['to'] ?? '');
                $toRel = \App\Services\ImageSeo\ImageFiles::local($to);

                if ($toRel !== null && isset($renamed[$toRel])) {
                    $to = \App\Services\ImageSeo\ImageSeoPlanner::swapBasename($to, basename($renamed[$toRel]));
                }

                $hints[(string) ($pair['from'] ?? '')] = $to;
            }

            $photos = \App\Services\Media\PictureTrash::afterSave(
                $product->fresh(), $picturesBefore, $hints, auth('admin')->user()
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'ok' => true,
            'product' => $this->payload($product->fresh(['brand', 'categories'])),
            'photos' => $photos + ['renamed' => array_map('basename', array_values($renamed))],
        ]);
    }

    /**
     * Lane RPL: a new picture with a generic upload name takes its Image SEO
     * name now. Never fails the save it follows.
     *
     * @param  list<array{url: string}>  $before
     * @return array<string, string> old relative path => new relative path
     */
    private function namePictures(?Product $product, array $before): array
    {
        if ($product === null || ! $product->exists) {
            return [];
        }

        try {
            $renamed = \App\Services\ImageSeo\NewPictures::nameNewFiles($product->fresh(), $before, auth('admin')->user());
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        if ($renamed !== []) {
            // The rename rewrote the row through the query builder; the shop's
            // home cache and every card keyed on updated_at learn of it here.
            Product::query()->find($product->id)?->touchQuietly();

            if (method_exists(\App\Http\Controllers\Store\HomeController::class, 'flushCache')) {
                \App\Http\Controllers\Store\HomeController::flushCache();
            }
        }

        return $renamed;
    }

    /**
     * Catalog → Products → edit → Pictures removed from the server → Undo.
     * (Lane RPL) The picture comes back from the trash, onto the server and
     * into its slot. Only a picture THIS product's save put in the trash.
     */
    public function photoUndo(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json(['ok' => false, 'message' => 'That product no longer exists.'], 404);
        }

        $data = $request->validate(['trash_id' => ['required', 'integer', 'min:1']]);
        $result = \App\Services\Media\PictureTrash::undo((int) $data['trash_id'], $product);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['message']], 409);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'product' => $this->payload($product->fresh(['brand', 'categories'])),
        ]);
    }

    /* ---------------------------------------------------------------- rules */

    /**
     * An image URL this store is willing to put in a src attribute.
     *
     * The retired create form carried this check (safeImageUrl(): http(s) or a
     * site-relative path, everything else refused) and the editor that replaced
     * it validated `string|max:500` and nothing more. Retiring the old screen
     * therefore removed a guard rather than consolidating it, which is the
     * failure mode a subtractive change has to be watched for. Restored here,
     * on the three fields that end up in a src or a meta tag.
     *
     * The specific shape it refuses is `data:image/svg+xml`, which is a
     * scriptable document wearing an image's name, plus `javascript:` and any
     * other scheme. An SVG loaded through <img> does not execute script in a
     * current browser, so this is defence in depth rather than a hole anyone
     * can walk through today — but the value is operator-supplied, it is
     * stored, and where it gets rendered next is not this method's to assume.
     */
    private static function imageUrlRule(): \Closure
    {
        return static function (string $attribute, $value, \Closure $fail): void {
            $url = trim((string) $value);

            if ($url === '') {
                return;
            }

            // A site-relative path is the common case: /media/whatever.jpg.
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                return;
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if ($scheme === 'http' || $scheme === 'https') {
                return;
            }

            $fail('An image has to be an http(s) address or a path on this site.');
        };
    }

    private function rules(bool $creating, array $payload = []): array
    {
        $money = ['nullable', 'string', MajorUnits::shape()];

        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gtin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')],
            /*
             * ── THE PRODUCT TYPE, AND THE SET (Lane SP) ────────────────────
             *
             * The owner: "can you please merge the Set functionality into the
             * product itself? i want if i add product, on that page it will
             * have optin to switch to Set product type, and all options will be
             * shown for set, with remaing same sections like seo etc."
             *
             * A set IS a `products` row with type='set' plus the
             * product_set_items pivot -- the shape Lane SET argued for at
             * length, and precisely so a set would carry slug, status,
             * category, description, images, SEO and position as an ordinary
             * product. This editor is what that shape was always for.
             *
             * ▲ IT IS NO LONGER `nullable`, AND IT IS AN ALLOWLIST. Both halves
             *   are fixes rather than tidying, and both are reachable from this
             *   endpoint as it stood:
             *
             *     `nullable` let a request send `type: null`, which the plain
             *     loop below wrote straight to the column -- turning a saved SET
             *     into a row that Product::isSet() answers false for, silently,
             *     with its members still in the pivot and its box gone from
             *     every surface that draws one.
             *
             *     `string|max:40` let it be anything at all. CLAUDE.md rule 5 is
             *     that a select stores one of its own options or the default,
             *     and this select now has three options.
             *
             * OMISSION STILL MEANS "LEAVE IT". `sometimes` plus the
             * array_key_exists() guard in apply() is what makes a save from a
             * client that knows nothing about types keep the type it found --
             * the property SetProductEditorTest pins by name.
             */
            'type' => ['sometimes', 'string', Rule::in(self::EDITOR_TYPES)],

            /*
             * What is in the box. Only read when the product is a set -- see
             * applySet() -- so an ordinary product may send it and nothing
             * happens, which is what keeps the pivot intact across a type
             * switch the operator may be about to undo.
             */
            'set_members' => ['sometimes', 'array', 'max:'.self::MAX_SET_MEMBERS],
            'set_members.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'set_members.*.variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')],
            'set_members.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],

            // How the set's price is decided. App\Support\SetPricing carries
            // the argument for why this is a rule and not a number.
            'price_mode' => ['sometimes', 'string', Rule::in(SetPricing::MODES)],
            'discount_percent' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'discount_amount' => ['sometimes', 'nullable', 'string', MajorUnits::shape()],

            'status' => ['sometimes', Rule::in(self::EDITOR_STATUSES)],
            // Required only when the status says scheduled — a date with no
            // schedule is meaningless and a schedule with no date is a product
            // that never launches.
            'published_at' => ['nullable', 'date', 'required_if:status,scheduled'],
            /*
             * Published and invisible at once is a product that saves cleanly
             * and then appears nowhere -- not the shop, not its category, not
             * the sitemap -- while the editor cheerfully says "Published".
             * The retired create form refused it (publishableOrRefused) and
             * the editor that replaced it did not, so consolidating the two
             * screens dropped a rule. Restored on both paths by living in
             * rules(), which store() and save() share.
             */
            'is_visible' => ['sometimes', 'boolean', static function (string $attribute, $value, \Closure $fail) use (&$payload) {
                if ((bool) $value === false && ($payload['status'] ?? null) === 'publish') {
                    $fail('A published product has to be visible. Set the status to Private to keep it off the shop.');
                }
            }],
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

            'image' => ['sometimes', 'nullable', 'string', 'max:500', self::imageUrlRule()],
            'images' => ['sometimes', 'array', 'max:24'],
            'images.*' => ['string', 'max:500', self::imageUrlRule()],
            'image_alts' => ['sometimes', 'nullable', 'array'],
            // Lane RPL: which picture took which slot, from the editor's
            // Replace. A hint only -- PictureTrash believes a pair only when
            // the old picture really left this product and the new one is on it.
            'replaced' => ['sometimes', 'nullable', 'array', 'max:24'],
            'replaced.*.from' => ['required', 'string', 'max:500'],
            'replaced.*.to' => ['required', 'string', 'max:500'],
            'image_alts.*' => ['nullable', 'string', 'max:250'],

            /*
             * ── TAGS (Lane SP) ─────────────────────────────────────────────
             *
             * The owner asked for "the proper tags etc on this page" while the
             * Sets editor was still its own screen. It is not: the `tags` and
             * `product_tag` tables have existed since the original schema and
             * NOTHING IN THIS APPLICATION HAS EVER WRITTEN TO THEM -- not the
             * importer, not either editor -- which is why this is on the
             * PRODUCT editor rather than on a set's own panel. A set is a
             * product; so is everything else on this screen.
             *
             * NAMES ON THE WIRE, ROWS IN `tags` ON THE WAY IN. The operator
             * types words, not ids.
             *
             * `nullable` on the items because Laravel's
             * ConvertEmptyStringsToNull middleware turns an empty chip into
             * NULL before the validator sees it, and a blank tag is something
             * to DROP (applyTags() skips it) rather than a 422 on an otherwise
             * good save. Found by a test, not by reading.
             */
            'tags' => ['sometimes', 'nullable', 'array', 'max:40'],
            'tags.*' => ['nullable', 'string', 'max:60'],

            /*
             * ── "START AGAIN FROM TODAY'S TOTAL" (Lane SP2) ────────────────
             *
             * An INSTRUCTION, not a field: it carries no value to store and is
             * never echoed back in the payload. The set panel's button sends it
             * so the operator can re-anchor a hand-typed price WITHOUT having
             * to retype the same number -- which is the one way he could not
             * otherwise reach anchorFixedPrice(), since an unchanged price is
             * deliberately not a re-anchor.
             */
            'reanchor' => ['sometimes', 'boolean'],

            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:200'],
            'seo.desc' => ['nullable', 'string', 'max:400'],
            'seo.canonical' => ['nullable', 'string', 'max:500', 'url'],
            'seo.og_image' => ['nullable', 'string', 'max:500', self::imageUrlRule()],
            'seo.noindex' => ['nullable', 'boolean'],
        ];

        if ($creating) {
            $rules['slug'] = ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
            $rules['wc_id'] = ['nullable', 'integer', 'min:1', Rule::unique('products', 'wc_id')];
        }

        /*
         * The Arabic boxes, validated by the SAME shape rules as the English
         * ones they sit beside — derived from the array above rather than
         * restated, so `name` cannot be 200 characters in one language and 300
         * in the other. Required-ness does not carry: an Arabic box is optional
         * by definition, because blank is how the shop says "not translated
         * yet". See App\Support\TranslationInput.
         *
         * Note these rules are additive and never reject an unknown key: a
         * field outside Product::$translatable and a locale the shop does not
         * run both fall to the catch-all, pass, and are then dropped by
         * saveTranslations(). A 422 in the middle of saving a product somebody
         * spent ten minutes on is worse than a dropped field, and the allowlist
         * is the guard that matters.
         */
        return $rules + TranslationInput::rules(new Product, $rules);
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
        /*
         * ── WHAT THIS ROW SAID BEFORE THIS REQUEST TOUCHED IT (Lane SP2) ───
         *
         * Read HERE and not where it is used, because `$product->save()` runs
         * in the middle of this method and save() syncs the original attributes
         * -- so by the time applySetPricing() asks, getOriginal('price') is the
         * value this request just wrote and every comparison against it is
         * false. Two things downstream need the genuine before-state:
         *
         *   the main image, so an auto-filled share image can follow it to its
         *   new value while a hand-picked one is left exactly where it is; and
         *
         *   the typed price, the sale price and the pricing mode, so a set's
         *   anchor is re-taken when the operator types a NEW price and is left
         *   alone when he saves a description.
         */
        $before = [
            'image' => (string) ($product->getOriginal('image') ?? ''),
            'price' => $product->getOriginal('price'),
            'sale_price' => $product->getOriginal('sale_price'),
            'mode' => SetPricing::mode($product->exists ? $product : null),
            'basis' => SetPricing::basis($product->exists ? $product : null),
        ];

        /* ------------------------------------------------------------ money */
        foreach (['price_aed' => ['price', 'Price'], 'sale_aed' => ['sale_price', 'Sale price']] as $field => [$column, $label]) {
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

            /*
             * WHOLE DIRHAMS — REFUSED, NOT ADJUSTED, AND ONLY ON A CHANGE.
             *
             * The owner's words were "no decimals. if any decimals comes.
             * adjust to the price." A price is the one figure on this screen
             * that he TYPES, so the adjustment is his to make and not this
             * controller's: a price that silently became AED 100 while he was
             * looking at 99.80 is a price he would next hear about from a
             * customer. App\Support\WholeDirhams' header sets out the whole
             * refuse-vs-adjust rule; this is the refusing half.
             *
             * ── AND ONLY ON A CHANGE, WHICH IS THE HALF THAT MATTERS MORE ──
             *
             * This shop has products priced in fils today, imported from
             * WooCommerce before any of this existed. The editor posts every
             * field it holds on every save, so without the `!== $stored`
             * comparison, opening a 9,980-fil product, fixing a typo in its
             * DESCRIPTION and pressing Save would be refused — and the owner
             * would have no way to edit the description at all short of
             * repricing the product.
             *
             * The alternative, adjusting it to AED 100 on the way past, is
             * worse than either: money moving because somebody edited a
             * description is the one outcome this lane exists to prevent. So
             * an unchanged value passes through untouched, whatever it holds,
             * and the audit command (kbb:whole-dirhams) is where an owner
             * deals with the legacy prices deliberately and all at once.
             *
             * `$stored` is read BEFORE the assignment below, so on a create —
             * where it is null — any fils value is a new one and is refused.
             */
            $stored = $product->{$column} === null ? null : (int) $product->{$column};

            if ($fils !== null && $fils !== $stored && ! WholeDirhams::isWhole($fils)) {
                return response()->json([
                    'ok' => false,
                    'message' => WholeDirhams::message($label, $fils),
                    'errors' => [$field => ['Whole ' . WholeDirhams::plural() . ' only.']],
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

        /* ------------------------------------------------------- the type */
        /*
         * WRITTEN ONLY WHEN IT IS SENT, AND ONLY WHEN IT IS ONE OF THE THREE.
         * (Lane SP)
         *
         * Lifted OUT of the plain loop below, which wrote whatever arrived --
         * including null, which is how a saved set could be turned back into a
         * non-set by a request that simply did not know what it was editing.
         *
         * `array_key_exists` and not `!empty`: omission means "leave the type
         * alone", which is what makes a save from any older client -- Catalog →
         * Products' inline cells, a script, the editor as it shipped this
         * morning -- keep a set a set. SetProductEditorTest pins that by name,
         * and pins the `$product->type ??= 'simple'` default below staying
         * inside `if (! $product->exists)` where it cannot reach a saved row.
         */
        if (array_key_exists('type', $data) && in_array($data['type'], self::EDITOR_TYPES, true)) {
            $product->type = (string) $data['type'];
        }

        /*
         * ── TURNING A SAVED PRODUCT INTO A SET (Lane PI-A, item 10) ────────
         *
         * The owner: "we have alot of sets which we used just as product ...
         * we need to switch the set products to proper set ... make this
         * super reliable." The switch itself is the type select above, and a
         * converted set is in every respect the row a set built as a set is:
         * type='set' plus product_set_items. What was missing were the two
         * cases where the switch produces a product the rest of the shop
         * cannot treat as a set, and both are refused HERE, before anything is
         * written, with a sentence saying what to do.
         */
        if ($product->exists && $product->isSet() && $product->getOriginal('type') !== 'set') {
            $refusal = $this->conversionRefusal($product);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        /* ----------------------------------------------------------- plain */
        foreach (['name', 'sku', 'stock', 'stock_status', 'position'] as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = $data[$field];
            }
        }

        /*
         * THE BRAND. Validated since the editor was written (rules(): exists
         * in brands) and then never written: the owner, 10 October, "when
         * select brand, and click save, then the brand resets to non-selection,
         * on front-end also this product not showing in that brand." The save
         * answered 200 with the old brand_id and the select redrew from it.
         * Present-and-null is "No brand"; absent is "leave it". A change of
         * brand forgets the product's place in the old brand's order
         * (Product::booted(), ScopeOrder::BRAND_COLUMN), so it joins the new
         * brand's page at the end, as Catalog -> Reorder documents.
         */
        if (array_key_exists('brand_id', $data)) {
            $product->brand_id = $data['brand_id'] === null ? null : (int) $data['brand_id'];
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
        foreach (self::RICH_FIELDS as $field) {
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

        /* ----------------------------------------- the share image follows */
        $this->followMainImageIntoSeo($product, $before['image']);

        /* --------------------------------------------------- the defaults */
        if (! $product->exists) {
            $product->status ??= 'draft';
            $product->is_visible ??= true;
            $product->stock_status ??= 'instock';
            $product->type ??= 'simple';
        }

        $product->save();

        /* ---------------------------------------------------- translations */
        /*
         * One call, in the same request and inside the same transaction as the
         * English row — which is what makes "the Arabic is entered at the
         * moment of creation" true rather than aspirational. It runs AFTER
         * save() because a new product has no id until then, so create and
         * update take the same path and there is no second one to get wrong.
         *
         * The rich fields are named so the Arabic goes through the very same
         * RichText::clean() the English went through twenty lines above.
         * product-tabs prints both with {!! !!}; a sanitiser applied to one
         * language only is a stored-XSS hole opened by adding the second.
         *
         * THE LIST IS DERIVED, NOT TYPED. It used to be a literal pair while
         * the comment above it said "the four rich fields", and the day
         * ingredients and how_to_use joined Product::$translatable the Arabic
         * halves of two {!! !!} tabs would have gone to the database
         * unsanitised — a stored-XSS hole opened by adding a column name to a
         * list somewhere else. Reading it from the same constant the English
         * sanitiser loop reads means the two cannot drift apart again.
         */
        $product->saveTranslations(TranslationInput::clean(
            $data['translations'] ?? [],
            self::RICH_FIELDS,
        ));

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

        /* -------------------------------------------------------- the tags */
        $this->applyTags($product, $data);

        /* --------------------------------------------------------- the box */
        return $this->applySet($product, $data, $before);
    }

    /**
     * THE SEARCH-RESULT SHARE IMAGE IS TAKEN FROM THE MAIN IMAGE, AUTOMATICALLY
     * — AND NEVER OVER THE TOP OF ONE THE OPERATOR CHOSE. (Lane SP2)
     *
     * The owner: "ON THE product edit page and set edit page, the seo image
     * must be taken auto from the main image automatically when i upload the
     * main image of the product or set, and manually also i can change that seo
     * image."
     *
     * ── HOW IT TELLS THE TWO APART, AND WHY THERE IS NO FLAG ───────────────
     *
     *     AUTOMATIC  ⇔  seo.og_image is empty, or equal to the main image.
     *     BY HAND    ⇔  anything else.
     *
     * That is a rule READ OFF THE DATA, not a second column recording what
     * somebody once intended, and the difference matters. A stored `og_auto`
     * boolean can disagree with the two values it describes -- an importer, a
     * hand-edited row, a restored backup or this screen shipped one version
     * behind all write one without the other -- and when it does, the screen
     * says "automatic" about an image that is not following anything, or
     * refuses to update one that is. The rule above cannot be out of step with
     * the values, because it IS the values. It also needs no migration, no
     * validation surface, and nothing from the client that the server would
     * then have to distrust: the client sends two image paths and the server
     * decides, which is the whole of rule 5.
     *
     * Its one blind spot, stated rather than hidden: an operator who
     * deliberately picks the SAME file as the main image is indistinguishable
     * from the automatic case and his choice will follow the main image later.
     * The outcome is that his share image goes on being the main image, which
     * is what he asked for; a flag would get this one case "right" at the price
     * of getting the four above wrong.
     *
     * ── WHY `$was` AND NOT JUST THE NEW MAIN IMAGE ────────────────────────
     *
     * Because the following happens AT THE MOMENT OF THE CHANGE. When the main
     * image moves from A to B, a share image still holding A is one this method
     * put there and re-points to B; a share image holding C is the operator's
     * and is untouched. Comparing only against B would make every auto-filled
     * value look hand-picked the instant the main image changed, and the
     * automatic behaviour would fire exactly once in a product's life.
     *
     * ── AND IT PUBLISHES NOTHING NEW ──────────────────────────────────────
     *
     * Store\ProductController::show() already reads `$override['og_image'] ??
     * $product->image`, so the head of a product with no share image ALREADY
     * carried its main image in og:image, twitter:image and the Product node's
     * `image`. Writing that same path into the column changes the emitted HTML
     * by not one byte -- StorefrontEnglishUnchangedTest is the instrument and
     * it stays green -- while making the value visible, editable and, from now
     * on, correct after the main image is swapped. App\Support\Seo::absolute()
     * turns the site-relative path into the full URL Facebook, Twitter and
     * Google require, exactly as it does for the fallback today.
     */
    private function followMainImageIntoSeo(Product $product, string $was): void
    {
        $now = trim((string) ($product->image ?? ''));
        $seo = is_array($product->seo) ? $product->seo : [];
        $og = trim((string) ($seo['og_image'] ?? ''));

        // Hand-picked: it is neither empty, nor the main image this request is
        // replacing, nor the one it is replacing it with. Left alone.
        if ($og !== '' && $og !== $was && $og !== $now) {
            return;
        }

        if ($now === '') {
            // The main image was removed and the share image was following it.
            // Dropped rather than left pointing at a picture this product no
            // longer has -- and the page then publishes the sitewide default
            // share image, which is what it did before either was set.
            unset($seo['og_image']);
        } else {
            $seo['og_image'] = $now;
        }

        $product->seo = $seo === [] ? null : $seo;
    }

    /**
     * Tags, on the `product_tag` pivot. (Lane SP)
     *
     * FOUND OR CREATED BY SLUG, not by name, so "Gift Set" and "gift set" are
     * one row rather than two fighting over `tags.slug`'s unique index.
     *
     * `sometimes` in the rules and array_key_exists() here: a client that does
     * not send the key leaves the pivot alone. That matters because this
     * endpoint takes a whole product on every save, and Catalog → Products'
     * inline price cell -- a different controller entirely -- must not be able
     * to strip a product's tags by not knowing about them.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyTags(Product $product, array $data): void
    {
        if (! array_key_exists('tags', $data)) {
            return;
        }

        $ids = [];

        foreach ((array) ($data['tags'] ?? []) as $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

            if ($slug === '') {
                continue;
            }

            $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name]);
            $ids[$tag->id] = true;
        }

        $product->tags()->sync(array_keys($ids));
    }

    /* ----------------------------------------------------------- the Set --
     *
     * WHAT IS IN THE BOX, AND WHAT THE BOX COSTS. (Lane SP)
     *
     * Everything below runs only for a product whose type is `set` AFTER this
     * request -- `$product->isSet()`, read from the row that has just been
     * saved, not from what arrived -- so an ordinary product cannot reach one
     * line of it and nothing about saving one changes.
     */

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySet(Product $product, array $data, array $before): ?JsonResponse
    {
        if (! $product->isSet()) {
            /*
             * ── SET → SIMPLE KEEPS THE PIVOT, DELIBERATELY ─────────────────
             *
             * Switching a set back to a simple product does NOT delete its
             * membership rows. Two reasons, and the first is the one that
             * decided it:
             *
             *   IT IS A SELECT. An operator who changes the type by accident,
             *   or to see what happens, must be able to change it back and find
             *   the box as they left it. Emptying a forty-product box on a
             *   dropdown change is destruction with no undo, from a control
             *   that looks like every other control on the page.
             *
             *   THEY ARE INVISIBLE ANYWAY. Every reader of this pivot goes
             *   through Product::isSet() first -- SetContents, SetEagerLoad,
             *   the seven storefront surfaces, Product::toApi(), StockSetRule.
             *   A simple product carrying membership rows behaves in every way
             *   like a simple product.
             *
             * AND HISTORY IS SAFE EITHER WAY, which was checked rather than
             * assumed: an order's contents are `order_items.set_contents`, a
             * JSON snapshot written at checkout, and SetContents::fromOrderItem()
             * reads it and never the pivot. An order sold as a set still prints
             * its box after the product stops being one -- SetCheckoutSnapshotTest
             * rewrites the set and asserts exactly that.
             */
            return null;
        }

        /*
         * ── WAS THE BOX ACTUALLY CHANGED? (Lane SP2) ───────────────────────
         *
         * NOT "did the request carry a set_members key". THE EDITOR POSTS EVERY
         * FIELD IT HOLDS ON EVERY SAVE, so that key is present when the owner
         * fixes a typo in the description -- and a hand-typed set's anchor is
         * re-taken when the box changes. Treating the key as the signal would
         * therefore re-anchor on every save, which silently throws away the
         * accumulated reduction and puts the set back to the full typed price:
         * the feature undoing itself, invisibly, the next time anybody edits
         * anything.
         *
         * So the rows are compared, before and after. Two cheap indexed reads
         * on an admin write path, and they are what makes "I only changed the
         * description" leave the pricing alone.
         */
        $membersBefore = $this->memberSignature($product);

        if (array_key_exists('set_members', $data)) {
            $failure = $this->writeSetMembers($product, (array) $data['set_members']);

            if ($failure !== null) {
                return $failure;
            }
        }

        return $this->applySetPricing(
            $product,
            $data,
            $before,
            $this->memberSignature($product) !== $membersBefore
        );
    }

    /**
     * Why this saved product cannot become a set, or null when it can.
     * (Lane PI-A, item 10)
     *
     * ▲ IT IS INSIDE ANOTHER SET'S BOX. A set cannot hold a set: writeSetMembers()
     *   refuses one at the door, SetPricing::partsTotal() skips one, and
     *   StockSetRule expands one level and no further. Converting a product
     *   that is ALREADY a member walks round that door from the other side,
     *   and re-prices a set nobody touched. Measured before this guard: a
     *   "10% off the box" set holding the booster and a serum fell from
     *   AED 764.10 to AED 89.10 the moment the booster was converted; a
     *   hand-priced one kept its figure but stopped following its members
     *   down, one member counted missing.
     *
     * ▲ IT HAS OPTIONS. A variable product's price lives on its variations and
     *   its own `price` is empty; a set is sold as one thing at one price, so
     *   the options would stop being offered and the set would be priced from
     *   a column that holds nothing. Its sizes are not deleted to make room --
     *   that is a decision about stock and orders, not a type change.
     */
    private function conversionRefusal(Product $product): ?JsonResponse
    {
        $containers = Product::query()
            ->whereIn('id', ProductSetItem::query()
                ->where('member_product_id', $product->id)
                ->where('set_product_id', '!=', $product->id)
                ->select('set_product_id'))
            ->where('type', 'set')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(3)
            ->pluck('name')
            ->all();

        if ($containers !== []) {
            return response()->json([
                'ok' => false,
                'message' => 'This product is inside another set ('.implode(', ', $containers).'), and a set '
                    .'cannot hold a set. Take it out of that set first, then make it a set.',
                'errors' => ['type' => ['Inside another set.']],
            ], 422);
        }

        if (ProductVariant::query()->where('product_id', $product->id)->exists()) {
            return response()->json([
                'ok' => false,
                'message' => 'This product has options (sizes or shades). A set is sold as one thing at one '
                    .'price, so its options would stop being offered. Make a new set instead, or remove the '
                    .'options first.',
                'errors' => ['type' => ['Has options.']],
            ], 422);
        }

        return null;
    }

    /**
     * What is in this box, as one comparable string. (Lane SP2)
     *
     * Product, variant, quantity and position for every row, in id order. It
     * answers the only question applySet() asks of it -- "is this the same box
     * as a moment ago?" -- and it answers it about the four columns a reduction
     * anchored to the parts total depends on. `position` is in because
     * reordering the box is an edit the operator made and re-anchoring on it is
     * harmless; `created_at` is out because rewriting identical rows would
     * otherwise read as a change on every single save, which is the exact
     * defect this method exists to avoid.
     */
    private function memberSignature(Product $product): string
    {
        if (! $product->exists) {
            return '';
        }

        return ProductSetItem::where('set_product_id', $product->id)
            ->orderBy('member_product_id')
            ->orderBy('member_variant_id')
            ->get(['member_product_id', 'member_variant_id', 'quantity', 'position'])
            ->map(static fn ($r) => $r->member_product_id.':'.((int) $r->member_variant_id)
                .':'.$r->quantity.':'.$r->position)
            ->implode('|');
    }

    /**
     * Replace the member list with exactly what was sent, in the order it was
     * sent.
     *
     * DELETE-THEN-INSERT inside the caller's transaction, the shape
     * SetApiController used and for its reasons: a diff would have to answer
     * "is this the same member row?" for a product chosen twice with two
     * different options, which is a question the screen does not make the
     * operator answer. The list is 40 rows at most and is rewritten by one
     * human pressing Save.
     *
     * @param  list<array<string, mixed>>  $members
     */
    private function writeSetMembers(Product $product, array $members): ?JsonResponse
    {
        ProductSetItem::where('set_product_id', $product->id)->delete();

        $seen = [];
        $position = 0;
        $rows = [];

        foreach ($members as $member) {
            $productId = (int) ($member['product_id'] ?? 0);
            $variantId = isset($member['variant_id']) && $member['variant_id'] !== null
                ? (int) $member['variant_id']
                : null;

            /*
             * A SET CANNOT CONTAIN ITSELF, and cannot contain another set. The
             * picker excludes both; this is the door, because the picker is a
             * screen and this is an endpoint. A set inside itself is an
             * infinite box, and App\Support\SetPricing::partsTotal() skips a
             * member that is a set for the same reason one layer down.
             */
            if ($productId === (int) $product->id || $productId < 1) {
                continue;
            }

            $memberRow = Product::query()->select(['id', 'type'])->find($productId);

            if ($memberRow === null || $memberRow->isSet()) {
                continue;
            }

            /*
             * DUPLICATES ARE REFUSED HERE and not by a unique index, for the
             * reason the sets migration gives: MySQL and SQLite both treat
             * NULLs as distinct, so an index on (set, member, variant) would
             * refuse a duplicate naming a variant and accept one that does not.
             * A member wanted twice is a quantity of two.
             */
            $key = $productId.':'.($variantId ?? 0);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            /*
             * A VARIANT MUST BELONG TO THE MEMBER IT IS SENT WITH. `exists:`
             * proves the variant row exists and nothing else -- a request
             * naming product A with product B's variation would store a box
             * whose contents contradict themselves, and it is the variant's
             * price the parts total is built from.
             */
            if ($variantId !== null
                && ! ProductVariant::where('id', $variantId)->where('product_id', $productId)->exists()) {
                $variantId = null;
            }

            $rows[] = [
                'set_product_id' => $product->id,
                'member_product_id' => $productId,
                'member_variant_id' => $variantId,
                'quantity' => max(1, min(99, (int) ($member['quantity'] ?? 1))),
                'position' => $position++,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            ProductSetItem::insert($rows);
        }

        // The pivot just changed, so anything the memo remembers about what
        // this box is worth is a statement about the box before this request.
        SetPricing::forget((int) $product->id);

        return null;
    }

    /**
     * The price, or the RULE that decides it.
     *
     * ── WHY `products.price` IS STILL WRITTEN IN A DISCOUNT MODE ───────────
     *
     * The authoritative answer is derived on every read by
     * Product::effectivePrice(), which is the whole point and what makes a
     * member's price drop reach the set. But `products.price` is also a COLUMN
     * that SQL sorts, filters and bands on -- Catalog → Products' price range,
     * the shop's "price, low to high", App\Support\EffectivePrice's buckets --
     * and none of those can call a PHP method.
     *
     * So the column is kept as a CACHE of the derived figure, refreshed here
     * after the members are written. It is exact at save time and CAN LAG: a
     * member repriced elsewhere moves the real price immediately and the cached
     * one at the set's next save. That is a stated limitation rather than an
     * oversight -- what a shopper is shown and charged is always the derived
     * figure; what can be a release behind is a sort order.
     *
     * @param  array<string, mixed>  $data
     */
    private function applySetPricing(Product $product, array $data, array $before, bool $membersChanged): ?JsonResponse
    {
        if (! array_key_exists('price_mode', $data)) {
            /*
             * ▲ AND THE ANCHOR IS STILL RE-TAKEN WHEN THE BOX CHANGED.
             *
             * A client that does not send `price_mode` -- anything older than
             * this screen, or a script -- can still rewrite the membership, and
             * a box whose contents changed under an anchor taken against the
             * OLD contents would read the difference as a price reduction and
             * mark the set down by the whole value of a product that was simply
             * removed. So the one case that cannot be left to the branch below
             * is handled here. (Lane SP2)
             */
            if ($membersChanged && SetPricing::mode($product) === SetPricing::MODE_FIXED) {
                $this->anchorFixedPrice($product, true);
                $product->save();
            }

            return null;
        }

        // One of its own options or the default, checked again after Rule::in.
        $mode = in_array($data['price_mode'], SetPricing::MODES, true)
            ? (string) $data['price_mode']
            : SetPricing::MODE_FIXED;

        if ($mode === SetPricing::MODE_FIXED) {
            $product->set_price_mode = SetPricing::MODE_FIXED;
            $product->set_discount = null;

            /*
             * ── WHEN A HAND-TYPED PRICE IS RE-ANCHORED (Lane SP2) ──────────
             *
             * Five triggers, and the reasoning for each is in
             * anchorFixedPrice(). What matters HERE is that saving the page
             * WITHOUT one of them must not re-anchor, because re-anchoring is
             * how an accumulated reduction is thrown away.
             */
            $this->anchorFixedPrice($product, $membersChanged
                || (bool) ($data['reanchor'] ?? false)
                || $before['mode'] !== SetPricing::MODE_FIXED
                || $before['basis'] === null
                || ($before['price'] === null ? null : (int) $before['price']) !== ($product->price === null ? null : (int) $product->price)
                || ($before['sale_price'] === null ? null : (int) $before['sale_price']) !== ($product->sale_price === null ? null : (int) $product->sale_price));

            $product->save();

            return null;
        }

        /*
         * ▲ A DISCOUNT OFF NOTHING IS A FREE SET. A box with no products in it
         *   has a parts total of zero, so "10% off the total" prices it at
         *   AED 0.00 -- published, buyable, and free. Refused out loud, with
         *   the sentence saying what to do, rather than saved and discovered by
         *   a customer.
         */
        SetPricing::forget((int) $product->id);
        $product->unsetRelation('setItems');

        if (SetPricing::partsTotal($product) < 1) {
            /*
             * ▲ THROWN, NOT RETURNED, AND THAT IS NOT A STYLE CHOICE.
             *
             * Every other refusal in apply() happens BEFORE $product->save(),
             * so returning a JsonResponse from inside DB::transaction() is
             * harmless: nothing has been written. This one is downstream of the
             * save -- it has to be, because the parts total is a fact about
             * membership rows that were written a statement ago -- and
             * `return` DOES NOT ROLL BACK a Laravel transaction. Returning here
             * would commit a half-made set and then tell the operator it had
             * refused. ValidationException unwinds it and answers 422 with the
             * same shape the validator does.
             */
            throw ValidationException::withMessages([
                'set_members' => ['This set is priced from what is in the box, and the box is empty. '
                    .'Add the products first, or choose "A price I type".'],
            ]);
        }

        $product->set_price_mode = $mode;

        if ($mode === SetPricing::MODE_PERCENT) {
            /*
             * BASIS POINTS, BY A ROUNDED MULTIPLICATION OF THE STRING. "12.5"
             * becomes 1250. Never `(float) $v * 100` assigned to an int: 12.5
             * is exact in binary and 10.1 is not, and the cast that truncates
             * it is the defect this repository has already paid for on the bulk
             * price action.
             */
            $bp = (int) round(((float) ($data['discount_percent'] ?? '0')) * 100);
            $product->set_discount = max(0, min(SetPricing::FULL_BP, $bp));
        } else {
            // fils() answers null for a null input; the coalesce is what keeps
            // max() from being handed one.
            $product->set_discount = max(0, (int) (MajorUnits::fils((string) ($data['discount_amount'] ?? '0')) ?? 0));
        }

        /*
         * NO SALE PRICE UNDER A RULE. The discount IS the markdown, and a
         * second one underneath it would be two answers to what the set costs
         * -- which is the defect App\Support\SetPricing exists to prevent.
         */
        $product->sale_price = null;

        SetPricing::forget((int) $product->id);
        $product->unsetRelation('setItems');
        $product->price = SetPricing::derived($product) ?? 0;
        $product->save();

        return null;
    }

    /**
     * RE-TAKE THE ANCHOR A HAND-TYPED SET PRICE IS MEASURED FROM. (Lane SP2)
     *
     * `products.set_price_basis` is the parts total at the moment the operator
     * last typed this set's price. App\Support\SetPricing::adjustment() takes
     * the difference between it and today's total off both of his figures, so
     * the anchor is the whole of "reduce it by how much I reduced the product".
     *
     * ── THE SEVEN THINGS THAT CAN HAPPEN TO A MEMBER, AND THE ANSWER ───────
     *
     *   A MEMBER IS REPRICED DOWN — no save happens on the set, the anchor is
     *   untouched, the parts total falls and the set falls with it by the same
     *   fils. This is the feature.
     *
     *   A MEMBER GOES ON SALE — the parts total is sale-aware, so the set
     *   follows it down for exactly as long as the sale runs.
     *
     *   THAT MEMBER'S SALE ENDS — the total climbs back to the anchor, the
     *   difference returns to zero and the set returns to the typed price. The
     *   reduction is a function of today, never of the lowest price ever seen.
     *
     *   A MEMBER IS REMOVED FROM THE BOX — a membership change, so this
     *   re-anchors. The alternative is a set that silently drops by the whole
     *   price of a product the operator took OUT of it, which is not a price
     *   reduction and could empty a box down to the floor. His typed price
     *   stands and the panel shows the new total beside it so he can decide.
     *
     *   A MEMBER IS ADDED — the same, in the other direction. Without the
     *   re-anchor the box would simply be worth more than its anchor, the
     *   difference would be negative, and max(0, ...) would hold the set at the
     *   typed price for ever after; re-anchoring keeps the arithmetic honest
     *   from today.
     *
     *   A MEMBER IS DELETED FROM THE CATALOGUE — nobody saves the set, so no
     *   re-anchor can happen. Handled where it has to be, on the READ side:
     *   SetPricing::tally() counts membership rows whose product is gone and
     *   adjustment() refuses to move a set that has one. See its note.
     *
     *   THE OPERATOR RE-TYPES THE PRICE — a changed `price` or `sale_price` is
     *   a trigger. He was looking at today's total when he typed it, so today's
     *   total is what it means.
     *
     * And the two that are not about members at all: switching INTO `fixed`
     * from a discount mode (he is now typing a number, so it needs a meaning),
     * and the panel's "Start again from today's total" button, which is the
     * only way to re-anchor without retyping the same figure.
     *
     * ── WHY A NULL ANCHOR IS A TRIGGER ────────────────────────────────────
     *
     * `$before['basis'] === null` is every set that existed before this shipped.
     * The first time one is saved from this screen it takes its anchor, and
     * because the anchor is today's total the reduction at that instant is
     * ZERO — the price does not move, on the shop or on the screen. Applying
     * the package changes no price anywhere; saving a set starts it following
     * its members from that point on, with the panel printing the anchor it
     * just took.
     */
    private function anchorFixedPrice(Product $product, bool $reanchor): void
    {
        if (! $reanchor) {
            return;
        }

        /*
         * The membership rows were rewritten one statement ago and the relation
         * on this instance is whatever was loaded before that, so both the memo
         * and the relation are statements about the box as it used to be.
         */
        SetPricing::forget((int) $product->id);
        $product->unsetRelation('setItems');

        $product->set_price_basis = SetPricing::partsTotal($product);

        // Read again by payload() once this is saved, and the write above is
        // what it must see.
        SetPricing::forget((int) $product->id);
        $product->unsetRelation('setItems');
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
