<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Old WordPress addresses Google Search Console listed as "Not found (404)"
 * on 21 October 2026 (Indexing -> Pages -> Not found (404), exported by the
 * owner), each pointed at the product, brand or collection that replaced it.
 *
 * Every target was checked against the live English sitemap the same day.
 * Products renamed when the catalogue moved go to the same product; products
 * the shop no longer has go to their brand's page, the closest real page.
 * Left 404 on purpose, because a 404 is the right answer there and a redirect
 * to an unrelated page reads to Google as a soft 404: brands the shop does not
 * carry (romand, needly, medi-peel, aroura), /hello-world/, /sample-page/,
 * /international/, /upload/*.m3u8, /wp-* and /product-tag/ampoule/.
 *
 * Same rules as 2027_09_07_100000_old_wordpress_address_redirects: a row is
 * added only where no row for that source exists, so nothing the owner typed
 * on Store -> SEO & Meta -> Redirects & 404s is overwritten, and every row is
 * an ordinary one he can edit or switch off there. A row matches one exact
 * spelling, so each folder address carries its trailing-slash and bare forms.
 */
return new class extends Migration
{
    public const MAP = [
        // Same product, new address
        '/product/cosrx-hyaluronic-hydra-intensive-cream-100mlhydration/' => '/product/cosrx-hyaluronic-hydra-intensive-cream-100ml/',
        '/product/arencia-holy-hyssop-serum-30-2/' => '/product/arencia-holy-hyssop-serum-30/',
        '/product/round-lab-birch-juice-moisturizing-sunscreen/' => '/product/round-lab-birch-juice-moisturizing-sunscreen-50ml/',
        '/product/round-lab-birch-juice-moisturizing-sunscreen/feed/' => '/product/round-lab-birch-juice-moisturizing-sunscreen-50ml/',
        '/product/anua-5-step-skincare-routine-set/' => '/product/anua-all-in-1-glow-essentials-5-step-skincare-set/',
        '/product/skin-1004-madagascar-centella-poremizing-light-gel-cream/' => '/product/skin-1004-madagascar-centella-pore-minimizing-light-gel-cream/',
        '/product/oil-free-ultra-moisturizing-lotion-with-birch-sap/' => '/product/cosrx-oil-free-ultra-moisturizing-lotion-with-birch-sap-100ml/',
        '/product/dr-ceuracle-vegan-kombucha-tea-gel-cream-2/' => '/product/dr-ceuracle-vegan-kombucha-tea-gel-cream/',
        '/product/numbuzin-no-5-vitamin-niacinamide-concentrated-pad/' => '/product/numbuzin-no-5-vitamin-niacinamide-concentrated-toner-pad/',
        '/product/torriden-dive-in-soothing-cream/' => '/product/torriden-dive-in-low-molecular-hyaluronic-acid-soothing-cream-100ml/',
        '/product/dear-klairs-rich-moist-soothing-serum-unscented/' => '/product/dear-klairs-rich-moist-soothing-serum/',
        '/product/axis-y-axis-y-complete-no-stress-physical-sunscreen-white/' => '/product/axis-y-complete-no-stress-physical-sunscreen/',
        '/product/medicube-collagen-jelly-cream-40pcs/' => '/product/medicube-collagen-jelly-cream/',
        '/product/marine-care-eye-cream-30ml/' => '/product/heimish-marine-care-eye-cream-30ml/',
        '/product/etude-soon-jung-ph-5-5-relief-toner/' => '/product/etude-soon-jung-ph-5-5-relief-toner-200ml/',
        '/product/etude-soon-jung-ph-5-5-relief-toner/feed/' => '/product/etude-soon-jung-ph-5-5-relief-toner-200ml/',
        '/product/the-retinol-0-5-oil/' => '/product/cosrx-the-retinol-0-5-oil/',
        '/product/medicube-kojic-acid-turmeric-brightening-gel-mask-set/' => '/product/medicube-kojic-acid-turmeric-brightening-gel-mask/',
        '/product/medicube-pdrn-hydrating-gel-cleanser-mini/' => '/product/medicube-pdrn-hydrating-gel-cleanser/',
        '/product-tag/beauty-of-joseon-matte-sun-stick/' => '/product/beauty-of-joseon-matte-sun-stick-mugwort-camelia-spf-50-pa/',

        // No longer sold: the brand's own page
        '/product/medicube-pdrn-pink-vita-coating-mask/' => '/brands/medicube/',
        '/product/medicube-zero-pore-cooling-mask/' => '/brands/medicube/',
        '/product/medicube-zero-pore-one-day-serum/' => '/brands/medicube/',
        '/product/numbuzin-no-5-daily-multi-vitamin-cream-60ml/' => '/brands/numbuzin/',
        '/product/arencia-cloud-body-scalp-scrub-lavender-pear/' => '/brands/arencia/',
        '/product/tirtir-glossy-coating-mist/' => '/brands/tirtir/',
        '/product/innisfree-dewy-glow-jelly-cream-with-jeju-cherry-blossom-50ml/' => '/brands/innisfree/',
        '/product/purito-seoul-sea-buckthorn-vital-70-cream/' => '/brands/purito-seoul/',
        '/product/round-lab-1025-dokdo-sleeping-pack-unscented/' => '/brands/round-lab/',
        '/product/im-from-fig-boosting-essence/' => '/brands/im-from/',

        // Old category and blog addresses
        '/product-category/lip-care/lip-balms/' => '/collections/lip-care/',
        '/product-category/lip-care/lip-oils/' => '/collections/lip-care/',
        '/category/blog/' => '/blog/',
        '/news/' => '/blog/',
    ];

    /** @return array<string, string> every source in both spellings */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::MAP as $source => $target) {
            $rows[$source] = $target;
            $rows[rtrim($source, '/')] = $target;
        }

        return $rows;
    }

    public function up(): void
    {
        $added = 0;

        foreach (self::rows() as $source => $target) {
            if (DB::table('redirects')->where('source', $source)->exists()) {
                continue;
            }

            $row = ['source' => $source, 'target' => $target, 'code' => 301, 'enabled' => true,
                'created_at' => now(), 'updated_at' => now()];

            if (\Illuminate\Support\Facades\Schema::hasColumn('redirects', 'auto_created')) {
                $row['auto_created'] = false;
            }

            DB::table('redirects')->insert($row);
            $added++;
        }

        CheckRedirects::flushIndex();

        if (app()->runningInConsole()) {
            echo "Search Console 404s: {$added} redirect(s) added (Store -> SEO & Meta -> Redirects & 404s).\n";
        }
    }

    public function down(): void {}
};
