<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The menu from kbeautybliss.com, in its published order.
 *
 * Used only when no menu has been built in the admin, so the sheet is never
 * empty before the WordPress menus are migrated. A real menu always wins — this
 * is a fallback, not a seed, and nothing here is written to the database.
 *
 * CATEGORY URLS ARE /product-category/{slug}/ — URL Contract U-03 — and not the
 * flat /toners/ addresses the live WordPress site published. Written flat, they
 * fell through routes/kbb-brands-blog.php's `/{slug}/` catch-all to
 * PageController@post, which looks up a BLOG POST by that slug and 404s. This
 * fallback reaches a real shopper: fill() tops up the MOBILE DRAWER whenever
 * Store → Modules → Demo content is on, so the addresses here are not
 * decoration. Slugs are preserved exactly as the live site spells them rather
 * than remapped onto whatever categories exist today — App\Support\
 * LegacyCategoryUrls gives the reasoning.
 */
class MenuDemo
{
    /** Trending brands, in the order the live site lists them. */
    public const TRENDING = [
        'Anua', 'Axis-Y', 'Beauty of Joseon', 'BIODANCE', 'Celimax', 'COSRX', 'Dr.Althea',
        'EQQUALBERRY', 'Goodal', 'I’m from', 'LANEIGE', 'MEDICUBE', 'numbuzin', 'Shiseido',
        'SKIN 1004', 'SOME BY MI', 'VT Cosmetics',
    ];

    /** All brands, alphabetical, as published. */
    public const BRANDS = [
        'Abib', 'Anua', 'APLB', 'Aquaphor', 'Axis-Y', 'A’PIEU', 'APRILSKIN', 'Arencia', 'Aromatica',
        'Beauty of Joseon', 'Beauty Works', 'B_LAB', 'BANILA CO', 'Benton', 'BIODANCE', 'Celimax',
        'Centellian24', 'COSRX', 'd’Alba', 'Dr.Althea', 'Dr.Reju-All', 'Dear, Klairs', 'Dr. Ceuracle',
        'Dr.G', 'Dr. Jart+', 'Dr.Melaxin', 'EQQUALBERRY', 'ETUDE', 'EUNYUL', 'Goodal', 'Haruharu Wonder',
        'Heimish', 'House of Hur', 'ilso', 'illiyoon', 'I’m from', 'Innisfree', 'Isntree', 'iUNIK',
        'JUMISO', 'KAINE', 'LANEIGE', 'La Roche-Posay', 'Manyo', 'Medicube', 'Mary&May', 'Mediheal',
        'Missha', 'mixsoon', 'numbuzin', 'Ongredients', 'Purito SEOUL', 'Pyunkang Yul', 'ROUND LAB',
        'SKIN 1004', 'Shiseido', 'SKIN&LAB', 'Skin Food', 'SOME BY MI', 'TIA’M', 'TIRTIR', 'TOCOBO',
        'Torriden', 'TOSOWOONG', 'VT Cosmetics',
    ];

    /**
     * A label reduced to its letters and digits, lower-cased.
     *
     * The join between a published label and a `brands` row, for the cases
     * where neither the slug nor the name matches character for character:
     * "SKIN 1004" and "SKIN1004" both fold to `skin1004`, and "Dr.Althea",
     * "Dr. Althea" and the slug `dr-althea` all fold to `dralthea`. It is a
     * LAST resort — brandIndex() tries the slug and then the name first, and
     * discards any fold key two different brands share.
     */
    private static function fold(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($s)));
    }

    /**
     * The brands this shop actually carries, indexed three ways for lookup.
     *
     * ── WHY A LOOKUP AND NOT Str::slug() ────────────────────────────────────
     *
     * The brand leaves used to build their slug by transforming the LABEL:
     * Str::slug('Dr.Althea') is `dralthea`, and the brand's real slug — the one
     * the seeded menu carries, explicitly mapped, and the one an import brings
     * in — is `dr-althea`. Nine of the sixty-five labels here disagree with
     * their own brand that way.
     *
     * A brand slug that names no brand is NOT a 404. ShopController applies the
     * facet whenever it is non-empty (`whereHas('brand', whereIn slug)`), so
     * /shop/?filter_brands=dralthea answers 200 with an empty grid and the
     * words "No products match those filters" — the shop telling a shopper who
     * asked for Dr.Althea that it stocks nothing by Dr.Althea, when what is
     * actually true is that the menu named a brand the shop does not carry.
     * Measured, not assumed: /shop/ renders 24 cards on the demo fixture,
     * ?filter_brands=cosrx renders 3, and ?filter_brands=dralthea renders 0.
     *
     * So the slug is READ OFF THE `brands` TABLE, and a label that matches no
     * brand is not published at all — see tree(). Unlike the category URLs in
     * App\Support\LegacyCategoryUrls, resolving here bakes nothing in: this
     * tree is rebuilt on every render, so a brand arriving by WordPress import
     * makes its menu item appear by itself on the next request, and a brand
     * deleted in the admin makes it disappear.
     *
     * @return array{slug: array<string,string>, name: array<string,string>, fold: array<string,string|null>}
     */
    private static function brandIndex(): array
    {
        $empty = ['slug' => [], 'name' => [], 'fold' => []];

        try {
            /** @var list<object{name: ?string, slug: ?string}> $rows */
            $rows = \App\Models\Brand::query()->orderBy('id')->get(['name', 'slug'])->all();
        } catch (\Throwable) {
            // No database, or no `brands` table yet: this runs from a Blade
            // partial on a real request, and a menu is never worth a 500. With
            // no brands known, no brand leaf is published — which is the same
            // rule the rest of this method keeps.
            return $empty;
        }

        $index = $empty;

        foreach ($rows as $row) {
            $slug = trim((string) ($row->slug ?? ''));

            if ($slug === '') {
                continue;
            }

            $index['slug'][$slug] ??= $slug;

            $name = mb_strtolower(trim((string) ($row->name ?? '')));

            if ($name !== '') {
                $index['name'][$name] ??= $slug;
            }

            foreach ([self::fold((string) ($row->name ?? '')), self::fold($slug)] as $key) {
                if ($key === '') {
                    continue;
                }

                // Two brands sharing a fold key make that key useless: there is
                // no way to tell which one a label meant, and guessing is how a
                // menu item ends up filtering for the wrong brand. Null marks it
                // as ambiguous and brandSlug() then declines to answer.
                if (array_key_exists($key, $index['fold']) && $index['fold'][$key] !== $slug) {
                    $index['fold'][$key] = null;

                    continue;
                }

                $index['fold'][$key] ??= $slug;
            }
        }

        return $index;
    }

    /**
     * The slug of the brand a published label names, or null if this shop has
     * no such brand.
     *
     * @param  array{slug: array<string,string>, name: array<string,string>, fold: array<string,string|null>}  $index
     */
    private static function brandSlug(string $label, array $index): ?string
    {
        $bySlug = $index['slug'][\Illuminate\Support\Str::slug($label)] ?? null;

        if ($bySlug !== null) {
            return $bySlug;
        }

        $byName = $index['name'][mb_strtolower(trim($label))] ?? null;

        if ($byName !== null) {
            return $byName;
        }

        return $index['fold'][self::fold($label)] ?? null;
    }

    private static function leaf(string $label, string $url, bool $hot = false): array
    {
        return ['id' => 0, 'label' => $label, 'url' => $url, 'icon' => null,
                'badge' => $hot ? 'sale' : null, 'children' => []];
    }

    /**
     * Top a real menu up with the published one.
     *
     * A menu that exists but holds four items is the common case before the
     * WordPress menus are migrated, and requiring it to be empty meant the
     * demo never appeared. Real items keep their place and their order;
     * anything from the live menu that is not already present is appended.
     */
    public static function fill(array $real): array
    {
        $demo = self::tree();

        // Index the published menu by label so a match can be found cheaply.
        $byLabel = [];

        foreach ($demo as $i => $item) {
            $byLabel[mb_strtolower(trim($item['label']))] = $i;
        }

        $used = [];
        $out = [];

        foreach ($real as $item) {
            $key = mb_strtolower(trim((string) ($item['label'] ?? '')));

            // A real item that matches the published menu but has no children of
            // its own adopts them. Without this a configured menu of flat links
            // named Brands and Skincare would show no sub-items at all, which is
            // the whole point of the demo.
            if (isset($byLabel[$key])) {
                $used[$byLabel[$key]] = true;

                if (empty($item['children'])) {
                    $item['children'] = $demo[$byLabel[$key]]['children'];
                }
            }

            $out[] = $item;
        }

        // Then everything from the published menu that was not matched at all.
        foreach ($demo as $i => $item) {
            if (! isset($used[$i])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** Every row in the tree, for counting and testing. */
    public static function count(array $tree = null): int
    {
        $tree ??= self::tree();
        $n = 0;

        foreach ($tree as $item) {
            $n++;
            $n += self::count($item['children'] ?? []);
        }

        return $n;
    }

    /** The full tree, in the same order as the live site. */
    public static function tree(): array
    {
        /*
         * URL Contract U-05: a brand's listing is /shop/?filter_brands={slug},
         * which is exactly what Brand::url() returns and what the real seeded
         * menu's brand items carry.
         *
         * These leaves used to publish /brand/{slug}/ instead. That route is a
         * legacy 301 whose handler does firstOrFail() on the brands table, so
         * every brand not yet imported was a 404 — sixty of the sixty-five
         * here, on a shop that has not run the WordPress import. It also meant
         * the mobile drawer (which this fallback tops up) and the header
         * published the same brand at two different addresses.
         *
         * Moving them onto /shop/?filter_brands= fixed the address and left the
         * SLUG wrong, which was the worse half: Str::slug('Dr.Althea') is
         * `dralthea` and the brand is `dr-althea`, so the link stopped 404ing
         * and started answering 200 with an empty grid. The slug now comes off
         * the `brands` table (brandIndex()), and a label with no brand behind it
         * is DROPPED rather than published — a menu entry that names a brand
         * and then shows the shopper nothing is the defect, not the mitigation.
         */
        $index = self::brandIndex();

        $brandLeaves = static function (array $labels) use ($index): array {
            $out = [];

            foreach ($labels as $label) {
                $slug = self::brandSlug($label, $index);

                if ($slug === null) {
                    continue;
                }

                $out[] = self::leaf($label, '/shop/?filter_brands=' . $slug);
            }

            return $out;
        };

        return [
            [
                'id' => 0, 'label' => 'Brands', 'url' => '/korean-skincare-brands/', 'icon' => null, 'badge' => null,
                'children' => [
                    /*
                     * Both nodes keep their own link to the brand directory
                     * even when every child has been dropped: /korean-skincare-
                     * brands/ lists whatever brands the shop has, so an empty
                     * Trending Brands still takes the shopper somewhere true.
                     */
                    ['id' => 0, 'label' => 'Trending Brands', 'url' => '/korean-skincare-brands/', 'icon' => null, 'badge' => null,
                     'children' => $brandLeaves(self::TRENDING)],
                    ['id' => 0, 'label' => 'All Brands', 'url' => '/korean-skincare-brands/', 'icon' => null, 'badge' => null,
                     'children' => $brandLeaves(self::BRANDS)],
                ],
            ],
            [
                'id' => 0, 'label' => 'Skincare', 'url' => '/product-category/skincare/', 'icon' => null, 'badge' => null,
                'children' => [
                    self::leaf('Sunscreens', '/product-category/sunscreens/'),
                    self::leaf('Exfoliators', '/product-category/exfoliators/'),
                    self::leaf('Toners', '/product-category/toners/'),
                    self::leaf('Eye Care', '/product-category/eye-care/'),
                    ['id' => 0, 'label' => 'Face Cleansers', 'url' => '/product-category/face-cleansers/', 'icon' => null, 'badge' => null,
                     'children' => [
                         self::leaf('Cleansing Oils', '/product-category/cleansing-oils/'),
                         self::leaf('Face Washes', '/product-category/face-washes/'),
                     ]],
                    self::leaf('Face Masks', '/product-category/face-masks/'),
                    self::leaf('Face Serums', '/product-category/face-serums/'),
                    self::leaf('Moisturizers', '/product-category/moisturizers/'),
                ],
            ],
            self::leaf('Sunscreens', '/product-category/sunscreens/'),
            self::leaf('Moisturizers', '/product-category/moisturizers/'),
            self::leaf('Toners', '/product-category/toners/'),
            self::leaf('Lip Care', '/product-category/lip-care/'),
            self::leaf('Hair Care', '/product-category/hair-care/'),
            self::leaf('Skincare sets', '/product-category/skincare-sets/'),
            self::leaf('SUPER SALE', '/super-sale/', true),
            self::leaf('Beauty Devices', '/product-category/beauty-devices/'),
            self::leaf('Everything under 54 AED', '/everything-under-54-aed/'),
            self::leaf('BLOG', '/skincare-guide/'),
        ];
    }
}
