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

    private function product(array $row): object
    {
        return new class($row) {
            public int $id = 0;
            public string $name, $slug;
            public ?string $image = null;
            public int $price, $sale_price, $review_count;
            public float $rating;
            public object $brand;
            public bool $demo = true;

            public function __construct(array $r)
            {
                [$this->name, $brand, $this->price, $this->sale_price, $this->rating, $this->review_count] =
                    [$r[0], $r[1], $r[2], $r[3], $r[4], $r[5]];
                $this->slug = \Illuminate\Support\Str::slug($r[0]);
                $this->brand = (object) ['name' => $brand, 'slug' => \Illuminate\Support\Str::slug($brand)];
            }

            public function effectivePrice(): int { return $this->sale_price ?: $this->price; }
            public function isOnSale(): bool { return $this->sale_price > 0 && $this->sale_price < $this->price; }
            /* Demo items link to the shop rather than a product page that does
               not exist — a 404 from a preview aid would be worse than useless. */
            public function url(): string { return \App\Support\Url::to('/shop/'); }
        };
    }

    public function products(): Collection
    {
        return collect([
            ['Relief Sun: Rice + Probiotics SPF50+', 'Beauty of Joseon', 6500, 5500, 4.9, 3204],
            ['Advanced Snail 96 Mucin Power Essence', 'COSRX', 13700, 11300, 4.8, 1240],
            ['Heartleaf 77% Soothing Toner', 'Anua', 12700, 8900, 4.9, 860],
            ['Madagascar Centella Ampoule', 'SKIN1004', 9400, 6600, 4.7, 410],
            ['1025 Dokdo Toner', 'Round Lab', 7200, 0, 4.6, 540],
            ['Dive-In Low Molecular Serum', 'Torriden', 6800, 0, 5.0, 318],
            ['Zero Pore Pad 2.0', 'Medicube', 15200, 11500, 4.8, 651],
            ['Hyaluronic Acid Watery Sun Gel', 'Isntree', 10000, 7000, 4.2, 1100],
        ])->map(fn ($r) => $this->product($r));
    }

    public function categories(): Collection
    {
        return collect([
            ['Sun care', 68], ['Serums', 112], ['Toners', 84], ['Masks', 57],
            ['Cleansers', 93], ['Moisturisers', 76], ['Beauty devices', 21], ['Under 54 AED', 140],
        ])->map(fn ($r) => new class($r) {
            public string $name;
            public int $products_count;
            public bool $demo = true;
            public function __construct(array $r) { [$this->name, $this->products_count] = $r; }
            public function url(): string { return \App\Support\Url::to('/shop/'); }
        });
    }

    public function brands(): Collection
    {
        return collect([
            ['Beauty of Joseon', 42], ['COSRX', 58], ['Anua', 36], ['SKIN1004', 29],
            ['Medicube', 24], ['Round Lab', 18], ['Torriden', 21], ['Goodal', 16],
            ['numbuzin', 19], ['AXIS-Y', 14], ['Haruharu Wonder', 17], ['SOME BY MI', 22],
        ])->map(fn ($r) => new class($r) {
            public string $name;
            public int $products_count;
            public bool $demo = true;
            public function __construct(array $r) { [$this->name, $this->products_count] = $r; }
            public function url(): string { return \App\Support\Url::to('/shop/'); }
        });
    }

    public function reviews(): Collection
    {
        return collect([
            ['Kingsley C.', 'Centella Hyalu-Cica Water-Fit Sun Serum', 'I love this so much. Super lightweight and moisturizing. I felt my skin brighten up and it smooths my fine lines.', 24],
            ['Houda A.', 'celimax The Vita-A Retinal Shot', 'Best retinal ever. I repurchased because it is effective and affordable. Works amazingly for dry and sensitive skin.', 17],
            ['Jenifer L.', '1025 Dokdo Cleansing Toner', 'Ordered twice now. Really effective at clearing anything left behind on your face, and it does not irritate at all.', 31],
            ['Rowena M.', 'Anua Azelaic Acid 10 Serum', 'The best azelaic acid product I have tried. Lightweight, non-greasy, and it does not dry out my mature skin.', 12],
        ])->map(fn ($r) => new class($r) {
            public string $author_name, $content;
            public int $rating = 5, $helpful;
            public bool $verified = true, $demo = true;
            public ?object $product;
            public $created_at;
            public function __construct(array $r)
            {
                [$this->author_name, $name, $this->content, $this->helpful] = $r;
                $this->product = (object) ['name' => $name];
                $this->created_at = now()->subDays(random_int(3, 40));
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
            public string $title, $category, $excerpt, $slug;
            public ?string $image = null, $content = null;
            public int $read_minutes;
            public bool $demo = true;
            public $published_at;
            public function __construct(array $r)
            {
                [$this->title, $this->category, $this->excerpt, $this->read_minutes] = $r;
                $this->slug = \Illuminate\Support\Str::slug($r[0]);
                $this->published_at = now()->subDays(random_int(5, 60));
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

    /** Reviews for a product page, with the summary the block expects. */
    public function productReviews(): array
    {
        // The last element of each row is how many demo photos that review
        // carries — 0, 1, or up to 5, deliberately spanning the range
        // reviews.blade.php actually has to handle: no photo icon at all,
        // a single photo, a plain multi-photo grid, and (at 5) the "+1 more"
        // overlay that only appears once a review has more than four.
        $items = collect([
            ['Aisha M.', 5, 'Third bottle. No white cast at all and it sits perfectly under makeup — the only SPF that has survived a Dubai August on my skin.', 24, true, 1],
            ['Fatima K.', 5, 'My skin stopped reacting within two weeks. I had almost given up on finding something gentle that still did something.', 17, true, 0],
            ['Noor S.', 4, 'Ordered Sunday evening, arrived Tuesday morning, sealed and genuine. Slightly heavier than I expected but it settles.', 31, true, 3],
            ['Layla H.', 5, 'Survives a Dubai August without turning greasy. I have repurchased twice and will keep doing so.', 12, true, 0],
            ['Mariam A.', 5, 'Two weeks in and the texture on my cheeks has smoothed right out. Lightweight and no pilling under makeup.', 9, true, 5],
            ['Dana Q.', 4, 'She liked mine so much she asked for her own. Good value for the size of the bottle.', 6, false, 0],
        ])->map(fn ($r) => new class($r) {
            public string $author_name, $content;
            public int $rating, $helpful;
            public bool $verified, $demo = true;
            public array $images = [];
            public ?string $title = null;
            public $created_at;
            // No real row backs a demo review, so no real id exists either —
            // reviews.blade.php is the first template to read ->id (the
            // helpful-vote button's data-id), which product-reviews.blade.php
            // never touched. 0 renders safely; the button itself is hidden
            // for demo rows below rather than left clickable against an id
            // that matches nothing in the database.
            public int $id = 0;
            public function __construct(array $r)
            {
                [$this->author_name, $this->rating, $this->content, $this->helpful, $this->verified, $photoCount] = $r;
                $this->created_at = now()->subDays(random_int(2, 60));

                for ($i = 0; $i < $photoCount; $i++) {
                    $this->images[] = DemoContent::demoPhoto($this->author_name.$i);
                }
            }
        });

        $total = 3204;
        $split = [5 => 88, 4 => 9, 3 => 2, 2 => 1, 1 => 0];
        $bars = [];

        // Same shape as ProductController::reviewSummary(), or the partial
        // reads ['pct'] off an integer and Laravel turns that warning into a
        // 500. Fixtures must match the real structure exactly.
        foreach ($split as $star => $pct) {
            $bars[$star] = ['n' => (int) round($total * $pct / 100), 'pct' => $pct];
        }

        return [
            'items' => $items,
            'summary' => ['total' => $total, 'average' => 4.9, 'bars' => $bars],
        ];
    }

    /**
     * A self-contained placeholder "photo" for demo reviews — an inline SVG
     * data URI, not a real uploaded image and not fetched from anywhere.
     * Deliberately not an external placeholder-image service: this renders
     * on a live storefront, and a demo review photo is not worth a runtime
     * dependency on a third party staying up, fast, and unblocked. Same
     * gradient palette as Gradient::for(), so a demo photo looks like it
     * belongs next to the rest of the site rather than visibly fake.
     */
    public static function demoPhoto(string $seed): string
    {
        $palette = [
            ['#ffe9a8', '#f3c969'], ['#ffd1e2', '#ff9fc1'], ['#bfe9d2', '#7fd3a9'],
            ['#d9ccff', '#b39cff'], ['#ffd9c9', '#ff9f80'], ['#cfe6ff', '#8fc0f0'],
        ];
        [$c1, $c2] = $palette[abs(crc32($seed)) % count($palette)];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
            . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="'.$c1.'"/><stop offset="1" stop-color="'.$c2.'"/>'
            . '</linearGradient></defs>'
            . '<rect width="200" height="200" fill="url(#g)"/></svg>';

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    /** The star distribution shown when there are no real reviews. */
    public function reviewSummary(): array
    {
        return ['total' => 12481, 'average' => 4.8, 'bars' => [5 => 86, 4 => 9, 3 => 3, 2 => 1, 1 => 1]];
    }
}
