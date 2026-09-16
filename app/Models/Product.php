<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Url;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{

    use SoftDeletes;

    protected $guarded = [];

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
     * advertisedSalePrice() below. The shape is unchanged — the same eleven
     * keys, pinned by ApiProductIndexCostTest — only the lying stopped.
     */
    public function toApi(): array
    {
        return [
            'slug'              => $this->slug,
            'name'              => $this->name,
            'brand'             => $this->relationLoaded('brand') ? $this->brand?->name : null,
            'price'             => $this->price,
            'sale_price'        => $this->advertisedSalePrice(),
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

        return \App\Support\ProductTitle::alt($this->brand?->name, $this->name, $index, $total);
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

    /** Effective unit price in fils, honouring a scheduled sale window. */
    public function effectivePrice(): int
    {
        if ($this->sale_price === null) {
            return (int) $this->price;
        }

        $now = now();
        if ($this->sale_starts_at && $now->lt($this->sale_starts_at)) {
            return (int) $this->price;
        }
        if ($this->sale_ends_at && $now->gt($this->sale_ends_at)) {
            return (int) $this->price;
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

    public function isOnSale(): bool
    {
        return $this->effectivePrice() < (int) $this->price;
    }

    public function discountPercent(): int
    {
        if (! $this->isOnSale() || ! $this->price) {
            return 0;
        }

        return (int) round((1 - $this->effectivePrice() / $this->price) * 100);
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
