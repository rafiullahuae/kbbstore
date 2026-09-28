<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Demo content for the homepage.
 *
 * Nothing here is written to the database. The fixtures are plain objects
 * rendered in place of empty sections, so switching demo content on or off
 * cannot create, alter or delete a single real row. Turning it off simply
 * stops substituting.
 *
 * That is the whole design: a preview aid must never be able to damage a
 * live catalogue.
 *
 * AND IT STATES NO FIGURES. The second half of that rule, which this class did
 * not used to keep.
 *
 * A stand-in card is a piece of LAYOUT — it shows the owner what the shelf will
 * look like once there is something on it. A rating, a review count, a star
 * average or a product tally is a CLAIM, and a claim does not become true
 * because a preview aid made it. This class used to make several: 12,481
 * reviews at 4.8 stars shop-wide, 3,204 at 4.9 on every unreviewed product
 * page, four and then six named customers marked as verified purchasers, a
 * rating and a review count on every stand-in card, and a product tally on
 * every stand-in category and brand tile. All of them rendered to shoppers, and
 * the card figures reached Google through App\Support\Seo.
 *
 * So the fixtures now carry names, prices, copy and images only. Every numeric
 * property that survives is fixed at zero, which the storefront templates
 * already render as their honest empty state — the "New" badge on a card, and
 * no tally on a tile. Reviews are gone from here altogether: the review wall
 * and the product page read `reviews`, and show nothing when it is empty.
 *
 * See tests/Feature/DemoContentTruthTest.php, which pins all of it with the
 * switch ON — because a setting that can be turned on to put invented numbers
 * in front of shoppers is the defect, not the mitigation.
 */
class DemoContent
{
    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('demo_content', false);
    }

    /**
     * Use the real collection when it has enough in it; otherwise fall back to
     * the fixtures. Real data always wins.
     */
    public function fill(Collection $real, string $kind, int $want = 4): Collection
    {
        if (! $this->enabled() || $real->count() >= $want) {
            return $real;
        }

        return $real->concat($this->{$kind}()->take($want - $real->count()))->values();
    }

    /**
     * ONE STAND-IN CARD, AND IT IS A REAL `Product` NOW.              Lane PG
     *
     * ── WHAT IT WAS, AND WHY IT STOPPED WORKING ─────────────────────────────
     *
     * It was an anonymous class carrying the six properties and four methods
     * partials/home/grid.blade.php happened to read. That worked for exactly as
     * long as the home rails drew their own card. They draw
     * components/product-card.blade.php now — the ONE product tile — and that
     * card asks two SERVICES about the product it is handed:
     * ProductLabels::for(Product) and VariantPricing::range(Product), both
     * type-hinted. A duck-typed stand-in is a TypeError on the home page the
     * moment demo content is switched on, which is exactly what it was:
     *
     *     App\Services\ProductLabels::for(): Argument #1 ($product) must be of
     *     type App\Models\Product, class@anonymous given
     *
     * ── WHY A SUBCLASS AND NOT A DUCK WITH MORE FEATHERS ────────────────────
     *
     * Adding the missing members one at a time is how the previous two
     * failures here were fixed (`t()`, then `brand->t()`, both recorded above),
     * and each time the next thing the card learned to read broke it again. A
     * stand-in that IS a Product cannot fall behind the card, and it is not a
     * larger object: it is unsaved, it never reaches the database, and the only
     * thing it overrides is where it links to.
     *
     * NOT `exists`, and never saved. forceFill() puts the fixture on the model
     * without touching `$fillable`; `exists` stays false, so nothing here can
     * be written by anything that mistakes it for a row.
     *
     * `stock_status` IS DELIBERATELY ABSENT, and that decides the button.
     * Product::isDirectlyBuyable() is `stock_status === 'instock'`, so a
     * stand-in answers false and its tile renders "View product" pointing at
     * /shop/ instead of an Add to cart bound to product id 0. The old fixture
     * bound one, and pressing it could only fail.
     *
     * `review_count` and `rating` stay at zero for the reason the old fixture
     * gave: no such product, no such reviews, no such customers. The tile draws
     * NO rating row at all for an unreviewed product now, which is the owner's
     * own instruction and the honest reading of a fixture.
     */
    private function product(array $row): object
    {
        [$name, $brandName, $price, $salePrice] = $row;

        $brand = new \App\Models\Brand();
        $brand->forceFill([
            'id' => 0,
            'name' => $brandName,
            'slug' => \Illuminate\Support\Str::slug($brandName),
        ]);

        $product = new class extends \App\Models\Product
        {
            /** The table the parent would derive from an anonymous class name. */
            protected $table = 'products';

            /** Read by store/home.blade.php to tell a fixture from a row. */
            public bool $demo = true;

            /* A stand-in links to the shop rather than to a product page that
               does not exist — a 404 from a preview aid would be worse than
               useless. */
            public function url(): string
            {
                return \App\Support\Url::to('/shop/');
            }
        };

        $product->forceFill([
            'id' => 0,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'image' => null,
            'price' => $price,
            'sale_price' => $salePrice,
            'review_count' => 0,
            'rating' => 0.0,
            'type' => 'simple',
            'featured' => false,
            'created_at' => null,
            'updated_at' => null,
        ]);

        $product->setRelation('brand', $brand);
        $product->setRelation('categories', collect());

        return $product;
    }

    /**
     * Stand-in product cards. Name, brand and price only — see product()
     * for why the rating and the review count are fixed at zero.
     */
    public function products(): Collection
    {
        return collect([
            ['Relief Sun: Rice + Probiotics SPF50+', 'Beauty of Joseon', 6500, 5500],
            ['Advanced Snail 96 Mucin Power Essence', 'COSRX', 13700, 11300],
            ['Heartleaf 77% Soothing Toner', 'Anua', 12700, 8900],
            ['Madagascar Centella Ampoule', 'SKIN1004', 9400, 6600],
            ['1025 Dokdo Toner', 'Round Lab', 7200, 0],
            ['Dive-In Low Molecular Serum', 'Torriden', 6800, 0],
            ['Zero Pore Pad 2.0', 'Medicube', 15200, 11500],
            ['Hyaluronic Acid Watery Sun Gel', 'Isntree', 10000, 7000],
        ])->map(fn ($r) => $this->product($r));
    }

    /**
     * Stand-in category tiles.
     *
     * `products_count` is 0 for the same reason a demo product carries no
     * rating: the tiles used to state a tally — "68 products" under Sun care,
     * "112" under Serums — for categories that do not exist and could not be
     * counted. home.blade.php prints the count only when it is non-zero, so
     * the tile shows its name alone rather than "0 products".
     */
    public function categories(): Collection
    {
        return collect(['Sun care', 'Serums', 'Toners', 'Masks',
            'Cleansers', 'Moisturisers', 'Beauty devices', 'Under 54 AED',
        ])->map(fn ($name) => new class($name) {
            public string $name;
            public int $products_count = 0;
            public bool $demo = true;
            public function __construct(string $name) { $this->name = $name; }
            public function url(): string { return \App\Support\Url::to('/shop/'); }

            /** See product() above: the templates read catalogue text with t(). */
            public function t(string $field, ?string $locale = null): mixed
            {
                return property_exists($this, $field) ? $this->{$field} : null;
            }
        });
    }

    /** Stand-in brand tiles. `products_count` is 0 — see categories(). */
    public function brands(): Collection
    {
        return collect(['Beauty of Joseon', 'COSRX', 'Anua', 'SKIN1004',
            'Medicube', 'Round Lab', 'Torriden', 'Goodal',
            'numbuzin', 'AXIS-Y', 'Haruharu Wonder', 'SOME BY MI',
        ])->map(fn ($name) => new class($name) {
            public string $name;
            public int $products_count = 0;
            public bool $demo = true;
            public function __construct(string $name) { $this->name = $name; }
            public function url(): string { return \App\Support\Url::to('/shop/'); }

            /** See product() above: the templates read catalogue text with t(). */
            public function t(string $field, ?string $locale = null): mixed
            {
                return property_exists($this, $field) ? $this->{$field} : null;
            }
        });
    }

    public function posts(): Collection
    {
        return collect([
            ['A beginner’s guide to Korean skincare', 'Routines', 'Korean skincare is renowned for its innovative formulations and transformative results.', 8],
            ['K-beauty face masks: the ultimate guide', 'Masks', 'Coming home after a long day in Dubai and indulging in a skin-rejuvenating treatment.', 6],
            ['Heartleaf extract: transforming K-beauty', 'Ingredients', 'One ingredient has quietly risen to become a staple in Korean formulations.', 5],
        ])->map(fn ($r) => new class($r) {
            /*
             * THE REAL COLUMN NAMES, because fill() concatenates these objects
             * onto a collection of actual Post models and the rail's markup has
             * to serve both from one expression.
             *
             * They used to be `category`, `content` and `image`. `posts` calls
             * those `tag`, `body` and `cover`, and the home rail was corrected
             * to read `cover` — at which point these stand-ins, which have no
             * such property, stopped being merely inconsistent and started
             * throwing "Undefined property" on the home page whenever demo
             * content was switched on. A plain object is not Eloquent: there is
             * no null for a field that is not there.
             */
            public string $title, $tag, $excerpt, $slug;
            public ?string $cover = null, $body = null;
            public bool $demo = true;
            public $published_at;

            /** The minutes the fixture states, since it has no body to measure. */
            private int $minutes;

            public function __construct(array $r)
            {
                [$this->title, $this->tag, $this->excerpt, $this->minutes] = $r;
                $this->slug = \Illuminate\Support\Str::slug($r[0]);
                $this->published_at = now()->subDays(random_int(5, 60));
            }

            /** Post::readMinutes(), answered the same way for a stand-in. */
            public function readMinutes(): int
            {
                return $this->minutes;
            }

            /** See product() above: the templates read catalogue text with t(). */
            public function t(string $field, ?string $locale = null): mixed
            {
                return property_exists($this, $field) ? $this->{$field} : null;
            }
        });
    }

    /** Detail tabs, so the tab bar can be seen before real copy is written. */
    public function tabs(): array
    {
        return [
            ['title' => 'Ingredients', 'body' => '<p>Rice extract, niacinamide, grain ferment, panthenol, hyaluronic acid, and broad-spectrum UV filters. Fragrance-light and EWG-friendly.</p><p>Full list: Water, Oryza Sativa (Rice) Extract, Dibutyl Adipate, Glycerin, Niacinamide, Butyl Methoxydibenzoylmethane, Ethylhexyl Salicylate, Panthenol, Sodium Hyaluronate, Centella Asiatica Extract, Butylene Glycol, 1,2-Hexanediol, Xanthan Gum.</p><p>Patch test before first use. Discontinue if irritation occurs.</p>'],
            ['title' => 'How to use', 'body' => '<p>Apply as the final step of your morning routine, after moisturiser and before makeup.</p><p>Use roughly two finger-lengths for the face and neck. Reapply every two hours in direct sun, and after swimming or heavy sweating.</p><p>Remove thoroughly at the end of the day with an oil cleanser followed by a water-based one.</p>'],
            ['title' => 'Shipping & returns', 'body' => '<p>Delivered across the UAE in one to three working days. Free over AED 199.</p><p>Returns accepted within fourteen days, unopened and in original packaging. Contact us on WhatsApp and we will arrange collection.</p>'],
        ];
    }

}
