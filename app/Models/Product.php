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

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class);
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
     */
    public function toApi(): array
    {
        return [
            'slug'              => $this->slug,
            'name'              => $this->name,
            'brand'             => $this->relationLoaded('brand') ? $this->brand?->name : null,
            'price'             => $this->price,
            'sale_price'        => $this->sale_price,
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
     */
    public function altFor(?string $url, ?string $label = null): string
    {
        $map = is_array($this->image_alts) ? $this->image_alts : [];
        $stored = trim((string) ($map[(string) $url] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        $parts = array_filter([
            $this->brand?->name,
            $this->name,
            // "Front" / "Texture" and friends, when the caller knows which shot
            // this is. Skipped when it is the generic "View 4", which is a
            // position rather than a description and reads as noise.
            $label !== null && ! preg_match('/^View \d+$/', $label) ? $label : null,
        ]);

        return implode(' — ', $parts);
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
