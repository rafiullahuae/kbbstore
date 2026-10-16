<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane SC: the owner's recent products into Shop by Skin Concern.
 *
 * On 16 October 2026, 136 of the 769 products in kbeautybliss.com's sitemap
 * were in none of the seven concern collections (pigmentation, pore-care,
 * anti-aging, hydration-glow, acne-blemish-care, redness-sensitive-skin,
 * oily-skin). Most of them are hair, body, lip, make-up and devices, which
 * the concerns do not cover (lip care was left out of 2.60.506 at his
 * request). The face-care ones below were read on their live pages and
 * placed only where their own ingredients and claims put them; a product
 * whose page makes no concern claim (a plain cleansing oil or balm) is left
 * out rather than guessed.
 *
 * ONLY ADDS, exactly as 2027_11_01_100000_shop_by_concern_products does:
 * a link that already exists is not touched (its category_position stays),
 * nothing is removed, and new links carry category_position NULL so they sort
 * after anything the owner ordered by hand. Concerns and products are found
 * by slug; a concern this shop does not have is NOT created and a product it
 * does not carry is skipped. Running it twice adds nothing the second time.
 *
 * Literals and framework calls only (no App\ class): a migration runs against
 * whatever code the server has. LaneScConcernsMigrationTest pins it.
 */
return new class extends Migration
{
    /** product slug => [concern category slugs]. Reasons are the live page's own claims. */
    private const ADD = [
        // Silk peptides, collagen, adenosine for fine lines; hyaluronic acid, overnight hydration.
        'sungboon-editor-silk-peptide-egf-intensive-gold-mask-set' => ['anti-aging', 'hydration-glow'],
        // Peptides, retinol and collagen for fine lines around the eyes.
        'sungboon-editor-silk-peptide-egf-intensive-eye-cream' => ['anti-aging'],
        // Retinol, nine peptides, collagen; eight hyaluronic acids for dryness.
        'sungboon-editor-deep-collagen-retinol-power-boosting-capsule-cream' => ['anti-aging', 'hydration-glow'],
        // 300 Da collagen and Volufiline for elasticity; hyaluronic acids, plump and radiant.
        'sungboon-editor-deep-collagen-power-boosting-cream-in-serum' => ['anti-aging', 'hydration-glow'],
        // 6% tranexamic acid + 10% niacinamide for dark spots; retinal for fine lines.
        'purito-seoul-txa-6-niacinamide-10-retinal-serum' => ['pigmentation', 'anti-aging'],
        // 1,000 ppm retinol + 1,000 ppm retinal + adenosine for wrinkles.
        'purito-seoul-retinol-retinal-2000-nad-serum' => ['anti-aging'],
        // 10% azelaic acid + tea tree for blemishes; kojic acid for post-acne marks.
        'purito-seoul-azelaic-acid-10-kojic-tea-tree-serum' => ['acne-blemish-care', 'pigmentation'],
        // Salicylic acid + PHA pads for enlarged pores and excess sebum (its 2.0 sibling is in both).
        'medicube-zero-pore-pad-mild' => ['pore-care', 'oily-skin'],
        // 5% niacinamide + 1% tranexamic acid + vitamin C for dark spots; hyaluronic acids overnight.
        'aprilskin-txa-niacinamide-99-night-wrapping-mask' => ['pigmentation', 'hydration-glow'],
        // Claims to reduce excess sebum; 80% aloe for hydration and dullness.
        'aprilskin-pink-aloe-vita-toner' => ['oily-skin', 'hydration-glow'],
        // 20,000 ppm niacinamide for dark spots; 78% aloe, panthenol, ceramide for moisture and glow.
        'aprilskin-pink-aloe-mucin-serum' => ['pigmentation', 'hydration-glow'],
        // Madecassoside, fragrance- and colourant-free, made for sensitive skin.
        'dr-althea-pure-grinding-cleansing-balm' => ['redness-sensitive-skin'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasTable('category_product') || ! Schema::hasTable('products')) {
            return;
        }

        $concernSlugs = array_values(array_unique(array_merge(...array_values(self::ADD))));
        $concerns = DB::table('categories')->whereIn('slug', $concernSlugs)->pluck('id', 'slug')->all();
        $products = DB::table('products')->whereIn('slug', array_keys(self::ADD))->pluck('id', 'slug')->all();

        if ($concerns === [] || $products === []) {
            return;
        }

        $have = [];
        foreach (DB::table('category_product')
            ->whereIn('category_id', array_values($concerns))
            ->whereIn('product_id', array_values($products))
            ->get(['category_id', 'product_id']) as $row) {
            $have[(int) $row->category_id.':'.(int) $row->product_id] = true;
        }

        $rows = [];
        foreach (self::ADD as $productSlug => $wants) {
            if (! isset($products[$productSlug])) {
                continue;
            }

            foreach ($wants as $concernSlug) {
                if (! isset($concerns[$concernSlug])) {
                    continue;
                }

                $key = (int) $concerns[$concernSlug].':'.(int) $products[$productSlug];
                if (isset($have[$key])) {
                    continue;
                }

                $have[$key] = true;
                $rows[] = ['category_id' => (int) $concerns[$concernSlug], 'product_id' => (int) $products[$productSlug]];
            }
        }

        if ($rows !== []) {
            DB::table('category_product')->insertOrIgnore($rows);
        }

        $this->forgetCaches();
    }

    public function down(): void
    {
        // Additions are indistinguishable from links the owner makes by hand
        // afterwards; removing them could take his own work with them.
    }

    /** The category lists and the homepage rails, by their literal keys. */
    private function forgetCaches(): void
    {
        try {
            foreach ([
                'kbb.shop.cats', 'kbb.home.cats', 'kbb.home.rails', 'kbb.cat.rollup.tree',
                'kbb.shop.cats.rollup.include', 'kbb.shop.cats.rollup.own',
                'kbb.home.cats.rollup.include', 'kbb.home.cats.rollup.own',
            ] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }
};
