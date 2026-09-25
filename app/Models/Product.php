<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use App\Support\Url;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{

    use HasTranslations;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * Every write to this table drops App\Services\VariantPricing's snapshot.
     *
     * That class memoises the price range of EVERY variable, un-priced parent
     * in one grouped query and then trusts it. Inside a web request that is
     * right — the catalogue cannot change mid-render — and outside one it is
     * not: a Product inserted after the first lookup was absent from the
     * snapshot, range() answered null, and effectivePrice() therefore answered
     * **0 fils** for it until the container was thrown away. In `artisan` it
     * never is: `scoped` is reset by the queue worker and by Octane, and by
     * nothing at all in a console process.
     *
     * So the signal is taken where the snapshot's inputs actually change rather
     * than at a seam somebody has to remember. invalidate() clears the snapshot the
     * container is already holding and builds nothing when there is none, so an
     * import saving ten thousand rows before anything asks for a price pays ten
     * thousand array lookups, and a page render, which saves no product, pays
     * nothing and reloads nothing.
     *
     * `saved` covers insert and update both; `deleted` covers the soft delete
     * this model uses (SoftDeletes fires `deleted`, not only `forceDeleted`).
     * A raw DB::table() write is not covered and cannot be — see
     * VariantPricing::invalidate() for the boundary.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => \App\Services\VariantPricing::invalidate());
        static::deleted(static fn () => \App\Services\VariantPricing::invalidate());
    }

    /**
     * The columns that may carry an Arabic version, and NOTHING ELSE.
     *
     * An allowlist rather than a denylist, because the interesting question is
     * what a translation must never touch and the answer has to be the default:
     *
     *   sku            an identifier the supplier and the warehouse share. A
     *                  translated SKU is a SKU nobody can look up.
     *   slug           one slug per product, in both languages, with the
     *                  language carried by the /ar prefix. See
     *                  App\Support\HasTranslations for the argument.
     *   price, stock   numbers. AED 199 stays AED 199 on an Arabic page.
     *   wc_id, total_sales, seo, images — machine fields.
     *
     * `description` is on the list and is deliberately NOT sent to a machine:
     * it carries HTML, and every machine-translation format option mangles
     * either the markup or the words. See
     * MachineTranslationRunner::isMachineSafe().
     *
     * `ingredients` and `how_to_use` are on it for the same reason `description`
     * is, and were missing for one round. They are prose, they are each their
     * own TAB on the product page — Store\ProductController::tabs() builds
     * Description, Ingredients and How to use from these three columns — and an
     * Arabic shopper with them off the list reads two of the three tabs in
     * English permanently, with nothing on the progress screen saying so,
     * because a field that is not translatable is not counted as work.
     *
     * They carry HTML like `description` does, so isMachineSafe() declines
     * them and they are typed rather than machine-translated. That is the
     * honest state, not a gap: an INCI list run through a translation engine is
     * a safety claim in a language nobody at the shop reads.
     *
     * @var list<string>
     */
    protected array $translatable = ['name', 'short_description', 'description', 'ingredients', 'how_to_use'];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'image_alts' => 'array',
            'seo' => 'array',
            'meta_feed' => 'array',
            'custom_tabs' => 'array',
            'featured' => 'bool',
            'is_visible' => 'bool',
            'manage_stock' => 'bool',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            'published_at' => 'datetime',
            'price' => 'int',
            'sale_price' => 'int',
            'rating' => 'float',
        ];
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * The attribute values this product offers.
     *
     * Named explicitly for the same reason as ProductVariant::attributeValues():
     * the convention gives `attribute_value_product` and the schema creates
     * `product_attribute_value`. Same latent 500, one table along.
     */
    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_attribute_value');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    /**
     * The public API projection.
     *
     * Named explicitly rather than returning the model, because the products
     * table carries fields the storefront has no business publishing: wc_id
     * and sku are internal identity, total_sales is commercial, stock is an
     * exact count competitors would read, and seo/meta_feed/custom_tabs are
     * admin blobs. An allowlist also means a column added later is private
     * until someone decides otherwise.
     *
     * Api\ProductController has called this since before 2.60.36, but the
     * method never existed: its status filter matched no rows, so the closure
     * never ran and the missing method never surfaced. Fixing the filter in
     * 2.60.105 turned that into a 500 on every /api/products request.
     *
     * `sale_price` IS THE ADVERTISED SALE, NOT THE STORED COLUMN. See
     * advertisedSalePrice() below. That change kept the shape — the same eleven
     * keys, pinned by ApiProductIndexCostTest — and only stopped the lying.
     *
     * `compare_at_price` IS THE TWELFTH KEY AND THE ONLY ONE EVER ADDED. It is
     * additive: no key already published changed its meaning or its value, and
     * the pin in ApiProductIndexCostTest was advanced deliberately, in the same
     * commit, for that one key. The argument for a third key rather than a new
     * meaning for `price` is written out where it is returned.
     */
    public function toApi(): array
    {
        return [
            'slug'              => $this->slug,
            'name'              => $this->name,
            'brand'             => $this->relationLoaded('brand') ? $this->brand?->name : null,
            /*
             * THE COLUMN, OR THE "FROM" PRICE WHEN THERE IS NO COLUMN.
             *
             * `price` is NULL on a variable parent — WooCommerce keeps the
             * figures on the variations — so this key published `null` for
             * every variable product while the tile beside it printed
             * "AED 120 – AED 190". Two public surfaces, two different answers
             * to what a product costs, which is the disagreement this file has
             * already been fixed for twice (see advertisedSalePrice() below).
             *
             * effectivePrice() now derives the low end of that range, so the
             * feed quotes the same "from" figure the tile, the price sort and
             * the listing JSON-LD quote.
             *
             * `?:` NOT `??`: effectivePrice() answers the int 0 when it has
             * nothing to derive from either — a parent whose variations are all
             * un-priced — and publishing `0` there would put back the AED 0
             * this whole change removes. Nothing-known stays null, exactly as
             * it reads today.
             *
             * ▲ ON /api/products THIS STILL ANSWERS null, and deliberately so.
             * Api\ProductController::INDEX_COLUMNS does not select `type`, and
             * App\Services\VariantPricing declines to derive a price for a row
             * whose shape it cannot confirm rather than guessing from a
             * narrowed SELECT — the same fail-closed rule advertisedSalePrice()
             * applies to the sale window. Adding `type` to that list is what
             * turns this on for the endpoint; that file is another lane's.
             */
            'price'             => $this->price ?? ($this->effectivePrice() ?: null),
            'sale_price'        => $this->advertisedSalePrice(),
            /*
             * THE FIGURE TO STRIKE THROUGH, OR null WHEN THERE IS NOTHING TO
             * STRIKE — and the one key on this feed that means the same thing
             * for every kind of product.
             *
             * ── WHY A THIRD KEY AND NOT A NEW MEANING FOR AN OLD ONE ────────
             *
             * On a SIMPLE product `price` is the regular price and `sale_price`
             * is what is charged while a markdown runs, so the pair carries the
             * whole story. On a VARIABLE one it cannot: WooCommerce keeps the
             * money on the variations, `products.sale_price` is NULL on the
             * parent (ProductImporter refuses a row that carries one without a
             * regular price), and advertisedSalePrice() therefore answers null
             * for every marked-down variable product in the catalogue. `price`
             * meanwhile publishes the CHARGED from-price, which is right and is
             * pinned — ApiProductTypeNotPublishedTest exists because this feed
             * once said `null` where the tile said "AED 120 – AED 190".
             *
             * So a variable product on sale published AED 90 and nothing else:
             * not a lie, and silent about a quarter off. A consumer could not
             * draw the badge the shop's own tile draws.
             *
             * The two ways to say it are a CHOICE OF CONTRACT, not a detail:
             *
             *   A. move `price` to the compare-at for variable rows and publish
             *      the charged figure as `sale_price`, making the pair mean the
             *      same thing for every product. Every consumer that already
             *      computes `sale_price ?? price` renders the markdown with no
             *      change at all — and every consumer that reads `price` alone
             *      silently starts quoting AED 120 where it quoted AED 90.
             *   B. leave both keys exactly as they are and add this one.
             *      Nothing already published moves; a consumer that wants to
             *      draw the markdown reads one new key.
             *
             * B is what ships, because A changes a published number on an
             * unauthenticated feed and that is the owner's call rather than
             * this lane's. B does not foreclose it: under A this key would
             * equal `price` on every row and still be correct.
             *
             * ── WHAT A CONSUMER DOES WITH IT ────────────────────────────────
             *
             *     charged = sale_price ?? price          (unchanged, and this
             *                                             is what the checkout
             *                                             will take)
             *     was     = compare_at_price             (strike it; null means
             *                                             draw no strike)
             *     percent = 1 - charged / compare_at_price
             *
             * One rule for both kinds of product. `compare_at_price !== null`
             * is also the on-sale test that works for both, which `sale_price
             * !== null` never did.
             *
             * ── NULL RATHER THAN THE REGULAR PRICE WHEN NOTHING IS OFF ──────
             *
             * isOnSale() first, so this is the figure a strikethrough may use
             * and not merely "the regular price". Publishing a compare-at equal
             * to the charged price invites exactly the strikethrough that reads
             * as a lie, and this endpoint already refuses to advertise a sale it
             * cannot vouch for (see advertisedSalePrice()). Both methods fail
             * closed on a narrowed SELECT — compareAtPrice() answers null
             * without `price`, isOnSale() answers false on a null compare-at —
             * so a row whose shape cannot be confirmed publishes null here
             * rather than a guess.
             *
             * NO SECOND QUERY. compareAtPrice() and isOnSale() read the same
             * grouped row App\Services\VariantPricing already holds for the
             * from-price, so this key costs nothing on a hundred-row page. That
             * is measured in ApiCompareAtPriceTest, not asserted.
             */
            'compare_at_price'  => $this->isOnSale() ? $this->compareAtPrice() : null,
            'image'             => $this->image,
            'images'            => $this->images,
            'rating'            => $this->rating,
            'review_count'      => $this->review_count,
            'stock_status'      => $this->stock_status,
            'short_description' => $this->short_description,
        ];
    }

    /**
     * On the storefront right now.
     *
     * The third condition is new: a product may be `publish` and visible and
     * still be scheduled for a date that has not arrived. See
     * App\Support\ProductVisibility for why that is a comparison against now()
     * rather than a cron job — this host has no scheduler, and
     * effectivePrice() above already sets the precedent by honouring
     * sale_starts_at / sale_ends_at exactly this way.
     *
     * published_at NULL means "not scheduled", which is every row that existed
     * before the column did, so nothing already in the catalogue changes.
     */
    public function scopeVisible($query)
    {
        $query->where('status', 'publish')->where('is_visible', true);

        return \App\Support\ProductVisibility::schedule($query);
    }

    /**
     * Alt text for one image of this product.
     *
     * WHY THERE IS A STORED FIELD AT ALL, rather than deriving everything.
     *
     * A derived alt — "Anua Heartleaf Toner, Texture" — is a fine default and a
     * poor ceiling. The whole reason to put real <img> tags on the product page
     * is so Google Images can index the photographs, and what it indexes is the
     * alt: five shots of one product that all say the same sentence are five
     * results competing with each other for the same query. The shots differ in
     * ways only the person who chose them knows — one is the texture on a hand,
     * one is the ingredient list on the back of the box, one is the product in
     * use — and none of that is recoverable from the filename or the position.
     * It is also the accessibility text, and "View 3" tells a screen-reader user
     * nothing.
     *
     * WHY IT IS A SEPARATE COLUMN KEYED BY URL, and not a change to `images`.
     *
     * `images` is a flat list of URL strings, and three things already read it
     * that way: Store\ProductController::gallery(), which merges it with
     * `image`; toApi() above, which publishes it; and the WooCommerce importer,
     * which writes it. Turning its entries into objects would break all three
     * at once, for a field only the page needs. A map keyed by URL costs those
     * three nothing, survives reordering for free — the key is the image, not
     * its position — and covers the main image, which is not in `images` at all.
     *
     * The fallback is the derived sentence, so a caller never has to decide:
     * ask for the alt, get the best one available.
     *
     * AND THE DERIVED HALF IS App\Support\ProductTitle's, not a second copy.
     * That helper exists because this catalogue is a WooCommerce import whose
     * product names are not written to one rule: some already carry the brand
     * ("Anua Azelaic Acid 10 Serum") and some do not ("1025 Dokdo Toner", by
     * Round Lab). Joining brand and name naively produces "Anua — Anua
     * Heartleaf…", which is precisely the defect ProductTitle was written to
     * stop. So the stored value is this method's contribution and the computed
     * one is delegated — one rule for how a product is named, in one file.
     *
     * $index and $total are the shot's position in the gallery, which is what
     * ProductTitle::alt() uses to distinguish the second photograph from the
     * first. Callers that do not know them pass nothing and get the base.
     */
    public function altFor(?string $url, int $index = 0, int $total = 1): string
    {
        $map = is_array($this->image_alts) ? $this->image_alts : [];
        $stored = trim((string) ($map[(string) $url] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        // t(), not the column: alt text is read aloud to a shopper and indexed
        // by a search engine, so it is content and not an identifier. On
        // English t() IS the column, so nothing about the English page moves.
        return \App\Support\ProductTitle::alt($this->brand?->t('name'), $this->t('name'), $index, $total);
    }

    /**
     * Waiting for a publish date that has not arrived.
     *
     * The editor's fourth status. It is not a value in `products.status` —
     * that column is publish | draft | private and inventing a fourth string
     * is the defect that took this catalogue off the storefront once already,
     * because scopeVisible(), the sitemap and every category page filter on
     * the literal 'publish'. A scheduled product is a PUBLISHED product with a
     * future date, so the day it comes due every one of those filters is
     * already correct without anything being rewritten.
     */
    public function isScheduled(): bool
    {
        return $this->status === 'publish'
            && $this->published_at !== null
            && now()->lt($this->published_at);
    }

    /**
     * What the editor's status control should show: the stored status, or
     * 'scheduled' when a future date makes that the truer word.
     */
    public function editorStatus(): string
    {
        return $this->isScheduled() ? 'scheduled' : (string) $this->status;
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_status', 'instock');
    }

    /**
     * Effective unit price in fils, honouring a scheduled sale window.
     *
     * ── THE ZERO THIS USED TO ANSWER, AND WHO HEARD IT ──────────────────────
     *
     * This method used to end `return (int) $this->price`. A WooCommerce
     * VARIABLE product keeps its money on its variations and `products.price`
     * is genuinely NULL on the parent row, and `(int) null === 0` — so every
     * variable product in the catalogue answered **0 fils**, and that zero was
     * not an internal detail. It was published:
     *
     *   - App\Support\CollectionSchema put `"price": "0.00"` in the ItemList
     *     JSON-LD of every /shop, category and brand listing page, so GOOGLE
     *     was told the product was free — while the product page beside it
     *     published a correct AggregateOffer from the same variations. The two
     *     documents contradicted each other and the listing one was wrong.
     *   - App\Services\MarketingPixels reported a ViewContent value of 0.
     *   - The homepage routine total and Related products summed it as nothing.
     *
     * ── WHAT IT ANSWERS NOW, AND WHY THE LOW END ────────────────────────────
     *
     * The cheapest price its variations actually charge — the "from" price.
     * A range has to collapse to ONE number for a sort, a bucket, a pixel or an
     * Offer, and the low end is the number WooCommerce itself shows ("From AED
     * 120"), the number Seo::aggregateOffer() already publishes as `lowPrice`,
     * and the number App\Support\EffectivePrice now orders and buckets on in
     * SQL. The tile and the product-page headline still print the full RANGE,
     * because they have room to; everything that needs a scalar gets the same
     * end of that range rather than a second opinion.
     *
     * ── IT IS NOT A QUERY PER PRODUCT ───────────────────────────────────────
     *
     * The answer comes from App\Services\VariantPricing, which is a `scoped`
     * binding holding a per-request memo filled by ONE grouped query over
     * `product_variants` — the same object, the same memo and the same query
     * the tiles already resolve, so a 24-tile grid asks once whether it is the
     * tile or this method that asks first. Resolving a fresh instance here, or
     * doing the obvious `$this->variants()->min(...)`, would have been an N+1
     * across that grid and a StorefrontQueryBudgetTest failure.
     *
     * range() also declines to answer for a product loaded by a narrowed SELECT
     * that did not fetch `price`, which is a real shape on the public API — so
     * such a row falls through to 0 exactly as it does today rather than
     * silently acquiring a price the query never asked about.
     *
     * ── NOTHING WITH A PRICE OF ITS OWN CHANGES ─────────────────────────────
     *
     * ownPrice() below is this method's previous body, verbatim, returning null
     * in the one case it used to cast to zero: a NULL `price` column. Every
     * product that has a price — which is every simple product and every
     * variable parent WooCommerce did put a figure on — takes exactly the path
     * it took before, sale window included. The only rows whose answer moves
     * are the rows that were answering 0.
     */
    public function effectivePrice(): int
    {
        $own = $this->ownPrice();

        if ($own !== null) {
            return $own;
        }

        $range = app(\App\Services\VariantPricing::class)->range($this);

        // Still 0 when nothing under it is priced either. MIN() over an
        // all-NULL set is NULL and range() answers null for that parent, which
        // is the honest answer -- un-priced data is not a free product, and
        // this is the one case that behaves exactly as it did before.
        return $range === null ? 0 : $range[0];
    }

    /**
     * The row's OWN effective price, or null when the column it lands on is
     * NULL — i.e. when this product carries no price of its own at all.
     *
     * The three branches are effectivePrice()'s previous body unchanged; only
     * the `(int) $this->price` casts have become an explicit null check, so
     * that "no price" and "a price of zero" stop being the same answer. They
     * never were the same thing and the cast is what conflated them.
     */
    private function ownPrice(): ?int
    {
        if ($this->sale_price === null) {
            return $this->price === null ? null : (int) $this->price;
        }

        $now = now();
        if ($this->sale_starts_at && $now->lt($this->sale_starts_at)) {
            return $this->price === null ? null : (int) $this->price;
        }
        if ($this->sale_ends_at && $now->gt($this->sale_ends_at)) {
            return $this->price === null ? null : (int) $this->price;
        }

        return (int) $this->sale_price;
    }

    /**
     * The sale price to ADVERTISE, or null when there is no live sale.
     *
     * WHY THIS IS NOT `$this->sale_price`. That column is a stored markdown
     * with a schedule beside it, and toApi() published it raw. So the public
     * feed quoted a sale that ended last month, and one that opens next week,
     * while Api\CheckoutController — the write half of the same public surface
     * — charged effectivePrice() and got it right. Advertised price and
     * charged price disagreed on an unauthenticated endpoint. The rule here is
     * simply: publish what will be charged.
     *
     * A sale is worth advertising only when it is a reduction that is live
     * NOW, so the answer is effectivePrice() whenever that differs from
     * `price`, and null otherwise. `sale_price` equal to `price` is not a sale
     * and gets no strikethrough; a `sale_price` ABOVE `price` is not one
     * either, but effectivePrice() charges it, so it is quoted rather than
     * hidden — the endpoint's job is to stop the two figures diverging, not to
     * second-guess a badly entered price.
     *
     * AND IT REFUSES TO GUESS WHEN THE WINDOW WAS NOT SELECTED.
     *
     * Api\ProductController::index hydrates an explicit column list, because
     * the endpoint is public and unthrottled. If `sale_starts_at` and
     * `sale_ends_at` are not in it, Eloquent answers null for both — and a
     * window check reads two nulls as "no start bound, no end bound", i.e. a
     * sale that is always on. That FAILS OPEN: the expired sale keeps being
     * advertised, on the exact endpoint the defect lives on, and silently,
     * because /api/products/{slug} selects whole rows and would still test
     * green. It is the trap CouponService::withRules() documents for coupon
     * rules, one model along.
     *
     * So an absent window is treated as "no sale I can vouch for", never as an
     * unbounded one. The column list IS widened (the window is selected there
     * now, and not published), and this guard is the second half: it makes
     * narrowing that list again a loud failure rather than a quiet lie.
     */
    public function advertisedSalePrice(): ?int
    {
        if ($this->sale_price === null) {
            return null;
        }

        $attributes = $this->getAttributes();

        if (! array_key_exists('sale_starts_at', $attributes)
            || ! array_key_exists('sale_ends_at', $attributes)) {
            return null;
        }

        $effective = $this->effectivePrice();

        return $effective === (int) $this->price ? null : $effective;
    }

    /**
     * Must an option be chosen before this can go in a basket?
     *
     * ── WHAT THIS IS FOR, AND IT IS MONEY ───────────────────────────────────
     *
     * A WooCommerce variable product is bought by its VARIATION, never by the
     * parent row: `products.price` is NULL on the parent and every real figure
     * lives in `product_variants`. effectivePrice() ends `return (int)
     * $this->price`, and `(int) null === 0`.
     *
     * So a variable parent reaching CartService::add() with no variant was
     * priced at ZERO and the basket accepted it. Three ways in, all live:
     * components/product-grid.blade.php drew an Add to cart button on every
     * tile including those; the checkout's "you were looking at" strip did the
     * same; and /api/cart/add takes a product_id with an optional variant_id
     * and nothing said the pair was required. A shopper could check out for
     * AED 0 and the shop would take the order.
     *
     * ── AND IT DOES NOT GUESS WHEN `type` IS NOT THERE ──────────────────────
     *
     * Half this application hydrates explicit column lists, because the
     * endpoints are public — CartController::LINE_COLUMNS, five different
     * CARD_COLUMNS, Api\ProductController's own. A model loaded without `type`
     * answers null for it, and `null !== 'variable'` is TRUE, which would
     * quietly mean "no option needed" for every product in a query that had
     * simply not asked. That fails OPEN, on the one question in this class
     * where failing open means selling something for nothing. It is the trap
     * advertisedSalePrice() documents one method along, about its sale window.
     *
     * ▲ THE FIRST VERSION OF THIS ANSWERED `true` FOR AN ABSENT COLUMN AND THAT
     * WAS WRONG, measured rather than argued: eight existing tests went red and
     * the reason was not a narrowed SELECT at all. `Product::create([...])`
     * without a `type` key leaves the attribute absent on the returned model
     * too — it is a column the row never set, not a column the query declined
     * to fetch — and the two are indistinguishable from in here. A blanket
     * `true` therefore refused ordinary simple products, which is a shop that
     * cannot sell anything: the safe-looking direction was its own outage.
     *
     * So an absent column is not guessed in either direction. It is LOOKED UP,
     * in the only rows that can answer it: a product is sold by options when it
     * HAS options. That costs one `exists()` — and only on the path that did
     * not fetch the column, which is no storefront path today (the case below
     * pins that every list reaching a tile or the cart selects `type`). A
     * product with variations is refused however it was loaded, and a product
     * with none is buyable however it was loaded. Nothing is assumed.
     */
    public function requiresVariant(): bool
    {
        $attributes = $this->getAttributes();

        if (array_key_exists('type', $attributes)) {
            return $attributes['type'] === 'variable';
        }

        // Already loaded (the product page eager-loads them) costs nothing;
        // otherwise one exists(), on a path no tile takes.
        return $this->relationLoaded('variants')
            ? $this->variants->isNotEmpty()
            : $this->variants()->exists();
    }

    /**
     * Can a tile put this in the basket on its own, with nothing to choose?
     *
     * ONE EXPRESSION, AND EVERY TILE READS IT. components/product-card.blade.php
     * had this inline as `$canAdd` and was right; components/product-grid.blade.php
     * did not have it at all and drew an Add to cart button on everything. Two
     * copies of a rule is how one of them ends up wrong, and the one that was
     * wrong is the one that sold a variable product for nothing.
     *
     * The stock half is the same test both templates already made. The variant
     * half is requiresVariant() above, which is also what CartService::add()
     * refuses on — so the button a shopper sees and the door the request goes
     * through cannot disagree.
     */
    public function isDirectlyBuyable(): bool
    {
        return $this->stock_status === 'instock' && ! $this->requiresVariant();
    }

    /**
     * The price to STRIKE THROUGH — what this product costs with no sale
     * running — or null when there is no such figure to vouch for.
     *
     * ── A MARKDOWN ON A VARIABLE PRODUCT WAS INVISIBLE TO THE WHOLE SHOP ────
     *
     * isOnSale() below was `effectivePrice() < (int) $this->price`, and
     * `products.price` is NULL on a variable parent — WooCommerce keeps the
     * money on the variations, which is the fact effectivePrice() above is
     * about. `(int) null` is 0, nothing is cheaper than nothing, so isOnSale()
     * answered FALSE for every variable product in the catalogue, whatever its
     * variations were charging.
     *
     * A variable product's markdown has exactly one place it can live and it is
     * not that column: `product_variants.sale_price`, one figure per variation,
     * scheduled once by the PARENT's `sale_starts_at`/`sale_ends_at` (the
     * variations table has no date columns — ProductVariant::effectivePrice()
     * and Import\Entities\VariationImporter::reportSaleWindow() both say so).
     * The parent cannot even carry one: ProductImporter REJECTS a row with a
     * `sale_price` and an empty `regular_price`.
     *
     * So the markdown was real, effectivePrice() honoured it — the tile's
     * "from" figure and the product page headline both dropped — and NOTHING
     * ELSE did. No Sale badge, no strikethrough, no percentage, and no entry in
     * the "On sale" listing, which is the listing the owner points at the stock
     * they most want to move. Every surface agreed, and every surface was
     * silent about a price that had just changed.
     *
     * ── "A PARENT HAS NO COMPARE-AT PRICE" IS TRUE OF THE ROW, NOT THE PRODUCT
     *
     * The previous round left this alone on that reasoning and said so. The row
     * really has nothing; the PRODUCT has a compare-at one level down, in the
     * `price` column of each variation, which is the column a variation's
     * `sale_price` is a markdown from. VariantPricing::regularLow() returns the
     * lowest of them — the from-price this product advertised the day before
     * the sale started — and that is the figure a saving is a saving against.
     * Its docblock carries the worked example for why it is MIN(regular) and
     * not "the regular price of whichever option is cheapest now".
     *
     * ── NULL, NEVER ZERO ────────────────────────────────────────────────────
     *
     * Three rows answer null and all three must: a product loaded by a narrowed
     * SELECT that never fetched `price` (Api\ProductController::INDEX_COLUMNS
     * is the standing example), a variable parent whose variations carry no
     * regular price at all, and a simple product whose `price` column is NULL.
     * isOnSale() reads null as "no compare-at I can vouch for" and answers
     * false, which is what all three did before — the same fail-closed rule
     * advertisedSalePrice() applies to an unselected sale window.
     *
     * NOTHING WITH A PRICE OF ITS OWN CHANGES. `$this->price` is returned
     * unconditionally when it is there, so every simple product and every
     * variable parent WooCommerce did put a figure on takes exactly the path it
     * took before, and `(int) $this->price` and this method are the same
     * expression for them.
     */
    public function compareAtPrice(): ?int
    {
        if ($this->price !== null) {
            return (int) $this->price;
        }

        return app(\App\Services\VariantPricing::class)->regularLow($this);
    }

    /**
     * Is this product cheaper right now than it normally is?
     *
     * `compareAtPrice()` rather than `(int) $this->price`, which is the whole
     * of the variable-product fix; read its note. The null test is not a
     * nicety — without it a product with no compare-at would ask
     * `effectivePrice() < 0`, which is the false-by-accident this method
     * answered before, and a product marked down to free would start answering
     * true against a figure that does not exist.
     *
     * App\Support\EffectivePrice::whereOnSale() is this method in SQL and
     * mirrors it term for term, the price facet against the badge, because the
     * two disagreeing is the defect this area of the codebase has already been
     * repaired for three times.
     */
    public function isOnSale(): bool
    {
        $compare = $this->compareAtPrice();

        return $compare !== null && $this->effectivePrice() < $compare;
    }

    /**
     * How much off, as a whole percent — of the compare-at price, not of the
     * `price` column, so the badge on a variable product states the saving
     * against the figure its tile used to print.
     */
    public function discountPercent(): int
    {
        $compare = $this->compareAtPrice();

        if (! $this->isOnSale() || ! $compare) {
            return 0;
        }

        return (int) round((1 - $this->effectivePrice() / $compare) * 100);
    }

    /** URL contract U-01: /product/{slug}/ with a trailing slash. */
    public function url(): string
    {
        return Url::to('/product/' . $this->slug . '/');
    }

    /**
     * URL contract U-02: legacy add-to-cart links in the wild use the WooCommerce
     * numeric ID, so wc_id is the public identifier wherever one exists.
     */
    public function publicId(): int
    {
        return (int) ($this->wc_id ?: $this->id);
    }

}
