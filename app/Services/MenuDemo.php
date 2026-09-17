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

    private static function slug(string $name): string
    {
        return \Illuminate\Support\Str::slug($name);
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
         */
        $brandLeaf = fn (string $b) => self::leaf($b, '/shop/?filter_brands=' . self::slug($b));

        return [
            [
                'id' => 0, 'label' => 'Brands', 'url' => '/korean-skincare-brands/', 'icon' => null, 'badge' => null,
                'children' => [
                    ['id' => 0, 'label' => 'Trending Brands', 'url' => '/korean-skincare-brands/', 'icon' => null, 'badge' => null,
                     'children' => array_map($brandLeaf, self::TRENDING)],
                    ['id' => 0, 'label' => 'All Brands', 'url' => '/korean-skincare-brands/', 'icon' => null, 'badge' => null,
                     'children' => array_map($brandLeaf, self::BRANDS)],
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
